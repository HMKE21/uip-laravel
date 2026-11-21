<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantDomainService;
use App\Services\Tenancy\TenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Tenants — تدفق كامل على sqlite في الذاكرة (من غير migrations المنصة كلها): جداول users + dev_* الصغيرة + migration الـ tenants نفسها. */
class DevTenantFlowTest extends TestCase
{
    private TenantService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['tenant_domains', 'tenant_branding', 'tenant_members', 'tenants', 'dev_apps', 'dev_api_keys', 'dev_webhooks', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('full_name', 150)->default('');
            $t->string('email', 190);
            $t->string('status', 12)->default('active');
            $t->dateTime('deleted_at')->nullable();
        });
        Schema::create('dev_apps', function ($t) { $t->id(); $t->unsignedBigInteger('owner_user_id')->default(0); $t->string('status', 12)->default('active'); $t->string('suspended_by', 12)->nullable(); $t->string('suspended_reason', 200)->nullable(); $t->dateTime('updated_at')->nullable(); });
        Schema::create('dev_api_keys', function ($t) { $t->id(); $t->unsignedBigInteger('user_id')->default(0); $t->dateTime('revoked_at')->nullable(); $t->dateTime('updated_at')->nullable(); });
        Schema::create('dev_webhooks', function ($t) { $t->id(); $t->unsignedBigInteger('user_id')->default(0); $t->boolean('is_active')->default(1); $t->dateTime('disabled_at')->nullable(); $t->string('disabled_reason', 120)->nullable(); $t->dateTime('updated_at')->nullable(); });
        (require base_path('database/migrations/2027_12_01_100000_create_platform_tenants.php'))->up();
        Cache::flush();
        TenantDomainService::$dns = null;
        $this->svc = app(TenantService::class);
    }

    protected function tearDown(): void
    {
        TenantDomainService::$dns = null;
        parent::tearDown();
    }

    private function user(string $email, string $status = 'active'): int
    {
        return (int) DB::table('users')->insertGetId(['full_name' => $email, 'email' => $email, 'status' => $status]);
    }

    private function workspace(int $owner): int
    {
        return (int) $this->svc->create($owner, ['name' => 'Acme Corp'])['tenant']['id'];
    }

    #[Test]
    public function creating_a_workspace_makes_the_creator_its_owner_with_default_branding(): void
    {
        $u = $this->user('o@acme.test');
        $out = $this->svc->create($u, ['name' => 'Acme Corp', 'kind' => 'company']);
        $this->assertSame('owner', $out['tenant']['role']);
        $this->assertStringStartsWith('tnt_', $out['tenant']['public_id']);
        $this->assertSame('Acme Corp', $out['branding']['display_name']);
        $this->assertCount(1, $this->svc->mine($u));
    }

    #[Test]
    public function a_non_member_cannot_even_see_that_the_workspace_exists(): void
    {
        $id = $this->workspace($this->user('o@acme.test'));
        $stranger = $this->user('x@other.test');
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(404);
        $this->svc->show($stranger, $id);
    }

    #[Test]
    public function roles_are_enforced_and_the_last_owner_cannot_leave(): void
    {
        $owner = $this->user('o@acme.test');
        $dev = $this->user('d@acme.test');
        $id = $this->workspace($owner);
        $this->svc->addMember($owner, $id, 'D@acme.test', 'developer');

        try {
            $this->svc->addMember($dev, $id, 'o@acme.test', 'viewer');
            $this->fail('a developer must not manage members');
        } catch (\DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }
        try {
            $this->svc->removeMember($owner, $id, $owner);
            $this->fail('the last owner must not leave');
        } catch (\DomainException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->svc->setRole($owner, $id, $dev, 'owner');
        $this->svc->removeMember($owner, $id, $owner);   // الآن فيه owner تاني
        $left = $this->svc->members($dev, $id);
        $this->assertCount(1, $left);
        $this->assertSame('owner', $left[0]['role']);
    }

    #[Test]
    public function an_admin_cannot_promote_to_admin_or_touch_owners(): void
    {
        $owner = $this->user('o@acme.test');
        $admin = $this->user('a@acme.test');
        $this->user('n@acme.test');
        $id = $this->workspace($owner);
        $this->svc->addMember($owner, $id, 'a@acme.test', 'admin');
        foreach ([['n@acme.test', 'admin'], ['n@acme.test', 'owner']] as [$mail, $role]) {
            try {
                $this->svc->addMember($admin, $id, $mail, $role);
                $this->fail("admin granted {$role}");
            } catch (\DomainException $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
        $this->svc->addMember($admin, $id, 'n@acme.test', 'viewer');   // مسموح
        $this->assertCount(3, $this->svc->members($owner, $id));
    }

    #[Test]
    public function removing_a_member_stops_their_keys_apps_and_webhooks_only(): void
    {
        $owner = $this->user('o@acme.test');
        $dev = $this->user('d@acme.test');
        $id = $this->workspace($owner);
        $this->svc->addMember($owner, $id, 'd@acme.test', 'developer');
        DB::table('dev_api_keys')->insert([['user_id' => $dev, 'tenant_id' => $id], ['user_id' => $owner, 'tenant_id' => $id], ['user_id' => $dev, 'tenant_id' => null]]);
        DB::table('dev_apps')->insert([['owner_user_id' => $dev, 'tenant_id' => $id], ['owner_user_id' => $dev, 'tenant_id' => null]]);
        DB::table('dev_webhooks')->insert([['user_id' => $dev, 'tenant_id' => $id]]);

        $this->svc->removeMember($owner, $id, $dev);

        $this->assertSame(1, DB::table('dev_api_keys')->whereNotNull('revoked_at')->count());                 // بتاع الـ tenant بس
        $this->assertNull(DB::table('dev_api_keys')->where('user_id', $owner)->value('revoked_at'));
        $this->assertNull(DB::table('dev_api_keys')->where('user_id', $dev)->whereNull('tenant_id')->value('revoked_at')); // الشخصي متلمسش
        $this->assertSame('suspended', DB::table('dev_apps')->where('tenant_id', $id)->value('status'));
        $this->assertSame('active', DB::table('dev_apps')->whereNull('tenant_id')->value('status'));
        $this->assertSame(0, (int) DB::table('dev_webhooks')->where('tenant_id', $id)->value('is_active'));
    }

    #[Test]
    public function the_api_gate_blocks_suspended_workspaces_removed_members_and_viewers(): void
    {
        $owner = $this->user('o@acme.test');
        $v = $this->user('v@acme.test');
        $id = $this->workspace($owner);
        $this->svc->addMember($owner, $id, 'v@acme.test', 'viewer');

        $this->assertNull(TenantService::gate($id, $owner));
        $this->assertSame('tenant_role_insufficient', TenantService::gate($id, $v));
        $this->assertSame('tenant_membership_revoked', TenantService::gate($id, 999));
        $this->assertSame('tenant_not_found', TenantService::gate(424242, $owner));

        $this->svc->adminUpdate($owner, $id, ['status' => 'suspended', 'suspended_reason' => 'abuse']);
        $this->assertSame('tenant_suspended', TenantService::gate($id, $owner));
        $this->svc->adminUpdate($owner, $id, ['status' => 'active']);
        $this->assertNull(TenantService::gate($id, $owner));
    }

    #[Test]
    public function a_suspended_workspace_is_read_only_for_its_members(): void
    {
        $owner = $this->user('o@acme.test');
        $id = $this->workspace($owner);
        $this->svc->adminUpdate($owner, $id, ['status' => 'suspended']);
        $this->assertSame('suspended', $this->svc->show($owner, $id)['tenant']['status']);   // القراءة شغالة
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(403);
        $this->svc->updateBranding($owner, $id, ['display_name' => 'x']);
    }

    #[Test]
    public function branding_is_validated_and_hiding_the_platform_needs_the_white_label_feature(): void
    {
        $owner = $this->user('o@acme.test');
        $id = $this->workspace($owner);
        $b = $this->svc->updateBranding($owner, $id, ['primary_color' => '#1a73e8', 'logo_url' => 'https://cdn.acme.test/logo.png', 'theme' => 'dark', 'default_locale' => 'ar']);
        $this->assertSame('#1A73E8', $b['primary_color']);
        $this->assertSame('ar', $b['default_locale']);

        foreach ([['primary_color' => 'red'], ['logo_url' => 'javascript:alert(1)'], ['logo_url' => 'http://insecure.test/a.png'], ['theme' => 'neon'], ['support_email' => 'nope'], ['hide_platform_branding' => true]] as $bad) {
            try {
                $this->svc->updateBranding($owner, $id, $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\DomainException $e) {
                $this->assertContains($e->getCode(), [403, 422], json_encode($bad));
            }
        }
        $this->svc->adminUpdate($owner, $id, ['features' => ['white_label']]);
        $this->assertTrue($this->svc->updateBranding($owner, $id, ['hide_platform_branding' => true])['hide_platform_branding']);
    }

    #[Test]
    public function a_domain_goes_pending_verified_active_and_only_active_ones_are_served(): void
    {
        config(['tenancy.domains.cname_target' => 'edge.uipe.test']);
        $owner = $this->user('o@acme.test');
        $id = $this->workspace($owner);

        try {
            $this->svc->addDomain($owner, $id, 'meet.acme.test');
            $this->fail('custom_domain feature is required');
        } catch (\DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->svc->adminUpdate($owner, $id, ['features' => ['custom_domain']]);
        $d = $this->svc->addDomain($owner, $id, 'Meet.Acme.test');
        $this->assertSame('pending', $d['status']);
        $this->assertSame('_uipe-challenge.meet.acme.test', $d['dns']['txt_name']);
        $this->assertNull(TenantDomainService::resolve('meet.acme.test'));

        $records = [];
        TenantDomainService::$dns = function (string $host, string $type) use (&$records) { return $records[$type . ':' . $host] ?? []; };

        $this->assertSame('pending', $this->svc->verifyDomain($owner, $id, $d['id'])['status']);   // مفيش TXT
        $this->backdateCheck($d['id']);

        $records['TXT:_uipe-challenge.meet.acme.test'] = ['uipe-verify=wrong'];
        $this->assertSame('pending', $this->svc->verifyDomain($owner, $id, $d['id'])['status']);   // TXT غلط
        $this->backdateCheck($d['id']);

        $records['TXT:_uipe-challenge.meet.acme.test'] = [$d['dns']['txt_value']];
        $v = $this->svc->verifyDomain($owner, $id, $d['id']);
        $this->assertSame('verified', $v['status']);                                              // الملكية تمام، CNAME لسه
        $this->assertNull(TenantDomainService::resolve('meet.acme.test'));
        $this->backdateCheck($d['id']);

        $records['CNAME:meet.acme.test'] = ['edge.uipe.test.'];
        $a = $this->svc->verifyDomain($owner, $id, $d['id']);
        $this->assertSame('active', $a['status']);
        $this->assertTrue($a['is_primary']);
        $this->assertSame($id, (int) TenantDomainService::resolve('MEET.acme.test')->id);

        $this->svc->adminUpdate($owner, $id, ['status' => 'suspended']);                          // tenant موقوف → الدومين مبيتخدمش
        $this->assertNull(TenantDomainService::resolve('meet.acme.test'));
    }

    #[Test]
    public function a_duckdns_style_domain_verifies_with_an_apex_txt_and_an_a_record(): void
    {
        config(['tenancy.domains.cname_target' => '', 'tenancy.domains.edge_ips' => ['13.51.57.46']]);
        $owner = $this->user('o@acme.test');
        $id = $this->workspace($owner);
        $this->svc->adminUpdate($owner, $id, ['features' => ['custom_domain']]);
        $d = $this->svc->addDomain($owner, $id, 'my-meet.duckdns.org');
        $this->assertSame('my-meet.duckdns.org', $d['dns']['txt_alt_name']);
        $this->assertSame(['13.51.57.46'], $d['dns']['a_targets']);

        $records = [];
        TenantDomainService::$dns = function (string $host, string $type) use (&$records) { return $records[$type . ':' . $host] ?? []; };

        $records['TXT:my-meet.duckdns.org'] = [$d['dns']['txt_value']];       // TXT على الدومين نفسه (مفيش _underscore في DuckDNS)
        $this->assertSame('verified', $this->svc->verifyDomain($owner, $id, $d['id'])['status']);   // الملكية تمام، لسه مش متوجّه
        $this->backdateCheck($d['id']);

        $records['A:my-meet.duckdns.org'] = ['1.2.3.4'];                      // IP غلط
        $this->assertSame('verified', $this->svc->verifyDomain($owner, $id, $d['id'])['status']);
        $this->backdateCheck($d['id']);

        $records['A:my-meet.duckdns.org'] = ['13.51.57.46'];
        $this->assertSame('active', $this->svc->verifyDomain($owner, $id, $d['id'])['status']);
        $this->assertSame($id, (int) TenantDomainService::resolve('my-meet.duckdns.org')->id);
    }

    #[Test]
    public function the_same_domain_cannot_be_claimed_by_two_workspaces(): void
    {
        $a = $this->user('a@one.test');
        $b = $this->user('b@two.test');
        $t1 = (int) $this->svc->create($a, ['name' => 'One Corp'])['tenant']['id'];
        $t2 = (int) $this->svc->create($b, ['name' => 'Two Corp'])['tenant']['id'];
        $this->svc->adminUpdate($a, $t1, ['features' => ['custom_domain']]);
        $this->svc->adminUpdate($a, $t2, ['features' => ['custom_domain']]);
        $this->svc->addDomain($a, $t1, 'meet.shared.test');
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(409);
        $this->svc->addDomain($b, $t2, 'meet.shared.test');
    }

    #[Test]
    public function deleting_a_workspace_needs_the_exact_slug_and_removes_it(): void
    {
        $owner = $this->user('o@acme.test');
        $id = $this->workspace($owner);
        $slug = (string) DB::table('tenants')->where('id', $id)->value('slug');
        try {
            $this->svc->delete($owner, $id, 'wrong');
            $this->fail('slug confirmation is required');
        } catch (\DomainException $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->svc->delete($owner, $id, $slug);
        $this->assertSame(0, DB::table('tenants')->where('id', $id)->count());
    }

    private function backdateCheck(int $domainId): void
    {
        DB::table('tenant_domains')->where('id', $domainId)->update(['last_checked_at' => now()->subMinute()]);
    }
}
