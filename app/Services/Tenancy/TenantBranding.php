<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

/** هوية الـ tenant: اسم وشعار وألوان وروابط. اللي بيخرج للمستخدم النهائي (publicPayload) مفيهوش أي حاجة داخلية. */
final class TenantBranding
{
    private static function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private static function color($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            self::fail('Colors must look like #1A73E8.');
        }
        return strtoupper($v);
    }

    /** https بس (مفيش javascript: ولا data:)، وفيه host فعلي. */
    private static function url($v, string $label): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (strlen($v) > 300 || !filter_var($v, FILTER_VALIDATE_URL) || !preg_match('~^https://[^\s]+$~i', $v) || !parse_url($v, PHP_URL_HOST)) {
            self::fail("{$label} must be a valid https:// link (up to 300 characters).");
        }
        return $v;
    }

    public static function defaults(): array
    {
        return ['display_name' => null, 'logo_url' => null, 'favicon_url' => null, 'primary_color' => null, 'accent_color' => null, 'theme' => 'auto',
            'default_locale' => 'en', 'support_email' => null, 'support_url' => null, 'privacy_url' => null, 'terms_url' => null, 'hide_platform_branding' => false];
    }

    public static function get(int $tenantId): array
    {
        $b = DB::table('tenant_branding')->where('tenant_id', $tenantId)->first();
        if (!$b) {
            return self::defaults();
        }
        $out = [];
        foreach (self::defaults() as $k => $def) {
            $out[$k] = $k === 'hide_platform_branding' ? (bool) $b->{$k} : ($b->{$k} ?? $def);
        }
        return $out;
    }

    public static function hasFeature(object $tenant, string $feature): bool
    {
        return in_array($feature, (array) json_decode((string) ($tenant->features ?? '[]'), true), true);
    }

    /** يحفظ الحقول المبعوتة بس. hide_platform_branding بيحتاج feature white_label (بيدّيها أدمن المنصة). */
    public static function update(object $tenant, array $in): array
    {
        $cur = self::get((int) $tenant->id);
        $new = $cur;
        if (array_key_exists('display_name', $in)) {
            $n = trim((string) $in['display_name']);
            if (mb_strlen($n) > 80) {
                self::fail('The display name is too long (80 characters max).');
            }
            $new['display_name'] = $n === '' ? null : $n;
        }
        foreach (['primary_color', 'accent_color'] as $k) {
            if (array_key_exists($k, $in)) {
                $new[$k] = self::color($in[$k]);
            }
        }
        foreach (['logo_url' => 'The logo', 'favicon_url' => 'The favicon', 'support_url' => 'The support link', 'privacy_url' => 'The privacy link', 'terms_url' => 'The terms link'] as $k => $label) {
            if (array_key_exists($k, $in)) {
                $new[$k] = self::url($in[$k], $label);
            }
        }
        if (array_key_exists('theme', $in)) {
            if (!in_array($in['theme'], ['light', 'dark', 'auto'], true)) {
                self::fail('Theme must be light, dark or auto.');
            }
            $new['theme'] = $in['theme'];
        }
        if (array_key_exists('default_locale', $in)) {
            if (!in_array($in['default_locale'], ['en', 'ar'], true)) {
                self::fail('Language must be en or ar.');
            }
            $new['default_locale'] = $in['default_locale'];
        }
        if (array_key_exists('support_email', $in)) {
            $e = trim((string) $in['support_email']);
            if ($e !== '' && (strlen($e) > 150 || !filter_var($e, FILTER_VALIDATE_EMAIL))) {
                self::fail('Enter a valid support email.');
            }
            $new['support_email'] = $e === '' ? null : $e;
        }
        if (array_key_exists('hide_platform_branding', $in)) {
            $want = filter_var($in['hide_platform_branding'], FILTER_VALIDATE_BOOLEAN);
            if ($want && !self::hasFeature($tenant, 'white_label')) {
                self::fail('Hiding the platform branding is part of the White Label plan. Ask the platform team to enable it.', 403);
            }
            $new['hide_platform_branding'] = $want;
        }
        $row = $new;
        $row['hide_platform_branding'] = $new['hide_platform_branding'] ? 1 : 0;
        if (DB::table('tenant_branding')->where('tenant_id', (int) $tenant->id)->exists()) {
            DB::table('tenant_branding')->where('tenant_id', (int) $tenant->id)->update($row + ['updated_at' => now()]);
        } else {
            DB::table('tenant_branding')->insert($row + ['tenant_id' => (int) $tenant->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $new;
    }

    /** اللي المستخدم النهائي / الـ Embed بيشوفه. من غير ids داخلية ولا حدود ولا أعضاء. */
    public static function publicPayload(object $tenant): array
    {
        $b = self::get((int) $tenant->id);
        $hide = $b['hide_platform_branding'] && self::hasFeature($tenant, 'white_label');
        return [
            'tenant' => $tenant->public_id,
            'name' => $b['display_name'] ?: $tenant->name,
            'logo_url' => $b['logo_url'], 'favicon_url' => $b['favicon_url'],
            'colors' => ['primary' => $b['primary_color'], 'accent' => $b['accent_color']],
            'theme' => $b['theme'], 'locale' => $b['default_locale'],
            'support' => ['email' => $b['support_email'], 'url' => $b['support_url']],
            // privacy_url/terms_url = رابط خارجي اختياري. privacy_page/terms_page = صفحات المساحة نفسها (دايمًا موجودة) — مفيش حد بيروح لسياسة المنصة.
            'legal' => ['privacy_url' => $b['privacy_url'], 'terms_url' => $b['terms_url'], 'privacy_page' => '/p/privacy', 'terms_page' => '/p/terms'],
            'powered_by' => $hide ? null : 'UIPE',
        ];
    }
}
