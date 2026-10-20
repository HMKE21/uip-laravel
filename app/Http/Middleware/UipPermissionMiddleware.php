<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;

/**
 * يقفل الـ route فعليًا حسب الصلاحيات اللي الأدمن بيظبطها من صفحة
 * الأدوار والصلاحيات (/admin/roles).
 *
 * الاستخدام (لازم بعد uip.auth في نفس الـ group):
 *   ->middleware(['uip.auth', 'uip.can:ai.request_analysis'])
 *   ->middleware('uip.can:security.incidents.manage,security.dashboard.view')  // أي واحدة منهم تكفي
 *   ->middleware('uip.can:write:notifications.manage')  // بيتطبق على POST/PUT/PATCH/DELETE بس، والقراءة (GET) مفتوحة
 *
 * - role=admin بيعدّي دايمًا (PermissionService::currentUserCan).
 * - لو الصلاحية مش ممنوحة: 403 + errors.code = "permission_denied" +
 *   errors.permission = <slug>. الفرونت بيلقط الكود ده، بيعيد تحميل
 *   صلاحيات المستخدم، ويقفل الزرار/الصفحة.
 * - الفحص ده بيتضاف فوق أي فحص دور (role) موجود جوّه الكنترولرز، مش بديل عنه.
 */
class UipPermissionMiddleware
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string ...$rules)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $role   = (string) $request->attributes->get('uip_role');

        if (!$userId) {
            return $this->deny('Authentication required.', 401, null);
        }

        $isWrite = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        $required = [];
        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (str_starts_with($rule, 'write:')) {
                if (!$isWrite) {
                    continue;
                }
                $rule = substr($rule, 6);
            }
            if ($rule !== '') {
                $required[] = $rule;
            }
        }

        // ولا قاعدة بتنطبق على نوع الريكوست ده (مثلًا write: على GET) => عدّي.
        if ($required === []) {
            return $next($request);
        }

        foreach ($required as $slug) {
            if ($this->permissions->currentUserCan($userId, $role, $slug)) {
                return $next($request);
            }
        }

        return $this->deny(
            'Your role does not have permission to do this. Ask an administrator to grant it.',
            403,
            $required[0]
        );
    }

    private function deny(string $message, int $status, ?string $permission)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $status === 403
                ? ['code' => 'permission_denied', 'permission' => $permission]
                : null,
            'meta'    => (object) [],
        ], $status);
    }
}
