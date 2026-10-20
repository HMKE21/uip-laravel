<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Services\RoleService;
use App\Services\UipJwtService;
use App\Services\UserSessionService;
use App\Support\AuthCookies;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** يطابق RefreshTokenController::submit() + AuthService::refreshApiTokens() القديمين (rotation: توكن واحد الاستخدام + reuse detection). */
class RefreshTokenController extends Controller
{
    public function __construct(
        private RoleService $roles,
        private UserSessionService $sessions,
        private \App\Services\DeviceRestrictionPolicyService $deviceRestriction
    ) {
    }

    public function submit(Request $request)
    {
        // Device Restrictions Policy — جلسة على نوع جهاز محظور ماتقدرش تجدّد التوكن.
        if (!$this->deviceRestriction->isAllowed($request->userAgent())) {
            SecurityLog::write('Refresh blocked - device type restricted', [
                'device' => $this->deviceRestriction->classify($request->userAgent()), 'ip' => $request->ip(),
            ]);
            return AuthCookies::noStore($this->apiError(
                $this->deviceRestriction->blockedMessage($request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en'),
                ['code' => 'device_blocked'],
                403
            ));
        }

        // المصدر الأساسي: كوكي HttpOnly (مع فحص CSRF). الجسم (refresh_token) مقبول
        // بس كـ migration لجلسات قديمة كانت في localStorage — والرد بيحط كوكي
        // ويشيل التوكن من الجسم فالـ JS مبيشوفوش تاني.
        $raw = AuthCookies::refreshFromCookie($request);
        if ($raw !== '') {
            if ($reason = AuthCookies::csrfFailure($request)) {
                SecurityLog::write('Refresh rejected — CSRF check failed', ['reason' => $reason, 'ip' => $request->ip()]);
                return AuthCookies::noStore($this->apiError('Request could not be verified.', ['code' => $reason], 403));
            }
        } elseif (AuthCookies::allowBodyRefresh()) {
            $raw = (string) $request->input('refresh_token', '');
        }

        if ($raw === '') {
            return AuthCookies::clear($this->apiError('Not authenticated.', null, 401), $request);
        }

        $hash = hash('sha256', $raw);

        $row = RefreshToken::where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        // تسامح مع ضياع الرد: التوكن اتبدّل من أقل من دقيقة (العميل مستلمش الجديد، موبايل/شبكة قطعت) —
        // مش سرقة. بنكمّل على نفس الجلسة بدل ما نقفل كل جلسات الحساب ونطرد صاحبه من "دخوله الدائم".
        $graceSession = null;
        if (!$row) {
            $graceSession = $this->sessions->findByPrevHash($hash);
            if ($graceSession) {
                $row = RefreshToken::where('token_hash', $hash)->first();
            }
        }

        if (!$row) {
            // Reuse detection: لو التوكن ده كان صادر فعلاً بس اتلغى قبل كده
            // (rotated أو logout)، ده دليل إنه ممكن يكون سُرق — نلغي كل
            // refresh tokens المستخدم ده ونجبره يعمل login تاني من الصفر.
            $stale = RefreshToken::where('token_hash', $hash)->whereNotNull('revoked_at')->first();
            // توكن جلسة اتقفلت عمدًا (logout / جلسة واحدة لكل حساب / Revoke) مش سرقة: ده متصفح
            // قديم رجع يفتح بعد ما جهاز تاني دخل. نرفضه بس — من غير ما نقفل جلسة الجهاز الجديد.
            $endedSession = $stale ? $this->sessions->findByRefreshHash($hash) : null;
            if ($endedSession && !$endedSession->is_active) {
                return AuthCookies::clear($this->apiError('This session was ended. Please log in again.', null, 401), $request);
            }
            if ($stale) {
                RefreshToken::where('user_id', $stale->user_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $this->sessions->endAllForUser((int) $stale->user_id, 'token_reuse');
                SecurityLog::write('Refresh token reuse detected — revoking all sessions', ['user_id' => $stale->user_id]);
            }

            return AuthCookies::clear($this->apiError('This refresh token is invalid, expired, or has already been used.', null, 401), $request);
        }

        // حساب اتحذف/اتوقف/اتحظر => مفيش تجديد توكن.
        $account = DB::table('users')->where('id', $row->user_id)->first(['status', 'deleted_at']);
        if (!$account || $account->deleted_at !== null || in_array($account->status, ['suspended', 'banned'], true)) {
            $row->revoked_at = now();
            $row->save();
            return AuthCookies::clear($this->apiError('Your account is no longer active. Please contact platform support.', ['code' => 'account_inactive'], 401), $request);
        }

        // الجلسة اللي الـ refresh token ده تبعها: لو اتلغت من بورتال الأمان
        // نرفض الـ refresh فالمستخدم يتطرد فعلًا.
        $session = $graceSession ?: $this->sessions->findByRefreshHash($hash);
        if ($session && !$session->is_active) {
            $row->revoked_at = now();
            $row->save();
            return AuthCookies::clear($this->apiError('This session was ended. Please log in again.', null, 401), $request);
        }

        // خمول: لو الجلسة مفيهاش نشاط أكتر من المدة المسموحة، الـ refresh token
        // لوحده ميكفيش — بنقفل الجلسة والمستخدم يعمل login تاني.
        if ($session && $this->sessions->idleExpired($session)) {
            $this->sessions->end((int) $session->id, 'idle_timeout');
            $row->revoked_at = now();
            $row->save();
            return AuthCookies::clear($this->apiError('Your session expired due to inactivity. Please log in again.', null, 401), $request);
        }

        $role = $this->roles->effectiveRoleFor((int) $row->user_id, $session ? (int) $session->id : null);

        // Device Restrictions Policy (وضع by_role) — جلسة حساب دوره ممنوع على نوع الجهاز ده ماتتجددش.
        if (!$this->deviceRestriction->isAllowed($request->userAgent(), $role)) {
            SecurityLog::write('Refresh blocked - device type restricted for role', [
                'user_id' => $row->user_id, 'role' => $role,
                'device' => $this->deviceRestriction->classify($request->userAgent()), 'ip' => $request->ip(),
            ]);
            return AuthCookies::noStore($this->apiError(
                $this->deviceRestriction->blockedMessage($request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en', $role),
                ['code' => 'device_blocked', 'role' => $role, 'device_type' => $this->deviceRestriction->classify($request->userAgent())],
                403
            ));
        }

        $tokens = UipJwtService::issueTokenPair($row->user_id, $role, $session ? (int) $session->id : null);

        // إلغاء القديم وربطه بالجديد (rotation chain)
        $newest = RefreshToken::where('user_id', $row->user_id)->latest('id')->first();
        $row->revoked_at = now();
        $row->replaced_by_id = $newest?->id;
        $row->save();

        return AuthCookies::attach($this->apiSuccess($tokens, 'Token refreshed successfully.'), $request);
    }
}
