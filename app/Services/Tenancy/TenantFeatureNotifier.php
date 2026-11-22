<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** لما أدمن UIPE يوافق على ميزة (أو يرفضها/يقفلها): إشعار داخل التطبيق + إيميل لمالك ومديري المنصة. */
final class TenantFeatureNotifier
{
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
