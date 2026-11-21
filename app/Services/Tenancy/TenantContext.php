<?php

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * العميل (tenant) الحالي في الـ request/الأمر. لما يبقى active:
 *  - الـ default connection بيتحوّل لقاعدة العميل (connection اسمه "tenant") فكل DB::table() والموديلات بتشتغل عليها لوحدها.
 *  - الـ cache بيتعزل (prefix / مسار مختلف) فمفاتيح زي acct:status:{uid} مبتتعارضش بين العملاء.
 *  - UipJwtService بيشتق سر توقيع وتشفير مختلف لكل عميل ويحط claim "tid" في التوكن.
 *
 * مهم: التطبيق لازم يشتغل request-per-process (php-fpm / artisan serve). مش Octane/Swoole من غير reset بين الـ requests.
 */
class TenantContext
{
    public const CONNECTION = 'tenant';

    private static ?object $tenant = null;
    private static ?string $central = null;

    /** القيم الأصلية للـ cache قبل العزل. @var array<string,mixed>|null */
    private static ?array $cacheOrig = null;

    /** للاختبارات بس: يرجّع الحالة الساكنة لأصلها. */
    public static function resetForTests(): void
    {
        self::$tenant = null;
        self::$central = null;
        self::$cacheOrig = null;
        TenantStorage::reset();
    }

    public static function active(): bool
    {
        return self::$tenant !== null;
    }

    public static function tenant(): ?object
    {
        return self::$tenant;
    }

    public static function id(): ?int
    {
        return self::$tenant ? (int) self::$tenant->id : null;
    }

    /** اسم الـ connection بتاع المنصة الأم (اللي فيه tenants و tenant_domains). */
    public static function centralConnection(): string
    {
        return self::$central ?? DB::getDefaultConnection();
    }

    /** بادئة بتتحط في مفاتيح الـ cache الحساسة (حزام أمان فوق عزل الـ cache نفسه). '' على المنصة الأم. */
    public static function ns(): string
    {
        return self::$tenant ? 't' . (int) self::$tenant->id . ':' : '';
    }

    /**
     * بادئة قنوات الـ realtime (Reverb): 't5.' للعميل، '' للمنصة الأم.
     * مهم: Pusher بيمنع ':' في أسماء القنوات، فبنستخدم '.'. الـ ids (مستخدم 5 عند عميل A = مستخدم 5 عند عميل B) بتتصادم من غيرها.
     */
    public static function channelPrefix(): string
    {
        return self::$tenant ? 't' . (int) self::$tenant->id . '.' : '';
    }

    /**
     * بارامترات بتتضاف لأي رابط موقّع (temporarySignedRoute) بيتفتح من <img>/<video> من غير هيدر X-Tenant-Domain:
     * ResolveTenant بيحدد العميل منها (والتوقيع بيحمي الرقم من التلاعب).
     * @return array<string,int>
     */
    public static function signedParams(): array
    {
        return self::$tenant ? ['tn' => (int) self::$tenant->id] : [];
    }

    /** صف العميل (isolated + active + جاهز) من قاعدة المنصة الأم بالـ id، أو null. للـ queue والـ scheduler والروابط الموقّعة. */
    public static function findIsolated(int $id): ?object
    {
        try {
            $r = \Illuminate\Support\Facades\DB::connection(self::centralConnection())->table('tenants')
                ->where('id', $id)->where('mode', 'isolated')
                ->first(['id', 'public_id', 'name', 'mode', 'db_name', 'db_status', 'status as tenant_status']);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$r || $r->tenant_status !== 'active' || $r->db_status !== 'ready' || empty($r->db_name)) {
            return null;
        }
        return $r;
    }

    /** @return array<string,mixed> إعدادات connection العميل (من قالب connection المنصة، مع اسم قاعدة العميل). */
    public static function connectionConfig(string $dbName): array
    {
        $tpl = (string) (config('tenancy.isolation.template') ?: self::centralConnection());
        $base = config('database.connections.' . $tpl);
        if (!is_array($base)) {
            throw new \RuntimeException("Tenancy: database connection template '{$tpl}' is not defined.");
        }
        $base['database'] = $dbName;
        unset($base['url']); // الـ DB_URL كان هيغلب اسم القاعدة
        if ($u = config('tenancy.isolation.username')) {
            $base['username'] = $u;
            $base['password'] = (string) config('tenancy.isolation.password', '');
        }
        return $base;
    }

    public static function activate(object $tenant): void
    {
        if (empty($tenant->db_name)) {
            throw new \RuntimeException('Tenant has no database.');
        }
        if (self::$central === null) {
            self::$central = DB::getDefaultConnection();
        }
        config(['database.connections.' . self::CONNECTION => self::connectionConfig((string) $tenant->db_name)]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        self::$tenant = $tenant;
        self::isolateCache((int) $tenant->id);
        TenantStorage::apply((int) $tenant->id);
        self::flushStaticMemos();
    }

    public static function deactivate(): void
    {
        if (self::$tenant === null) {
            return;
        }
        DB::setDefaultConnection(self::centralConnection());
        DB::purge(self::CONNECTION);
        self::$tenant = null;
        self::isolateCache(null);
        TenantStorage::apply(null);
        self::flushStaticMemos();
    }

    /** ذاكرة مؤقتة جوه الـ process (إعدادات المنصة...) لازم تتفضى مع كل تبديل، عشان إعدادات عميل متتسربش لعميل تاني. */
    private static function flushStaticMemos(): void
    {
        try {
            \App\Support\PlatformPolicy::flush();
        } catch (\Throwable $e) {
            // مش حرج
        }
    }

    /** يشغّل $fn جوه العميل ويرجّع الحالة السابقة (المنصة أو عميل تاني) بعدها. */
    public static function run(object $tenant, Closure $fn): mixed
    {
        $prev = self::$tenant;
        self::activate($tenant);
        try {
            return $fn();
        } finally {
            if ($prev) {
                self::activate($prev);
            } else {
                self::deactivate();
            }
        }
    }

    /** يشغّل $fn على المنصة الأم حتى لو جوه عميل (مثلاً قراءة جدول tenants). */
    public static function runCentral(Closure $fn): mixed
    {
        $prev = self::$tenant;
        if (!$prev) {
            return $fn();
        }
        self::deactivate();
        try {
            return $fn();
        } finally {
            self::activate($prev);
        }
    }

    private static function isolateCache(?int $id): void
    {
        $stores = (array) config('cache.stores', []);
        if (self::$cacheOrig === null) {
            $paths = [];
            $prefixes = [];
            foreach ($stores as $name => $cfg) {
                if (($cfg['driver'] ?? null) === 'file') {
                    $paths[$name] = $cfg['path'] ?? null;
                }
                if (array_key_exists('prefix', $cfg)) {
                    $prefixes[$name] = $cfg['prefix'];
                }
            }
            self::$cacheOrig = ['prefix' => (string) config('cache.prefix', ''), 'paths' => $paths, 'prefixes' => $prefixes];
        }
        $o = self::$cacheOrig;
        $suffix = $id === null ? '' : 'tenant' . $id . '_';

        config(['cache.prefix' => $o['prefix'] . $suffix]);
        foreach ($o['prefixes'] as $name => $p) {
            config(["cache.stores.{$name}.prefix" => $p === null ? null : (string) $p . $suffix]);
        }
        foreach ($o['paths'] as $name => $path) {
            if ($path !== null) {
                config(["cache.stores.{$name}.path" => $id === null ? $path : rtrim((string) $path, '/\\') . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $id]);
            }
        }

        // الـ stores والـ singletons اللي اتبنت بالـ prefix/المسار القديم لازم تتبني من جديد.
        Cache::forgetDriver(array_keys($stores));
        $app = app();
        foreach (['cache.store', \Illuminate\Cache\RateLimiter::class] as $abstract) {
            $app->forgetInstance($abstract);
        }
    }
}
