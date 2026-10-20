<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * نسخة طبق الأصل من App\Services\RoleService القديمة (بترجع نفس القيم
 * بالظبط من config/roles.php)، زائد primaryRoleFor() اللي هي بورت لـ
 * UserRepository::getPrimaryRoleSlug() القديمة (نفس الكويري بالظبط:
 * أقدم صف في user_roles حسب assigned_at).
 */
class RoleService
{
    public function availableRoles(): array
    {
        return (array) config('roles.available', []);
    }

    public function isValidRole(string $role): bool
    {
        return in_array($role, $this->availableRoles(), true);
    }

    public function homeRouteFor(string $role): string
    {
        return (string) config("roles.home_route.{$role}", '/');
    }

    /** Role labels for the role-selection screen — يطابق roleLabels() القديمة بالظبط. */
    public function roleLabels(): array
    {
        return [
            'student'    => ['en' => 'Student',    'ar' => 'طالب'],
            'university' => ['en' => 'University', 'ar' => 'جامعة'],
        ];
    }

    /**
     * يطابق UserRepository::getPrimaryRoleSlug() القديمة بالظبط: أقدم صف
     * في user_roles للمستخدم ده (حسب assigned_at ASC) → slug بتاع الدور.
     * الافتراضي 'student' لو مفيش أي صف (نفس فallback القديم في AuthService).
     */
    public function primaryRoleFor(int $userId): string
    {
        $slug = DB::table('roles')
            ->join('user_roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $userId)
            ->orderBy('user_roles.assigned_at', 'asc')
            ->value('roles.slug');

        return $slug ?? 'student';
    }

    /** True for a "joker" account (holds every role, may switch between them). */
    public function isJoker(int $userId): bool
    {
        return (bool) DB::table('users')->where('id', $userId)->value('is_joker');
    }

    /** Every role slug the user holds (primary first). */
    public function allRolesFor(int $userId): array
    {
        return DB::table('roles')
            ->join('user_roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $userId)
            ->orderBy('user_roles.assigned_at', 'asc')
            ->pluck('roles.slug')
            ->all();
    }

    /**
     * Role a session acts as. Normal accounts: always the primary role (unchanged
     * behaviour). Joker accounts: the role last chosen on this session if the user
     * still holds it, otherwise the primary role.
     */
    public function effectiveRoleFor(int $userId, ?int $sessionId = null): string
    {
        $primary = $this->primaryRoleFor($userId);
        if (!$sessionId || !$this->isJoker($userId)) {
            return $primary;
        }
        $active = (string) DB::table('user_sessions')->where('id', $sessionId)->where('user_id', $userId)->value('active_role');

        return ($active !== '' && in_array($active, $this->allRolesFor($userId), true)) ? $active : $primary;
    }
}
