<?php

namespace App\Services\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * مين يقدر يدخل مساحة (workspace) من رابطها الجاهز /w/<slug> أو من دومينها.
 *
 * المساحات المعزولة (mode=isolated): ليها قاعدة بيانات ومستخدمين خاصين بيها (TenantContext::active()) فمفيش حساب من المنصة بيعدّي عليها.
 * المساحات المشتركة (mode=shared): بتشتغل على قاعدة المنصة، فكان أي حساب UIPE بيقدر يدخلها.
 * دلوقتي: حساب مش عضو في tenant_members للمساحة => مرفوض (403 not_workspace_member). أدمن المنصة بس بيعدّي (دعم/مراجعة).
 * على المنصة نفسها (من غير X-Tenant-Slug / X-Tenant-Domain) مفيش أي تغيير.
 */
final class WorkspaceAccess
{
    /** @return array{code:string,message_en:string,message_ar:string}|null  null = مسموح */
    public static function check(Request $request, int $userId, string $role = ''): ?array
    {
        if ($userId <= 0 || TenantContext::active()) {
            return null;
        }
        $tenant = self::sharedTenant($request);
        if (!$tenant || $role === 'admin') {
            return null;
        }
        // سياسات مالك المساحة المشتركة: IP allowlist ودومين الإيميل (باقي السياسات للمنصات المعزولة).
        // الإعدادات بتتخزن 30 ثانية لأن الدالة دي بتتنادى مع كل request محمي.
        try {
            $sec = Cache::remember('ws:sec:' . $tenant->id, 30, static function () use ($tenant) {
                $x = TenantSecurity::forTenant((int) $tenant->id);
                return ['ip' => (array) ($x['ip_allowlist'] ?? []), 'domains' => (array) ($x['allowed_domains'] ?? [])];
            });
            if ($sec['ip'] !== [] && !TenantSecurity::ipInList($request->ip(), $sec['ip'])) {
                return [
                    'code' => 'ip_not_allowed',
                    'message_en' => "Sign-in is only allowed from this workspace's approved networks.",
                    'message_ar' => 'الدخول مسموح من الشبكات المعتمدة للمساحة دي بس.',
                ];
            }
            if ($sec['domains'] !== []) {
                $email = (string) Cache::remember('ws:mail:' . $userId, 60, static fn () => (string) DB::table('users')->where('id', $userId)->value('email'));
                if (!TenantSecurity::emailAllowed($email, $sec['domains'])) {
                    return [
                        'code' => 'email_domain_not_allowed',
                        'message_en' => 'This email address is not on a domain allowed in this workspace.',
                        'message_ar' => 'الإيميل ده مش على دومين مسموح له بالدخول للمساحة دي.',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // الإعدادات لسه مش موجودة: مفيش قيود
        }
        try {
            $member = Cache::remember('ws:member:' . $tenant->id . ':' . $userId, 15, static fn () => DB::table('tenant_members')
                ->where('tenant_id', (int) $tenant->id)->where('user_id', $userId)->exists());
        } catch (\Throwable $e) {
            return null; // جداول المساحات لسه مش موجودة: المنصة تفضل شغالة
        }
        if ($member) {
            return null;
        }
        return [
            'code' => 'not_workspace_member',
            'message_en' => 'This account is not a member of this workspace. Ask the workspace admin to add you.',
            'message_ar' => 'الحساب ده مش عضو في المساحة دي. اطلب من مدير المساحة يضيفك.',
        ];
    }

    /** المساحة المشتركة اللي الطلب جاي منها، أو null (المنصة / معزولة / مجهولة — ResolveTenant بيرفضها قبل كده). */
    private static function sharedTenant(Request $request): ?object
    {
        $domain = trim((string) $request->header('X-Tenant-Domain', ''));
        if ($domain !== '') {
            $host = TenantResolver::normalizeHost($domain);
            if ($host === null || TenantResolver::isPlatformHost($host)) {
                return null;
            }
            $t = TenantResolver::lookup($host);
        } else {
            $slug = strtolower(trim((string) $request->header('X-Tenant-Slug', '')));
            if ($slug === '' || preg_match(TenantAddress::PATTERN, $slug) !== 1) {
                return null;
            }
            $t = TenantResolver::lookupSlug($slug);
        }
        if (!$t || ($t->mode ?? 'shared') === 'isolated') {
            return null;
        }
        return $t;
    }

    public static function forget(int $tenantId, int $userId): void
    {
        Cache::forget('ws:member:' . $tenantId . ':' . $userId);
    }
}
