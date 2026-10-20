<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MfaPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;

/**
 * تفعيل 2FA المطلوب بسياسة "MFA Requirements" — بيشتغل لكل الأدوار من نفس
 * الـ endpoints، وتحت /auth/* عشان UipAuthMiddleware بيسيب المسار ده مفتوح
 * حتى لو حساب المستخدم مقيّد (غير كده مكنش هيعرف يفعّل 2FA أصلًا).
 *
 *   GET  /api/v1/auth/mfa/status   حالة المستخدم مقابل السياسة
 *   POST /api/v1/auth/mfa/setup    سر + QR جديد (setup_token)
 *   POST /api/v1/auth/mfa/confirm  تأكيد الكود وتفعيل 2FA (بيرجّع recovery codes)
 */
class MfaEnrollmentController extends Controller
{
    public function __construct(
        private MfaPolicyService $policy,
        private TwoFactorService $twoFactor,
        private AuditLogService $auditLog
    ) {
    }

    public function status(Request $request)
    {
        return $this->apiSuccess($this->currentStatus($request));
    }

    public function setup(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($this->twoFactor->status($userId)['enabled']) {
            return $this->apiError('Two-factor authentication is already enabled.', null, 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        return $this->apiSuccess(
            $this->twoFactor->generateSetup($userId, (string) $user->email),
            'Two-factor setup generated successfully.'
        );
    }

    public function confirm(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $setupToken = (string) $request->input('setup_token');
        $code = trim((string) $request->input('code'));

        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $setupToken, $code);
        if ($recoveryCodes === null) {
            return $this->apiError('That code did not match, or the setup request expired. Please try again.', null, 422);
        }

        $role = (string) $request->attributes->get('uip_role');
        $this->auditLog->record($userId, $role . '.two_factor_enabled', 'User', $userId);

        return $this->apiSuccess(['recovery_codes' => $recoveryCodes], 'Two-factor authentication enabled successfully.', 201);
    }

    private function currentStatus(Request $request): array
    {
        return $this->policy->status(
            (int) $request->attributes->get('uip_user_id'),
            (string) $request->attributes->get('uip_role')
        );
    }
}
