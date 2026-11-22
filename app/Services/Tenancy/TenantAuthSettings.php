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
        ];
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
            $row = TenantContext::runCentral(static fn () => DB::table('tenant_auth_settings')->where('tenant_id', $tenantId)->first(['template', 'config']));
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
            return (bool) TenantContext::runCentral(static fn () => DB::table('tenant_auth_settings')->where('tenant_id', $tenantId)->exists());
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
        $s = self::normalize($in);
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
