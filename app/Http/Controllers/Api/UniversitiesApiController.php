<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\University;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Repositories\UniversityRepository;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\NotificationPreferencesService;
use App\Services\UniversityBrandingService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/UniversitiesApiController.php القديمة —
 * نفس الـ 14 endpoint بالظبط (directory + self-service profile + admin
 * verification workflow + innovation hub)، نفس شكل الـ JSON، نفس رسائل
 * الأخطاء.
 *
 * فرق شكلي فقط عن القديمة:
 *  - Session::userId()/Session::hasRole() -> $request->attributes->get('uip_user_id')/'uip_role'
 *    (بتتحط من UipAuthMiddleware — شوف الملحوظة في UniversitiesController الأصلية).
 *  - app_url() القديمة -> config('app.url') مباشرة (مفيش helper مطابق هنا).
 *  - AuditLogService هنا شريحة record()-فقط (شوف ملحوظتها)، NotificationService
 *    هنا NotificationPreferencesService (شريحة الـ 7 متودز الخاصة بالتفضيلات فقط).
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php)؛ uip.admin بتغطي إضافة
 * روتس قرار الاعتماد بس. كل self-service action بتستخرج الجامعة بتاعة
 * الكولر نفسه من uip_user_id — أبدًا مش من id جاي من العميل.
 */
class UniversitiesApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private UniversityRepository $universities,
        private SettingRepository $settings,
        private FileUploadService $uploads,
        private AuditLogService $auditLog,
        private NotificationPreferencesService $notifications,
        private UniversityBrandingService $branding
    ) {
    }

    // -- Directory ----------------------------------------------------------

    /**
     * GET /api/v1/universities — الأدمن بيشوف كل الحالات (طابور الاعتماد
     * كمان)؛ أي حد تاني بيشوف الـ directory العام (verified بس).
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->input('search', (string) $request->input('q', '')));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));

        $isAdmin = $request->attributes->get('uip_role') === 'admin';
        $status = $isAdmin ? (string) $request->input('status', '') : 'verified';

        $result = $this->universities->paginateWithStats($q, $status, $page, $perPage);
        $rows = $isAdmin ? $result['rows'] : array_map([$this, 'toPublicRow'], $result['rows']);

        return $this->apiSuccess($rows, 'Universities retrieved successfully.', 200, [
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $result['total'],
            'totalPages' => $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 0,
        ]);
    }

    /** GET /api/v1/universities/{id} — بروفايل + عدد طلاب/مشاريع منشورة لايف. */
    public function show(Request $request, int $id)
    {
        $row = $this->universities->withProfileStats($id);
        if (!$row) {
            return $this->apiError('University not found.', null, 404);
        }

        $isAdmin = $request->attributes->get('uip_role') === 'admin';
        if (!$isAdmin && $row['verification_status'] !== 'verified') {
            return $this->apiError('University not found.', null, 404);
        }

        return $this->apiSuccess($isAdmin ? $row : $this->toPublicRow($row), 'University retrieved successfully.');
    }

    /**
     * GET /api/v1/universities/{id}/innovation-hub — براءات اختراع +
     * عناصر حديثة من كل فئة.
     */
    public function innovationHub(Request $request, int $id)
    {
        $row = $this->universities->withProfileStats($id);
        $isAdmin = $request->attributes->get('uip_role') === 'admin';
        if (!$row || (!$isAdmin && $row['verification_status'] !== 'verified')) {
            return $this->apiError('University not found.', null, 404);
        }

        return $this->apiSuccess([
            'stats'        => $this->universities->innovationHubStats($id),
            'patents'      => $this->universities->innovationHubPatents($id),
        ], 'Innovation hub data retrieved successfully.');
    }

    // -- Self-service (بروفايل/إعدادات الجامعة بتاعة الكولر نفسه) ------------

    /**
     * GET /api/v1/universities/me — بروفايل الجامعة بتاعة الكولر. Role
     * جامعة بس. شامل withProfileStats() (عدد طلاب/مشاريع منشورة لايف)
     * مدموجة، مش الصف الخام بس — نفس call ده كمان بيغطي كروت صفحة الـ
     * Portfolio.
     */
    public function me(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts have a university profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
        $stats = $this->universities->withProfileStats((int) $university->id);

        return $this->apiSuccess([
            'university'               => array_merge($university->toArray(), $stats ?? [], $university->brandingUrls()),
            'user'                     => $user?->toArray(),
            'share_url'                => $this->publicBaseUrl() . '/u/' . ($user->uuid ?? ''),
            'auto_approve_threshold'   => (int) $this->settingsValue($userId, 'auto_approve_threshold', '80'),
            'notify_on_submission'     => $this->settingsValue($userId, 'notify_on_submission', '1') === '1',
            'notification_categories'  => $this->notifications->categoryLabels(),
            'muted_categories'         => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'         => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'              => $this->notifications->quietHoursFor($userId),
        ], 'University profile retrieved successfully.');
    }

    /**
     * PATCH /api/v1/universities/me/visibility — تشغيل/إيقاف صفحة
     * البورتفوليو العامة (/u/{uuid}). Role جامعة بس.
     */
    public function updateVisibility(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update portfolio visibility.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
        $before = ['is_public' => (bool) $university->is_public];

        $university->fill(['is_public' => $request->boolean('is_public')]);
        $university->save();

        $this->auditLog->record($userId, 'university.visibility_update', 'University', $university->id, $before, ['is_public' => (bool) $university->is_public]);

        return $this->apiSuccess(['is_public' => (bool) $university->is_public], 'Portfolio visibility updated successfully.');
    }

    /** PATCH /api/v1/universities/me — الاسم الرسمي/الدولة/المدينة/الموقع. Role جامعة بس. */
    public function updateMe(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a university profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
        $before = $university->toArray();

        $newAr = trim((string) $request->input('official_name_ar', $university->official_name_ar));
        $newEn = trim((string) $request->input('official_name_en', $university->official_name_en));
        $dup = \App\Support\UniversityName::conflicts($newAr, $newEn, $userId, \App\Support\BilingualName::localeOf($request));
        if ($dup) {
            return $this->apiError(reset($dup), $dup, 422);
        }

        $university->fill([
            'official_name_ar' => trim((string) $request->input('official_name_ar', $university->official_name_ar)),
            'official_name_en' => trim((string) $request->input('official_name_en', $university->official_name_en)),
            'country'          => trim((string) $request->input('country', $university->country)),
            'city'             => trim((string) $request->input('city', $university->city)),
            'website'          => trim((string) $request->input('website', $university->website)),
        ]);
        $university->save();

        $this->auditLog->record($userId, 'university.profile_update', 'University', $university->id, $before, $university->toArray());

        return $this->apiSuccess($university->toArray(), 'University profile updated successfully.');
    }

    /**
     * POST /api/v1/universities/me/avatar — رفع multipart. Role جامعة بس.
     * دي صورة الشخص نفسه (الكارت الصغير اللي رابط Profile بيروحله)، مش
     * لوجو الجامعة (uploadLogo() تحت — عمود تاني في صف تاني).
     */
    public function uploadAvatar(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update this profile photo.', null, 403);
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

        $this->auditLog->record($userId, 'university.avatar_update', 'User', $user->id, $before, ['avatar_path' => $stored['stored_path']]);

        return $this->apiSuccess(['avatar_path' => $user->avatar_path], 'Photo updated successfully.');
    }

    /** POST /api/v1/universities/me/logo — رفع multipart. Role جامعة بس. */
    public function uploadLogo(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a university logo.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));

        try {
            $stored = $this->uploads->store($request->file('logo'), 'logos', (string) $university->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($university->logo_path) {
            $this->uploads->delete($university->logo_path);
        }

        $university->fill(['logo_path' => $stored['stored_path']]);
        $university->save();

        $this->auditLog->record($userId, 'university.logo_update', 'University', $university->id, null, ['logo_path' => $stored['stored_path']]);

        return $this->apiSuccess(['logo_path' => $university->logo_path], 'Logo updated successfully.');
    }

    // -- Certificate branding (signature / stamp / dean signature + dean details) ----

    /**
     * POST /api/v1/universities/me/branding/{kind} — multipart field `file` (PNG).
     * kind: signature | stamp | dean_signature. Role جامعة بس، والجامعة بتتاخد من
     * التوكن (مش من أي id جاي من العميل).
     */
    public function uploadBranding(Request $request, string $kind)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update certificate images.', null, 403);
        }
        if (!isset(University::BRANDING_KINDS[$kind])) {
            return $this->apiError('Unknown image type.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
        $column = University::BRANDING_KINDS[$kind];
        $before = $university->{$column};

        try {
            $path = $this->branding->store($university, $kind, $request->file('file'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record(
            $userId, 'university.branding_update', 'University', $university->id,
            [$column => $before], [$column => $path], $request->ip()
        );

        $urls = $university->brandingUrls();
        return $this->apiSuccess($urls + ['url' => $urls[$kind . '_url']], 'Image saved successfully.');
    }

    /** DELETE /api/v1/universities/me/branding/{kind} — Role جامعة بس. */
    public function deleteBranding(Request $request, string $kind)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update certificate images.', null, 403);
        }
        if (!isset(University::BRANDING_KINDS[$kind])) {
            return $this->apiError('Unknown image type.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));

        $removed = $this->branding->remove($university, $kind);
        if ($removed !== null) {
            $this->auditLog->record(
                $userId, 'university.branding_remove', 'University', $university->id,
                [University::BRANDING_KINDS[$kind] => $removed], null, $request->ip()
            );
        }

        return $this->apiSuccess($university->brandingUrls(), 'Image removed successfully.');
    }

    /** PATCH /api/v1/universities/me/dean — اسم ومنصب العميد (عربي/إنجليزي). Role جامعة بس. */
    public function updateDean(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update dean details.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));

        $fields = ['dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar'];
        $before = $university->only($fields);

        try {
            $saved = $this->branding->saveDean($university, $request->only($fields));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($saved) {
            $this->auditLog->record(
                $userId, 'university.dean_update', 'University', $university->id,
                $before, $saved, $request->ip()
            );
        }

        return $this->apiSuccess($university->brandingUrls(), 'Dean details updated successfully.');
    }

    /** PATCH /api/v1/universities/me/preferences — تفضيلات workflow الاعتماد. Role جامعة بس. */
    public function updatePreferences(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update these preferences.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $threshold = max(0, min(100, (int) $request->input('auto_approve_threshold', 80)));
        $notify = $request->boolean('notify_on_submission') ? '1' : '0';

        $this->settings->set('auto_approve_threshold', (string) $threshold, 'user', $userId);
        $this->settings->set('notify_on_submission', $notify, 'user', $userId);

        return $this->apiSuccess([
            'auto_approve_threshold' => $threshold,
            'notify_on_submission'   => $notify === '1',
        ], 'Preferences updated successfully.');
    }

    /** PATCH /api/v1/universities/me/notification-preferences — يطابق UniversitySettingsController::updateNotificationPreferences() القديمة. */
    public function updateNotificationPreferences(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update notification preferences.', null, 403);
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
            'muted_categories' => $muted,
            'digest_frequency' => $digest,
            'quiet_hours'      => ['start' => $quietStart ?: null, 'end' => $quietEnd ?: null],
        ];
        $this->auditLog->record($userId, 'university.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences updated successfully.');
    }

    // -- Admin verification workflow -----------------------------------------

    /** POST /api/v1/universities/{id}/verify — Admin بس. */
    public function verify(Request $request, int $id)
    {
        return $this->decide($request, $id, 'verified', 'University verified successfully.', 'university.verify');
    }

    /** POST /api/v1/universities/{id}/reject — Admin بس. */
    public function reject(Request $request, int $id)
    {
        return $this->decide($request, $id, 'rejected', 'University application rejected.', 'university.reject');
    }

    /** PATCH /api/v1/universities/{id}/reverification — Admin بس. */
    public function updateReverification(Request $request, int $id)
    {
        $periodDays = (int) $request->input('verification_period_days', 0);
        $autoReverify = filter_var($request->input('auto_reverify_enabled', false), FILTER_VALIDATE_BOOLEAN);

        $ok = $this->universities->updateReverificationSettings($id, $periodDays > 0 ? $periodDays : null, $autoReverify);
        if (!$ok) {
            return $this->apiError('University not found.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $this->auditLog->record($userId, 'university.reverification_settings_update', 'University', $id, null, [
            'verification_period_days' => $periodDays ?: null,
            'auto_reverify_enabled'    => $autoReverify,
        ]);

        return $this->apiSuccess(null, 'Re-verification settings updated successfully.');
    }

    /** DELETE /api/v1/universities/{id} — Admin بس، حذف نهائي (بيوقف الحساب المرتبط كمان). */
    public function destroy(Request $request, int $id)
    {
        $ok = $this->universities->delete($id);
        if (!$ok) {
            return $this->apiError('University not found.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $this->auditLog->record($userId, 'university.delete', 'University', $id);

        return $this->apiSuccess(null, 'University removed successfully.');
    }

    private function decide(Request $request, int $id, string $status, string $successMessage, string $action)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->universities->decide($id, $status, $userId);
        if (!$ok) {
            return $this->apiError('University not found, or already decided.', null, 409);
        }

        $this->auditLog->record($userId, $action, 'University', $id, ['verification_status' => 'pending'], ['verification_status' => $status]);

        return $this->apiSuccess(null, $successMessage);
    }

    // -- helpers --------------------------------------------------------------

    /** شكل آمن للعرض العام — بيشيل حقول workflow الاعتماد الداخلية لأي كولر مش أدمن. */
    private function toPublicRow(array $row): array
    {
        return [
            'id'                        => (int) $row['id'],
            'official_name_ar'          => $row['official_name_ar'],
            'official_name_en'          => $row['official_name_en'],
            'slug'                      => $row['slug'] ?? null,
            'country'                   => $row['country'],
            'city'                      => $row['city'],
            'website'                   => $row['website'],
            'logo_path'                 => $row['logo_path'],
            'students_count'            => (int) ($row['students_count'] ?? 0),
            'published_projects_count'  => (int) ($row['published_projects_count'] ?? ($row['projects_count'] ?? 0)),
        ];
    }

    private function settingsValue(int $userId, string $key, string $default): string
    {
        return (string) $this->settings->get($key, 'user', $userId, $default);
    }

    /**
     * أساس رابط الفرونت (SPA) عشان بناء share_url — config('app.url') هنا
     * عنوان الباك إند نفسه (Laravel API)، مش الفرونت الـ React اللي شغال
     * على بورت/دومين تاني في التطوير (Vite على :5173 مثلًا). نفس منطق
     * MailService::loginUrl() بالظبط: FRONTEND_URL لو متظبطة في .env،
     * وإلا رجوع لـ APP_URL (تطابق بروداكشن اللي بيفضّوا نفس الدومين).
     */
    private function publicBaseUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
    }
}
