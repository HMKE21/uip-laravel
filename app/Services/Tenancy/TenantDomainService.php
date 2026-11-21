<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * نطاقات الـ tenant المخصصة (meet.company.com).
 *  1) register: بنطلع token. الجهة تعمل TXT:  _uipe-challenge.<domain>  =  uipe-verify=<token>   (إثبات ملكية)
 *  2) verify:   بنقرا الـ TXT. لو سليم → verified. ولو TENANT_CNAME_TARGET متحدد وCNAME بيشاور عليه (أو مفيش target) → active.
 *  3) active بس هو اللي بيتخدم (resolve) وهو اللي الـ SSL (Caddy on-demand TLS) بيسأل عنه.
 * الـ DNS قابل للاستبدال في الاختبارات: TenantDomainService::$dns = fn (string $host, string $type): array.
 */
final class TenantDomainService
{
    /** @var (\Closure(string,string):array<int,string>)|null */
    public static ?\Closure $dns = null;

    private static function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /** يرجّع الدومين بصيغة ASCII صغيرة، أو يرمي DomainException لو مش صالح كاسم موقع عام. */
    public static function normalize(string $in): string
    {
        $d = strtolower(trim($in));
        $d = rtrim($d, '.');
        if ($d === '' || preg_match('~[\s/:@*_?#\\\\]~', $d)) {
            self::fail('Enter a plain domain name like meet.example.com (no https://, path, port or wildcard).');
        }
        if (preg_match('/[^\x20-\x7e]/', $d)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if (!$ascii) {
                self::fail('This domain name is not valid.');
            }
            $d = strtolower($ascii);
        }
        if (strlen($d) > 253) {
            self::fail('This domain name is too long.');
        }
        $labels = explode('.', $d);
        if (count($labels) < 2) {
            self::fail('Use a full domain name like meet.example.com.');
        }
        foreach ($labels as $l) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $l)) {
                self::fail('This domain name is not valid.');
            }
        }
        $tld = end($labels);
        if (!preg_match('/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld)) {
            self::fail('This domain name is not valid (IP addresses are not allowed).');
        }
        foreach (self::reserved() as $r) {
            if ($d === $r || str_ends_with($d, '.' . $r)) {
                self::fail('This domain belongs to the platform and cannot be used.', 403);
            }
        }
        return $d;
    }

    /** @return string[] */
    public static function reserved(): array
    {
        $out = array_map('strtolower', (array) config('tenancy.domains.reserved', []));
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $out[] = strtolower($host);
        }
        return array_values(array_unique(array_filter($out)));
    }

    private static function lookup(string $host, string $type): array
    {
        if (self::$dns) {
            return (array) (self::$dns)($host, $type);
        }
        try {
            $rows = @dns_get_record($host, ['CNAME' => DNS_CNAME, 'A' => DNS_A, 'AAAA' => DNS_AAAA][$type] ?? DNS_TXT);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $v = ['CNAME' => 'target', 'A' => 'ip', 'AAAA' => 'ipv6'][$type] ?? 'txt';
            $v = $r[$v] ?? null;
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** @return string[] عناوين IP بتاعة سيرفر الـ edge (TENANT_EDGE_IPS) — لدومينات الـ A record (زي DuckDNS). */
    public static function edgeIps(): array
    {
        return array_values(array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), (array) config('tenancy.domains.edge_ips', []))));
    }

    public static function txtName(string $domain): string
    {
        return rtrim((string) config('tenancy.domains.txt_prefix', '_uipe-challenge'), '.') . '.' . $domain;
    }

    public static function payload(object $d): array
    {
        $target = trim((string) config('tenancy.domains.cname_target', ''));
        $ips = self::edgeIps();
        return [
            'id' => (int) $d->id, 'domain' => $d->domain, 'status' => $d->status, 'is_primary' => (bool) $d->is_primary,
            'verified_at' => $d->verified_at, 'activated_at' => $d->activated_at, 'last_checked_at' => $d->last_checked_at, 'last_error' => $d->last_error,
            'dns' => [
                'txt_name' => self::txtName($d->domain), 'txt_value' => 'uipe-verify=' . $d->verification_token,
                // بديل لمزوّدي DNS اللي مبيسمحوش بـ _underscore (زي DuckDNS): نفس القيمة كـ TXT على الدومين نفسه.
                'txt_alt_name' => $d->domain,
                'cname_name' => $d->domain, 'cname_target' => $target !== '' ? $target : null, 'a_targets' => $ips,
            ],
        ];
    }

    public static function list(int $tenantId): array
    {
        return DB::table('tenant_domains')->where('tenant_id', $tenantId)->orderByDesc('is_primary')->orderBy('id')->get()->map(fn ($d) => self::payload($d))->all();
    }

    public static function register(int $tenantId, string $domain, int $max): array
    {
        $d = self::normalize($domain);
        if (DB::table('tenant_domains')->where('tenant_id', $tenantId)->count() >= $max) {
            self::fail('You reached the maximum number of domains for this workspace.', 403);
        }
        $ex = DB::table('tenant_domains')->where('domain', $d)->first();
        if ($ex) {
            if ((int) $ex->tenant_id === $tenantId) {
                self::fail('This domain is already added.', 409);
            }
            $ttl = max(1, (int) config('tenancy.domains.pending_ttl_hours', 48));
            $stale = $ex->status === 'pending' && $ex->created_at && now()->diffInHours(\Illuminate\Support\Carbon::parse($ex->created_at), true) >= $ttl;
            if (!$stale) {
                self::fail('This domain is already used by another workspace.', 409);
            }
            DB::table('tenant_domains')->where('id', $ex->id)->delete();
        }
        $id = DB::table('tenant_domains')->insertGetId([
            'tenant_id' => $tenantId, 'domain' => $d, 'is_primary' => 0, 'status' => 'pending',
            'verification_token' => Str::lower(Str::random(32)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::payload(DB::table('tenant_domains')->find($id));
    }

    private static function own(int $tenantId, int $id): object
    {
        $d = DB::table('tenant_domains')->where('id', $id)->where('tenant_id', $tenantId)->first();
        if (!$d) {
            self::fail('Domain not found.', 404);
        }
        return $d;
    }

    /** يفحص الـ DNS ويحدّث الحالة. الحالة بتتحسب من جديد كل مرة (domain اتحذف الـ TXT بتاعه بيرجع pending). */
    public static function verify(int $tenantId, int $id): array
    {
        $d = self::own($tenantId, $id);
        if ($d->last_checked_at && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($d->last_checked_at), true) < 10) {
            self::fail('Please wait a few seconds before checking again.', 429);
        }
        $want = 'uipe-verify=' . $d->verification_token;
        $found = false;
        foreach ([self::txtName($d->domain), $d->domain] as $name) {   // _uipe-challenge.<domain> أو TXT على الدومين نفسه (DuckDNS)
            foreach (self::lookup($name, 'TXT') as $v) {
                if (hash_equals($want, trim((string) $v))) {
                    $found = true;
                    break 2;
                }
            }
        }
        $upd = ['last_checked_at' => now(), 'updated_at' => now()];
        if (!$found) {
            $upd += ['status' => 'pending', 'last_error' => 'The TXT record was not found yet. DNS changes can take a while.', 'activated_at' => null, 'verified_at' => null];
        } else {
            $upd += ['verified_at' => $d->verified_at ?: now()];
            $target = strtolower(rtrim(trim((string) config('tenancy.domains.cname_target', '')), '.'));
            $ips = self::edgeIps();
            $routed = $target === '' && !$ips;     // مفيش شرط توجيه متحدد: الـ TXT كفاية
            if (!$routed && $target !== '') {
                foreach (self::lookup($d->domain, 'CNAME') as $c) {
                    if (strtolower(rtrim(trim((string) $c), '.')) === $target) {
                        $routed = true;
                        break;
                    }
                }
            }
            if (!$routed && $ips) {                // A / AAAA بيشاور على سيرفرنا (DuckDNS وأي مزوّد من غير CNAME)
                foreach (array_merge(self::lookup($d->domain, 'A'), self::lookup($d->domain, 'AAAA')) as $ip) {
                    if (in_array(strtolower(trim((string) $ip)), $ips, true)) {
                        $routed = true;
                        break;
                    }
                }
            }
            if ($routed) {
                $upd += ['status' => 'active', 'last_error' => null, 'activated_at' => $d->activated_at ?: now()];
            } else {
                $hint = $target !== '' ? "a CNAME record to {$target}" : '';
                $hint .= $target !== '' && $ips ? ' or ' : '';
                $hint .= $ips ? ('an A record to ' . implode(' / ', $ips)) : '';
                $upd += ['status' => 'verified', 'last_error' => 'Ownership verified. Point this domain with ' . $hint . '.', 'activated_at' => null];
            }
        }
        DB::table('tenant_domains')->where('id', $d->id)->update($upd);
        if (($upd['status'] ?? '') === 'active' && !DB::table('tenant_domains')->where('tenant_id', $tenantId)->where('is_primary', 1)->where('status', 'active')->exists()) {
            DB::table('tenant_domains')->where('id', $d->id)->update(['is_primary' => 1]);
        }
        self::forget($d->domain);
        return self::payload(DB::table('tenant_domains')->find($d->id));
    }

    public static function setPrimary(int $tenantId, int $id): array
    {
        $d = self::own($tenantId, $id);
        if ($d->status !== 'active') {
            self::fail('Only an active domain can be the primary domain.');
        }
        DB::transaction(function () use ($tenantId, $d) {
            DB::table('tenant_domains')->where('tenant_id', $tenantId)->update(['is_primary' => 0]);
            DB::table('tenant_domains')->where('id', $d->id)->update(['is_primary' => 1, 'updated_at' => now()]);
        });
        return self::payload(DB::table('tenant_domains')->find($d->id));
    }

    public static function remove(int $tenantId, int $id): void
    {
        $d = self::own($tenantId, $id);
        DB::table('tenant_domains')->where('id', $d->id)->delete();
        if ($d->is_primary) {
            $next = DB::table('tenant_domains')->where('tenant_id', $tenantId)->where('status', 'active')->orderBy('id')->value('id');
            if ($next) {
                DB::table('tenant_domains')->where('id', $next)->update(['is_primary' => 1]);
            }
        }
        self::forget($d->domain);
    }

    public static function forget(string $domain): void
    {
        Cache::forget('tenant:host:' . strtolower($domain));
    }

    /** الـ tenant (object) اللي بيخدم الـ host ده، أو null. بيشترط الدومين active والـ tenant active. متكاشّي 60 ثانية. */
    public static function resolve(string $host): ?object
    {
        $h = strtolower(rtrim(trim($host), '.'));
        if ($h === '' || strlen($h) > 253) {
            return null;
        }
        $tid = Cache::remember('tenant:host:' . $h, 60, function () use ($h) {
            $d = DB::table('tenant_domains as d')->join('tenants as t', 't.id', '=', 'd.tenant_id')
                ->where('d.domain', $h)->where('d.status', 'active')->where('t.status', 'active')->value('t.id');
            return $d ? (int) $d : 0;
        });
        return $tid ? (DB::table('tenants')->where('id', $tid)->first() ?: null) : null;
    }
}
