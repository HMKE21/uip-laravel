<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * مصدر الحقيقة الوحيد لسؤال "المستخدم ده مسموح له بالصلاحية دي؟".
 *
 * الصلاحية الفعلية = (صلاحيات كل أدواره من role_permission)
 *                    + (أي grant في user_permission_overrides)
 *                    − (أي revoke في user_permission_overrides).
 *
 * بتتقري من الداتابيز في كل ريكوست (مفيش كاش بين الريكوستات)، فأي تعديل
 * من صفحة الأدوار والصلاحيات (/admin/roles) بيسري فورًا على أول ريكوست
 * بعده — من غير ما المستخدم يعمل logout/login ومن غير ما التوكن يتغيّر.
 *
 * مفيش Core\Session ثابت هنا، فالكولر (uip.auth middleware بيحط
 * uip_user_id/uip_role في $request->attributes) بيمررهم صراحة.
 *
 * "أدمن دايمًا true" — عشان تعديل صلاحية أبدًا ميقفلش حساب الأدمن نفسه بالغلط.
 */
class PermissionService
{
    /**
     * الصلاحيات الفعلية للمستخدم (أدوار + overrides).
     *
     * @return string[] permission slugs, e.g. ['project.approve', 'analytics.view', ...]
     */
    public function permissionsForUser(int $userId): array
    {
        $fromRoles = DB::table('user_roles as ur')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $userId)
            ->distinct()
            ->pluck('p.slug')
            ->all();

        $overrides = DB::table('user_permission_overrides as upo')
            ->join('permissions as p', 'p.id', '=', 'upo.permission_id')
            ->where('upo.user_id', $userId)
            ->get(['p.slug', 'upo.effect']);

        $set = array_fill_keys($fromRoles, true);
        foreach ($overrides as $o) {
            if ($o->effect === 'grant') {
                $set[$o->slug] = true;
            } elseif ($o->effect === 'revoke') {
                unset($set[$o->slug]);
            }
        }

        return array_keys($set);
    }

    /** كل slugs الكتالوج — بيستخدمها endpoint "صلاحياتي" للأدمن (عنده كلها دايمًا). */
    public function allSlugs(): array
    {
        return DB::table('permissions')->orderBy('slug')->pluck('slug')->all();
    }

    public function userHasPermission(int $userId, string $permissionSlug): bool
    {
        return in_array($permissionSlug, $this->permissionsForUser($userId), true);
    }

    /**
     * الشيك اللي أغلب الكولرز فعلًا محتاجاه: المستخدم الحالي (uip_user_id/
     * uip_role جايين من $request->attributes) مسموح له يعمل كذا؟
     * role='admin' بترجع true على طول.
     */
    public function currentUserCan(?int $userId, ?string $role, string $permissionSlug): bool
    {
        if (!$userId) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        return $this->userHasPermission($userId, $permissionSlug);
    }
}
