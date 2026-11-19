<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\LoginHistoryService;
use App\Services\Identity\TokenRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 5 (الهوية):
 *  /api/v1/auth/login-history, /auth/sessions/revoke-others, /auth/sessions/revoke-all  → المستخدم نفسه
 *  /api/v1/identity/login-history, /identity/users/{id}/revoke-tokens                   → أدمن المنصة (الكل) / الجامعة (أعضائها بس)
 */
class IdentityApiController extends Controller
{
    public function __construct(private LoginHistoryService $history, private TokenRevocationService $revoker)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    /* ------------------------------------------------------------------ المستخدم نفسه */

    public function myLoginHistory(Request $r)
    {
        $d = $this->history->forUser($this->uid($r), (int) $r->query('page', 1), (int) $r->query('per_page', 20));
        return $this->apiSuccess($d['items'], 'OK', 200, $d['meta']);
    }

    public function revokeOthers(Request $r)
    {
        $res = $this->revoker->revokeOthers($this->uid($r), (int) $r->attributes->get('uip_session_id') ?: null, 'revoked_others', $this->uid($r));
        return $this->apiSuccess($res, 'Signed out of your other devices.');
    }

    public function revokeAll(Request $r)
    {
        $res = $this->revoker->revokeAll($this->uid($r), 'revoked_all', $this->uid($r));
        return $this->apiSuccess($res, 'Signed out of all devices.');
    }

    /* ------------------------------------------------------------------ أدمن / جامعة */

    /** @return array{role:string,universityId:?int}|null  admin = الكل (universityId=null) */
    private function scope(Request $r): ?array
    {
        $role = (string) $r->attributes->get('uip_role');
        if ($role === 'admin') {
            return ['role' => 'admin', 'universityId' => null];
        }
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $this->uid($r))->value('id');
            return $id ? ['role' => 'university', 'universityId' => (int) $id] : null;
        }
        return null;
    }

    private function isMember(int $userId, int $universityId): bool
    {
        return DB::table('students')->where('user_id', $userId)->where('university_id', $universityId)->exists()
            || DB::table('academic_staff')->where('user_id', $userId)->where('university_id', $universityId)->exists()
            || DB::table('faculties')->where('user_id', $userId)->where('university_id', $universityId)->exists();
    }

    public function loginHistory(Request $r)
    {
        $s = $this->scope($r);
        if (!$s) {
            return $this->apiError('Platform or university admins only.', null, 403);
        }
        $d = $this->history->search($r->query(), $s['universityId'], (int) $r->query('page', 1), (int) $r->query('per_page', 25));
        return $this->apiSuccess($d['items'], 'OK', 200, $d['meta']);
    }

    public function revokeUserTokens(Request $r, int $id)
    {
        $s = $this->scope($r);
        if (!$s) {
            return $this->apiError('Platform or university admins only.', null, 403);
        }
        if (!DB::table('users')->where('id', $id)->exists()) {
            return $this->apiError('User not found.', null, 404);
        }
        if ($s['universityId'] !== null && !$this->isMember($id, $s['universityId'])) {
            return $this->apiError('That user is not a member of your university.', null, 403);
        }
        if ($id === $this->uid($r)) {
            return $this->apiError('Use "sign out of all devices" for your own account.', null, 422);
        }
        $res = $this->revoker->revokeAll($id, 'revoked_by_admin', $this->uid($r));
        return $this->apiSuccess($res, 'All sessions and tokens of this user were revoked.');
    }
}
