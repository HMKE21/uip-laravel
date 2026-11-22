<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * هوية العميل في الإيميلات (الخطوة 6). لو الإيميل بيتبعت جوه عميل (request على دومينه أو جوب في الـ queue بيشيل tenant_id)
 * بنرجّع اسمه ولوجوه ولونه وإيميل الدعم؛ على المنصة الأم بنرجّع null والإيميل يفضل زي ما هو.
 * بنقرا من قاعدة المنصة الأم (tenant_branding / tenant_domains) حتى والـ default connection متحوّل لقاعدة العميل.
 */
class TenantMailBrand
{
    /** @var array<int, array<string,mixed>> */
    private static array $memo = [];

    /** منصة بنبعت باسمها من خارج سياقها (مثلًا دعوة من مركز المطوّرين على المنصة الأم). */
    private static ?object $override = null;

    /** يشغّل $fn والإيميلات اللي بتتبعت جواه بهوية المنصة $tenant، من غير تبديل قاعدة البيانات. */
    public static function using(object $tenant, \Closure $fn): mixed
    {
        $prev = self::$override;
        self::$override = $tenant;
        try {
            return $fn();
        } finally {
            self::$override = $prev;
        }
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** @return array{name:string,logo_url:?string,color:?string,color_dark:?string,support_email:?string,hide_platform:bool}|null */
    public static function current(): ?array
    {
        $tenant = TenantContext::tenant() ?? self::$override;
        if (!$tenant) {
            return null;
        }
        $id = (int) $tenant->id;
        if (isset(self::$memo[$id])) {
            return self::$memo[$id];
        }

        try {
            $b = TenantContext::runCentral(fn () => TenantBranding::get($id));
        } catch (\Throwable $e) {
            $b = TenantBranding::defaults();
        }

        $name = trim((string) ($b['display_name'] ?: ($tenant->name ?? '')));
        $color = self::hex($b['primary_color'] ?? null);
        $logo = (is_string($b['logo_url'] ?? null) && preg_match('#^https://#i', $b['logo_url'])) ? $b['logo_url'] : null;
        $email = filter_var((string) ($b['support_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;

        // سيندر المنصة (لو المالك فعّله وعنوانه على دومين شغّال للمنصة): بيظهر كـ From بدل عنوان UIPE.
        $fromAddress = null;
        try {
            $sec = TenantSecurity::forTenant($id);
            $addr = strtolower(trim((string) ($sec['mail']['from_address'] ?? '')));
            if (!empty($sec['mail']['from_enabled']) && $addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false
                && in_array(substr($addr, (int) strrpos($addr, '@') + 1), TenantSecurity::activeDomains($id), true)) {
                $fromAddress = $addr;
            }
        } catch (\Throwable $e) {
            $fromAddress = null;
        }

        return self::$memo[$id] = [
            'from_address' => $fromAddress,
            'name' => $name !== '' ? $name : 'Workspace',
            'logo_url' => $logo,
            'color' => $color,
            'color_dark' => $color ? self::darken($color, 0.35) : null,
            'support_email' => $email,
            'hide_platform' => (bool) ($b['hide_platform_branding'] ?? false),
        ];
    }

    /** رابط الواجهة بتاع العميل (دومينه الأساسي الـ active) أو null. للروابط جوه الإيميلات. */
    public static function frontendUrl(int $tenantId): ?string
    {
        try {
            $d = DB::connection(TenantContext::centralConnection())->table('tenant_domains')
                ->where('tenant_id', $tenantId)->where('status', 'active')
                ->orderByDesc('is_primary')->orderBy('id')->value('domain');
        } catch (\Throwable $e) {
            return null;
        }
        return $d ? 'https://' . $d : TenantAddress::urlFor($tenantId);   // مفيش دومين active: رابط المنصة الجاهز /w/<slug>
    }

    private static function hex($v): ?string
    {
        return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? strtolower($v) : null;
    }

    private static function darken(string $hex, float $pct): string
    {
        $out = '#';
        foreach ([1, 3, 5] as $i) {
            $out .= str_pad(dechex((int) max(0, round(hexdec(substr($hex, $i, 2)) * (1 - $pct)))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}
