<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Services\AuditLogService;
use App\Services\DeviceRestrictionPolicyService;
use App\Services\RoleService;
use App\Services\UipJwtService;
use App\Support\AuthCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/auth/switch-role — joker accounts only.
 *
 * A joker (users.is_joker, granted by an admin) holds every role. This swaps the
 * role the *current session* acts as: it validates the target against the roles
 * the user really holds, stores it on user_sessions.active_role (so refresh keeps
 * it) and issues a fresh token pair for that role. Regular accounts get 403.
 */
class SwitchRoleController extends Controller
{
    public function __construct(
        private RoleService $roles,
        private AuditLogService $auditLog,
        private DeviceRestrictionPolicyService $deviceRestriction
    ) {
    }

    public function submit(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $sid    = (int) $request->attributes->get('uip_session_id');
        $from   = (string) $request->attributes->get('uip_role');

        if (!$this->roles->isJoker($userId)) {
            return $this->apiError('Role switching is not available for this account.', null, 403);
        }
        if (!$sid) {
            return $this->apiError('Please sign in again to switch roles.', null, 401);
        }

        $target = (string) $request->input('role', '');
        if (!in_array($target, $this->roles->allRolesFor($userId), true)) {
            return $this->apiError('You do not hold that role.', null, 422);
        }
        if (!$this->deviceRestriction->isAllowed($request->userAgent(), $target)) {
            return $this->apiError('This device type is not allowed for that role.', ['code' => 'device_blocked'], 403);
        }

        $oldHash = (string) DB::table('user_sessions')->where('id', $sid)->value('session_token');

        DB::table('user_sessions')->where('id', $sid)->where('user_id', $userId)->update(['active_role' => $target]);
        $tokens = UipJwtService::issueTokenPair($userId, $target, $sid);

        // The previous refresh token of this session must not stay usable.
        if ($oldHash !== '') {
            RefreshToken::where('user_id', $userId)->where('token_hash', $oldHash)->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        $this->auditLog->record($userId, 'user.role_switch', 'User', $userId, ['role' => $from], ['role' => $target]);

        return AuthCookies::attach($this->apiSuccess(
            array_merge($tokens, ['redirect' => $this->roles->homeRouteFor($target)]),
            'Role switched successfully.'
        ), $request);
    }
}
