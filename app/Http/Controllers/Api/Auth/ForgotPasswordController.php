<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailService;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * يطابق ForgotPasswordController::submit() + AuthService::createPasswordResetToken() القديمين.
 * ⚠️ عن قصد الرد دايمًا بنجاح بغض النظر هل الإيميل موجود ولا لأ (منع user enumeration).
 *
 * بيبعت رمز OTP (6 أرقام) على الإيميل عبر MailService::sendPasswordResetOtp()، والصفحة بتاخد الرمز + كلمة السر الجديدة،
 * وأي فشل في الإرسال بيتسجل في الـ log بس (الرد للمستخدم ثابت).
 */
class ForgotPasswordController extends Controller
{
    public const OTP_PURPOSE = 'password_reset';
    public const RESEND_AFTER = OtpService::RESEND_AFTER_SECONDS;

    public function __construct(private MailService $mail)
    {
    }

    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = $request->input('email');

        // Per-address cooldown that exists for EVERY address (known or not), so the answer — including
        // `retry_after` — is identical either way and reveals nothing. Cache::add is atomic: only one of
        // several simultaneous requests starts a new window (and may send); the others just get the time left.
        $cooldownKey = 'pw_reset_cd:' . sha1(mb_strtolower(trim((string) $email)));
        $now = time();
        $started = Cache::add($cooldownKey, $now + self::RESEND_AFTER, self::RESEND_AFTER);
        $retryAfter = $started ? self::RESEND_AFTER : max(1, (int) Cache::get($cooldownKey, $now + 1) - $now);

        $user = $started ? User::where('email', $email)->first() : null;

        if ($user) {
            $otp = app(OtpService::class);
            // برضه مفيش إرسال أسرع من مرة كل 60 ثانية لنفس الحساب (والرد بيفضل ثابت).
            if ($otp->secondsUntilResend(self::OTP_PURPOSE, (int) $user->id) === 0) {
                $code = $otp->issue(self::OTP_PURPOSE, (int) $user->id);
                if ($code === '') {
                    // محظور مؤقتًا (OtpGuard): مفيش إيميل، والرد بيفضل ثابت عشان مفيش تسريب.
                    return response()->json([
                        'success' => true,
                        'message' => 'If an account with that email exists, a reset code has been sent.',
                        'retry_after' => $retryAfter,
                        'data' => ['retry_after' => $retryAfter],
                    ]);
                }

                $sent = $this->mail->sendPasswordResetOtp(
                    (string) $user->email,
                    (string) ($user->full_name ?? ''),
                    $code,
                    OtpService::TTL_MINUTES,
                    $request->ip(),
                    (string) ($user->preferred_language ?? 'ar')
                );

                if (!$sent) {
                    $otp->forget(self::OTP_PURPOSE, (int) $user->id);
                    Log::error('Password reset email failed', ['email' => $email, 'error' => $this->mail->getLastError()]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a reset code has been sent.',
            'retry_after' => $retryAfter,
            'data' => ['retry_after' => $retryAfter],
        ]);
    }
}
