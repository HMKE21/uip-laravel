<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** المشاركين الخارجيين (المستخدم النهائي عند العميل): upsert جزئي + عزل بين الـ tenants + صلاحيات الأدوار + الحد. sqlite في الذاكرة. */
class DevTenantParticipantsTest extends TestCase
{
    private TenantService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['tenant_embed_sessions', 'tenant_embed_origins', 'tenant_participants', 'tenant_domains', 'tenant_branding', 'tenant_members', 'tenants', 'dev_apps', 'dev_api_keys', 'dev_webhooks', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('full_name', 150)->default('');
            $t->string('email', 190);
            $t->string('status', 12)->default('active');
            $t->dateTime('deleted_at')->nullable();
        });
        Schema::create('dev_apps', function ($t) { $t->id(); $t->unsignedBigInteger('owner_user_id')->default(0); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('status', 12)->default('active'); $t->string('suspended_by', 12)->nullable(); $t->string('suspended_reason', 200)->nullable(); $t->dateTime('updated_at')->nullable(); });
        Schema::create('dev_api_keys', function ($t) { $t->id(); $t->unsignedBigInteger('user_id')->default(0); $t->unsignedBigInteger('tenant_id')->nullable(); $t->dateTime('revoked_at')->nullable(); $t->dateTime('updated_at')->nullable(); });
        Schema::create('dev_webhooks', function ($t) { $t->id(); $t->unsignedBigInteger('user_id')->default(0); $t->unsignedBigInteger('tenant_id')->nullable(); $t->boolean('is_active')->default(1); $t->dateTime('disabled_at')->nullable(); $t->string('disabled_reason', 120)->nullable(); $t->dateTime('updated_at')->nullable(); });
        (require base_path('database/migrations/2027_12_01_100000_create_platform_tenants.php'))->up();
        (require base_path('database/migrations/2027_12_03_100000_create_tenant_embed.php'))->up();
        Cache::flush();
        $this->svc = app(TenantService::class);
    }

    private function user(string $email): int
    {
        return (int) DB::table('users')->insertGetId(['full_name' => $email, 'email' => $email, 'status' => 'active']);
    }

    private function workspace(int $owner, string $name = 'Acme Corp'): int
    {
        return (int) $this->svc->create($owner, ['name' => $name])['tenant']['id'];
    }

    #[Test]
    public function upsert_creates_then_changes_only_the_fields_that_were_sent(): void
    {
        $o = $this->user('o@acme.test');
        $id = $this->workspace($o);
        $first = $this->svc->saveParticipant($o, $id, ['external_id' => 'emp-1', 'name' => 'Sara Ali', 'email' => 'sara@acme.test', 'role' => 'host', 'meta' => ['dept' => 'HR']]);
        $this->assertTrue($first['created']);
        $this->assertSame('host', $first['participant']['role']);

        $second = $this->svc->saveParticipant($o, $id, ['external_id' => 'emp-1', 'role' => 'viewer']);
        $this->assertFalse($second['created']);
        $this->assertSame('viewer', $second['participant']['role']);
        $this->assertSame('Sara Ali', $second['participant']['name']);
        $this->assertSame('sara@acme.test', $second['participant']['email']);
        $this->assertSame(['dept' => 'HR'], (array) $second['participant']['meta']);
        $this->assertSame(1, $this->svc->listParticipants($o, $id)['meta']['total']);
    }

    #[Test]
    public function validation_rejects_bad_ids_roles_emails_and_a_nameless_new_participant(): void
    {
        $o = $this->user('o@acme.test');
        $id = $this->workspace($o);
        foreach ([
            ['external_id' => 'has space', 'name' => 'X'],
            ['external_id' => '', 'name' => 'X'],
            ['external_id' => 'ok', 'name' => 'X', 'role' => 'superadmin'],
            ['external_id' => 'ok', 'name' => 'X', 'email' => 'not-an-email'],
            ['external_id' => 'ok'],                                          // name مطلوب عند الإنشاء
            ['external_id' => 'ok', 'name' => 'X', 'meta' => ['blob' => str_repeat('a', 5000)]],
        ] as $bad) {
            try {
                $this->svc->saveParticipant($o, $id, $bad);
                $this->fail('Expected a DomainException for ' . json_encode($bad));
            } catch (\DomainException $e) {
                $this->assertSame(422, $e->getCode(), json_encode($bad));
            }
        }
        $this->assertSame(0, $this->svc->listParticipants($o, $id)['meta']['total']);
    }

    #[Test]
    public function the_same_external_id_in_two_workspaces_stays_isolated(): void
    {
        $a = $this->user('a@one.test');
        $b = $this->user('b@two.test');
        $ta = $this->workspace($a, 'One Corp');
        $tb = $this->workspace($b, 'Two Corp');
        $this->svc->saveParticipant($a, $ta, ['external_id' => 'u1', 'name' => 'From One']);
        $this->svc->saveParticipant($b, $tb, ['external_id' => 'u1', 'name' => 'From Two']);

        $this->assertSame('From One', $this->svc->listParticipants($a, $ta)['items'][0]['name']);
        $this->assertSame('From Two', $this->svc->listParticipants($b, $tb)['items'][0]['name']);

        $this->svc->deleteParticipant($a, $ta, 'u1');
        $this->assertSame(0, $this->svc->listParticipants($a, $ta)['meta']['total']);
        $this->assertSame(1, $this->svc->listParticipants($b, $tb)['meta']['total']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(404);                                      // غريب مش عضو: الـ workspace مش موجود بالنسبة له
        $this->svc->listParticipants($a, $tb);
    }

    #[Test]
    public function viewers_can_read_but_not_write_and_the_limit_is_enforced(): void
    {
        $o = $this->user('o@acme.test');
        $v = $this->user('v@acme.test');
        $id = $this->workspace($o);
        $this->svc->addMember($o, $id, 'v@acme.test', 'viewer');
        $this->svc->saveParticipant($o, $id, ['external_id' => 'p1', 'name' => 'P1']);

        $this->assertSame(1, $this->svc->listParticipants($v, $id)['meta']['total']);
        try {
            $this->svc->saveParticipant($v, $id, ['external_id' => 'p2', 'name' => 'P2']);
            $this->fail('viewer must not write');
        } catch (\DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        DB::table('tenants')->where('id', $id)->update(['limits' => json_encode(['participants' => 1])]);
        try {
            $this->svc->saveParticipant($o, $id, ['external_id' => 'p2', 'name' => 'P2']);
            $this->fail('limit must apply');
        } catch (\DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame('P1b', $this->svc->saveParticipant($o, $id, ['external_id' => 'p1', 'name' => 'P1b'])['participant']['name']);   // التعديل مش بيتأثر بالحد
    }

    #[Test]
    public function search_and_paging_work_and_wildcards_in_the_query_are_literal(): void
    {
        $o = $this->user('o@acme.test');
        $id = $this->workspace($o);
        foreach (['alpha' => 'Alpha One', 'beta' => 'Beta Two', 'gamma' => '100% Gamma'] as $ext => $name) {
            $this->svc->saveParticipant($o, $id, ['external_id' => $ext, 'name' => $name]);
        }
        $this->assertSame(['beta'], array_column($this->svc->listParticipants($o, $id, ['q' => 'Beta'])['items'], 'external_id'));
        $this->assertSame(['gamma'], array_column($this->svc->listParticipants($o, $id, ['q' => '100%'])['items'], 'external_id'));
        $this->assertSame(1, $this->svc->listParticipants($o, $id, ['q' => '%'])['meta']['total']);   // '%' حرفيًا: مطابق لـ gamma بس
        $page = $this->svc->listParticipants($o, $id, ['per_page' => 2, 'page' => 2]);
        $this->assertCount(1, $page['items']);
        $this->assertSame(2, $page['meta']['pages']);
    }

    #[Test]
    public function deleting_the_workspace_removes_its_participants_origins_and_sessions(): void
    {
        $o = $this->user('o@acme.test');
        $id = $this->workspace($o);
        $this->svc->saveParticipant($o, $id, ['external_id' => 'p1', 'name' => 'P1']);
        $this->svc->addEmbedOrigin($o, $id, 'https://app.acme.test');
        $slug = (string) DB::table('tenants')->where('id', $id)->value('slug');
        $this->svc->delete($o, $id, $slug);
        $this->assertSame(0, DB::table('tenant_participants')->count());
        $this->assertSame(0, DB::table('tenant_embed_origins')->count());
    }
}
