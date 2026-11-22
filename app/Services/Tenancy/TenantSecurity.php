<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * تطبيق سياسات الدخول والأمان بتاعة المنصة (اللي المالك ضابطها في TenantAuthSettings['security']):
 * دومين الإيميل، IP allowlist، مدة الجلسة، سياسة الباسورد، وتفعيل Magic link / Passkeys / SSO.
 * كل الدوال بتشتغل على "المنصة الحالية" (TenantContext) إلا لو اتدّى tenantId صريح (المساحة المشتركة).
 */
final class TenantSecurity
{
    /** @return array<string,mixed> */
    public static function forTenant(int $tenantId): array
    {
        return TenantAuthSettings::forTenant($tenantId)['security'] ?? TenantAuthSettings::securityDefaults();
    }

    /** سياسات المنصة اللي الطلب شغّال جواها (معزولة)، أو null. */
    public static function current(): ?array
    {
        return (TenantContext::active() && TenantContext::id()) ? self::forTenant((int) TenantContext::id()) : null;
    }

    /* ------------------------------------------------------------------ IP */

    private static function unmap(string $bin): string
    {
        // ::ffff:a.b.c.d (IPv4 جوه IPv6) => 4 بايت
        return (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") ? substr($bin, 12) : $bin;
    }

    private static function bitsMatch(string $a, string $b, int $bits): bool
    {
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }

    /** true لو الـ IP جوه أي قاعدة (IP أو CIDR). قايمة فاضية = مسموح للكل. */
    public static function ipInList(?string $ip, array $rules): bool
    {
        if ($rules === []) {
            return true;
        }
        $bin = $ip !== null && $ip !== '' ? @inet_pton($ip) : false;
        if ($bin === false) {
            return false;
        }
        $bin = self::unmap($bin);
        foreach ($rules as $r) {
            [$net, $bits] = array_pad(explode('/', trim((string) $r), 2), 2, null);
            $nb = @inet_pton((string) $net);
            if ($nb === false) {
                continue;
            }
            $nb = self::unmap($nb);
            if (strlen($nb) !== strlen($bin)) {
                continue;
            }
            $max = strlen($nb) * 8;
            $bits = $bits === null ? $max : (int) $bits;
            if ($bits < 0 || $bits > $max) {
                continue;
            }
            if (self::bitsMatch($bin, $nb, $bits)) {
                return true;
            }
        }
        return false;
    }

    /** قواعد الـ IP للمنصة الحالية، متخزنة 30 ثانية (الـ middleware بيسأل كل request). */
    private static function ipRules(int $tenantId, bool $fresh): array
    {
        if ($fresh) {
            return (array) (self::forTenant($tenantId)['ip_allowlist'] ?? []);
        }
        try {
            return (array) Cache::remember('tsec:ip:' . $tenantId, 30, static fn () => (array) (self::forTenant($tenantId)['ip_allowlist'] ?? []));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** الـ IP ده ممنوع من المنصة الحالية؟ ($tenantId لمساحة مشتركة). $fresh=true في الدخول (من غير كاش). */
    public static function ipBlocked(?string $ip, ?int $tenantId = null, bool $fresh = false): bool
    {
        $tid = $tenantId ?? ((TenantContext::active() && TenantContext::id()) ? (int) TenantContext::id() : null);
        if (!$tid) {
            return false;
        }
        $rules = self::ipRules($tid, $fresh);
        return $rules !== [] && !self::ipInList($ip, $rules);
    }

    /* ------------------------------------------------------------------ دومين الإيميل */

    /** الإيميل على دومين من المسموحين؟ مطابقة تامة (sub.company.com مش company.com). قايمة فاضية = مسموح. */
    public static function emailAllowed(string $email, array $domains): bool
    {
        if ($domains === []) {
            return true;
        }
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $d = substr($email, $at + 1);
        foreach ($domains as $x) {
            if ($d === strtolower(ltrim(trim((string) $x), '@'))) {
                return true;
            }
        }
        return false;
    }

    public static function emailBlockedHere(string $email): bool
    {
        $s = self::current();
        return $s !== null && !self::emailAllowed($email, (array) ($s['allowed_domains'] ?? []));
    }

    /* ------------------------------------------------------------------ الجلسة والباسورد */

    /** مدة الجلسة بالساعات للمنصة الحالية (0 = الافتراضي). */
    public static function sessionHours(): int
    {
        $s = self::current();
        return $s ? max(0, (int) ($s['session_hours'] ?? 0)) : 0;
    }

    /** قيم تتركّب فوق سياسة الباسورد لما المالك يفعّل "سياسة مخصصة"، أو [] . */
    public static function passwordOverlay(): array
    {
        $s = self::current();
        $p = $s['password'] ?? null;
        if (!is_array($p) || empty($p['custom'])) {
            return [];
        }
        return [
            'min_length' => (int) $p['min_length'],
            'require_upper' => (bool) $p['require_upper'],
            'require_lower' => (bool) $p['require_lower'],
            'require_number' => (bool) $p['require_number'],
            'require_special' => (bool) $p['require_special'],
            'expire_days' => (int) $p['expire_days'],
        ];
    }

    /* ------------------------------------------------------------------ دومينات المنصة */

    /** @return string[] دومينات المنصة الشغّالة (status=active). */
    public static function activeDomains(int $tenantId): array
    {
        try {
            return DB::connection(TenantContext::centralConnection())->table('tenant_domains')->where('tenant_id', $tenantId)->where('status', 'active')->pluck('domain')->map(fn ($d) => strtolower((string) $d))->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
