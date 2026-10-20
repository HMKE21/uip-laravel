<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UserRepository;
use App\Services\AccountLifecycleService;
use App\Services\AccountRecoveryOtp;
use App\Services\AuditLogService;
use App\Services\MailService;
use App\Support\OtpGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationPreferencesService;
use App\Services\PasswordPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح إعدادات /api/v1/student/settings/* لحساب لوجين الطالب، بنفس نمط
 * DataAnalysisSettingsApiController/FacultySettingsApiController
 * (بروفايل للقراءة بس — الاسم/الإيميل بيتغيروا من إدارة الجامعة مش هنا،
 * زي ما StudentSettings.jsx بتوثقه — تفضيلات ثيم/لغة/إشعارات email،
 * كارت إشعارات category-level عبر NotificationPreferencesService
 * المشتركة، خصوصية مراسلة (read receipts) عبر نفس مفتاح الـ setting اللي
 * MessagingService بيقرا منه، تغيير باسورد، وTOTP 2FA كامل).
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='student' بتتفحص
 * جوه كل ميثود.
 */
class StudentSettingsApiController extends Controller
{
    public function __construct(
        private StudentRepository $students,
        private SettingRepository $settings,
        private UserRepository $users,
        private PasswordPolicyService $passwordPolicy,
        private AuditLogService $auditLog,
        private TwoFactorService $twoFactor,
        private NotificationPreferencesService $notifications,
        private AccountLifecycleService $lifecycle,
        private AccountRecoveryOtp $otp,
        private MailService $mail
    ) {
    }

    private function requireStudent(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can access these settings.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/student/settings */
    public function index(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $student = $this->students->findByUserId($userId);
        $profile = $student ? $this->students->withProfileDetails($student->id) : null;

        return $this->apiSuccess([
            'profile'                  => $profile,
            'email_notifications'      => $this->settings->get('email_notifications', 'user', $userId, '1') === '1',
            'read_receipts_enabled'    => $this->settings->get('messaging_read_receipts_enabled', 'user', $userId, '1') === '1',
            'two_factor'               => $this->twoFactor->status($userId),
            'account_deletion'         => ['password_required' => $this->passwordRequiredFor($userId)],
            'notification_categories'  => $this->notifications->categoryLabels(),
            'muted_categories'         => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'         => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'              => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /** PATCH /api/v1/student/settings/preferences — language + theme + email notifications. */
    public function updatePreferences(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        $language = (string) $request->input('preferred_language', $user->preferred_language ?? 'ar');
        $theme = (string) $request->input('theme_preference', $user->theme_preference ?? 'light');
        $emailNotifications = $request->input('email_notifications') ? '1' : '0';

        $fill = [];
        if (in_array($language, ['ar', 'en'], true)) {
            $fill['preferred_language'] = $language;
        }
        if (in_array($theme, ['light', 'dark'], true)) {
            $fill['theme_preference'] = $theme;
        }
        if ($fill) {
            $user->fill($fill);
            $user->save();
        }

        $this->settings->set('email_notifications', $emailNotifications, 'user', $userId);

        return $this->apiSuccess([
            'preferred_language'  => $user->preferred_language,
            'theme_preference'    => $user->theme_preference,
            'email_notifications' => $emailNotifications === '1',
        ], 'Preferences saved successfully.');
    }

    /** PATCH /api/v1/student/settings/notifications */
    public function updateNotificationPreferences(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        $before = [
            'muted_categories' => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency' => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'      => $this->notifications->quietHoursFor($userId),
        ];

        $enabledCategories = (array) $request->input('enabled_categories', []);
        $allCategories = array_keys($this->notifications->categoryLabels());
        $muted = array_diff($allCategories, array_map('strval', $enabledCategories));
        $this->notifications->setMutedCategories($userId, $muted);

        $digest = (string) $request->input('digest_frequency', 'immediate');
        $this->notifications->setDigestFrequency($userId, $digest);

        $quietStart = trim((string) $request->input('quiet_hours_start', ''));
        $quietEnd = trim((string) $request->input('quiet_hours_end', ''));
        $this->notifications->setQuietHours($userId, $quietStart ?: null, $quietEnd ?: null);

        $after = [
            'muted_categories' => array_values($muted),
            'digest_frequency' => $digest,
            'quiet_hours'      => ['start' => $quietStart ?: null, 'end' => $quietEnd ?: null],
        ];
        $this->auditLog->record($userId, 'student.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /** PATCH /api/v1/student/settings/messaging-privacy — read receipts toggle. */
    public function updateMessagingPrivacy(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $enabled = $request->input('read_receipts_enabled') ? '1' : '0';
        $this->settings->set('messaging_read_receipts_enabled', $enabled, 'user', $userId);

        $this->auditLog->record($userId, 'student.messaging_privacy_update', 'Setting', null, null, ['read_receipts_enabled' => $enabled === '1']);

        return $this->apiSuccess(['read_receipts_enabled' => $enabled === '1'], 'Messaging privacy saved successfully.');
    }

    /** PATCH /api/v1/student/settings/password */
    public function updatePassword(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }
        $data = $validator->validated();

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user || !password_verify($data['current_password'], (string) $user->password_hash)) {
            return $this->apiError('Current password is incorrect.', null, 422);
        }

        try {
            $this->passwordPolicy->assertValid($data['new_password']);
            $this->passwordPolicy->assertNotReused($user->id, $data['new_password']);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $newHash = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $this->users->updatePassword($user->id, $newHash);
        $this->passwordPolicy->recordPasswordChange($user->id, $newHash);
        $this->auditLog->record($userId, 'student.password_change', 'User', $user->id);

        return $this->apiSuccess(null, 'Password changed successfully.');
    }

    /** POST /api/v1/student/settings/2fa/setup */
    public function twoFactorSetup(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $status = $this->twoFactor->status($userId);
        if ($status['enabled']) {
            return $this->apiError('Two-factor authentication is already enabled.', null, 422);
        }

        $user = User::find($userId);
        $setup = $this->twoFactor->generateSetup($userId, (string) $user->email);

        return $this->apiSuccess($setup, 'Two-factor setup generated successfully.');
    }

    /** POST /api/v1/student/settings/2fa/confirm */
    public function twoFactorConfirm(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $setupToken = (string) $request->input('setup_token');
        $code = trim((string) $request->input('code'));
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $setupToken, $code);

        if ($recoveryCodes === null) {
            return $this->apiError('That code did not match, or the setup request expired. Please try again.', null, 422);
        }

        $this->auditLog->record($userId, 'student.two_factor_enabled', 'User', $userId);

        return $this->apiSuccess(['recovery_codes' => $recoveryCodes], 'Two-factor authentication enabled successfully.', 201);
    }

    /** POST /api/v1/student/settings/2fa/disable */
    public function twoFactorDisable(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->twoFactor->disable($userId, (string) $request->input('current_password'));

        if ($ok) {
            $this->auditLog->record($userId, 'student.two_factor_disabled', 'User', $userId);
        }

        return $ok
            ? $this->apiSuccess(null, 'Two-factor authentication disabled successfully.')
            : $this->apiError('Incorrect password — two-factor authentication was not disabled.', null, 422);
    }

    // ------------------------------------------------------------ delete my account

    /**
     * حساب اتعمل بجوجل ومحدّدش كلمة سر بنفسه عمره (مفيش password_history) كلمة سره عشوائية —
     * فمنطلبهاش منه، ويبقى الـ OTP على الإيميل هو الإثبات.
     */
    private function passwordRequiredFor(int $userId): bool
    {
        $social = DB::table('user_social_accounts')->where('user_id', $userId)->exists();
        $setOwn = DB::table('password_history')->where('user_id', $userId)->exists();

        return !($social && !$setOwn);
    }

    /** @return array{0:?User,1:?\Illuminate\Http\JsonResponse} */
    private function checkDeletionPassword(Request $request, int $userId): array
    {
        $ar = $request->input('locale') !== 'en';
        $user = User::find($userId);
        if (!$user) {
            return [null, $this->apiError('User not found.', null, 404)];
        }
        if ($this->passwordRequiredFor($userId)) {
            $scope = 'delete-pw:' . $userId;
            if ($left = OtpGuard::blockedSeconds($scope)) {
                return [null, $this->apiError(OtpGuard::message($ar, $left), null, 429)];
            }
            if (!password_verify((string) $request->input('password', ''), (string) $user->password_hash)) {
                $blocked = OtpGuard::recordFailure($scope);
                return [null, $blocked
                    ? $this->apiError(OtpGuard::message($ar, $blocked), null, 429)
                    : $this->apiError($ar ? 'كلمة السر غير صحيحة.' : 'Incorrect password.', null, 422)];
            }
            OtpGuard::recordSuccess($scope);
        }

        return [$user, null];
    }

    /** POST /api/v1/student/settings/delete-account/send-otp — يتأكد من كلمة السر ويبعت كود على الإيميل. */
    public function deleteAccountSendOtp(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        $userId = (int) $request->attributes->get('uip_user_id');
        $ar = $request->input('locale') !== 'en';
        [$user, $fail] = $this->checkDeletionPassword($request, $userId);
        if ($fail) {
            return $fail;
        }

        $issued = $this->otp->issue('self-delete:' . $userId);
        if ($issued['otp'] === null) {
            return $this->apiError($ar ? "استنى {$issued['retry_after']} ثانية قبل طلب كود جديد." : "Wait {$issued['retry_after']}s before requesting a new code.", ['retry_after' => $issued['retry_after']], 429);
        }
        $sent = $this->mail->sendAccountRecoveryOtp((string) $user->email, (string) $user->full_name, $issued['otp'], $this->otp->ttl(), 'delete', $request->ip(), $ar ? 'ar' : 'en');
        if (!$sent) {
            $this->otp->consume('self-delete:' . $userId);
            return $this->apiError($ar ? 'تعذّر إرسال الكود. حاول بعد شوية.' : 'Could not send the code. Try again shortly.', null, 502);
        }

        return $this->apiSuccess([
            'email'              => \App\Services\AccountRecoveryService::maskEmail((string) $user->email),
            'expires_in_minutes' => $this->otp->ttl(),
            'retry_after'        => (int) config('account_recovery.otp.resend_seconds', 60),
        ], $ar ? 'تم إرسال الكود إلى إيميلك.' : 'A code was sent to your email.');
    }

    /** POST /api/v1/student/settings/delete-account — كلمة السر + OTP + تأكيد صريح => حذف (soft) وقفل كل الجلسات. */
    public function deleteAccount(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        $userId = (int) $request->attributes->get('uip_user_id');
        $ar = $request->input('locale') !== 'en';

        $v = Validator::make($request->all(), ['otp' => 'required|string|max:20', 'confirm' => 'required|accepted']);
        if ($v->fails()) {
            return $this->apiError($ar ? 'أدخل الكود وأكّد الحذف.' : 'Enter the code and confirm the deletion.', $v->errors()->toArray(), 422);
        }

        [$user, $fail] = $this->checkDeletionPassword($request, $userId);
        if ($fail) {
            return $fail;
        }

        $scope = 'self-delete:' . $userId;
        $res = $this->otp->verify($scope, (string) $request->input('otp'));
        if ($res !== AccountRecoveryOtp::OK) {
            $msg = [
                AccountRecoveryOtp::EXPIRED => $ar ? 'انتهت صلاحية الكود. اطلب كود جديد.' : 'The code has expired. Request a new one.',
                AccountRecoveryOtp::LOCKED  => $ar ? 'محاولات كتير. اطلب كود جديد بعد شوية.' : 'Too many attempts. Request a new code in a moment.',
                AccountRecoveryOtp::INVALID => $ar ? 'الكود غير صحيح.' : 'The code is incorrect.',
            ][$res] ?? 'Invalid code.';
            return $this->apiError($msg, null, $res === AccountRecoveryOtp::LOCKED ? 429 : 422);
        }

        $result = $this->lifecycle->delete($userId, $userId);
        if (!$result['ok']) {
            return $this->apiError((string) ($result['error'] ?? 'Could not delete the account.'), null, 422);
        }
        $this->otp->consume($scope);
        $this->auditLog->record($userId, 'student.self_delete', 'User', $userId, null, null, $request->ip());
        SecurityLog::write('Student deleted own account', ['user_id' => $userId, 'ip' => $request->ip()]);

        return $this->apiSuccess(null, $ar ? 'تم حذف حسابك.' : 'Your account was deleted.');
    }
}
