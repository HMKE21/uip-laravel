<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountMergeService;
use App\Services\AccountRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * استرجاع الحساب المحذوف / دمج بياناته (بإثبات ملكية الإيميل بـ OTP).
 *
 * عام (من غير توكن):
 *  POST /auth/account-recovery/check    { email }  -> { state, can_self_restore, ... }
 *  POST /auth/account-recovery/send     { email }  -> OTP على الإيميل الأصلي
 *  POST /auth/account-recovery/restore  { email, otp, password, password_confirmation }
 * بتوكن (الإيميل اتسجّل بحساب جديد فعلًا):
 *  GET  /auth/account-recovery/merge/candidates
 *  POST /auth/account-recovery/merge/send     { deleted_uuid }
 *  POST /auth/account-recovery/merge/confirm  { deleted_uuid, otp, groups[] }
 */
class AccountRecoveryController extends Controller
{
    public function __construct(private AccountRecoveryService $recovery, private AccountMergeService $merger)
    {
    }

    public function check(Request $request)
    {
        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), null, 422);
        }
        $st = $this->recovery->state((string) $request->input('email'));

        return $this->apiSuccess([
            'state'            => $st['state'],
            'can_self_restore' => $st['state'] === AccountRecoveryService::STATE_RESTORABLE,
            'needs_login'      => $st['state'] === AccountRecoveryService::STATE_NEEDS_LOGIN,
            'reason'           => $st['code'],
            'deleted_at'       => $st['deleted']->deleted_at ?? null,
        ]);
    }

    public function send(Request $request)
    {
        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), null, 422);
        }

        return $this->respond($request, $this->recovery->sendRestoreOtp((string) $request->input('email'), $this->locale($request), $request->ip()), 'Verification code sent.');
    }

    public function restore(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email'    => 'required|email',
            'otp'      => 'required|string',
            'password' => 'required|min:8|confirmed',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), null, 422);
        }
        $res = $this->recovery->restoreWithOtp((string) $request->input('email'), (string) $request->input('otp'), (string) $request->input('password'), $request->ip());

        return $this->respond($request, $res, 'Your account was restored. You can sign in now.');
    }

    public function mergeCandidates(Request $request)
    {
        $user = $this->current($request);
        if (!$user) {
            return $this->apiError('Unauthorized.', null, 401);
        }

        return $this->apiSuccess([
            'candidates' => $this->recovery->mergeCandidates($user, $this->locale($request)),
            'groups'     => $this->merger->groups($this->locale($request)),
        ]);
    }

    public function mergeSend(Request $request)
    {
        $user = $this->current($request);
        if (!$user) {
            return $this->apiError('Unauthorized.', null, 401);
        }
        $v = Validator::make($request->all(), ['deleted_uuid' => 'required|string']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), null, 422);
        }

        return $this->respond($request, $this->recovery->sendMergeOtp($user, (string) $request->input('deleted_uuid'), $this->locale($request), $request->ip()), 'Verification code sent.');
    }

    public function mergeConfirm(Request $request)
    {
        $user = $this->current($request);
        if (!$user) {
            return $this->apiError('Unauthorized.', null, 401);
        }
        $v = Validator::make($request->all(), [
            'deleted_uuid' => 'required|string',
            'otp'          => 'required|string',
            'groups'       => 'nullable|array',
            'groups.*'     => 'string',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), null, 422);
        }
        $res = $this->recovery->mergeWithOtp($user, (string) $request->input('deleted_uuid'), (string) $request->input('otp'), $request->input('groups'), $request->ip());

        return $this->respond($request, $res, 'Your old account data was moved to this account.', $res['result'] ?? null);
    }

    // ------------------------------------------------------------------ helpers

    private function current(Request $request): ?object
    {
        $id = $request->attributes->get('uip_user_id');

        return $id ? User::find((int) $id) : null;
    }

    private function respond(Request $request, array $res, string $okMessage, $data = null)
    {
        if ($res['ok']) {
            $payload = array_intersect_key($res, array_flip(['email', 'expires_in_minutes', 'retry_after']));

            return $this->apiSuccess($data ?? ($payload ?: null), $okMessage);
        }
        $code = (string) $res['code'];
        $msg = $res['message'] ?? $this->message($request, $code);
        $errors = ['code' => $code] + (isset($res['retry_after']) ? ['retry_after' => $res['retry_after']] : []);

        return $this->apiError($msg, $errors, (int) $res['status']);
    }

    private function message(Request $request, string $code): string
    {
        $m = [
            'otp_invalid'          => ['الرمز غير صحيح.', 'The code is incorrect.'],
            'otp_expired'          => ['الرمز منتهي أو لم يُرسل. اطلب رمز جديد.', 'The code expired or was not requested. Request a new one.'],
            'otp_locked'           => ['محاولات كتير غلط. اطلب رمز جديد.', 'Too many wrong attempts. Request a new code.'],
            'otp_wait'             => ['استنى شوية قبل إعادة الإرسال.', 'Please wait before requesting another code.'],
            'mail_failed'          => ['تعذّر إرسال الرمز حاليًا. حاول بعد شوية.', 'We could not send the code right now. Please try again shortly.'],
            'not_found'            => ['مفيش حساب محذوف مطابق.', 'No matching deleted account.'],
            'needs_login_merge'    => ['الإيميل ده عليه حساب شغّال. سجّل الدخول بيه وبعدين استرجع بيانات حسابك القديم من الإعدادات.', 'This email already has an active account. Sign in with it, then recover your old data from settings.'],
            'needs_admin'          => ['استرجاع الحساب ده محتاج موافقة الإدارة. تواصل مع الدعم.', 'Restoring this account requires an administrator. Contact support.'],
            'role_not_allowed'     => ['نوع الحساب ده بيتسترجع عن طريق الإدارة بس. تواصل مع الدعم.', 'This account type can only be restored by an administrator. Contact support.'],
            'retention_expired'    => ['فات الوقت المسموح للاسترجاع الذاتي. تواصل مع الدعم.', 'The self-service recovery window has passed. Contact support.'],
            'self_recovery_disabled' => ['الاسترجاع الذاتي متوقف حاليًا. تواصل مع الدعم.', 'Self-service recovery is currently disabled. Contact support.'],
        ];
        $ar = $this->locale($request) === 'ar';

        return isset($m[$code]) ? $m[$code][$ar ? 0 : 1] : ($ar ? 'تعذّر تنفيذ الطلب.' : 'The request could not be completed.');
    }

    private function locale(Request $request): string
    {
        return $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
    }
}
