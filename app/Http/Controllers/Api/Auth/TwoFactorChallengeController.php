<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\RoleService;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorService;
use App\Services\UipJwtService;
use App\Support\AuthCookies;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * يطابق TwoFactorChallengeController القديمة — بفرق تصميم واحد **متعمّد
 * وموثّق** (شوف "⚠️ فرق عن العقد الأصلي" في AUTH_API_CONTRACT.md):
 *
 * القديم بيعتمد على PHP session (`_2fa_pending_user_id`) عشان يعرف مين
 * اللي بيكمّل الـ 2FA. إحنا هنا API بالكامل Stateless (JWT بس، من غير
 * session cookie)، فمفيش حاجة تتخزن على السيرفر بين خطوة اللوجن وخطوة
 * التحقق. البديل: اللوجن بيرجّع `challenge_token` (JWT قصير العمر — 5
 * دقايق، claim خاص `typ=2fa_challenge` عشان محدش يستخدمه كـ access token
 * عادي)، والفرونت لازم يبعته تاني هنا. ده أكتر أمانًا من إنه يبعت الـ
 * user_id نفسه في الـ body (اللي كان ممكن يتلاعب بيه).
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private RoleService $roles,
        private TwoFactorService $twoFactor,
        private TrustedDeviceService $trustedDevice,
        private AccountLockoutService $lockout
    ) {
    }

    /** POST /api/v1/auth/two-factor/verify */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'challenge_token' => 'required|string',
            'code'            => 'required|string',
            'remember_device' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $claims = UipJwtService::decode($request->input('challenge_token'));

        if (!$claims || ($claims['typ'] ?? null) !== '2fa_challenge' || empty($claims['sub'])) {
            return $this->apiError('No two-factor challenge is pending. Please log in again.', null, 409);
        }

        $user = User::find($claims['sub']);
        if (!$user) {
            return $this->apiError('Session expired. Please log in again.', null, 401);
        }

        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        if ($block = app(\App\Services\UserSessionService::class)->loginBlock((int) $user->id, $locale, (string) ($claims['role'] ?? $this->roles->primaryRoleFor($user->id)))) {
            return response()->json(['success' => false, 'message' => $block[0], 'data' => $block[1], 'errors' => null, 'meta' => (object) []], 409);
        }

        // A 6-digit code can be brute-forced, so wrong codes are counted per ACCOUNT (not per IP, so
        // rotating IPs doesn't help): 5 wrong codes lock 2FA for this account for 15 minutes.
        $limiterKey = '2fa_fail:' . (int) $user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $wait = RateLimiter::availableIn($limiterKey);
            SecurityLog::write('2FA blocked - too many wrong codes', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return $this->apiError(
                $locale === 'ar'
                    ? 'محاولات كتير غلط. حاول تاني بعد ' . (int) ceil($wait / 60) . ' دقيقة.'
                    : 'Too many wrong codes. Try again in ' . (int) ceil($wait / 60) . ' minute(s).',
                ['code' => ['two_factor_locked'], 'retry_after' => $wait],
                429
            );
        }

        if (!$this->twoFactor->verifyLoginCode($user, (string) $request->input('code'))) {
            RateLimiter::hit($limiterKey, 15 * 60);
            SecurityLog::write('2FA wrong code', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return $this->apiError('Invalid or expired code. Please try again.', ['code' => ['Invalid or expired code.']], 422);
        }
        RateLimiter::clear($limiterKey);

        $role = (string) ($claims['role'] ?? $this->roles->primaryRoleFor($user->id));

        $trustCookie = null;
        if ($request->boolean('remember_device')) {
            $trustCookie = $this->trustedDevice->trustCurrentDevice($user->id, $request->ip(), $request->userAgent());
        }

        $this->lockout->registerSuccessfulLogin($user);
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $tokens = UipJwtService::issueTokenPair($user->id, $role);

        SecurityLog::write('Successful login (2FA)', ['user_id' => $user->id, 'ip' => $request->ip()]);

        $response = AuthCookies::attach($this->apiSuccess(
            array_merge(['redirect' => $this->roles->homeRouteFor($role)], $tokens),
            'Two-factor verification successful.'
        ), $request);

        // كوكي "الجهاز الموثوق" لازم تتحط على الرد صراحةً (الـ api group مفيهوش queued cookies).
        if ($trustCookie) {
            $response->headers->setCookie($trustCookie);
        }

        return $response;
    }

    /** POST /api/v1/auth/two-factor/cancel */
    public function cancel(Request $request)
    {
        // Stateless: مفيش حاجة متخزنة على السيرفر تتلغى (القديم كان بيمسح
        // مفاتيح الـ session بس) — الـ challenge_token نفسه هيخلص لوحده
        // بعد 5 دقايق. الرد دايمًا نجاح، زي القديم بالظبط.
        return $this->apiSuccess(null, 'Two-factor challenge cancelled.');
    }
}
