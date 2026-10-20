<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\AuditLogService;
use App\Services\EmailVerificationService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /auth/activate-without-email { email, password }
 *
 * خروج طوارئ لما لينك التأكيد مش بيوصل (SMTP مش متظبط / الإيميل مش متاح لصاحبه): بيفعّل حساب
 * pending لصاحب الباسورد الصح من غير ما يضغط لينك على إيميله.
 *
 * ⚠️ ده بيتخطى إثبات ملكية الإيميل، فمتحكّم فيه بـ EMAIL_ACTIVATION_FALLBACK في .env:
 *   - always (الافتراضي): متاح دايمًا لصاحب الباسورد الصح.
 *   - outage : متاح بس لو إرسال إيميل التأكيد فعليًا فشل دلوقتي (المزوّد مش شغال/مش متظبط)؛
 *              لو الإيميل اتبعت بنجاح بيتبعت ومبيتفعّلش.
 *   - off    : متعطّل خالص (الـ endpoint بيرجع 403).
 * لإنتاج بيعتمد على إثبات الإيميل: خليه outage أو off.
 */
class ActivateWithoutEmailController extends Controller
{
    public function __construct(
        private EmailVerificationService $verification,
        private AccountLockoutService $lockout,
        private AuditLogService $audit
    ) {
    }

    public static function mode(): string
    {
        $m = strtolower((string) config('security.email_activation_fallback', 'always'));

        return in_array($m, ['always', 'outage', 'off'], true) ? $m : 'always';
    }

    public function submit(Request $request)
    {
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        $msg = fn (string $ar, string $en) => $locale === 'ar' ? $ar : $en;

        if (self::mode() === 'off') {
            return response()->json(['success' => false, 'message' => $msg('تفعيل الحساب بدون إيميل غير متاح حاليًا.', 'Activating without email is not available.')], 403);
        }

        $v = Validator::make($request->all(), ['email' => 'required|email', 'password' => 'required']);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower((string) $request->input('email'))])->first();
        $invalid = response()->json(['success' => false, 'message' => 'Invalid email or password.'], 422);

        if (!$user) {
            return $invalid;
        }

        // نفس سياسة القفل بتاعة اللوجين: الـ endpoint ده مينفعش يبقى باب لتخمين الباسورد.
        $lock = $this->lockout->checkStatus($user, $locale);
        if ($lock['locked']) {
            return response()->json(['success' => false, 'message' => $lock['message']], 422);
        }

        if (!password_verify((string) $request->input('password'), (string) $user->password_hash)) {
            $attempt = $this->lockout->registerFailedAttempt($user, (string) $request->ip(), $locale);
            if ($attempt['just_locked']) {
                return response()->json(['success' => false, 'message' => $attempt['message']], 422);
            }
            return $invalid;
        }

        if (!EmailVerificationService::blocksLogin($user)) {
            return response()->json(['success' => false, 'message' => $msg('الحساب ده مش محتاج تفعيل.', 'This account does not need activation.')], 422);
        }

        if (self::mode() === 'outage') {
            // لو الإيميل اتبعت فعلًا يبقى مفيش عطل: نخليه يأكّد من إيميله.
            if ($this->verification->issueAndSend($user)) {
                return response()->json([
                    'success' => true,
                    'message' => $msg('بعتنا لك رمز التأكيد على بريدك. شوف الوارد والرسائل غير المرغوبة.', 'We sent the confirmation code. Check your inbox and spam.'),
                    'data'    => ['activated' => false, 'email_sent' => true],
                ]);
            }
        }

        $this->verification->markVerified((int) $user->id);
        SecurityLog::write('Account activated without email confirmation (fallback)', ['user_id' => $user->id, 'ip' => $request->ip(), 'mode' => self::mode()]);
        try {
            $this->audit->record((int) $user->id, 'auth.email_activation_fallback', 'User', (int) $user->id, null, ['mode' => self::mode()], $request->ip());
        } catch (\Throwable $e) {
            // التدقيق مينفعش يوقف التفعيل
        }

        return response()->json([
            'success' => true,
            'message' => $msg('تم تفعيل حسابك. تقدر تسجّل الدخول دلوقتي.', 'Your account is activated. You can sign in now.'),
            'data'    => ['activated' => true, 'email_sent' => false],
        ]);
    }
}
