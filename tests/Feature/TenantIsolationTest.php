<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantResolver;
use App\Services\UipJwtService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * عزل الـ tenants على sqlite: المنصة الأم في الذاكرة، وكل عميل ملف sqlite مؤقت.
 * بيغطي: عزل المستخدمين، عزل التوكنات، عزل الـ cache، وتحديد الـ tenant من الهيدر.
 */
class TenantIsolationTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::resetForTests();
        config([
            'security.jwt.secret' => 'test-secret-test-secret-test-secret',
            'security.jwt.enc_key' => 'test-enc-key-test-enc-key-test-enc',
            'tenancy.isolation.template' => 'sqlite',
            'tenancy.isolation.platform_hosts' => ['uipe.test'],
            'cache.default' => 'file',
            'cache.stores.file.path' => sys_get_temp_dir() . '/uip-tenancy-cache-' . uniqid(),
        ]);
        foreach (['tenants', 'tenant_domains'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('tenants', function ($t) {
            $t->id(); $t->string('public_id'); $t->string('name'); $t->string('status')->default('active');
            $t->string('mode')->default('shared'); $t->string('db_name')->nullable(); $t->string('db_status')->default('none');
        });
        Schema::create('tenant_domains', function ($t) {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->string('domain')->unique(); $t->string('status')->default('active');
        });
        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id(); $t->string('email'); $t->string('status')->default('active');
        });
        DB::table('users')->insert(['email' => 'student@uipe.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::deactivate();
        TenantContext::resetForTests();
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tenant(int $id, string $domain, string $mode = 'isolated', string $dbStatus = 'ready', string $email = 'admin@client.test'): object
    {
        $file = sys_get_temp_dir() . "/uip-tenant-{$id}-" . uniqid() . '.sqlite';
        touch($file);
        $this->files[] = $file;
        DB::table('tenants')->insert(['id' => $id, 'public_id' => "tnt_{$id}", 'name' => "T{$id}", 'mode' => $mode, 'db_name' => $file, 'db_status' => $dbStatus]);
        DB::table('tenant_domains')->insert(['tenant_id' => $id, 'domain' => $domain, 'status' => 'active']);
        $row = DB::table('tenants')->where('id', $id)->first();

        TenantContext::run($row, function () use ($email) {
            Schema::create('users', function ($t) {
                $t->id(); $t->string('email'); $t->string('status')->default('active');
            });
            DB::table('users')->insert(['email' => $email]);
        });
        return $row;
    }

    #[Test]
    public function platform_users_are_not_visible_inside_a_tenant_and_vice_versa(): void
    {
        $t = $this->tenant(1, 'client.test');

        $this->assertSame(1, DB::table('users')->where('email', 'student@uipe.test')->count());
        TenantContext::run($t, function () {
            $this->assertTrue(TenantContext::active());
            $this->assertSame(0, DB::table('users')->where('email', 'student@uipe.test')->count());
            $this->assertSame(1, DB::table('users')->where('email', 'admin@client.test')->count());
        });
        $this->assertFalse(TenantContext::active());
        $this->assertSame(0, DB::table('users')->where('email', 'admin@client.test')->count());
        $this->assertSame(1, DB::table('users')->where('email', 'student@uipe.test')->count());
    }

    #[Test]
    public function tokens_do_not_cross_between_platform_and_tenants(): void
    {
        $a = $this->tenant(1, 'a.test');
        $b = $this->tenant(2, 'b.test');

        $platformToken = UipJwtService::seal(UipJwtService::encode(['typ' => 'access', 'sub' => 5], 600));
        $tokenA = TenantContext::run($a, fn () => UipJwtService::seal(UipJwtService::encode(['typ' => 'access', 'sub' => 5], 600)));

        // كل توكن بيتفك في مكانه بس
        $this->assertSame(5, (int) UipJwtService::decodeAccess($platformToken)['sub']);
        $this->assertNull(UipJwtService::decodeAccess($tokenA));
        TenantContext::run($a, function () use ($tokenA, $platformToken) {
            $this->assertSame(5, (int) UipJwtService::decodeAccess($tokenA)['sub']);
            $this->assertNull(UipJwtService::decodeAccess($platformToken));
        });
        TenantContext::run($b, fn () => $this->assertNull(UipJwtService::decodeAccess($tokenA)));

        // نفس الكلام من غير التشفير (التوقيع + claim tid لوحدهم)
        $rawA = TenantContext::run($a, fn () => UipJwtService::encode(['typ' => 'access', 'sub' => 5], 600));
        $this->assertNull(UipJwtService::decode($rawA));
        TenantContext::run($b, fn () => $this->assertNull(UipJwtService::decode($rawA)));
    }

    #[Test]
    public function cache_keys_are_isolated_per_tenant(): void
    {
        $a = $this->tenant(1, 'a.test');
        $b = $this->tenant(2, 'b.test');

        Cache::put('acct:status:5', 'platform', 300);
        TenantContext::run($a, function () {
            $this->assertNull(Cache::get('acct:status:5'));
            Cache::put('acct:status:5', 'A', 300);
        });
        TenantContext::run($b, function () {
            $this->assertNull(Cache::get('acct:status:5'));
            Cache::put('acct:status:5', 'B', 300);
        });
        TenantContext::run($a, fn () => $this->assertSame('A', Cache::get('acct:status:5')));
        $this->assertSame('platform', Cache::get('acct:status:5'));
    }

    #[Test]
    public function middleware_switches_database_by_header_and_rejects_unknown_domains(): void
    {
        $this->tenant(1, 'client.test');
        $this->tenant(2, 'shared.test', 'shared');
        $this->tenant(3, 'pending.test', 'isolated', 'provisioning');

        $mw = new \App\Http\Middleware\ResolveTenant();
        $seen = null;
        $next = function ($req) use (&$seen) {
            $seen = ['tenant' => TenantContext::id(), 'rid' => $req->attributes->get('tenant_id')];
            return response('ok');
        };
        $call = function (array $headers, string $host = 'api.nip.test') use ($mw, $next) {
            $req = \Illuminate\Http\Request::create('http://' . $host . '/api/v1/ping', 'GET', [], [], [], ['HTTP_HOST' => $host]);
            foreach ($headers as $k => $v) {
                $req->headers->set($k, $v);
            }
            $r = $mw->handle($req, $next);
            TenantContext::deactivate();
            return $r;
        };

        // هيدر عميل معزول => تبديل
        $call(['X-Tenant-Domain' => 'client.test']);
        $this->assertSame(1, $seen['tenant']);
        // من غير هيدر + Host مجهول => المنصة الأم
        $seen = null;
        $call([]);
        $this->assertNull($seen['tenant']);
        // دومين المنصة الأم في الهيدر => المنصة
        $seen = null;
        $call(['X-Tenant-Domain' => 'uipe.test']);
        $this->assertNull($seen['tenant']);
        // shared => من غير تبديل
        $seen = null;
        $call(['X-Tenant-Domain' => 'shared.test']);
        $this->assertNull($seen['tenant']);
        // هيدر مجهول => 404، وNext مش بيتنده
        $seen = null;
        $this->assertSame(404, $call(['X-Tenant-Domain' => 'nobody.test'])->getStatusCode());
        $this->assertNull($seen);
        // قاعدة لسه مش جاهزة => 503
        $this->assertSame(503, $call(['X-Tenant-Domain' => 'pending.test'])->getStatusCode());
        // هيدر باظ => 400
        $this->assertSame(400, $call(['X-Tenant-Domain' => 'bad host/x'])->getStatusCode());
        // Host مباشر بدومين العميل (من غير هيدر) => تبديل برضو
        $call([], 'client.test');
        $this->assertSame(1, $seen['tenant']);
    }

    #[Test]
    public function host_normalisation_is_strict(): void
    {
        $this->assertSame('a.example.com', TenantResolver::normalizeHost(' A.Example.COM. '));
        foreach (['', 'a b.com', 'a.com/x', 'a.com:8080', '-a.com', str_repeat('a', 254)] as $bad) {
            $this->assertNull(TenantResolver::normalizeHost($bad), $bad);
        }
    }
}
