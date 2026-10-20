<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\NotificationPreferencesService;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة من app/Controllers/Api/SupervisorSettingsApiController.php القديمة
 * — بند 9 (Supervisors)، جزء 2. سطح إعدادات مقيّد لحساب لوجين المشرف نفسه
 * (api/v1/supervisor/settings/*)، بيطابق
 * App\Controllers\Supervisor\SupervisorSettingsController (web) القديمة
 * بالظبط: بروفايل: المشرف نفسه يعدّل اسمه (عربي/إنجليزي) وتليفونه ولقبه وقسمه
 * وصورته (updateProfile/uploadAvatar)، أما الصلاحيات والنطاقات والإيميل
 * والحالة فالجامعة الداعية بس اللي تعدّلهم عبر /api/v1/supervisors/{id}، تفضيلات إشعارات مستوى-فئة، وتغيير باسورد
 * حقيقي. مفيش avatar/2FA/theme toggle هنا لأن القديمة ماكانتش عاملاهم
 * لحساب المشرف (على عكس باقي بورتالات الـ Settings).
 *
 * فرق شكلي فقط عن القديمة (نفس نمط بند 6/8):
 *  - Session::userId()/hasRole() -> $request->attributes->get('uip_user_id')/'uip_role'.
 *  - NotificationService -> NotificationPreferencesService (نفس تبديل
 *    بند 6/8، الميثودز الفعلية زي ما هي).
 *  - $this->validate([...]) -> Validator::make() صريح + updatePassword()
 *    بتاخد UserRepository::updatePassword() زيادة عن
 *    PasswordPolicyService::recordPasswordChange() (نفس نمط
 *    باقي كنترولرز الـ Settings::updatePassword()).
 *  - \RuntimeException -> \InvalidArgumentException (شوف
 *    PasswordPolicyService الحالية).
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='supervisor' بتتفحص
 * كمان جوه كل ميثود عبر uip_role.
 */
class SupervisorSettingsApiController extends Controller
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private UserRepository $users,
        private PasswordPolicyService $passwordPolicy,
        private NotificationPreferencesService $notifications,
        private AuditLogService $auditLog,
        private FileUploadService $uploads
    ) {
    }

    /** GET /api/v1/supervisor/settings — بروفايل روستر للقراءة بس + نطاقاته + تفضيلات الإشعارات. Role مشرف بس. */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can view these settings.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $supervisor = $this->supervisors->findActiveByUserId($userId);

        $user = User::find($userId);

        return $this->apiSuccess([
            'supervisor'              => $supervisor,
            'profile'                 => $user ? $this->profileRow($user) : null,
            'scopes'                  => $supervisor ? $this->assignments->forSupervisorWithLabels($supervisor->id) : [],
            'notification_categories' => $this->notifications->categoryLabels(),
            'muted_categories'        => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'        => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'             => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /** The account fields the supervisor owns (users row) — shape shared by index() and updateProfile(). */
    private function profileRow(User $user): array
    {
        return [
            'full_name'   => $user->full_name,
            'name_ar'     => $user->name_ar,
            'name_en'     => $user->name_en,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'avatar_path' => $user->avatar_path,
        ];
    }

    /**
     * PATCH /api/v1/supervisor/settings/profile — the supervisor fixes their
     * OWN name (Arabic + English), phone, academic title and department
     * without going back to the university. Email, permissions, supervision
     * scope and status stay university-managed. Role مشرف بس.
     */
    public function updateProfile(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can update this profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $supervisor = $this->supervisors->findActiveByUserId($userId);
        if (!$user || !$supervisor) {
            return $this->apiError('Your supervisor account is not currently active.', null, 404);
        }

        $locale = \App\Support\BilingualName::localeOf($request);
        $userFill = [];
        $supFill = [];

        if ($request->has('name_ar') || $request->has('name_en')) {
            $names = \App\Support\BilingualName::resolve(
                $request->input('name_ar', $user->name_ar),
                $request->input('name_en', $user->name_en),
                $locale
            );
            if (!$names['ok']) {
                return $this->apiError('Validation failed.', $names['errors'], 422);
            }
            // users.* AND the roster row both carry the name — keep them identical.
            $userFill = \App\Support\BilingualName::columns($names);
            $supFill = \App\Support\BilingualName::columns($names);
        }

        $phone = \App\Support\ProfilePhone::fromRequest($request, $locale);
        if (!$phone['ok']) {
            return $this->apiError('Validation failed.', ['phone' => $phone['error']], 422);
        }
        if ($phone['present']) {
            $userFill['phone'] = $phone['value'];
        }

        foreach (['title' => 100, 'department' => 150] as $field => $max) {
            if ($request->has($field)) {
                $value = trim((string) preg_replace('/\s+/u', ' ', (string) $request->input($field, '')));
                if (mb_strlen($value) > $max) {
                    return $this->apiError('Validation failed.', [$field => "Maximum {$max} characters."], 422);
                }
                $supFill[$field] = $value !== '' ? $value : null;
            }
        }

        if (!$userFill && !$supFill) {
            return $this->apiError('No updatable fields provided.', null, 422);
        }

        $before = [
            'name_ar' => $user->name_ar, 'name_en' => $user->name_en, 'phone' => $user->phone,
            'title' => $supervisor->title, 'department' => $supervisor->department,
        ];

        if ($userFill) {
            $user->fill($userFill);
            $user->save();
        }
        if ($supFill) {
            $supervisor->fill($supFill);
            $supervisor->save();
        }

        $this->auditLog->record($userId, 'supervisor.profile_update', 'Supervisor', $supervisor->id, $before, [
            'name_ar' => $user->name_ar, 'name_en' => $user->name_en, 'phone' => $user->phone,
            'title' => $supervisor->title, 'department' => $supervisor->department,
        ]);

        return $this->apiSuccess([
            'supervisor' => $supervisor->fresh(),
            'profile'    => $this->profileRow($user->fresh()),
        ], 'Profile updated successfully.');
    }

    /** POST /api/v1/supervisor/settings/avatar — the supervisor's own profile photo (users.avatar_path). */
    public function uploadAvatar(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can update this profile photo.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        $file = $request->file('avatar');
        if (!$file) {
            return $this->apiError('avatar file is required.', null, 422);
        }

        try {
            $stored = $this->uploads->store($file, 'avatars', (string) $user->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($user->avatar_path) {
            $this->uploads->delete($user->avatar_path);
        }

        $before = ['avatar_path' => $user->avatar_path];
        $user->fill(['avatar_path' => $stored['stored_path']]);
        $user->save();
        $this->auditLog->record($userId, 'supervisor.avatar_update', 'User', $user->id, $before, ['avatar_path' => $user->avatar_path]);

        return $this->apiSuccess(['avatar_path' => $user->avatar_path], 'Photo updated successfully.');
    }

    /** PATCH /api/v1/supervisor/settings/notifications — Role مشرف بس. */
    public function updateNotificationPreferences(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can update notification preferences.', null, 403);
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
        $this->auditLog->record($userId, 'supervisor.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /** PATCH /api/v1/supervisor/settings/password — Role مشرف بس. */
    public function updatePassword(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can update this password.', null, 403);
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

        return $this->apiSuccess(null, 'Password updated successfully.');
    }
}
