<?php

namespace App\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Network Control — مساعدات مشتركة (بند 4):
 *  - مطابقة IP / CIDR (IPv4 بـ bitmask، IPv6 بـ bitmask على الـ 16 بايت).
 *  - قائمة الـ IPs الموثوقة (isTrusted) بكاش قصير — بتتنادى من الـ middlewares ومن BlockedIpRepository.
 *  - قواعد حد المعدل (rateRules) بكاش قصير.
 *  - parseEntries(): تحليل نص/CSV للاستيراد الجماعي.
 */
class NetworkControlService
{
    private const CACHE_TTL = 30;
    public const MAX_IMPORT = 5000;

    private static function ns(): string
    {
        return TenantContext::ns();
    }

    public static function forgetCaches(): void
    {
        try {
            Cache::forget('netctl:trusted:' . self::ns());
            Cache::forget('netctl:rules:' . self::ns());
            Cache::forget('netctl:cidrblocks:' . self::ns());
        } catch (\Throwable) {
        }
    }

    // ---- IP / CIDR -------------------------------------------------------------------------------

    /** true لو الـ entry صالح كـ IP أو CIDR. */
    public static function validEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        if (!str_contains($entry, '/')) {
            return (bool) filter_var($entry, FILTER_VALIDATE_IP);
        }
        [$addr, $bits] = explode('/', $entry, 2);
        if (!filter_var($addr, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
            return false;
        }
        $max = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
        return (int) $bits >= 0 && (int) $bits <= $max;
    }

    /** يوحّد الشكل (IPv6 صغيرة، CIDR بدون مسافات). */
    public static function normalizeEntry(string $entry): string
    {
        $entry = trim($entry);
        if (str_contains($entry, '/')) {
            [$a, $b] = explode('/', $entry, 2);
            return self::normalizeAddr($a) . '/' . (int) $b;
        }
        return self::normalizeAddr($entry);
    }

    private static function normalizeAddr(string $a): string
    {
        $bin = @inet_pton($a);
        return $bin === false ? strtolower($a) : (string) inet_ntop($bin);
    }

    public static function isCidr(string $entry): bool
    {
        return str_contains($entry, '/');
    }

    /** هل الـ $ip جوه الـ $entry (IP مفرد أو CIDR)؟ */
    public static function matches(string $ip, string $entry): bool
    {
        $entry = trim($entry);
        if ($ip === '' || $entry === '') {
            return false;
        }
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (!str_contains($entry, '/')) {
            $eBin = @inet_pton($entry);
            return $eBin !== false && $eBin === $ipBin;
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $sBin = @inet_pton($subnet);
        if ($sBin === false || strlen($sBin) !== strlen($ipBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits > strlen($sBin) * 8) {
            return false;
        }
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($ipBin, 0, $full) !== substr($sBin, 0, $full)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$full]) & $mask) === (ord($sBin[$full]) & $mask);
    }

    // ---- trusted ---------------------------------------------------------------------------------

    private function hasTable(string $t): bool
    {
        static $memo = [];
        if (!array_key_exists($t, $memo)) {
            try {
                $memo[$t] = Schema::hasTable($t);
            } catch (\Throwable) {
                $memo[$t] = false;
            }
        }
        return $memo[$t];
    }

    /** @return string[] الـ entries الموثوقة النشطة. */
    public function trustedEntries(): array
    {
        if (!$this->hasTable('network_trusted_ips')) {
            return [];
        }
        try {
            return Cache::remember('netctl:trusted:' . self::ns(), self::CACHE_TTL, function () {
                return DB::table('network_trusted_ips')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->pluck('ip_address')->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /** مفيش استثناء بيطلع من هنا أبدًا — الـ middleware ما يوقعش الطلب. */
    public function isTrusted(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($this->trustedEntries() as $e) {
            if (self::matches($ip, (string) $e)) {
                return true;
            }
        }
        return false;
    }

    // ---- rate rules ------------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> القواعد المفعّلة. */
    public function activeRateRules(): array
    {
        if (!$this->hasTable('network_rate_rules')) {
            return [];
        }
        try {
            return Cache::remember('netctl:rules:' . self::ns(), self::CACHE_TTL, function () {
                return DB::table('network_rate_rules')->where('enabled', 1)->orderBy('id')->get()
                    ->map(fn ($r) => (array) $r)->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    // ---- import parsing --------------------------------------------------------------------------

    /**
     * سطر لكل entry: "1.2.3.4" أو "10.0.0.0/8" أو "1.2.3.4,سبب" أو "1.2.3.4;سبب" (CSV بسيط).
     * أي سطر بيبدأ بـ # أو رأس عمود (ip / address) بيتخطّى.
     *
     * @return array{valid: array<int,array{ip:string,reason:?string}>, invalid: string[], duplicates:int}
     */
    public static function parseEntries(string $raw): array
    {
        $valid = [];
        $invalid = [];
        $dups = 0;
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // لو الصف فيه فواصل/مسافات بس من غير سبب (قائمة في سطر واحد: "1.1.1.1, 2.2.2.2") نفصلها.
            $parts = preg_split('/[,;\t]+/', $line) ?: [];
            $parts = array_map('trim', $parts);
            $allEntries = count($parts) > 1 && count(array_filter($parts, fn ($p) => self::validEntry($p))) === count($parts);
            $cells = $allEntries ? array_map(fn ($p) => [$p, null], $parts) : [[$parts[0] ?? '', isset($parts[1]) && $parts[1] !== '' ? implode(', ', array_slice($parts, 1)) : null]];
            foreach ($cells as [$entry, $reason]) {
                if ($i === 0 && in_array(strtolower($entry), ['ip', 'address', 'ip_address', 'ip address'], true)) {
                    continue;
                }
                if (!self::validEntry($entry)) {
                    $invalid[] = mb_substr($entry, 0, 80);
                    continue;
                }
                $norm = self::normalizeEntry($entry);
                if (isset($seen[$norm])) {
                    $dups++;
                    continue;
                }
                $seen[$norm] = true;
                $valid[] = ['ip' => $norm, 'reason' => $reason !== null ? mb_substr($reason, 0, 200) : null];
            }
        }
        return ['valid' => $valid, 'invalid' => array_slice($invalid, 0, 50), 'duplicates' => $dups];
    }
}
