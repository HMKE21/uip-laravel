<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\Paginates;
use App\Repositories\AcademicRankRepository;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StaffAssignmentRepository;
use App\Repositories\UniversityRepository;
use App\Services\AcademicStaffManagementService;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AcademicStaffApiController.php القديمة —
 * بند 10. سطح REST واحد /api/v1/academic-staff/* لجدول `academic_staff`
 * (migration 101)، بيلف AcademicStaffManagementService بالظبط زي ما
 * University\UniversityAcademicStaffController و
 * Faculty\FacultyAcademicStaffController القديمتين كانتا بتعملوا — البورتال
 * الاتنين بيديروا نفس الجدول، بس بنطاق مختلف:
 *
 *   - Role جامعة: بتشوف/تدير كل أعضاء هيئة التدريس بجامعتها (university_id
 *     محلول من uip_user_id، من غير أي نطاق كلية).
 *   - Role كلية: بتشوف/تدير أعضاء كليتها بس (faculty_id محلول من
 *     FacultyRepository::findByUserId()، ممرر كنطاق $facultyId اختياري
 *     لكل نداء على الـ service — نفس آلية docblock
 *     FacultyAcademicStaffController القديمة بالظبط).
 *
 * index()/show() بيستخدموا AcademicStaffRepository::forUniversityWithDetails()/
 * forFacultyWithDetails() (اسم/إيميل/رتبة/كلية/قسم مدموجين)؛ store() بتلف
 * invite()؛ update() بتلف updateAssignment()؛ activate()/deactivate()/
 * resend()/destroy() تغليف رفيع لنفس نداءات الـ service.
 *
 * إسناد القيادة (assignLeadership()/endLeadership() — ألقاب Dean/Head of
 * Department) خارج نطاق الكنترولر ده — نفس ما كان في القديمة (List/Show/
 * Update بس في api/v1/academic-staff الأصلية).
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php)؛ كل ميثود بتتأكد من
 * uip_role بنفسها لأن الجامعة والكلية بينادوا بنطاق مختلف، مطابقة للكنترولرين
 * القديمين. كل تعديل بيعيد استخراج university_id/faculty_id بتاع الكولر نفسه
 * من uip_user_id — عمرها ما تاخد id من العميل.
 */
class AcademicStaffApiController extends Controller
{
    use Paginates;

    public function __construct(
        private AcademicStaffRepository $staff,
        private FacultyRepository $faculties,
        private UniversityRepository $universities,
        private StaffAssignmentRepository $staffAssignments,
        private AcademicStaffManagementService $management,
        private AcademicRankRepository $ranks,
        private AuditLogService $auditLog,
        private FileUploadService $uploads
    ) {
    }

    /**
     * GET /api/v1/academic-staff/me — منصب/تاريخ قيادة دكتور/محاضر/معيد
     * نفسه، محلول عبر AcademicStaffRepository::findByUserId(uip_user_id) —
     * أبدًا id من العميل. Role عضو هيئة تدريس بس.
     */
    public function me(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts have this profile.', null, 403);
        }

        $staff = $this->staff->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return $this->apiError('Academic staff profile not found.', null, 404);
        }

        return $this->apiSuccess([
            'staff'      => $staff->toArray(),
            // اسم/إيميل/صورة/رتبة/كلية/قسم مدموجين — لصفحة /academic-staff/profile.
            'profile'    => $this->staff->withProfileDetailsByUserId((int) $request->attributes->get('uip_user_id')),
            'leadership' => $this->staffAssignments->historyForStaff($staff->id),
        ], 'Academic staff profile retrieved successfully.');
    }

    /**
     * POST /api/v1/academic-staff/me/avatar — صورة الحساب الشخصية لعضو هيئة
     * التدريس نفسه (users.avatar_path)، نفس منطق FacultyApiController::
     * uploadAvatar(). الـ user محلول من uip_user_id، أبدًا id من العميل.
     */
    public function uploadAvatar(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can update this profile photo.', null, 403);
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

        $this->auditLog->record($userId, 'academic_staff.avatar_update', 'User', $user->id, $before, ['avatar_path' => $stored['stored_path']]);

        return $this->apiSuccess(['avatar_path' => $stored['stored_path']], 'Profile photo updated successfully.');
    }

    // -- الدليل ------------------------------------------------------------

    /**
     * GET /api/v1/academic-staff/ranks — رتب افتراضية على المنصة + رتب
     * الجامعة دي الخاصة، لقايمة الرتبة في فورم الدعوة/التعديل. Role جامعة
     * أو كلية (رتب الكلية هي نفس قايمة جامعتها).
     */
    public function ranks(Request $request)
    {
        [$universityId, , $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $rows = array_map(fn ($r) => $r->toArray(), $this->ranks->availableFor($universityId));

        return $this->apiSuccess($rows, 'Academic ranks retrieved successfully.');
    }

    /**
     * GET /api/v1/academic-staff — روستر الكولر نفسه (جامعة كاملة أو
     * مقيّد بكلية، شوف docblock الكلاس). بتدعم `search` (full_name/email)
     * و`page`/`per_page`.
     */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') === 'university') {
            $university = $this->universities->getOrCreate((int) $request->attributes->get('uip_user_id'));
            $rows = $this->filterBySearch($request, $this->withStatus($this->staff->forUniversityWithDetails($university->id)), ['full_name', 'name_ar', 'name_en', 'email']);
            [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);
            return $this->apiSuccess($items, 'Academic staff retrieved successfully.', 200, $this->meta($page, $perPage, $total));
        }

        if ($request->attributes->get('uip_role') === 'faculty') {
            $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
            if (!$faculty) {
                return $this->apiSuccess([], 'Academic staff retrieved successfully.', 200, $this->meta(1, 20, 0));
            }
            $rows = $this->filterBySearch($request, $this->withStatus($this->staff->forFacultyWithDetails($faculty->id)), ['full_name', 'name_ar', 'name_en', 'email']);
            [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);
            return $this->apiSuccess($items, 'Academic staff retrieved successfully.', 200, $this->meta($page, $perPage, $total));
        }

        return $this->apiError('Only university or faculty accounts can list academic staff.', null, 403);
    }

    /** GET /api/v1/academic-staff/{id} — محكوم بالملكية (جامعة كاملة أو مقيّد بكلية). */
    public function show(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $member = $this->staff->findOwnedByUniversity($id, $universityId, $facultyId);
        if (!$member) {
            return $this->apiError('Staff member not found.', null, 404);
        }

        return $this->apiSuccess($member->toArray(), 'Academic staff member retrieved successfully.');
    }

    // -- التعديلات ---------------------------------------------------------

    /**
     * POST /api/v1/academic-staff — دعوة. Role جامعة تقدر تستهدف أي كلية/
     * قسم بتاعها (faculty_id من الـ body)؛ Role كلية دايمًا مقفولة على
     * faculty_id بتاعها هي — أبدًا قيمة من العميل. `password` اختياري في
     * الـ body — فاضي يبقى عشوائي زي الأول؛ محدد يبقى مخصص (8 أحرف على
     * الأقل). النتيجة بترجع `password` (النص الصريح) في `data` عشان
     * الواجهة تعرضه للمستخدم مرة واحدة وقت الإنشاء.
     */
    public function store(Request $request)
    {
        $names = \App\Support\BilingualName::fromRequest($request);
        $email = trim((string) $request->input('email', ''));
        $errors = $names['errors'];
        if ($email === '') {
            $errors['email'] = 'Required.';
        }
        if ($errors) {
            return $this->apiError('Validation failed.', $errors, 422);
        }
        $fullName = $names['full_name'];

        $departmentId = $request->input('department_id') !== null && $request->input('department_id') !== '' ? (int) $request->input('department_id') : null;
        $rankId = $request->input('academic_rank_id') !== null && $request->input('academic_rank_id') !== '' ? (int) $request->input('academic_rank_id') : null;
        $password = $request->input('password') !== null && trim((string) $request->input('password')) !== '' ? trim((string) $request->input('password')) : null;
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($request->attributes->get('uip_role') === 'university') {
            $university = $this->universities->getOrCreate($userId);
            $facultyId = $request->input('faculty_id') !== null && $request->input('faculty_id') !== '' ? (int) $request->input('faculty_id') : null;

            $result = $this->management->invite(
                $university->id,
                $userId,
                $fullName,
                $email,
                $facultyId,
                $departmentId,
                $rankId,
                $request->input('staff_number'),
                $request->input('bio'),
                $password,
                \App\Support\BilingualName::localeOf($request),
                $names['name_ar'],
                $names['name_en']
            );
        } elseif ($request->attributes->get('uip_role') === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            if (!$faculty) {
                return $this->apiError('This login is not linked to a faculty.', null, 422);
            }

            $result = $this->management->invite(
                $faculty->university_id,
                $userId,
                $fullName,
                $email,
                (int) $faculty->id, // مقفول على كلية الكولر نفسه — أبدًا من العميل
                $departmentId,
                $rankId,
                $request->input('staff_number'),
                $request->input('bio'),
                $password,
                \App\Support\BilingualName::localeOf($request),
                $names['name_ar'],
                $names['name_en']
            );
        } else {
            return $this->apiError('Only university or faculty accounts can invite academic staff.', null, 403);
        }

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password'] ?? null], $result['message'], 201)
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * PATCH /api/v1/academic-staff/{id} — faculty/department/rank/bio.
     * Role جامعة تقدر تستهدف أي كلية/قسم بتاعها؛ Role كلية مقيّدة على
     * أعضائها هي بس (updateAssignment()'s $scopeFacultyId) وأبدًا تنقل
     * عضو لكلية تانية.
     */
    public function update(Request $request, $id)
    {
        $departmentId = $request->input('department_id') !== null && $request->input('department_id') !== '' ? (int) $request->input('department_id') : null;
        $rankId = $request->input('academic_rank_id') !== null && $request->input('academic_rank_id') !== '' ? (int) $request->input('academic_rank_id') : null;
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($request->attributes->get('uip_role') === 'university') {
            $university = $this->universities->getOrCreate($userId);
            $facultyId = $request->input('faculty_id') !== null && $request->input('faculty_id') !== '' ? (int) $request->input('faculty_id') : null;

            $result = $this->management->updateAssignment(
                $id,
                $university->id,
                $userId,
                $facultyId,
                $departmentId,
                $rankId,
                $request->input('bio')
            );
        } elseif ($request->attributes->get('uip_role') === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            if (!$faculty) {
                return $this->apiError('This login is not linked to a faculty.', null, 422);
            }

            $result = $this->management->updateAssignment(
                $id,
                $faculty->university_id,
                $userId,
                (int) $faculty->id, // مقفول على كلية الكولر نفسه
                $departmentId,
                $rankId,
                $request->input('bio'),
                'ar',
                (int) $faculty->id // $scopeFacultyId — يمنع تعديل عضو تابع لكلية تانية
            );
        } else {
            return $this->apiError('Only university or faculty accounts can update a staff assignment.', null, 403);
        }

        return $result['success']
            ? $this->apiSuccess(null, $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * POST /api/v1/academic-staff/ranks — رتبة مخصصة جديدة
     * (AcademicRankRepository::create())، مقفولة على جامعة الكولر
     * (university_id، مش platform-default). متاحة للجامعة أو الكلية —
     * رتبة اتضافت من كلية بتبقى متاحة لجامعتها كلها (مش مقفولة على
     * الكلية دي بس)، نفس قرار القديمة.
     */
    public function storeRank(Request $request)
    {
        $nameEn = trim((string) $request->input('name_en', ''));
        $nameAr = trim((string) $request->input('name_ar', ''));
        if ($nameEn === '' || $nameAr === '') {
            return $this->apiError('Validation failed.', ['name_en' => 'Required.', 'name_ar' => 'Required.'], 422);
        }

        [$universityId, , $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $category = $request->input('category') === 'administrative' ? 'administrative' : 'academic';

        $rank = $this->ranks->create([
            'university_id' => $universityId,
            'name_ar'       => $nameAr,
            'name_en'       => $nameEn,
            'category'      => $category,
            'sort_order'    => 100,
            'is_active'     => 1,
        ]);

        $auditAction = $request->attributes->get('uip_role') === 'faculty' ? 'faculty.rank_create' : 'university.rank_create';
        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), $auditAction, 'AcademicRank', $rank->id, null, $rank->toArray());

        return $this->apiSuccess($rank->toArray(), 'Academic rank created successfully.', 201);
    }

    /** POST /api/v1/academic-staff/{id}/activate — Role جامعة أو كلية مقيّدة. */
    public function activate(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $ok = $this->management->activate($id, $universityId, (int) $request->attributes->get('uip_user_id'), $facultyId);

        return $ok
            ? $this->apiSuccess(null, 'Staff account activated successfully.')
            : $this->apiError('Staff member not found.', null, 404);
    }

    /** POST /api/v1/academic-staff/{id}/deactivate — Role جامعة أو كلية مقيّدة. */
    public function deactivate(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $ok = $this->management->deactivate($id, $universityId, (int) $request->attributes->get('uip_user_id'), $facultyId);

        return $ok
            ? $this->apiSuccess(null, 'Staff account deactivated — they can no longer sign in.')
            : $this->apiError('Staff member not found.', null, 404);
    }

    /**
     * POST /api/v1/academic-staff/{id}/resend — إعادة إرسال إيميل الدعوة.
     * Role جامعة أو كلية مقيّدة. `password` اختياري في الـ body (زي
     * store())؛ النتيجة بترجع `password` الجديد في `data`.
     */
    public function resend(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $password = $request->input('password') !== null && trim((string) $request->input('password')) !== '' ? trim((string) $request->input('password')) : null;
        $result = $this->management->resendInvite($id, $universityId, (int) $request->attributes->get('uip_user_id'), $password, 'ar', $facultyId);

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password'] ?? null], $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * PATCH /api/v1/academic-staff/{id}/password — تحديد/تغيير كلمة مرور
     * عضو مباشرة، شغالة حتى لو دعوته اتقبلت خلاص (بعكس /resend اللي
     * بيتوقف لما invitation_status = accepted). `password` اختياري —
     * فاضي يبقى عشوائي جديد. Role جامعة أو كلية مقيّدة.
     */
    public function setPassword(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $password = $request->input('password') !== null && trim((string) $request->input('password')) !== '' ? trim((string) $request->input('password')) : null;
        $result = $this->management->setPassword($id, $universityId, (int) $request->attributes->get('uip_user_id'), $password, 'ar', $facultyId);

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password'] ?? null], $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * GET /api/v1/academic-staff/{id}/password — فك تشفير وعرض كلمة
     * المرور المحفوظة الحالية للعضو (زرار "عرض" في الواجهة). كل استدعاء
     * بيتسجل في الـ audit log — راجع
     * AcademicStaffManagementService::revealPassword(). Role جامعة أو
     * كلية مقيّدة.
     */
    public function revealPassword(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $result = $this->management->revealPassword($id, $universityId, (int) $request->attributes->get('uip_user_id'), 'ar', $facultyId);

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password']], 'Password retrieved successfully.')
            : $this->apiError($result['message'], null, 404);
    }

    /**
     * POST /api/v1/academic-staff/import — multipart/form-data: file
     * (.csv أو .xlsx). كل صف بيتحول لدعوة عادية عبر
     * AcademicStaffManagementService::importRows() (نفس فحوصات store()
     * بالظبط، صف صف — صف فيه خطأ ميوقفش الباقي). Role جامعة (تقدر تحدد
     * faculty_id اختياري في الـ body لتوجيه كل الصفوف لكلية واحدة) أو
     * كلية (مقفولة على كليتها هي، زي store()).
     */
    public function import(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->apiError('Validation failed.', ['file' => 'A .csv or .xlsx file is required.'], 422);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        try {
            $rows = \App\Support\TabularFileReader::readAssoc($file->getRealPath(), $extension);
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage() ?: 'Could not read the uploaded file.', null, 422);
        }

        if (empty($rows)) {
            return $this->apiError('The file has no data rows.', null, 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        if ($request->attributes->get('uip_role') === 'university') {
            $university = $this->universities->getOrCreate($userId);
            $facultyId = $request->input('faculty_id') !== null && $request->input('faculty_id') !== '' ? (int) $request->input('faculty_id') : null;
            $summary = $this->management->importRows($rows, $university->id, $userId, $facultyId);
        } elseif ($request->attributes->get('uip_role') === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            if (!$faculty) {
                return $this->apiError('This login is not linked to a faculty.', null, 422);
            }
            $summary = $this->management->importRows($rows, $faculty->university_id, $userId, (int) $faculty->id);
        } else {
            return $this->apiError('Only university or faculty accounts can import academic staff.', null, 403);
        }

        return $this->apiSuccess($summary, 'Import finished.');
    }

    /** DELETE /api/v1/academic-staff/{id} — Role جامعة أو كلية مقيّدة. */
    public function destroy(Request $request, $id)
    {
        [$universityId, $facultyId, $error] = $this->resolveScope($request);
        if ($error) {
            return $error;
        }

        $ok = $this->management->delete($id, $universityId, (int) $request->attributes->get('uip_user_id'), $facultyId);

        return $ok
            ? $this->apiSuccess(null, 'Staff member removed successfully.')
            : $this->apiError('Staff member not found.', null, 404);
    }

    // -- Helpers -------------------------------------------------------------

    /** @return array{0:?int,1:?int,2:mixed} [universityId, facultyId, earlyErrorResponse] */
    private function resolveScope(Request $request): array
    {
        if ($request->attributes->get('uip_role') === 'university') {
            $university = $this->universities->getOrCreate((int) $request->attributes->get('uip_user_id'));
            return [(int) $university->id, null, null];
        }

        if ($request->attributes->get('uip_role') === 'faculty') {
            $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
            if (!$faculty) {
                return [null, null, $this->apiError('This login is not linked to a faculty.', null, 422)];
            }
            return [(int) $faculty->university_id, (int) $faculty->id, null];
        }

        return [null, null, $this->apiError('Only university or faculty accounts can manage academic staff.', null, 403)];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function withStatus(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['effective_invitation_status'] = AcademicStaffManagementService::effectiveInvitationStatus($row);
        }
        unset($row);
        return $rows;
    }
}
