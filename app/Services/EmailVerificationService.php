<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تأكيد ملكية الإيميل عند التسجيل بالباسورد.
 *
 * الحساب بيتعمل بحالة `pending` ومبيقدرش يدخل لحد ما:
 *   - صاحب الإيميل يكتب رمز الـ OTP اللي بيوصله على إيميله (VerifyEmailController::submit)، أو
 *   - الأدمن يفعّل الحساب بنفسه (Admin → Users → Activate)، أو
 *   - صاحب الإيميل يثبت ملكيته بجوجل أو بـ "نسيت كلمة السر".
 * ده بيمنع حد يسجّل بإيميل شخص تاني ويستخدم الحساب.
 */
class EmailVerificationService
{
    public const OTP_PURPOSE = 'signup_verify';
    /** أقل فترة بين إيميلين تأكيد لنفس الحساب (ثواني) — يمنع إغراق صندوق حد بالرسائل. */
    public const RESEND_COOLDOWN = 60;

    public function __construct(private MailService $mail)
    {
    }

    /** هل الحساب محتاج تأكيد إيميل/تفعيل أدمن قبل ما يدخل؟ */
    public static function blocksLogin(object $user): bool
    {
        return ($user->status ?? null) === 'pending' && empty($user->email_verified_at);
    }

    /**
     * يبعت رمز OTP جديد (6 أرقام) لتأكيد الإيميل ويلغي أي رمز/لينك قديم. يرجع true لو الإيميل اتبعت فعلًا.
     * (الاسم issueAndSend فضل زي ما هو عشان كل الكولرز القدام يفضلوا شغالين.)
     */
    public function issueAndSend(User $user): bool
    {
        // أي لينكات تأكيد قديمة تتقفل: التأكيد دلوقتي بالرمز بس.
        DB::table('email_verification_tokens')
            ->where('user_id', $user->id)->where('purpose', 'signup')->whereNull('used_at')
            ->update(['used_at' => now()]);

        $otp = app(OtpService::class);
        $code = $otp->issue(self::OTP_PURPOSE, (int) $user->id);
        if ($code === '') {
            return false; // الحساب محظور مؤقتًا من طلب رموز (OtpGuard)
        }

        $sent = $this->mail->sendEmailVerificationOtp(
            (string) $user->email,
            (string) ($user->full_name ?? ''),
            $code,
            OtpService::TTL_MINUTES,
            (string) ($user->preferred_language ?? 'ar')
        );
        if (!$sent) {
            $otp->forget(self::OTP_PURPOSE, (int) $user->id);
            Log::error('Verification email failed', ['user_id' => $user->id, 'error' => $this->mail->getLastError()]);
        }

        return $sent;
    }

    /** آخر مرة اتبعت فيها لينك لحساب ده (للـ cooldown). */
    public function lastSentAt(int $userId): ?\Illuminate\Support\Carbon
    {
        $wait = app(OtpService::class)->secondsUntilResend(self::OTP_PURPOSE, $userId);

        return $wait > 0 ? now()->subSeconds(OtpService::RESEND_AFTER_SECONDS - $wait) : null;
    }

    /** يثبّت إن الإيميل ملك صاحبه ويفعّل الحساب لو كان pending. */
    public function markVerified(int $userId): void
    {
        // مكتوبة بدون دوال SQL خاصة بقاعدة بيانات بعينها (تشتغل على MySQL و SQLite الاختبارات).
        DB::table('users')->where('id', $userId)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        DB::table('users')->where('id', $userId)->where('status', 'pending')->update(['status' => 'active']);
        DB::table('users')->where('id', $userId)->update(['updated_at' => now()]);
        DB::table('email_verification_tokens')
            ->where('user_id', $userId)->where('purpose', 'signup')->whereNull('used_at')
            ->update(['used_at' => now()]);
        app(OtpService::class)->forget(self::OTP_PURPOSE, $userId);
    }
}
