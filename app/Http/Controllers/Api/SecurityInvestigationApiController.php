<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityInvestigationService;
use Illuminate\Http\Request;

/**
 * /api/v1/security/investigate/* — مركز التحقيق في حساب (بند 2).
 *
 * القراءة + إنهاء الجلسات: security_admin / security_officer / admin (نفس صلاحية صفحة Sessions).
 * القفل / الفتح / إعادة ضبط MFA / طلب تغيير كلمة المرور / حظر IP: security_admin / admin بس.
 * حسابات admin / security_admin: admin بس يقدر يقفلها أو يصفّر MFA بتاعها. ومحدش يعمل ده على حسابه هو.
 */
class SecurityInvestigationApiController extends Controller
{
    public function __construct(private SecurityInvestigationService $svc)
    {
    }

    /** GET /users?q= */
    public function search(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can investigate accounts.', null, 403);
        }
        return $this->apiSuccess($this->svc->search((string) $request->input('q', '')), 'Users retrieved successfully.');
    }

    /** GET /users/{id} */
    public function show(Request $request, $id)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can investigate accounts.', null, 403);
        }
        $profile = $this->svc->profile((int) $id);
        if (!$profile) {
            return $this->apiError('User not found.', null, 404);
        }
        $profile['can_act'] = $this->canAct($request);
        $profile['can_end_sessions'] = true;
        $profile['viewer_is_self'] = (int) $id === $this->actorId($request);
        return $this->apiSuccess($profile, 'Account profile retrieved successfully.');
    }

    /** POST /users/{id}/revoke-sessions { note? } */
    public function revokeSessions(Request $request, $id)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can end sessions.', null, 403);
        }
        // إنهاء جلسات حساب تاني آمن للكل؛ لكن مينفعش حد ينهي جلساته هو بالغلط وهو بيحقق.
        if ($err = $this->svc->guard((int) $id, $this->actorId($request), $this->role($request), false)) {
            return $this->apiError($err, null, 404);
        }
        if ((int) $id === $this->actorId($request)) {
            return $this->apiError('Use the normal sign-out for your own account.', null, 422);
        }
        $res = $this->svc->revokeSessions((int) $id, $this->actorId($request), $request->ip(), $this->note($request));
        return $this->apiSuccess($res, "Ended {$res['sessions']} session(s).");
    }

    /** POST /users/{id}/lock { reason, minutes (0 = permanent), end_sessions } */
    public function lock(Request $request, $id)
    {
        if (!$this->canAct($request)) {
            return $this->apiError('Only Security Administrators can lock accounts.', null, 403);
        }
        if ($err = $this->svc->guard((int) $id, $this->actorId($request), $this->role($request), true)) {
            return $this->apiError($err, null, $err === 'User not found.' ? 404 : 403);
        }
        $reason = trim((string) $request->input('reason', ''));
        $minutes = (int) $request->input('minutes', 0);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 250) {
            return $this->apiError('A reason is required (3–250 characters).', ['reason' => ['A reason is required (3–250 characters).']], 422);
        }
        if ($minutes < 0 || $minutes > 525600) {
            return $this->apiError('Lock duration must be 0 (permanent) to 525600 minutes.', ['minutes' => ['Invalid duration.']], 422);
        }
        $res = $this->svc->lock((int) $id, $this->actorId($request), $minutes, $reason, $this->bool($request, 'end_sessions', true), $request->ip());
        return $this->apiSuccess($res, 'Account locked.');
    }

    /** POST /users/{id}/unlock */
    public function unlock(Request $request, $id)
    {
        if (!$this->canAct($request)) {
            return $this->apiError('Only Security Administrators can unlock accounts.', null, 403);
        }
        if ($err = $this->svc->guard((int) $id, $this->actorId($request), $this->role($request), false)) {
            return $this->apiError($err, null, 404);
        }
        $this->svc->unlock((int) $id, $this->actorId($request), $request->ip());
        return $this->apiSuccess(null, 'Account unlocked.');
    }

    /** POST /users/{id}/reset-mfa { end_sessions } */
    public function resetMfa(Request $request, $id)
    {
        if (!$this->canAct($request)) {
            return $this->apiError('Only Security Administrators can reset MFA.', null, 403);
        }
        if ($err = $this->svc->guard((int) $id, $this->actorId($request), $this->role($request), true)) {
            return $this->apiError($err, null, $err === 'User not found.' ? 404 : 403);
        }
        $res = $this->svc->resetMfa((int) $id, $this->actorId($request), $this->bool($request, 'end_sessions', true), $request->ip());
        return $this->apiSuccess($res, 'MFA was reset.');
    }

    /** POST /users/{id}/require-password-change { end_sessions } */
    public function requirePasswordChange(Request $request, $id)
    {
        if (!$this->canAct($request)) {
            return $this->apiError('Only Security Administrators can require a password change.', null, 403);
        }
        if ($err = $this->svc->guard((int) $id, $this->actorId($request), $this->role($request), true)) {
            return $this->apiError($err, null, $err === 'User not found.' ? 404 : 403);
        }
        $res = $this->svc->requirePasswordChange((int) $id, $this->actorId($request), $this->bool($request, 'end_sessions', true), $request->ip());
        return $this->apiSuccess($res, 'The user must change the password at next sign-in.');
    }

    /** POST /users/{id}/block-ip { ip, minutes } */
    public function blockIp(Request $request, $id)
    {
        if (!$this->canAct($request)) {
            return $this->apiError('Only Security Administrators can block IP addresses.', null, 403);
        }
        if ($err = $this->svc->guard((int) $id, $this->actorId($request), $this->role($request), false)) {
            return $this->apiError($err, null, 404);
        }
        $minutes = (int) $request->input('minutes', 1440);
        if ($minutes < 0 || $minutes > 525600) {
            return $this->apiError('Block duration must be 0 (permanent) to 525600 minutes.', null, 422);
        }
        $err = $this->svc->blockIp((int) $id, $this->actorId($request), (string) $request->input('ip', ''), $minutes, $request->ip());
        return $err ? $this->apiError($err, null, 422) : $this->apiSuccess(null, 'IP address blocked.');
    }

    // -- helpers --------------------------------------------------------------

    private function role(Request $r): string
    {
        return (string) $r->attributes->get('uip_role');
    }

    private function actorId(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function isStaff(Request $r): bool
    {
        return in_array($this->role($r), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function canAct(Request $r): bool
    {
        return in_array($this->role($r), ['security_admin', 'admin'], true);
    }

    private function note(Request $r): ?string
    {
        $n = trim((string) $r->input('note', ''));
        return $n === '' ? null : mb_substr($n, 0, 250);
    }

    private function bool(Request $r, string $key, bool $default): bool
    {
        return $r->has($key) ? filter_var($r->input($key), FILTER_VALIDATE_BOOLEAN) : $default;
    }
}
