<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\MailConfigService;
use App\Services\MailService;
use App\Services\NotificationPreferencesService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * كانت الوحيدة الناقصة من كل بورتالات Settings في اللارافيل الجديد —
 * app/Controllers/Admin/AdminSettingsController.php القديمة (PHP/web،
 * 21KB) موجودة، وFront/src/pages/admin/AdminSettings.jsx + AdminProfile.jsx
 * بينادوا على /api/v1/admin/settings/* بالفعل (14 endpoint)، بس مفيش
 * كنترولر أو route يرد عليهم هنا، فكل نداء كان بيرجع 404
 * "The route api/v1/admin/settings could not be found." هي دي.
 *
 * نفس نمط باقي كنترولرز الـ Settings:
 *  - Session::userId()/hasRole() -> $request->attributes->get('uip_user_id'|'uip_role').
 *  - TwoFactorService::confirmSetup($userId, $setupToken, $code) — نفس
 *    تصميم TwoFactorService الـ stateless.
 *  - تغيير الإيميل (pending-email confirm-link flow) مش منقول هنا عمدًا
 *    القديمة كانت
 *    بتعتمد على AuthService::createEmailChangeToken() +
 *    MailService::sendEmailChangeConfirmation() — ولا واحد فيهم موجود في
 *    اللارافيل الجديد لأي بورتال لحد دلوقتي. updateProfile() هنا بتحدّث
 *    full_name بس.
 *  - Mail/AI settings بتتخزن في نفس generic settings store
 *    (SettingRepository، مفاتيح mail_ / ai_) زي القديمة بالظبط، مع نفس
 *    قاعدة "قيمة فاضية = سيب القديمة" لباسورد الـ SMTP ومفتاح الـ AI.
 *    القيم الافتراضية بترجع من config('mail.mailers.smtp')/config('mail.from')
 *    و config('ai') بدل الشكل القديم المسطّح لـ config('mail')، عشان
 *    Laravel's mail config شكله مختلف.
 *  - sendTestEmail()/mail settings الحقيقية (SMTP فعلي) مش موصولة —
 *    نفس فجوة MailService الموثّقة (باقي الميثودز فيها stub بترجع true).
 *
 * RBAC: uip.auth + uip.admin بيغطوا الجروب (routes/api.php) + فحص
 * uip_role === 'admin' جوه كل ميثود كمان، نفس كل كنترولرز admin تانية.
 */
class AdminSettingsApiController extends Controller
{
    public function __construct(
        private SettingRepository $settings,
        private AuditLogService $auditLog,
        private TwoFactorService $twoFactor,
        private MailService $mail,
        private NotificationPreferencesService $notifications,
        private FileUploadService $uploads
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access these settings.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/settings */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);

        return $this->apiSuccess([
            'profile'                 => $user ? $user->toArray() : null,
            'maintenance_mode'        => $this->settings->get('maintenance_mode', 'global', null, '0') === '1',
            'session_minutes'         => (int) (config('session.lifetime', 120)),
            'two_factor'              => $this->twoFactor->status($userId),
            'mail_settings'           => $this->currentMailSettings(),
            'ai_settings'             => $this->currentAiSettings(),
            'notification_categories' => $this->notifications->categoryLabels(),
            'muted_categories'        => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'        => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'             => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /** Current effective mail settings (DB override if present, else config/mail.php) — password never leaves this method as plaintext. */
    private function currentMailSettings(): array
    {
        $get = fn (string $key, $default) => $this->settings->get('mail_' . $key, 'global', null, $default);
        $smtp = (array) config('mail.mailers.smtp', []);
        $from = (array) config('mail.from', []);

        $hasPasswordOverride = $get('password', '') !== '';
        $envDefault = (string) config('mail.default', 'smtp');
        $driver = (string) $get('driver', in_array($envDefault, MailConfigService::DRIVERS, true) ? $envDefault : 'smtp');

        return [
            'driver'         => $driver,
            'has_brevo_key'  => $get('brevo_api_key', '') !== '' || (string) config('mail.mailers.brevo.key', '') !== '',
            'host'         => $get('host', $smtp['host'] ?? ''),
            'port'         => $get('port', $smtp['port'] ?? 587),
            'username'     => $get('username', $smtp['username'] ?? ''),
            'has_password' => $hasPasswordOverride || (($smtp['password'] ?? '') !== ''),
            'encryption'   => $get('encryption', 'tls'),
            'from_address' => $get('from_address', $from['address'] ?? ''),
            'from_name'    => $get('from_name', $from['name'] ?? ''),
            'reply_to'     => $get('reply_to', ''),
        ];
    }

    /** Current effective AI settings (DB override if present, else config/ai.php) — API key never leaves this method as plaintext. */
    private function currentAiSettings(): array
    {
        $get = fn (string $key, $default) => $this->settings->get('ai_' . $key, 'global', null, $default);
        $aiConfig = (array) config('ai', []);

        $storedEnabled = $get('enabled', '');
        $storedPersonalization = $get('email_personalization_enabled', '');
        $hasKeyOverride = $get('api_key', '') !== '';

        return [
            'enabled'                       => $storedEnabled !== '' ? $storedEnabled === '1' : (bool) ($aiConfig['enabled'] ?? false),
            'base_url'                      => $get('base_url', $aiConfig['base_url'] ?? ''),
            'has_api_key'                   => $hasKeyOverride || (($aiConfig['api_key'] ?? '') !== ''),
            'model'                         => $get('model', $aiConfig['model'] ?? ''),
            'email_personalization_enabled' => $storedPersonalization !== '' ? $storedPersonalization === '1' : (bool) ($aiConfig['email_personalization_enabled'] ?? false),
            // تفريغ الصوت للترجمة التلقائية (سيرفر Whisper متوافق مع OpenAI) — المفتاح عمره ما بيرجع للفرونت.
            'transcribe_base_url'           => $get('transcribe_base_url', $aiConfig['transcribe']['base_url'] ?? ''),
            'has_transcribe_api_key'        => $get('transcribe_api_key', '') !== '' || (($aiConfig['transcribe']['api_key'] ?? '') !== ''),
            'transcribe_model'              => $get('transcribe_model', $aiConfig['transcribe']['model'] ?? ''),
        ];
    }

    /** PATCH /api/v1/admin/settings/profile */
    public function updateProfile(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
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
        $this->auditLog->record($userId, 'admin.profile_update', 'User', $user->id, $before, $after);

        return $this->apiSuccess($user->toArray(), 'Profile updated successfully.');
    }

    /** PATCH /api/v1/admin/settings/preferences */
    public function updatePreferences(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $theme = (string) $request->input('theme_preference', 'light');

        if (!$user || !in_array($theme, ['light', 'dark'], true)) {
            return $this->apiError('Invalid theme preference.', null, 422);
        }

        $before = ['theme_preference' => $user->theme_preference];
        $user->fill(['theme_preference' => $theme]);
        $user->save();
        $this->auditLog->record($userId, 'admin.preferences_update', 'User', $user->id, $before, ['theme_preference' => $theme]);

        return $this->apiSuccess(['theme_preference' => $theme], 'Preferences saved successfully.');
    }

    /** POST /api/v1/admin/settings/theme/toggle */
    public function toggleTheme(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
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

    /** POST /api/v1/admin/settings/avatar — صورة الشخص نفسه (users.avatar_path). */
    public function uploadAvatar(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
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

        $this->auditLog->record($userId, 'admin.avatar_update', 'User', $user->id, $before, ['avatar_path' => $user->avatar_path]);

        return $this->apiSuccess(['avatar_path' => $user->avatar_path], 'Photo updated successfully.');
    }

    /** PATCH /api/v1/admin/settings/notifications */
    public function updateNotificationPreferences(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
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
        $this->auditLog->record($userId, 'admin.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /** POST /api/v1/admin/settings/2fa/setup */
    public function twoFactorSetup(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
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

    /** POST /api/v1/admin/settings/2fa/confirm */
    public function twoFactorConfirm(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $setupToken = (string) $request->input('setup_token');
        $code = trim((string) $request->input('code'));
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $setupToken, $code);

        if ($recoveryCodes === null) {
            return $this->apiError('That code did not match, or the setup request expired. Please try again.', null, 422);
        }

        $this->auditLog->record($userId, 'admin.two_factor_enabled', 'User', $userId);

        return $this->apiSuccess(['recovery_codes' => $recoveryCodes], 'Two-factor authentication enabled successfully.', 201);
    }

    /** POST /api/v1/admin/settings/2fa/disable */
    public function twoFactorDisable(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->twoFactor->disable($userId, (string) $request->input('current_password'));

        if ($ok) {
            $this->auditLog->record($userId, 'admin.two_factor_disabled', 'User', $userId);
        }

        return $ok
            ? $this->apiSuccess(null, 'Two-factor authentication disabled successfully.')
            : $this->apiError('Incorrect password — two-factor authentication was not disabled.', null, 422);
    }

    /** POST /api/v1/admin/settings/maintenance/toggle */
    public function toggleMaintenance(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $current = $this->settings->get('maintenance_mode', 'global', null, '0') === '1';
        $next = !$current;
        $this->settings->set('maintenance_mode', $next ? '1' : '0', 'global', null);

        $this->auditLog->record(
            $userId,
            'platform.maintenance_mode_toggle',
            'Setting',
            null,
            ['maintenance_mode' => $current],
            ['maintenance_mode' => $next]
        );

        return $this->apiSuccess(['maintenance_mode' => $next], $next ? 'Maintenance mode enabled.' : 'Maintenance mode disabled.');
    }

    /**
     * PATCH /api/v1/admin/settings/mail — blank password submission means
     * "keep the currently stored/.env password", never "clear it".
     */
    public function updateMailSettings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        $host       = trim((string) $request->input('mail_host', ''));
        $port       = trim((string) $request->input('mail_port', ''));
        $username   = trim((string) $request->input('mail_username', ''));
        $password   = (string) $request->input('mail_password', '');
        $encryption = (string) $request->input('mail_encryption', 'tls');
        $fromName   = trim((string) $request->input('mail_from_name', ''));
        $fromAddr   = trim((string) $request->input('mail_from_address', ''));
        $replyTo    = trim((string) $request->input('mail_reply_to', ''));
        $driver     = (string) $request->input('mail_driver', 'smtp');
        $brevoKey   = trim((string) $request->input('mail_brevo_api_key', ''));

        if (!in_array($driver, MailConfigService::DRIVERS, true)) {
            return $this->apiError('Invalid mail driver.', null, 422);
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return $this->apiError('The reply-to email format is invalid.', null, 422);
        }

        if ($fromAddr !== '' && !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
            return $this->apiError('The from-address email format is invalid.', null, 422);
        }

        if ($brevoKey !== '' && str_starts_with($brevoKey, 'xsmtpsib-')) {
            return $this->apiError('That is a Brevo SMTP key. Please use an API key (starts with "xkeysib-") from SMTP & API → API Keys.', null, 422);
        }

        $this->settings->set('mail_driver', $driver);
        if ($brevoKey !== '') {
            $this->settings->set('mail_brevo_api_key', MailConfigService::encryptSecret($brevoKey));
        }
        $this->settings->set('mail_host', $host);
        $this->settings->set('mail_port', $port);
        $this->settings->set('mail_username', $username);
        if ($password !== '') {
            $this->settings->set('mail_password', MailConfigService::encryptSecret($password));
        }
        $this->settings->set('mail_encryption', in_array($encryption, ['tls', 'ssl', ''], true) ? $encryption : 'tls');
        $this->settings->set('mail_from_name', $fromName);
        $this->settings->set('mail_from_address', $fromAddr);
        $this->settings->set('mail_reply_to', $replyTo);

        // Never record the password value itself, only that it changed.
        $this->auditLog->record($userId, 'admin.mail_settings_update', 'Setting', null, null, [
            'driver' => $driver, 'brevo_key_changed' => $brevoKey !== '',
            'host' => $host, 'port' => $port, 'username' => $username,
            'password_changed' => $password !== '', 'encryption' => $encryption,
            'from_name' => $fromName, 'from_address' => $fromAddr, 'reply_to' => $replyTo,
        ]);

        return $this->apiSuccess($this->currentMailSettings(), 'Mail settings saved successfully.');
    }

    /** POST /api/v1/admin/settings/mail/test — real end-to-end send with whatever is currently configured. */
    public function sendTestEmail(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $user = User::find((int) $request->attributes->get('uip_user_id'));
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        $sent = $this->mail->sendTestEmail((string) $user->email, (string) $user->full_name, (string) ($user->preferred_language ?? 'ar'));

        return $sent
            ? $this->apiSuccess(null, "Test email sent to {$user->email}.")
            : $this->apiError('Send failed — ' . ($this->mail->getLastError() ?: 'check your mail credentials and the error log.'), null, 422);
    }

    /** PATCH /api/v1/admin/settings/ai — blank API key submission means "keep existing", same contract as updateMailSettings(). */
    public function updateAiSettings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        $enabled              = $request->input('ai_enabled') !== null;
        $baseUrl              = trim((string) $request->input('ai_base_url', ''));
        $apiKey               = (string) $request->input('ai_api_key', '');
        $model                = trim((string) $request->input('ai_model', ''));
        $emailPersonalization = $request->input('ai_email_personalization_enabled') !== null;

        $this->settings->set('ai_enabled', $enabled ? '1' : '0');
        $this->settings->set('ai_base_url', $baseUrl);
        if ($apiKey !== '') {
            $this->settings->set('ai_api_key', $apiKey);
        }
        $this->settings->set('ai_model', $model);
        $this->settings->set('ai_email_personalization_enabled', $emailPersonalization ? '1' : '0');

        // Speech-to-text (الترجمة التلقائية للفيديو). بنكتبها بس لو الفرونت بعتها — عشان نسخة قديمة من الشاشة ماتمسحش الإعداد.
        $sttChanged = [];
        if ($request->has('ai_transcribe_base_url')) {
            $sttBase = rtrim(trim((string) $request->input('ai_transcribe_base_url', '')), '/');
            if ($sttBase !== '' && !preg_match('#^https?://[^\s]+$#i', $sttBase)) {
                return $this->apiError('Speech-to-text Base URL must start with http:// or https://', ['ai_transcribe_base_url' => ['Invalid URL.']], 422);
            }
            $this->settings->set('ai_transcribe_base_url', $sttBase);
            $this->settings->set('ai_transcribe_model', trim((string) $request->input('ai_transcribe_model', '')));
            $sttKey = (string) $request->input('ai_transcribe_api_key', '');
            if ($sttKey !== '') {
                $this->settings->set('ai_transcribe_api_key', $sttKey);
            }
            $sttChanged = ['transcribe_base_url' => $sttBase, 'transcribe_model' => trim((string) $request->input('ai_transcribe_model', '')), 'transcribe_api_key_changed' => $sttKey !== ''];
        }

        $this->auditLog->record($userId, 'admin.ai_settings_update', 'Setting', null, null, [
            'enabled' => $enabled, 'base_url' => $baseUrl, 'api_key_changed' => $apiKey !== '',
            'model' => $model, 'email_personalization_enabled' => $emailPersonalization,
        ] + $sttChanged);

        return $this->apiSuccess($this->currentAiSettings(), 'AI settings saved successfully.');
    }
}
