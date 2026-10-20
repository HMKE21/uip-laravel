<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\RolePermissionRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminRolesApiController.php القديمة —
 * بند 25 batch 10 (Admin > Roles & Permissions). سطح /api/v1/admin/roles/*
 * واحد بيعيد استخدام RolePermissionRepository بالظبط زي Admin\
 * AdminRoleManagementController القديمة. الأدوار الأساسية الستة (System)
 * ثابتة (مزروعة، مبتتعملهاش create/delete من هنا)؛ اللي بيتدار هنا هو
 * الأدوار المخصصة (create/delete، بس لو الدور المخصص مفيهوش أي مستخدم)
 * زائد صلاحيات كل دور (replace كامل مش diff، زي محرر checklist الويب بالظبط).
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php).
 */
class AdminRolesApiController extends Controller
{
    public function __construct(
        private RolePermissionRepository $roles,
        private AuditLogService $auditLog
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access role management.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/roles — every role with live user/permission counts. */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->roles->rolesWithCounts(), 'Roles retrieved successfully.');
    }

    /** GET /api/v1/admin/roles/{slug} — role details + its full permission set (all groups + which ids are granted). */
    public function show(Request $request, $slug)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $role = $this->roles->findRoleBySlug((string) $slug);
        if (!$role) {
            return $this->apiError('Role not found.', null, 404);
        }

        return $this->apiSuccess([
            'role'              => $role->toArray(),
            'permission_groups' => $this->roles->allPermissionsGrouped(),
            'granted_ids'       => $this->roles->grantedPermissionIds($role->id),
        ], 'Role retrieved successfully.');
    }

    /** POST /api/v1/admin/roles — creates a custom role (slug/name_en/name_ar required). */
    public function store(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $data = $request->validate([
            'slug'    => 'required|min:2',
            'name_en' => 'required|min:2',
            'name_ar' => 'required|min:2',
        ]);

        $slug = strtolower(trim((string) $data['slug']));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug);
        $slug = trim((string) $slug, '_');

        if ($slug === '') {
            return $this->apiError('Please provide a valid role identifier.', ['slug' => 'Invalid identifier.'], 422);
        }

        $role = $this->roles->createRole($slug, $data['name_en'], $data['name_ar'], $request->input('description') ?: null);
        if (!$role) {
            return $this->apiError('A role with that identifier already exists.', null, 422);
        }

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'role.create', 'Role', $role->id, null, ['slug' => $role->slug]);

        return $this->apiSuccess($role->toArray(), 'Role created successfully.', 201);
    }

    /** PATCH /api/v1/admin/roles/{slug}/permissions — replaces the role's whole permission set. */
    public function updatePermissions(Request $request, $slug)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $role = $this->roles->findRoleBySlug((string) $slug);
        if (!$role) {
            return $this->apiError('Role not found.', null, 404);
        }

        $before = $this->roles->grantedPermissionIds($role->id);
        $submitted = (array) $request->input('permissions', []);
        $after = array_map('intval', $submitted);

        $this->roles->syncPermissions($role->id, $after);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'role.permissions_update', 'Role', $role->id, ['permission_ids' => $before], ['permission_ids' => $after]);

        return $this->apiSuccess(['granted_ids' => $after], 'Permissions updated for this role.');
    }

    /** DELETE /api/v1/admin/roles/{slug} — only reachable for custom (non-system) roles with zero users assigned. */
    public function destroy(Request $request, $slug)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $role = $this->roles->findRoleBySlug((string) $slug);
        if (!$role) {
            return $this->apiError('Role not found.', null, 404);
        }

        $ok = $this->roles->deleteRole($role->id);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'role.delete', 'Role', $role->id, ['slug' => $role->slug], null);
        }

        return $ok
            ? $this->apiSuccess(null, 'Role deleted successfully.')
            : $this->apiError('This role can\'t be deleted — it\'s either a built-in role or still assigned to at least one user.', null, 422);
    }
}
