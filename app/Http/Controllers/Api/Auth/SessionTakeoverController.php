<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\MailService;
use App\Services\PasswordPolicyService;
use App\Services\RoleService;
use App\Services\SecurityAlertService;
use App\Services\TrustedDeviceService;
use App\Services\UipJwtService;
use App\Services\UserSessionService;
use App\Support\AuthCookies;
use App\Support\OtpGuard;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * "الحساب مفتوح في مكان تاني وده مش أنا" — استرجاع الحساب من حد مخترقه.
 *
 *  1) الدخول بيترفض بـ 409 account_in_use وبيرجّع takeover_token (15 دقيقة، بيثبت إن
 *     كلمة السر اتكتبت صح).
 *  2) POST /auth/session-takeover/send   → رمز OTP من 8 أرقام على إيميل الحساب (صالح 10 دقايق).
 *  3) POST /auth/session-takeover/verify → لو الرمز صح: كلمة سر جديدة (بنفس سياسة كلمات السر)،
 *     كل الجلسات التانية بتتقفل وكل refresh tokens وأجهزة "الجهاز الموثوق" بتتلغي،
 *     وصاحب الحساب بيدخل فورًا.
 *
 * الرمز بيتخزن مهشّر (HMAC) في الـ cache، 5 محاولات غلط بس بعدها بيبطل، وإعادة الإرسال كل 60 ثانية.
 */
class SessionTakeoverController extends Controller
{
    private const OTP_TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_AFTER_SECONDS = 60;

    public function __construct(
        private MailService $mail,
        private PasswordPolicyService $passwordPolicy,
        private RoleService $roles,
        private UserSessionService $sessions,
        private TrustedDeviceService $trustedDevice,
        private SecurityAlertService $alerts
    ) {
    }

    /**
     * POST /api/v1/auth/session-takeover/release — { takeover_token, session_id }
     * وضع "منع الدخول الجديد": صاحب الحساب (كلمة السر اتكتبت صح = takeover_token) يسجّل خروج من
     * جهاز معيّن من القائمة اللي رجعت في رد الدخول، وبعدها يجرّب يدخل تاني. بيقفل الجلسة دي بس.
     */
    public function release(Request $request)
    {
        $user = $this->userFromToken($request);
        if (!$user) {
            return $this->apiError($this->t($request, 'انتهت صلاحية الطلب. حاول تسجّل الدخول من جديد.', 'This request expired. Please try signing in again.'), null, 401);
        }
        $sid = (int) $request->input('session_id', 0);
        $owned = $sid > 0 && DB::table('user_sessions')->where('id', $sid)->where('user_id', $user->id)->where('is_active', 1)->exists();
        if (!$owned) {
            return $this->apiError($this->t($request, 'الجهاز ده مش موجود أو اتسجّل منه خروج بالفعل.', 'That device was not found or is already signed out.'), null, 404);
        }
        $this->sessions->end($sid, 'released_at_login', (int) $user->id);
        SecurityLog::write('Device signed out from the device-limit screen', ['user_id' => $user->id, 'session_id' => $sid, 'ip' => $request->ip()]);

        return $this->apiSuccess(['devices_left' => count($this->sessions->activeDevices((int) $user->id))], 'Device signed out.');
    }

    /** POST /api/v1/auth/session-takeover/send */
    public function send(Request $request)
    {
        $user = $this->userFromToken($request);
        if (!$user) {
            return $this->apiError($this->t($request, 'انتهت صلاحية الطلب. حاول تسجّل الدخول من جديد.', 'This request expired. Please try signing in again.'), null, 401);
        }

        $key = $this->cacheKey((int) $user->id);
        $guard = 'takeover:' . (int) $user->id;
        if ($left = OtpGuard::blockedSeconds($guard)) {
            return $this->apiError(OtpGuard::message($this->locale($request) === 'ar', $left), ['code' => 'otp_blocked', 'retry_after' => $left], 429);
        }
        $state = Cache::get($key);
        if ($state && (time() - (int) ($state['sent_at'] ?? 0)) < self::RESEND_AFTER_SECONDS) {
            $wait = self::RESEND_AFTER_SECONDS - (time() - (int) $state['sent_at']);
            return $this->apiError($this->t($request, "استنى {$wait} ثانية قبل إعادة الإرسال.", "Please wait {$wait} seconds before requesting another code."), ['retry_after' => $wait], 429);
        }
        // أكتر من حد الرموز في الساعة => حظر (بدل طلب رمز جديد للأبد).
        if ($left = OtpGuard::registerSend($guard)) {
            return $this->apiError(OtpGuard::message($this->locale($request) === 'ar', $left), ['code' => 'otp_blocked', 'retry_after' => $left], 429);
        }

        $otp = (string) random_int(10000000, 99999999);
        Cache::put($key, [
            'hash'     => $this->hashOtp((int) $user->id, $otp),
            'attempts' => 0,
            'sent_at'  => time(),
        ], now()->addMinutes(self::OTP_TTL_MINUTES));

        $locale = $this->locale($request);
        $sent = $this->mail->sendSessionTakeoverOtp((string) $user->email, (string) ($user->full_name ?? ''), $otp, self::OTP_TTL_MINUTES, $request->ip(), $locale);
        if (!$sent) {
            Cache::forget($key);
            return $this->apiError($this->t($request, 'تعذّر إرسال الرمز حاليًا. حاول بعد شوية.', 'We could not send the code right now. Please try again shortly.'), null, 502);
        }

        SecurityLog::write('Session takeover OTP sent', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $this->apiSuccess(
            ['email' => $this->maskEmail((string) $user->email), 'expires_in_minutes' => self::OTP_TTL_MINUTES, 'resend_after' => self::RESEND_AFTER_SECONDS],
            'Verification code sent.'
        );
    }

    /** POST /api/v1/auth/session-takeover/verify */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'takeover_token' => 'required|string',
            'otp'            => 'required|string',
            'password'       => 'required|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), null, 422);
        }

        $user = $this->userFromToken($request);
        if (!$user) {
            return $this->apiError($this->t($request, 'انتهت صلاحية الطلب. حاول تسجّل الدخول من جديد.', 'This request expired. Please try signing in again.'), null, 401);
        }

        $key = $this->cacheKey((int) $user->id);
        $guard = 'takeover:' . (int) $user->id;
        if ($left = OtpGuard::blockedSeconds($guard)) {
            Cache::forget($key);
            return $this->apiError(OtpGuard::message($this->locale($request) === 'ar', $left), ['code' => 'otp_blocked', 'retry_after' => $left], 429);
        }
        $state = Cache::get($key);
        if (!$state) {
            return $this->apiError($this->t($request, 'الرمز منتهي أو لم يُرسل. اطلب رمز جديد.', 'The code expired or was not requested. Request a new one.'), ['code' => 'otp_expired'], 422);
        }
        if ((int) ($state['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return $this->apiError($this->t($request, 'محاولات كتير غلط. اطلب رمز جديد.', 'Too many wrong attempts. Request a new code.'), ['code' => 'otp_locked'], 429);
        }

        $given = preg_replace('/\D+/', '', (string) $request->input('otp'));
        if (!hash_equals((string) $state['hash'], $this->hashOtp((int) $user->id, (string) $given))) {
            $state['attempts'] = (int) $state['attempts'] + 1;
            if ($blockedFor = OtpGuard::recordFailure($guard)) {
                Cache::forget($key);
                SecurityLog::write('Session takeover OTP blocked', ['user_id' => $user->id, 'ip' => $request->ip()]);
                return $this->apiError(OtpGuard::message($this->locale($request) === 'ar', $blockedFor), ['code' => 'otp_blocked', 'retry_after' => $blockedFor], 429);
            }
            Cache::put($key, $state, now()->addMinutes(self::OTP_TTL_MINUTES));
            SecurityLog::write('Session takeover OTP wrong', ['user_id' => $user->id, 'ip' => $request->ip(), 'attempt' => $state['attempts']]);
            return $this->apiError($this->t($request, 'الرمز غير صحيح.', 'The code is incorrect.'), ['code' => 'otp_invalid'], 422);
        }

        $password = (string) $request->input('password');
        if (password_verify($password, (string) $user->password_hash)) {
            return $this->apiError($this->t($request, 'اختار كلمة سر جديدة مختلفة عن الحالية.', 'Choose a new password that differs from the current one.'), null, 422);
        }
        try {
            $this->passwordPolicy->assertValid($password);
            $this->passwordPolicy->assertNotReused($user->id, $password);
        } catch (\InvalidArgumentException $e) {
            // الرمز صح لكن كلمة السر مرفوضة: منحرقش الرمز، يجرّب كلمة سر تانية.
            return $this->apiError($e->getMessage(), null, 422);
        }

        if (in_array($user->status, ['suspended', 'banned'], true)) {
            return $this->apiError('This account has been ' . $user->status . '. Contact support.', null, 422);
        }

        Cache::forget($key); // الرمز يُستخدم مرة واحدة
        OtpGuard::recordSuccess($guard);

        $newHash = password_hash($password, PASSWORD_BCRYPT);
        DB::table('users')->where('id', $user->id)->update(['password_hash' => $newHash, 'updated_at' => now()]);
        $this->passwordPolicy->recordPasswordChange($user->id, $newHash);

        // طرد أي حد تاني: الجلسات + refresh tokens + الأجهزة الموثوقة + أي روابط reset قديمة.
        $this->sessions->endAllForUser((int) $user->id, 'takeover_reclaimed');
        RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->trustedDevice->revokeAllForUser((int) $user->id);
        DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        app(\App\Services\OtpService::class)->forget(ForgotPasswordController::OTP_PURPOSE, (int) $user->id);

        SecurityLog::write('Account reclaimed via session takeover OTP', ['user_id' => $user->id, 'ip' => $request->ip()]);
        $this->alerts->raise(
            'session_takeover',
            'high',
            'Account reclaimed after suspected unauthorized access',
            'The owner proved control of the email with an OTP, changed the password and all other sessions were ended.',
            $request->ip(),
            (int) $user->id,
            [],
            1
        );

        // دخول صاحب الحساب فورًا بنفس شكل رد /auth/login.
        $role = $this->roles->primaryRoleFor($user->id);
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);
        $tokens = UipJwtService::issueTokenPair((int) $user->id, $role);

        return AuthCookies::attach(response()->json([
            'success' => true,
            'message' => 'Account recovered.',
            'data'    => array_merge(['redirect' => $this->roles->homeRouteFor($role)], $tokens),
            'errors'  => null,
            'meta'    => (object) [],
        ]), $request);
    }

    private function userFromToken(Request $request): ?User
    {
        $claims = UipJwtService::decode((string) $request->input('takeover_token', ''));
        if (!$claims || ($claims['typ'] ?? null) !== 'session_takeover' || empty($claims['sub'])) {
            return null;
        }
        return User::find((int) $claims['sub']);
    }

    private function cacheKey(int $userId): string
    {
        return 'session_takeover_otp:' . $userId;
    }

    private function hashOtp(int $userId, string $otp): string
    {
        return hash_hmac('sha256', $userId . ':' . $otp, (string) config('app.key'));
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));
        return $visible . str_repeat('*', max(3, mb_strlen($name) - mb_strlen($visible))) . '@' . $domain;
    }

    private function locale(Request $request): string
    {
        return $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
    }

    private function t(Request $request, string $ar, string $en): string
    {
        return $this->locale($request) === 'ar' ? $ar : $en;
    }
}
