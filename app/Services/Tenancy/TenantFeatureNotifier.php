<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** لما أدمن UIPE يوافق على ميزة (أو يرفضها/يقفلها): إشعار داخل التطبيق + إيميل لمالك ومديري المنصة. */
final class TenantFeatureNotifier
{
    /**
     * لما أدمن عميل يطلب ميزة: إشعار داخل التطبيق + إيميل لكل أدمن في المنصة الأم (الصندوق: /admin/tenant-features).
     * الطلب بيتسجّل جوه سياق العميل، فالإشعار لازم يتكتب على قاعدة المنصة الأم (runCentral) وإلا مايوصلش لأي أدمن.
     * @param string[] $featureKeys
     */
    public static function requested(int $tenantId, array $featureKeys, ?int $requesterId = null, ?string $note = null): void
    {
        if ($featureKeys === []) {
            return;
        }
        try {
            TenantContext::runCentral(function () use ($tenantId, $featureKeys, $requesterId, $note) {
                $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name']);
                $name = $tenant->name ?? ('#' . $tenantId);
                $who = $requesterId ? (string) DB::table('users')->where('id', $requesterId)->value('full_name') : '';
                $catalog = TenantFeatures::catalog();
                $admins = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')
                    ->where('r.slug', 'admin')->whereNull('u.deleted_at')->distinct()
                    ->get(['u.id', 'u.email', 'u.full_name', 'u.preferred_language']);
                foreach ($admins as $a) {
                    $ar = ($a->preferred_language ?? 'ar') !== 'en';
                    $labels = implode($ar ? '، ' : ', ', array_map(static fn ($k) => $ar ? ($catalog[$k]['label_ar'] ?? $k) : ($catalog[$k]['label_en'] ?? $k), $featureKeys));
                    $title = $ar ? "طلب ميزات جديد من {$name}" : "New feature request from {$name}";
                    $body = $labels . ($who !== '' ? ($ar ? " — بواسطة {$who}" : " — by {$who}") : '') . ($note ? ($ar ? " — ملاحظة: {$note}" : " — Note: {$note}") : '');
                    try {
                        app(\App\Services\NotificationService::class)->notify((int) $a->id, 'tenant_feature_request', $title, $body, '/admin/tenant-features', 'high');
                    } catch (\Throwable $e) {
                        Log::warning('Feature request in-app notice failed', ['admin' => $a->id, 'error' => $e->getMessage()]);
                    }
                    try {
                        app(\App\Services\MailService::class)->sendNotificationEmail((string) $a->email, (string) $a->full_name, $title, $body, null, $ar ? 'ar' : 'en');
                    } catch (\Throwable $e) {
                        Log::notice('Feature request email failed', ['error' => $e->getMessage()]);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Feature request notification failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
    }

    /** @param string[] $featureKeys */
    public static function decided(int $tenantId, array $featureKeys, string $status, ?string $note = null): void
    {
        if (!in_array($status, ['enabled', 'rejected', 'disabled'], true) || $featureKeys === []) {
            return;
        }
        try {
            $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name']);
            $catalog = TenantFeatures::catalog();
            $people = DB::table('tenant_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.tenant_id', $tenantId)->whereIn('m.role', ['owner', 'admin'])->whereNull('u.deleted_at')
                ->get(['u.id', 'u.email', 'u.full_name', 'u.preferred_language']);
            foreach ($people as $p) {
                $ar = ($p->preferred_language ?? 'ar') !== 'en';
                $labels = implode($ar ? '، ' : ', ', array_map(static fn ($k) => $ar ? ($catalog[$k]['label_ar'] ?? $k) : ($catalog[$k]['label_en'] ?? $k), $featureKeys));
                $name = $tenant->name ?? '';
                $title = match ($status) {
                    'enabled' => $ar ? "اتفعّلت ميزات في {$name}" : "Features approved for {$name}",
                    'rejected' => $ar ? "اتعذّر تفعيل ميزات في {$name}" : "Features not approved for {$name}",
                    default => $ar ? "اتقفلت ميزات في {$name}" : "Features turned off for {$name}",
                };
                $body = $labels . ($note ? ($ar ? " — السبب: {$note}" : " — Reason: {$note}") : '');
                app(\App\Services\NotificationService::class)->notify((int) $p->id, 'tenant_feature', $title, $body, '/developer');
                try {
                    app(\App\Services\MailService::class)->sendNotificationEmail((string) $p->email, (string) $p->full_name, $title, $body, null, $ar ? 'ar' : 'en');
                } catch (\Throwable $e) {
                    Log::notice('Feature decision email failed', ['error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Feature decision notification failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
    }
}
