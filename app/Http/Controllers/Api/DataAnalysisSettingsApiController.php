<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\NotificationPreferencesService;
use App\Services\PasswordPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة من app/Controllers/Api/DataAnalysisSettingsApiController.php
 * القديمة — بند 24 batch 8 (Settings + Profile). نفس تركيبة صفحة
 * Settings في باقي البورتالات (Admin/...): بروفايل
 * الاسم، تفضيلات الثيم + إشعارات email عامة، كارت إشعارات category-level
 * عبر NotificationPreferencesService المشتركة، صورة البروفايل، تغيير
 * باسورد، وTOTP 2FA كامل (setup/confirm/disable).
 *
 * فروق موثّقة عن القديمة، متسقة مع كل كنترولرز Settings اللارافيل
 * التانية (نفس قرار AdminSettingsApiController بالظبط):
 *  - Session::userId()/userRole() -> $request->attributes->get('uip_user_id'|'uip_role').
 *  - TwoFactorService::confirmSetup($userId, $code) -> confirmSetup($userId,
 *    $setupToken, $code) — نفس التصميم الـ stateless (setup_token بدل
 *    PHP session) في كل كنترولرز Settings الجديدة.
 *  - تغيير الإيميل (pending-email confirm-link flow عبر
 *    AuthService::createEmailChangeToken() + MailService::
 *    sendEmailChangeConfirmation()) مش منقول هنا عمدًا — ولا واحد فيهم
 *    موجود في اللارافيل الجديد لأي بورتال لحد دلوقتي. updateProfile()
 *    هنا بتحدّث full_name بس، وده متسق مع كل بورتال تاني حاليًا؛ الـ
 *    flow الكامل هيتضاف مع أول بورتال يحتاجه فعليًا مش مُخترع هنا.
 *    الفرونت (DataAnalysisSettings.jsx) بيبعت contact_email كمان في
 *    نفس الطلب، بس هيتجاهل لحد ما البنية التحتية دي تتضاف.
 *  - NotificationService القديمة (categoryLabels/mutedCategoriesFor/...)
 *    -> NotificationPreferencesService المشتركة (بند 6)، نفس أسماء
 *    الميثودز بالظبط.
 *
 * RBAC: uip.auth بيغطي الجروب؛ isDataAnalyst() بيتأكد كمان (data_analyst
 * أو admin) — نفس قاعدة باقي كنترولرز DataAnalysis*.
 */
class DataAnalysisSettingsApiController extends Controller
{
    public function __construct(
        private SettingRepository $settings,
        private UserRepository $users,
        private PasswordPolicyService $passwordPolicy,
        private AuditLogService $auditLog,
        private TwoFactorService $twoFactor,
        private NotificationPreferencesService $notifications,
        private FileUploadService $uploads
    ) {
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }

    /** GET /api/v1/data-analysis/settings */
    public function index(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view these settings.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);

        return $this->apiSuccess([
            'profile'                 => $user ? $user->toArray() : null,
            'email_notifications'     => $this->settings->get('email_notifications', 'user', $userId, '1') === '1',
            'two_factor'              => $this->twoFactor->status($userId),
            'notification_categories' => $this->notifications->categoryLabels(),
            'muted_categories'        => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'        => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'             => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /**
     * POST /api/v1/data-analysis/settings/avatar — نفس نمط JSON-upload
     * زي AdminSettingsApiController::uploadAvatar()/DataAnalysisProfile.jsx
     * بيستخدمها كمان لصورة البروفايل.
     */
    public function uploadAvatar(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can update this profile photo.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        try {
            $stored = $this->uploads->store($request->file('avatar'), 'avatars', (string) $user->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($user->avatar_path) {
            $this->uploads->delete($user->avatar_path);
        }

        $before = ['avatar_path' => $user->avatar_path];
        $user->fill(['avatar_path' => $stored['stored_path']]);
        $user->save();

        $this->auditLog->record($userId, 'data_analysis.avatar_update', 'User', $user->id, $before, ['avatar_path' => $user->avatar_path]);

        return $this->apiSuccess(['avatar_path' => $user->avatar_path], 'Photo updated successfully.');
    }

    /** PATCH /api/v1/data-analysis/settings/notifications */
    public function updateNotificationPreferences(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can update notification preferences.', null, 403);
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
        $this->auditLog->record($userId, 'data_analysis.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /**
     * PATCH /api/v1/data-analysis/settings/profile — full_name بس (شوف
     * الفرق الموثّق فوق بخصوص contact_email/الإيميل).
     */
    public function updateProfile(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can update this profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if ($user === null) {
            return $this->apiError('Please provide a valid name.', null, 422);
        }

        $names = \App\Support\BilingualName::fromRequest($request);
        if (!$names['ok']) {
            return $this->apiError('Please provide the name in both Arabic and English.', $names['errors'], 422);
        }

        $phone = \App\Support\ProfilePhone::fromRequest($request, \App\Support\BilingualName::localeOf($request));
        if (!$phone['ok']) {
            return $this->apiError('Validation failed.', ['phone' => $phone['error']], 422);
        }

        $before = ['full_name' => $user->full_name, 'name_ar' => $user->name_ar, 'name_en' => $user->name_en, 'phone' => $user->phone];
        $after = \App\Support\BilingualName::columns($names);
        if ($phone['present']) {
            $after['phone'] = $phone['value'];
        }
        $user->fill($after);
        $user->save();
        $this->auditLog->record($userId, 'data_analysis.profile_update', 'User', $user->id, $before, $after);

        return $this->apiSuccess($user->toArray(), 'Profile updated successfully.');
    }

    /** PATCH /api/v1/data-analysis/settings/preferences */
    public function updatePreferences(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can update these preferences.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $theme = (string) $request->input('theme_preference', 'light');
        $emailNotifications = $request->input('email_notifications') ? '1' : '0';

        if ($user && in_array($theme, ['light', 'dark'], true)) {
            $user->fill(['theme_preference' => $theme]);
            $user->save();
        }

        $this->settings->set('email_notifications', $emailNotifications, 'user', $userId);

        return $this->apiSuccess([
            'theme_preference'    => $theme,
            'email_notifications' => $emailNotifications === '1',
        ], 'Preferences saved successfully.');
    }

    /** POST /api/v1/data-analysis/settings/theme/toggle */
    public function toggleTheme(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can toggle theme.', null, 403);
        }

        $user = User::find((int) $request->attributes->get('uip_user_id'));
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        $next = $user->theme_preference === 'dark' ? 'light' : 'dark';
        $user->fill(['theme_preference' => $next]);
        $user->save();

        return $this->apiSuccess(['theme_preference' => $next], 'Theme toggled successfully.');
    }

    /** PATCH /api/v1/data-analysis/settings/password */
    public function updatePassword(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can update this password.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'      => 'required|string|min:8|confirmed',
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
        $this->auditLog->record($userId, 'data_analysis.password_change', 'User', $user->id);

        return $this->apiSuccess(null, 'Password changed successfully.');
    }

    /** POST /api/v1/data-analysis/settings/2fa/setup */
    public function twoFactorSetup(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can set up two-factor authentication.', null, 403);
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

    /** POST /api/v1/data-analysis/settings/2fa/confirm */
    public function twoFactorConfirm(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can confirm two-factor setup.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $setupToken = (string) $request->input('setup_token');
        $code = trim((string) $request->input('code'));
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $setupToken, $code);

        if ($recoveryCodes === null) {
            return $this->apiError('That code did not match, or the setup request expired. Please try again.', null, 422);
        }

        $this->auditLog->record($userId, 'data_analysis.two_factor_enabled', 'User', $userId);

        return $this->apiSuccess(['recovery_codes' => $recoveryCodes], 'Two-factor authentication enabled successfully.', 201);
    }

    /** POST /api/v1/data-analysis/settings/2fa/disable */
    public function twoFactorDisable(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can disable two-factor authentication.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->twoFactor->disable($userId, (string) $request->input('current_password'));

        if ($ok) {
            $this->auditLog->record($userId, 'data_analysis.two_factor_disabled', 'User', $userId);
        }

        return $ok
            ? $this->apiSuccess(null, 'Two-factor authentication disabled successfully.')
            : $this->apiError('Incorrect password — two-factor authentication was not disabled.', null, 422);
    }
}
