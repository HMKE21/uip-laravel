<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/RolePermissionRepository.php القديمة —
 * بند 25 batch 10 (Admin > Roles & Permissions). Data access لـ roles/
 * permissions/role_permission (migration 002)، أول مستهلك حقيقي ليها هو
 * صفحة Admin Roles & Permissions — الأدوار الستة (System) الأساسية ثابتة
 * (صفوف مزروعة في `roles`) ومش بتتعمل create/delete من هنا، بس الأدوار
 * المخصصة (custom) وصلاحيات كل دور هما اللي بيتغيروا.
 */
class RolePermissionRepository
{
    /**
     * كل دور، مع عدد المستخدمين الحي (user_roles) وعدد الصلاحيات الحي
     * (role_permission) — لكروت صفحة Roles & Permissions.
     * @return array<int,array<string,mixed>>
     */
    public function rolesWithCounts(): array
    {
        return DB::table('roles as r')
            ->select(
                'r.*',
                DB::raw('(SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) as users_count'),
                DB::raw('(SELECT COUNT(*) FROM role_permission rp WHERE rp.role_id = r.id) as permissions_count')
            )
            ->orderBy('r.id', 'asc')
            ->get()
            ->map(fn ($row) => (array) $row)->all();
    }

    public function findRoleBySlug(string $slug): ?Role
    {
        return Role::where('slug', $slug)->first();
    }

    /** كل صلاحية في الكتالوج، مجمّعة حسب الموديول، لـ checklist المحرر. */
    public function allPermissionsGrouped(): array
    {
        $rows = Permission::orderBy('module', 'asc')->orderBy('slug', 'asc')->get();
        $grouped = [];
        foreach ($rows as $p) {
            $grouped[$p->module][] = $p->toArray();
        }
        return $grouped;
    }

    /** أرقام الصلاحيات الممنوحة حاليًا لدور معيّن (عشان checkboxes المحرر تتحدد مسبقًا). */
    public function grantedPermissionIds($roleId): array
    {
        return DB::table('role_permission')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * تستبدل كل صلاحيات الدور دفعة واحدة — المحرر بيبعت القايمة الكاملة
     * المختارة كل مرة، يعني مفيش diff: حذف ثم إعادة إدراج المختار بس.
     * @param int[] $permissionIds
     */
    public function syncPermissions($roleId, array $permissionIds): void
    {
        DB::transaction(function () use ($roleId, $permissionIds) {
            DB::table('role_permission')->where('role_id', $roleId)->delete();
            $unique = array_unique(array_map('intval', $permissionIds));
            $rows = array_map(fn ($pid) => ['role_id' => $roleId, 'permission_id' => $pid], $unique);
            if ($rows !== []) {
                DB::table('role_permission')->insert($rows);
            }
        });
    }

    /**
     * تنشئ دور مخصص جديد (is_system = 0). الأدوار الأساسية (system) مبتتعملش
     * هنا برضه — الوصول لها بس من زرار "New Custom Role". بترجع null لو
     * الـ slug مستخدم بالفعل.
     */
    public function createRole(string $slug, string $nameEn, string $nameAr, ?string $description): ?Role
    {
        if (Role::where('slug', $slug)->exists()) {
            return null;
        }
        return Role::create([
            'slug'        => $slug,
            'name_en'     => $nameEn,
            'name_ar'     => $nameAr,
            'description' => $description,
            'is_system'   => 0,
        ]);
    }

    /**
     * تحذف دور مخصص. بترفض (وترجع false) لأي دور أساسي (is_system = 1)
     * — الـ slugs بتاعتهم متعرّف عليها بالاسم في config/roles.php و
     * RoleMiddleware، فحذف واحد منهم هيكسر الراوتينج — وبترفض لو أي
     * مستخدم لسه ماسك الدور ده، عشان حساب مايفقدش دوره الوحيد بصمت.
     */
    public function deleteRole($roleId): bool
    {
        $role = Role::find($roleId);
        if (!$role || (int) $role->is_system === 1) {
            return false;
        }
        $usersCount = DB::table('user_roles')->where('role_id', $roleId)->count();
        if ($usersCount > 0) {
            return false;
        }
        return (bool) $role->delete();
    }
}
