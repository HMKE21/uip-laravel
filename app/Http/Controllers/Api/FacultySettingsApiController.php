<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\FacultyRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\NotificationPreferencesService;
use App\Services\PasswordPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة من app/Controllers/Api/FacultySettingsApiController.php القديمة
 * — بند 10. سطح إعدادات /api/v1/faculty/settings/* لحساب لوجين الكلية
 * (migration 106)، بيلف نفس الـ Repositories/Services اللي
 * App\Controllers\Faculty\FacultySettingsController القديمة كانت بتغلّفهم
 * — نفس نمط باقي كنترولرز الـ Settings لـ 2FA/theme/
 * password.
 *
 * فرق شكلي فقط عن القديمة (نفس نمط بند 6/9):
 *  - Session::userId()/hasRole() -> $request->attributes->get('uip_user_id'|'uip_role').
 *  - NotificationService -> NotificationPreferencesService.
 *  - TwoFactorService::confirmSetup($userId, $code) -> confirmSetup($userId,
 *    $setupToken, $code) — تصميم stateless الحالي.
 *  - $this->validate([...]) -> Validator::make() صريح.
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='faculty' بتتفحص جوه
 * كل ميثود. لوجين الكلية دايمًا متعمول من بورتال الجامعة، عمره ما بيتعمل
 * لوحده — profile/logo بيتأكدوا دفاعيًا من findByUserId() زي القديمة.
 */
class FacultySettingsApiController extends Controller
{
    public function __construct(
        private FacultyRepository $faculties,
        private FileUploadService $uploads,
        private AuditLogService $auditLog,
        private UserRepository $users,
        private PasswordPolicyService $passwordPolicy,
        private TwoFactorService $twoFactor,
        private NotificationPreferencesService $notifications
    ) {
    }

    private function requireFaculty(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can access these settings.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/faculty/settings */
    public function index(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $faculty = $this->faculties->findByUserId($userId);

        return $this->apiSuccess([
            'faculty'                 => $faculty ? $faculty->toArray() : null,
            'two_factor'              => $this->twoFactor->status($userId),
            'notification_categories' => $this->notifications->categoryLabels(),
            'muted_categories'        => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'        => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'             => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /** PATCH /api/v1/faculty/settings/notifications */
    public function updateNotificationPreferences(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
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
        $this->auditLog->record($userId, 'faculty.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /** PATCH /api/v1/faculty/settings/profile */
    public function updateProfile(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
            return $err;
        }

        $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$faculty) {
            return $this->apiError('This login is not linked to a faculty.', null, 404);
        }

        $before = $faculty->toArray();
        $faculty->fill([
            'name_ar'       => trim((string) $request->input('name_ar', $faculty->name_ar)),
            'name_en'       => trim((string) $request->input('name_en', $faculty->name_en)),
            'description'   => trim((string) $request->input('description', $faculty->description)),
            'mission'       => trim((string) $request->input('mission', $faculty->mission)),
            'vision'        => trim((string) $request->input('vision', $faculty->vision)),
            'website'       => trim((string) $request->input('website', $faculty->website)),
            'contact_email' => trim((string) $request->input('contact_email', $faculty->contact_email)),
            'contact_phone' => trim((string) $request->input('contact_phone', $faculty->contact_phone)),
            'location'      => trim((string) $request->input('location', $faculty->location)),
        ]);
        $faculty->save();

        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'faculty.profile_update', 'Faculty', $faculty->id, $before, $faculty->toArray());

        return $this->apiSuccess($faculty->toArray(), 'Faculty profile updated successfully.');
    }

    /** POST /api/v1/faculty/settings/logo */
    public function uploadLogo(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
            return $err;
        }

        $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$faculty) {
            return $this->apiError('This login is not linked to a faculty.', null, 404);
        }

        try {
            $stored = $this->uploads->store($request->file('logo'), 'logos', (string) $faculty->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($faculty->logo_path) {
            $this->uploads->delete($faculty->logo_path);
        }

        $faculty->fill(['logo_path' => $stored['stored_path']]);
        $faculty->save();

        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'faculty.logo_update', 'Faculty', $faculty->id, null, ['logo_path' => $faculty->logo_path]);

        return $this->apiSuccess(['logo_path' => $faculty->logo_path], 'Logo updated successfully.');
    }

    /** POST /api/v1/faculty/settings/theme/toggle */
    public function toggleTheme(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
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

    /** PATCH /api/v1/faculty/settings/password */
    public function updatePassword(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
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

        $user = User::find((int) $request->attributes->get('uip_user_id'));
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

        return $this->apiSuccess(null, 'Password changed successfully.');
    }

    /** POST /api/v1/faculty/settings/2fa/setup */
    public function twoFactorSetup(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
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

    /** POST /api/v1/faculty/settings/2fa/confirm */
    public function twoFactorConfirm(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $setupToken = (string) $request->input('setup_token');
        $code = trim((string) $request->input('code'));
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $setupToken, $code);

        if ($recoveryCodes === null) {
            return $this->apiError('That code did not match, or the setup request expired. Please try again.', null, 422);
        }

        $this->auditLog->record($userId, 'faculty.two_factor_enabled', 'User', $userId);

        return $this->apiSuccess(['recovery_codes' => $recoveryCodes], 'Two-factor authentication enabled successfully.', 201);
    }

    /** POST /api/v1/faculty/settings/2fa/disable */
    public function twoFactorDisable(Request $request)
    {
        if ($err = $this->requireFaculty($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->twoFactor->disable($userId, (string) $request->input('current_password'));

        if ($ok) {
            $this->auditLog->record($userId, 'faculty.two_factor_disabled', 'User', $userId);
        }

        return $ok
            ? $this->apiSuccess(null, 'Two-factor authentication disabled successfully.')
            : $this->apiError('Incorrect password — two-factor authentication was not disabled.', null, 422);
    }
}
