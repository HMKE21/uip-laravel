<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * صفحة دخول المنصة المعزولة: القالب، النصوص، ومزوّدو الدخول (Google / Microsoft) بالـ client id الخاص بالمنصة.
 * محفوظة على قاعدة المنصة الأم (tenant_auth_settings). جوّه منصة معزولة، المزوّدين بيتحددوا من هنا بس — مش من إعدادات UIPE.
 */
final class TenantAuthSettings
{
    public const TEMPLATES = ['split', 'centered', 'minimal', 'aurora', 'showcase'];
    private const GOOGLE_RE = '/^[0-9A-Za-z._-]{6,120}\.apps\.googleusercontent\.com$/';
    private const GUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function defaults(): array
    {
        return [
            'template' => 'split',
            'headline' => ['en' => '', 'ar' => ''],
            'subtitle' => ['en' => '', 'ar' => ''],
            'google' => ['enabled' => false, 'client_id' => ''],
            'microsoft' => ['enabled' => false, 'client_id' => '', 'directory' => 'common'],
            'security' => self::securityDefaults(),
        ];
    }

    /** سياسات الدخول والأمان لكل منصة (بتتخزن مع إعدادات صفحة الدخول). 0 / فاضي = مفيش قيد (يفضل سلوك UIPE الافتراضي). */
    public static function securityDefaults(): array
    {
        return [
            'allowed_domains' => [],
            'session_hours' => 0,
            'password' => ['custom' => false, 'min_length' => 8, 'require_upper' => true, 'require_lower' => true, 'require_number' => true, 'require_special' => false, 'expire_days' => 0],
            'ip_allowlist' => [],
            'magic_link' => false,
            'passkeys' => false,
            'oidc' => ['enabled' => false, 'issuer' => '', 'client_id' => '', 'label' => ''],
            'mail' => ['from_enabled' => false, 'from_address' => ''],
        ];
    }

    public const DOMAIN_RE = '/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/';

    /** @return string[] */
    private static function domainList($v): array
    {
        $out = [];
        foreach ((array) $v as $d) {
            $d = strtolower(trim((string) $d));
            $d = ltrim($d, '@');
            if ($d !== '' && preg_match(self::DOMAIN_RE, $d) === 1) {
                $out[$d] = $d;
            }
        }
        return array_slice(array_values($out), 0, 20);
    }

    /** IP أو CIDR سليم (IPv4/IPv6) بصيغته الموحّدة، أو null. */
    public static function ipRule(string $r): ?string
    {
        $r = trim($r);
        if ($r === '') {
            return null;
        }
        [$ip, $bits] = array_pad(explode('/', $r, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        if ($bits === null) {
            return strtolower($ip);
        }
        if (!ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }
        return strtolower($ip) . '/' . (int) $bits;
    }

    /** @return array<string,mixed> */
    public static function normalizeSecurity($in): array
    {
        $d = self::securityDefaults();
        $in = is_array($in) ? $in : [];
        $bool = static fn ($v, bool $def): bool => $v === null ? $def : filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $int = static fn ($v, int $min, int $max, int $def): int => is_numeric($v) ? max($min, min($max, (int) $v)) : $def;

        $p = is_array($in['password'] ?? null) ? $in['password'] : [];
        $pd = $d['password'];
        $o = is_array($in['oidc'] ?? null) ? $in['oidc'] : [];
        $issuer = rtrim(trim((string) ($o['issuer'] ?? '')), '/');
        $cid = trim((string) ($o['client_id'] ?? ''));
        $issuerOk = $issuer !== '' && strlen($issuer) <= 300 && TenantOidc::issuerSafe($issuer, false);
        $cidOk = $cid !== '' && preg_match('/^[\x21-\x7e]{3,200}$/', $cid) === 1;
        $m = is_array($in['mail'] ?? null) ? $in['mail'] : [];
        $from = strtolower(trim((string) ($m['from_address'] ?? '')));
        $fromOk = $from !== '' && strlen($from) <= 190 && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;

        $ips = [];
        foreach ((array) ($in['ip_allowlist'] ?? []) as $r) {
            if (($n = self::ipRule((string) $r)) !== null) {
                $ips[$n] = $n;
            }
        }

        return [
            'allowed_domains' => self::domainList($in['allowed_domains'] ?? []),
            'session_hours' => $int($in['session_hours'] ?? 0, 0, 8760, 0),
            'password' => [
                'custom' => $bool($p['custom'] ?? null, $pd['custom']),
                'min_length' => $int($p['min_length'] ?? null, 6, 64, $pd['min_length']),
                'require_upper' => $bool($p['require_upper'] ?? null, $pd['require_upper']),
                'require_lower' => $bool($p['require_lower'] ?? null, $pd['require_lower']),
                'require_number' => $bool($p['require_number'] ?? null, $pd['require_number']),
                'require_special' => $bool($p['require_special'] ?? null, $pd['require_special']),
                'expire_days' => $int($p['expire_days'] ?? 0, 0, 3650, 0),
            ],
            'ip_allowlist' => array_slice(array_values($ips), 0, 50),
            'magic_link' => $bool($in['magic_link'] ?? null, false),
            'passkeys' => $bool($in['passkeys'] ?? null, false),
            'oidc' => [
                'enabled' => $bool($o['enabled'] ?? null, false) && $issuerOk && $cidOk,
                'issuer' => $issuerOk ? $issuer : '',
                'client_id' => $cidOk ? $cid : '',
                'label' => mb_substr(trim(strip_tags((string) ($o['label'] ?? ''))), 0, 40),
            ],
            'mail' => [
                'from_enabled' => $bool($m['from_enabled'] ?? null, false) && $fromOk,
                'from_address' => $fromOk ? $from : '',
            ],
        ];
    }

    /** @throws \InvalidArgumentException لو قيمة اتكتبت غلط (بدل ما نتجاهلها من غير ما نقول للمالك). */
    public static function validateSecurity($in): void
    {
        $in = is_array($in) ? $in : [];
        foreach ((array) ($in['allowed_domains'] ?? []) as $dm) {
            $dm = ltrim(strtolower(trim((string) $dm)), '@');
            if ($dm !== '' && preg_match(self::DOMAIN_RE, $dm) !== 1) {
                throw new \InvalidArgumentException("\"{$dm}\" is not a valid email domain. Use something like company.com");
            }
        }
        if (count((array) ($in['allowed_domains'] ?? [])) > 20) {
            throw new \InvalidArgumentException('You can allow up to 20 email domains.');
        }
        foreach ((array) ($in['ip_allowlist'] ?? []) as $r) {
            if (trim((string) $r) !== '' && self::ipRule((string) $r) === null) {
                throw new \InvalidArgumentException("\"{$r}\" is not a valid IP address or range (example: 203.0.113.7 or 10.0.0.0/24).");
            }
        }
        if (count((array) ($in['ip_allowlist'] ?? [])) > 50) {
            throw new \InvalidArgumentException('You can list up to 50 IP addresses or ranges.');
        }
        $h = $in['session_hours'] ?? 0;
        if ($h !== null && $h !== '' && (!is_numeric($h) || (int) $h < 0 || (int) $h > 8760)) {
            throw new \InvalidArgumentException('Session length must be between 1 and 8760 hours (or 0 for the default).');
        }
        $p = is_array($in['password'] ?? null) ? $in['password'] : [];
        if (isset($p['min_length']) && (!is_numeric($p['min_length']) || (int) $p['min_length'] < 6 || (int) $p['min_length'] > 64)) {
            throw new \InvalidArgumentException('Minimum password length must be between 6 and 64.');
        }
        if (isset($p['expire_days']) && (!is_numeric($p['expire_days']) || (int) $p['expire_days'] < 0 || (int) $p['expire_days'] > 3650)) {
            throw new \InvalidArgumentException('Password expiration must be between 0 (never) and 3650 days.');
        }
        $o = is_array($in['oidc'] ?? null) ? $in['oidc'] : [];
        $issuer = trim((string) ($o['issuer'] ?? ''));
        if ($issuer !== '' && !TenantOidc::issuerSafe(rtrim($issuer, '/'), false)) {
            throw new \InvalidArgumentException('The SSO issuer must be a public https:// address (for example https://login.example.com/realms/acme).');
        }
        if (!empty($o['enabled']) && ($issuer === '' || trim((string) ($o['client_id'] ?? '')) === '')) {
            throw new \InvalidArgumentException('Enter the SSO issuer URL and client ID before turning SSO on.');
        }
        $m = is_array($in['mail'] ?? null) ? $in['mail'] : [];
        $from = trim((string) ($m['from_address'] ?? ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The sender email address is not valid.');
        }
        if (!empty($m['from_enabled']) && $from === '') {
            throw new \InvalidArgumentException('Enter the sender email address first.');
        }
    }

    /** @var array<int,array> ذاكرة لنفس الـ request بس (الـ Cache مبينفعش: مفاتيحه مفصولة لكل منصة، فمستحيل نمسحه من لوحة المالك). */
    private static array $memo = [];

    public static function flush(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[$tenantId]);
        }
    }

    public static function forTenant(int $tenantId): array
    {
        if (isset(self::$memo[$tenantId])) {
            return self::$memo[$tenantId];
        }
        $row = null;
        try {
            // قراءة مباشرة من connection المنصة الأم (من غير تبديل سياق العميل: ده بيتنادى في كل request محمي جوه منصة معزولة).
            $row = DB::connection(TenantContext::centralConnection())->table('tenant_auth_settings')->where('tenant_id', $tenantId)->first(['template', 'config']);
        } catch (\Throwable $e) {
            // الجدول لسه مش موجود (قبل الـ migration): الافتراضي
        }
        if (!$row) {
            return self::$memo[$tenantId] = self::defaults();
        }
        $c = json_decode((string) $row->config, true);
        return self::$memo[$tenantId] = self::normalize(['template' => $row->template] + (is_array($c) ? $c : []));
    }

    public static function exists(int $tenantId): bool
    {
        try {
            return (bool) DB::connection(TenantContext::centralConnection())->table('tenant_auth_settings')->where('tenant_id', $tenantId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** إعدادات المنصة المعزولة اللي الطلب شغّال جواها، أو null (المنصة الأم / مساحة مشتركة). */
    public static function active(): ?array
    {
        return TenantContext::active() && TenantContext::id() ? self::forTenant((int) TenantContext::id()) : null;
    }

    /** null = مفيش منصة معزولة (استخدم إعدادات UIPE)؛ مصفوفة (ممكن فاضية) = Google client ids المسموحة جوّه المنصة. */
    public static function googleIds(): ?array
    {
        $s = self::active();
        if ($s === null) {
            return null;
        }
        return ($s['google']['enabled'] && $s['google']['client_id'] !== '') ? [$s['google']['client_id']] : [];
    }

    public static function microsoftClientId(): ?string
    {
        $s = self::active();
        if ($s === null) {
            return null;
        }
        return ($s['microsoft']['enabled'] && $s['microsoft']['client_id'] !== '') ? $s['microsoft']['client_id'] : '';
    }

    public static function microsoftAuthority(): ?string
    {
        $s = self::active();
        return $s === null ? null : 'https://login.microsoftonline.com/' . $s['microsoft']['directory'];
    }

    public static function normalize(array $in): array
    {
        $d = self::defaults();
        $txt = static fn ($v, int $max): string => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $tpl = (string) ($in['template'] ?? $d['template']);
        $g = (array) ($in['google'] ?? []);
        $m = (array) ($in['microsoft'] ?? []);
        $gid = trim((string) ($g['client_id'] ?? ''));
        $mid = strtolower(trim((string) ($m['client_id'] ?? '')));
        $dir = strtolower(trim((string) ($m['directory'] ?? 'common')));
        return [
            'template' => in_array($tpl, self::TEMPLATES, true) ? $tpl : $d['template'],
            'headline' => ['en' => $txt($in['headline']['en'] ?? '', 80), 'ar' => $txt($in['headline']['ar'] ?? '', 80)],
            'subtitle' => ['en' => $txt($in['subtitle']['en'] ?? '', 200), 'ar' => $txt($in['subtitle']['ar'] ?? '', 200)],
            'google' => ['enabled' => !empty($g['enabled']) && preg_match(self::GOOGLE_RE, $gid) === 1, 'client_id' => preg_match(self::GOOGLE_RE, $gid) === 1 ? $gid : ''],
            'microsoft' => [
                'enabled' => !empty($m['enabled']) && preg_match(self::GUID_RE, $mid) === 1,
                'client_id' => preg_match(self::GUID_RE, $mid) === 1 ? $mid : '',
                'directory' => in_array($dir, ['common', 'organizations', 'consumers'], true) || preg_match(self::GUID_RE, $dir) === 1 ? $dir : 'common',
            ],
            'security' => self::normalizeSecurity($in['security'] ?? null),
        ];
    }

    /** @throws \InvalidArgumentException لو id مكتوب غلط (بدل ما نتجاهله من غير ما نقول للمالك). */
    public static function validate(array $in): void
    {
        $g = trim((string) ($in['google']['client_id'] ?? ''));
        if ($g !== '' && preg_match(self::GOOGLE_RE, $g) !== 1) {
            throw new \InvalidArgumentException('The Google client ID must end with .apps.googleusercontent.com');
        }
        $m = trim((string) ($in['microsoft']['client_id'] ?? ''));
        if ($m !== '' && preg_match(self::GUID_RE, $m) !== 1) {
            throw new \InvalidArgumentException('The Microsoft Application (client) ID must be a GUID.');
        }
        $dir = strtolower(trim((string) ($in['microsoft']['directory'] ?? 'common')));
        if ($dir !== '' && !in_array($dir, ['common', 'organizations', 'consumers'], true) && preg_match(self::GUID_RE, $dir) !== 1) {
            throw new \InvalidArgumentException('Microsoft directory must be common, organizations, consumers or a tenant GUID.');
        }
    }

    public static function save(int $tenantId, array $in): array
    {
        self::validate($in);
        // صفحة الدخول (القالب/النصوص/جوجل/مايكروسوفت) متعرفش حاجة عن سياسات الأمان: منفقدهاش لما تتحفظ.
        if (!array_key_exists('security', $in)) {
            $in['security'] = self::forTenant($tenantId)['security'];
        }
        return self::write($tenantId, self::normalize($in));
    }

    /** يحفظ سياسات الأمان بس (الباقي زي ما هو). */
    public static function saveSecurity(int $tenantId, array $security): array
    {
        self::validateSecurity($security);
        $cur = self::forTenant($tenantId);
        $cur['security'] = $security;
        return self::write($tenantId, self::normalize($cur));
    }

    private static function write(int $tenantId, array $s): array
    {
        $template = $s['template'];
        unset($s['template']);
        TenantContext::runCentral(static function () use ($tenantId, $template, $s) {
            $q = DB::table('tenant_auth_settings')->where('tenant_id', $tenantId);
            $data = ['template' => $template, 'config' => json_encode($s, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($q->exists()) {
                $q->update($data);
            } else {
                DB::table('tenant_auth_settings')->insert($data + ['tenant_id' => $tenantId, 'created_at' => now()]);
            }
        });
        self::flush($tenantId);
        return self::forTenant($tenantId);
    }
}
