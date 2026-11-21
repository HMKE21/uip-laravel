<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** تحديد الـ tenant من اسم الدومين (من جدول tenant_domains الموجود). بيشتغل على قاعدة المنصة الأم قبل أي تبديل. */
class TenantResolver
{
    /** hostname سليم (من غير بورت/slash/مسافات) أو null. */
    public static function normalizeHost(?string $raw): ?string
    {
        $h = strtolower(trim((string) $raw));
        $h = rtrim($h, '.');
        if ($h === '' || strlen($h) > 253) {
            return null;
        }
        if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $h) !== 1) {
            return null;
        }
        return $h;
    }

    /** دومين المنصة نفسها (مفيش tenant): APP_URL، المحجوز، TENANT_PLATFORM_HOSTS، localhost، وأي IP. */
    public static function isPlatformHost(string $host): bool
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^\d+(\.\d+){3}$/', $host)) {
            return true;
        }
        $list = array_map('strtolower', (array) config('tenancy.isolation.platform_hosts', []));
        $app = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($app) && $app !== '') {
            $list[] = strtolower($app);
        }
        return in_array($host, $list, true);
    }

    /** @return object|null {id, public_id, name, mode, db_name, db_status, tenant_status, domain_status} */
    public static function lookup(string $host): ?object
    {
        $key = 'tenancy:host:' . $host;
        try {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return (object) $hit;
            }
            $r = DB::table('tenant_domains as d')->join('tenants as t', 't.id', '=', 'd.tenant_id')
                ->where('d.domain', $host)
                ->first(['t.id', 't.public_id', 't.name', 't.mode', 't.db_name', 't.db_status', 't.status as tenant_status', 'd.status as domain_status']);
            if (!$r) {
                return null; // النتيجة السالبة مش بتتكاشّى (الهيدر بيجي من العميل: مينفعش نملا الـ cache بدومينات عشوائية)
            }
            Cache::put($key, (array) $r, 60);
            return $r;
        } catch (\Throwable $e) {
            // الجداول/الأعمدة لسه مش موجودة (قبل تشغيل الـ migration): المنصة تفضل شغالة زي ما كانت.
            return null;
        }
    }

    /**
     * العميل من الـ slug بتاع الرابط الجاهز (/w/<slug>). بيرجّع نفس شكل lookup() (domain_status = active لأنه مفيش دومين).
     * بيشتغل على قاعدة المنصة الأم قبل أي تبديل. النتيجة السالبة مش بتتكاشّى.
     */
    public static function lookupSlug(string $slug): ?object
    {
        $slug = strtolower($slug);
        $key = 'tenancy:slug:' . $slug;
        try {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return (object) $hit;
            }
            $r = DB::table('tenants as t')->where('t.slug', $slug)
                ->first(['t.id', 't.public_id', 't.name', 't.mode', 't.db_name', 't.db_status', 't.status as tenant_status']);
            if (!$r) {
                return null;
            }
            $r->domain_status = 'active';
            Cache::put($key, (array) $r, 60);
            return $r;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function forget(string $host): void
    {
        Cache::forget('tenancy:host:' . strtolower($host));
    }
}
