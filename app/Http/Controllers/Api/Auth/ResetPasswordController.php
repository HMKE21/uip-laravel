<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PasswordPolicyService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** يطابق ResetPasswordController::submit() + AuthService::resetPassword() القديمين، تعقيد كلمة السر شغال بالكامل. */
class ResetPasswordController extends Controller
{
    public function __construct(private PasswordPolicyService $passwordPolicy)
    {
    }

    public function submit(Request $request)
    {
        // الطريقة الأساسية: رمز OTP على الإيميل. اللينك القديم (token) فاضل شغال بس للرسايل اللي اتبعتت قبل التحويل.
        if ($request->filled('otp') || !$request->filled('token')) {
            return $this->submitWithOtp($request);
        }

        $validator = Validator::make($request->all(), [
            'token'    => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $hash = hash('sha256', $data['token']);

        $row = DB::table('password_reset_tokens')
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => 'This reset link is invalid or has expired.',
            ], 422);
        }

        try {
            $this->passwordPolicy->assertValid($data['password']);
            $this->passwordPolicy->assertNotReused($row->user_id, $data['password']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $newHash = password_hash($data['password'], PASSWORD_BCRYPT);

        DB::table('users')->where('id', $row->user_id)->update([
            'password_hash' => $newHash,
            'updated_at'    => now(),
        ]);

        DB::table('password_reset_tokens')->where('id', $row->id)->update(['used_at' => now()]);
        // اللينك وصل لإيميل الحساب -> ده إثبات ملكية الإيميل (يفعّل حساب لسه pending).
        app(\App\Services\EmailVerificationService::class)->markVerified((int) $row->user_id);
        $this->passwordPolicy->recordPasswordChange($row->user_id, $newHash);

        SecurityLog::write('Password reset completed', ['user_id' => $row->user_id]);

        return response()->json(['success' => true, 'redirect' => '/auth/login']);
    }

    /**
     * POST /api/v1/auth/reset-password/verify — { email, otp }
     * يتأكد إن الرمز صح من غير ما يحرقه (الغلط بيتعدّ ضمن الـ 5 محاولات). الصفحة بعدها تفتح خانة كلمة السر،
     * والتغيير الفعلي بيرجع يفحص الرمز تاني في submit().
     */
    public function verify(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp'   => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $otp = app(OtpService::class);
        $user = User::whereRaw('LOWER(email) = ?', [strtolower((string) $request->input('email'))])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => OtpService::errorMessage(OtpService::INVALID, $ar), 'code' => 'otp_invalid'], 422);
        }

        $result = $otp->check(ForgotPasswordController::OTP_PURPOSE, (int) $user->id, (string) $request->input('otp'));
        if ($result !== OtpService::OK) {
            SecurityLog::write('Password reset OTP rejected', ['user_id' => $user->id, 'ip' => $request->ip(), 'result' => $result]);
            return response()->json(['success' => false, 'message' => OtpService::errorMessage($result, $ar), 'code' => 'otp_' . $result], $result === OtpService::LOCKED ? 429 : 422);
        }

        return response()->json(['success' => true]);
    }

    /** POST /api/v1/auth/reset-password — { email, otp, password, password_confirmation } */
    private function submitWithOtp(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'otp'      => 'required|string',
            'password' => 'required|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $otp = app(OtpService::class);
        $user = User::whereRaw('LOWER(email) = ?', [strtolower((string) $request->input('email'))])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => OtpService::errorMessage(OtpService::INVALID, $ar), 'code' => 'otp_invalid'], 422);
        }

        $result = $otp->check(ForgotPasswordController::OTP_PURPOSE, (int) $user->id, (string) $request->input('otp'));
        if ($result !== OtpService::OK) {
            SecurityLog::write('Password reset OTP rejected', ['user_id' => $user->id, 'ip' => $request->ip(), 'result' => $result]);
            return response()->json(['success' => false, 'message' => OtpService::errorMessage($result, $ar), 'code' => 'otp_' . $result], $result === OtpService::LOCKED ? 429 : 422);
        }

        $password = (string) $request->input('password');
        try {
            $this->passwordPolicy->assertValid($password);
            $this->passwordPolicy->assertNotReused($user->id, $password);
        } catch (\InvalidArgumentException $e) {
            // الرمز صح لكن كلمة السر مرفوضة: منحرقش الرمز، يجرّب كلمة سر تانية.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $otp->forget(ForgotPasswordController::OTP_PURPOSE, (int) $user->id); // مرة واحدة

        $newHash = password_hash($password, PASSWORD_BCRYPT);
        DB::table('users')->where('id', $user->id)->update(['password_hash' => $newHash, 'updated_at' => now()]);
        // أي لينكات قديمة لسه صالحة تتقفل.
        DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        // الرمز وصل لإيميل الحساب -> إثبات ملكية الإيميل (يفعّل حساب لسه pending).
        app(\App\Services\EmailVerificationService::class)->markVerified((int) $user->id);
        $this->passwordPolicy->recordPasswordChange($user->id, $newHash);

        SecurityLog::write('Password reset completed (OTP)', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return response()->json(['success' => true, 'redirect' => '/auth/login']);
    }
}
