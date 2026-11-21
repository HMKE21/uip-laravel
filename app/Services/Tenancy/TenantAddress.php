<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * الرابط الجاهز من المنصة: https://<المنصة>/w/<slug>. الـ slug 3-40 حرف a-z 0-9 و "-" (يقبل أرقام بس).
 * ResolveTenant بيحدد العميل بيه لما الفرونت يبعت X-Tenant-Slug.
 */
final class TenantAddress
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/';

    public static function enabled(): bool
    {
        return (bool) config('tenancy.address.enabled', true);
    }

    public static function baseUrl(): ?string
    {
        $b = trim((string) (config('tenancy.address.base_url') ?: config('app.frontend_url', '')));
        return ($b !== '' && preg_match('~^https?://~i', $b)) ? rtrim($b, '/') : null;
    }

    public static function url(string $slug): ?string
    {
        $b = self::baseUrl();
        return ($b && self::enabled()) ? $b . '/' . trim((string) config('tenancy.address.path_prefix', 'w'), '/') . '/' . $slug : null;
    }

    public static function urlFor(int $tenantId): ?string
    {
        try {
            $slug = DB::connection(TenantContext::centralConnection())->table('tenants')->where('id', $tenantId)->value('slug');
        } catch (\Throwable $e) {
            return null;
        }
        return $slug ? self::url((string) $slug) : null;
    }

    /** يرجّع slug سليم أو يرمي DomainException. */
    public static function normalize(string $in): string
    {
        $s = strtolower(trim($in));
        if (!preg_match(self::PATTERN, $s)) {
            throw new \DomainException('Use 3-40 characters: letters, numbers and "-" (numbers only is fine).', 422);
        }
        if (str_contains($s, '--')) {
            throw new \DomainException('Please avoid double dashes.', 422);
        }
        if (in_array($s, array_map('strtolower', (array) config('tenancy.address.reserved', [])), true)) {
            throw new \DomainException('This address is reserved. Please choose another one.', 422);
        }
        return $s;
    }

    public static function forget(string $slug): void
    {
        Cache::forget('tenancy:slug:' . strtolower($slug));
    }

    public static function payload(object $tenant): array
    {
        $url = self::url((string) $tenant->slug);
        return ['enabled' => self::enabled(), 'slug' => $tenant->slug, 'url' => $url, 'login_url' => $url ? $url . '/login' : null, 'is_numeric' => ctype_digit((string) $tenant->slug)];
    }
}
