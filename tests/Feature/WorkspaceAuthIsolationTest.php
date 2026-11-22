<?php

namespace Tests\Feature;

use App\Services\Tenancy\PlatformUsers;
use App\Services\Tenancy\TenantAuthSettings;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\WorkspaceAccess;
use App\Services\UipJwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * عزل دخول المنصات (sqlite): لينك الدعوة، إعدادات جوجل/مايكروسوفت لكل منصة، وفحص عضوية المساحات المشتركة.
 * المنصة الأم في الذاكرة، وكل منصة معزولة ملف sqlite مؤقت (زي TenantIsolationTest).
 */
class WorkspaceAuthIsolationTest extends TestCase
{
    private const GOOGLE_A = '111111111111-abcdefghijklmnop.apps.googleusercontent.com';
    private const MS_A = '11111111-2222-3333-4444-555555555555';

    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::resetForTests();
        TenantAuthSettings::flush();
        config([
            'security.jwt.secret' => 'test-secret-test-secret-test-secret',
            'security.jwt.enc_key' => 'test-enc-key-test-enc-key-test-enc',
            'tenancy.isolation.template' => 'sqlite',
            'tenancy.isolation.platform_hosts' => ['uipe.test'],
            'cache.default' => 'array',
        ]);
        Cache::flush();
        foreach (['tenants', 'tenant_members', 'tenant_auth_settings', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('tenants', function ($t) {
            $t->id(); $t->string('public_id'); $t->string('name'); $t->string('slug')->nullable(); $t->string('status')->default('active');
            $t->string('mode')->default('shared'); $t->string('db_name')->nullable(); $t->string('db_status')->default('none');
        });
        Schema::create('tenant_members', function ($t) {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('user_id'); $t->string('role')->default('viewer');
        });
        Schema::create('tenant_auth_settings', function ($t) {
            $t->id(); $t->unsignedBigInteger('tenant_id')->unique(); $t->string('template')->default('split'); $t->text('config')->nullable(); $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        TenantContext::deactivate();
        TenantContext::resetForTests();
        TenantAuthSettings::flush();
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** منصة معزولة: صف في tenants + ملف sqlite فيه جدول users. */
    private function isolated(int $id, string $slug, string $email = 'admin@client.test', string $hash = 'hash-1'): object
    {
        $file = sys_get_temp_dir() . "/uip-ws-{$id}-" . uniqid() . '.sqlite';
        touch($file);
        $this->files[] = $file;
        DB::table('tenants')->insert(['id' => $id, 'public_id' => "tnt_{$id}", 'name' => "T{$id}", 'slug' => $slug, 'mode' => 'isolated', 'db_name' => $file, 'db_status' => 'ready']);
        $row = DB::table('tenants')->where('id', $id)->first();
        TenantContext::run($row, function () use ($email, $hash) {
            Schema::create('users', function ($t) {
                $t->id(); $t->string('full_name')->nullable(); $t->string('email'); $t->string('status')->default('active');
                $t->string('password_hash')->nullable(); $t->timestamp('deleted_at')->nullable();
            });
            DB::table('users')->insert(['email' => $email, 'full_name' => 'Admin', 'password_hash' => $hash]);
        });
        return $row;
    }

    private function settings(int $tenantId, array $cfg, string $template = 'split'): void
    {
        DB::table('tenant_auth_settings')->insert(['tenant_id' => $tenantId, 'template' => $template, 'config' => json_encode($cfg), 'created_at' => now(), 'updated_at' => now()]);
        TenantAuthSettings::flush();
    }

    /* ------------------------------------------------------------ invitations */

    #[Test]
    public function an_invitation_opens_only_in_its_own_platform_and_only_once(): void
    {
        $a = $this->isolated(1, 'acme');
        $b = $this->isolated(2, 'beta');
        $token = PlatformUsers::mintInviteToken($a, 1, 'hash-1', 600);

        TenantContext::run($a, function () use ($token) {
            $u = PlatformUsers::userFromInvite($token);
            $this->assertNotNull($u);
            $this->assertSame('admin@client.test', $u->email);
        });
        // منصة تانية (وفيها مستخدم برقم 1 كمان) والمنصة الأم: مرفوض
        TenantContext::run($b, fn () => $this->assertNull(PlatformUsers::userFromInvite($token)));
        $this->assertNull(UipJwtService::decode($token));

        // أول ما الباسورد يتغيّر اللينك يبطل (استخدام واحد)
        TenantContext::run($a, function () use ($token) {
            DB::table('users')->where('id', 1)->update(['password_hash' => 'hash-2']);
            $this->assertNull(PlatformUsers::userFromInvite($token));
        });
    }

    #[Test]
    public function an_invitation_token_is_never_accepted_as_a_login_token(): void
    {
        $a = $this->isolated(1, 'acme');
        $token = PlatformUsers::mintInviteToken($a, 1, 'hash-1', 600);
        TenantContext::run($a, fn () => $this->assertNull(UipJwtService::decodeAccess($token)));
    }

    #[Test]
    public function suspended_or_deleted_people_cannot_use_an_invitation(): void
    {
        $a = $this->isolated(1, 'acme');
        $token = PlatformUsers::mintInviteToken($a, 1, 'hash-1', 600);
        TenantContext::run($a, function () use ($token) {
            DB::table('users')->where('id', 1)->update(['status' => 'suspended']);
            $this->assertNull(PlatformUsers::userFromInvite($token));
            DB::table('users')->where('id', 1)->update(['status' => 'active', 'deleted_at' => now()]);
            $this->assertNull(PlatformUsers::userFromInvite($token));
        });
    }

    /* ------------------------------------------------------------ Google / Microsoft per platform */

    #[Test]
    public function each_platform_accepts_only_its_own_google_and_microsoft_client(): void
    {
        $a = $this->isolated(1, 'acme');
        $b = $this->isolated(2, 'beta');
        $this->settings(1, ['google' => ['enabled' => true, 'client_id' => self::GOOGLE_A], 'microsoft' => ['enabled' => true, 'client_id' => self::MS_A, 'directory' => 'organizations']]);

        $this->assertNull(TenantAuthSettings::googleIds());          // المنصة الأم: إعدادات UIPE زي ما هي
        $this->assertNull(TenantAuthSettings::microsoftClientId());
        TenantContext::run($a, function () {
            $this->assertSame([self::GOOGLE_A], TenantAuthSettings::googleIds());
            $this->assertSame(self::MS_A, TenantAuthSettings::microsoftClientId());
            $this->assertSame('https://login.microsoftonline.com/organizations', TenantAuthSettings::microsoftAuthority());
        });
        TenantContext::run($b, function () { // منصة من غير إعدادات: مفيش مزوّد (مش بترجع لإعدادات UIPE)
            $this->assertSame([], TenantAuthSettings::googleIds());
            $this->assertSame('', TenantAuthSettings::microsoftClientId());
        });
    }

    #[Test]
    public function a_disabled_provider_is_off_even_with_an_id_saved(): void
    {
        $a = $this->isolated(1, 'acme');
        $this->settings(1, ['google' => ['enabled' => false, 'client_id' => self::GOOGLE_A]]);
        TenantContext::run($a, fn () => $this->assertSame([], TenantAuthSettings::googleIds()));
    }

    #[Test]
    public function saving_sign_in_settings_takes_effect_at_once_inside_the_platform(): void
    {
        $a = $this->isolated(1, 'acme');
        TenantContext::run($a, fn () => $this->assertSame([], TenantAuthSettings::googleIds()));
        TenantAuthSettings::save(1, ['template' => 'aurora', 'google' => ['enabled' => true, 'client_id' => self::GOOGLE_A]]);
        TenantContext::run($a, function () {
            $this->assertSame([self::GOOGLE_A], TenantAuthSettings::googleIds());
            $this->assertSame('aurora', TenantAuthSettings::active()['template']);
        });
    }

    #[Test]
    public function sign_in_settings_are_validated_and_sanitised(): void
    {
        $n = TenantAuthSettings::normalize([
            'template' => 'not-a-template', 'headline' => ['en' => '<script>alert(1)</script>Hi', 'ar' => str_repeat('ع', 200)],
            'google' => ['enabled' => true, 'client_id' => 'evil.example.com'],
            'microsoft' => ['enabled' => true, 'client_id' => 'not-a-guid', 'directory' => 'https://evil.example'],
        ]);
        $this->assertSame('split', $n['template']);
        $this->assertSame('alert(1)Hi', $n['headline']['en']);
        $this->assertSame(80, mb_strlen($n['headline']['ar']));
        $this->assertFalse($n['google']['enabled']);
        $this->assertSame('', $n['google']['client_id']);
        $this->assertFalse($n['microsoft']['enabled']);
        $this->assertSame('common', $n['microsoft']['directory']);

        $this->expectException(\InvalidArgumentException::class);
        TenantAuthSettings::validate(['google' => ['client_id' => 'evil.example.com']]);
    }

    /* ------------------------------------------------------------ shared workspaces (UIPE accounts) */

    private function sharedWorkspace(int $id, string $slug): void
    {
        DB::table('tenants')->insert(['id' => $id, 'public_id' => "tnt_{$id}", 'name' => "W{$id}", 'slug' => $slug, 'mode' => 'shared']);
    }

    private function reqFor(string $slug): Request
    {
        $r = Request::create('/api/v1/me', 'GET');
        $r->headers->set('X-Tenant-Slug', $slug);
        return $r;
    }

    #[Test]
    public function only_members_enter_a_shared_workspace(): void
    {
        $this->sharedWorkspace(10, 'haitham');
        $this->sharedWorkspace(11, 'other');
        DB::table('tenant_members')->insert(['tenant_id' => 10, 'user_id' => 7, 'role' => 'viewer']);
        DB::table('tenant_members')->insert(['tenant_id' => 11, 'user_id' => 8, 'role' => 'owner']); // عضو في مساحة تانية بس

        $this->assertNull(WorkspaceAccess::check($this->reqFor('haitham'), 7, 'student'));
        $this->assertSame('not_workspace_member', WorkspaceAccess::check($this->reqFor('haitham'), 99, 'student')['code']);
        $this->assertSame('not_workspace_member', WorkspaceAccess::check($this->reqFor('haitham'), 8, 'student')['code']);
        $this->assertNull(WorkspaceAccess::check($this->reqFor('haitham'), 99, 'admin'));      // أدمن المنصة: دعم/مراجعة
        $this->assertNull(WorkspaceAccess::check(Request::create('/api/v1/me', 'GET'), 99, 'student')); // المنصة نفسها
    }

    #[Test]
    public function removing_a_member_closes_the_door_straight_away(): void
    {
        $this->sharedWorkspace(10, 'haitham');
        DB::table('tenant_members')->insert(['tenant_id' => 10, 'user_id' => 7, 'role' => 'viewer']);
        $this->assertNull(WorkspaceAccess::check($this->reqFor('haitham'), 7, 'student'));

        DB::table('tenant_members')->where('user_id', 7)->delete();
        WorkspaceAccess::forget(10, 7); // نفس اللي TenantService::removeMember بيعمله
        $this->assertSame('not_workspace_member', WorkspaceAccess::check($this->reqFor('haitham'), 7, 'student')['code']);
    }

    #[Test]
    public function an_isolated_platform_is_not_decided_here(): void
    {
        $this->isolated(1, 'acme');
        // المنصة المعزولة ليها قاعدتها ومستخدميها؛ ResolveTenant هو اللي بيحكم عليها
        $this->assertNull(WorkspaceAccess::check($this->reqFor('acme'), 99, 'student'));
        $this->assertNull(WorkspaceAccess::check($this->reqFor('../etc'), 99, 'student'));
    }

    /* ------------------------------------------------------------ guards that must not be removed */

    #[Test]
    public function sign_up_paths_stay_closed_inside_a_platform(): void
    {
        $base = dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Auth/';
        foreach (['RegisterController.php' => 'public function submit', 'GoogleAuthController.php' => 'public function complete'] as $file => $anchor) {
            $src = (string) file_get_contents($base . $file);
            $pos = strpos($src, $anchor);
            $this->assertNotFalse($pos, "{$file}: {$anchor} not found");
            $this->assertStringContainsString('TenantContext::active()', substr($src, $pos, 700), "{$file}: the platform sign-up guard is missing");
        }
        $google = (string) file_get_contents($base . 'GoogleAuthController.php');
        $this->assertStringContainsString('no_workspace_account', $google);
    }
}
