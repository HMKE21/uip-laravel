<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\Request;

/**
 * GET /api/v1/auth/permissions — الصلاحيات الفعلية للمستخدم الحالي، عشان
 * الفرونت يخفي الأزرار/الصفحات المقفولة. ده للعرض بس — الإنفورس الحقيقي
 * على السيرفر (uip.can middleware)، والفرونت مش مصدر ثقة.
 */
class MyPermissionsApiController extends Controller
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function show(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $role   = (string) $request->attributes->get('uip_role');
        $isAdmin = $role === 'admin';

        $roles = app(\App\Services\RoleService::class);
        $joker = $roles->isJoker($userId);

        return $this->apiSuccess([
            'role'        => $role,
            'is_joker'    => $joker,
            'available_roles' => $joker ? $roles->allRolesFor($userId) : [],
            'is_admin'    => $isAdmin,
            'permissions' => $isAdmin
                ? $this->permissions->allSlugs()
                : $this->permissions->permissionsForUser($userId),
        ], 'Permissions retrieved successfully.');
    }
}
