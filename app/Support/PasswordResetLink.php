<?php

namespace App\Support;

use App\Services\RoleService;

/**
 * لينك إعادة تعيين الباسورد اللي بيتبعت في الإيميل. حسابات الستاف (أدمن / محلل
 * بيانات / أمن) بتروح لصفحة الاسترجاع بتاعت بوابة الستاف المخفية بنفس تصميمها،
 * وباقي الحسابات للصفحة العادية. مسار بوابة الستاف من STAFF_LOGIN_PATH في الـ .env
 * (لازم يطابق VITE_STAFF_LOGIN_PATH في الفرونت، والافتراضي /staff-access).
 */
class PasswordResetLink
{
    private const STAFF_ROLES = ['admin', 'data_analyst', 'security_admin', 'security_officer'];

    public static function for(int $userId, string $plainToken): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        $role = app(RoleService::class)->primaryRoleFor($userId);
        if (in_array($role, self::STAFF_ROLES, true)) {
            $path = '/' . ltrim((string) config('app.staff_login_path', '/staff-access'), '/');
            return $base . $path . '/reset-password?token=' . $plainToken;
        }

        return $base . '/auth/reset-password?token=' . $plainToken;
    }
}
