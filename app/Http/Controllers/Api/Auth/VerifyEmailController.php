<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\OtpService;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * تأكيد الإيميل بالرمز (OTP): POST /auth/verify-email { email, otp }.
 * handle() (GET + token) فاضلة بس عشان لينكات التأكيد اللي اتبعتت قبل التحويل للـ OTP — الرسايل الجديدة بتبعت رمز.
 */
class VerifyEmailController extends Controller
{
    public function handle(Request $request)
    {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return $this->apiError('This verification link is invalid or has expired.', null, 422);
        }

        $hash = hash('sha256', $token);

        $row = DB::table('email_verification_tokens')
            ->where('token_hash', $hash)
            ->where('purpose', 'signup')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return $this->apiError('This verification link is invalid or has expired.', null, 422);
        }

        // يثبّت الإيميل ويفعّل الحساب (pending -> active)، ويقفل باقي لينكات التأكيد.
        app(\App\Services\EmailVerificationService::class)->markVerified((int) $row->user_id);
        DB::table('email_verification_tokens')->where('id', $row->id)->update(['used_at' => now()]);

        return $this->apiSuccess(null, 'Email verified successfully.');
    }

    /** POST /api/v1/auth/verify-email — { email, otp } */
    public function submit(Request $request, OtpService $otp, EmailVerificationService $verification)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $v = Validator::make($request->all(), ['email' => 'required|email', 'otp' => 'required|string']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), null, 422);
        }

        $generic = $ar ? 'الرمز غير صحيح أو منتهي.' : 'The code is incorrect or expired.';
        $user = User::whereRaw('LOWER(email) = ?', [strtolower((string) $request->input('email'))])->first();
        if (!$user) {
            return $this->apiError($generic, ['code' => 'otp_invalid'], 422);
        }
        if (!EmailVerificationService::blocksLogin($user)) {
            // الحساب متأكد أصلًا.
            return $this->apiSuccess(['already_verified' => true], 'Email verified successfully.');
        }

        $result = $otp->check(EmailVerificationService::OTP_PURPOSE, (int) $user->id, (string) $request->input('otp'));
        if ($result !== OtpService::OK) {
            SecurityLog::write('Email verification OTP rejected', ['user_id' => $user->id, 'ip' => $request->ip(), 'result' => $result]);
            return $this->apiError(OtpService::errorMessage($result, $ar), ['code' => 'otp_' . $result], $result === OtpService::LOCKED ? 429 : 422);
        }

        $verification->markVerified((int) $user->id); // بيقفل الرمز كمان
        SecurityLog::write('Email verified via OTP', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $this->apiSuccess(null, 'Email verified successfully.');
    }
}
