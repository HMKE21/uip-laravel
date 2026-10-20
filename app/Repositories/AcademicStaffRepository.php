<?php

namespace App\Repositories;

use App\Models\AcademicStaff;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AcademicStaffRepository.php القديمة
 * (Core\Model -> Eloquent) — بند 10. findOwnedByUniversity() هي
 * lookup مقيّدة الملكية اللي كل تعديل المفروض يعدي عليها — جامعة تانية
 * عمرها ما تقدر تقرا/تعدّل عضو هيئة تدريس تابع لجامعة تانية. $facultyId
 * الاختياري بيضيف تقييد إضافي لنطاق كلية واحدة، عشان بورتال الكلية يقدر
 * يعيد استخدام نفس ميثودز التعديل بأمان (زي StudentRepository::findOwned()
 * بالظبط).
 */
class AcademicStaffRepository
{
    /** كل أعمدة academic_staff ما عدا password_encrypted — للاستخدام في الـ raw SELECTs المدموجة تحت. */
    private const LISTING_COLUMNS = "SELECT s.id, s.user_id, s.university_id, s.faculty_id, s.department_id,
                    s.academic_rank_id, s.staff_number, s.bio, s.status,
                    s.invitation_status, s.invited_at, s.accepted_at, s.expires_at,
                    s.created_at, s.updated_at";

    public function find($id): ?AcademicStaff
    {
        return AcademicStaff::find($id);
    }

    public function findByUserId($userId): ?AcademicStaff
    {
        return AcademicStaff::where('user_id', $userId)->first();
    }

    /**
     * صف academic_staff للمشرف/المعيد (بيتعمل مرة واحدة). الرتبة "معيد" (Demonstrator)
     * لو موجودة في academic_ranks، والكلية/القسم فاضيين (المشرف نطاقه على مستوى
     * الجامعة). بيستخدمه UipSupervisorAsStaffMiddleware بس.
     */
    public function ensureForSupervisor($supervisor): AcademicStaff
    {
        $existing = $this->findByUserId($supervisor->user_id);
        if ($existing) {
            return $existing;
        }

        // الرتب الافتراضية للمنصة (university_id NULL) — "معيد" ثم "معيد مساعد تدريس".
        $rankId = DB::table('academic_ranks')
            ->whereNull('university_id')
            ->whereIn('name_en', ['Demonstrator', 'Teaching Assistant'])
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->value('id');

        return AcademicStaff::create([
            'user_id'           => $supervisor->user_id,
            'university_id'     => $supervisor->university_id,
            'academic_rank_id'  => $rankId ?: null,
            'status'            => 'active',
            'invitation_status' => 'accepted',
            'accepted_at'       => now(),
        ]);
    }

    /** @param int|null $facultyId لو موجودة، بتشترط كمان إن faculty_id بتاع العضو نفسه يطابقها */
    public function findOwnedByUniversity($id, $universityId, $facultyId = null): ?AcademicStaff
    {
        $staff = AcademicStaff::find($id);
        if (!$staff || (int) $staff->university_id !== (int) $universityId) {
            return null;
        }
        if ($facultyId !== null && (int) $staff->faculty_id !== (int) $facultyId) {
            return null;
        }
        return $staff;
    }

    public function findOwnedByDepartment($id, $departmentId): ?AcademicStaff
    {
        $staff = AcademicStaff::find($id);
        if (!$staff || (int) $staff->department_id !== (int) $departmentId) {
            return null;
        }
        return $staff;
    }

    /** @return AcademicStaff[] كل أعضاء هيئة التدريس بجامعة واحدة */
    public function forUniversity($universityId, ?string $status = null): array
    {
        $query = AcademicStaff::where('university_id', $universityId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderByDesc('id')->get()->all();
    }

    /** @return AcademicStaff[] كل أعضاء هيئة التدريس بكلية واحدة */
    public function forFaculty($facultyId, ?string $status = null): array
    {
        $query = AcademicStaff::where('faculty_id', $facultyId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderByDesc('id')->get()->all();
    }

    /** @return AcademicStaff[] كل أعضاء هيئة التدريس بقسم واحد */
    public function forDepartment($departmentId, ?string $status = null): array
    {
        $query = AcademicStaff::where('department_id', $departmentId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderByDesc('id')->get()->all();
    }

    /**
     * روستر مدموج مع اسم/إيميل الحساب + اسم الرتبة — الشكل اللي صفحة
     * روستر الجامعة محتاجاه، كويري واحدة بدل N+1.
     * @return array<int,array<string,mixed>>
     */
    public function forUniversityWithDetails($universityId): array
    {
        // DB::select() returns stdClass rows, not arrays — cast each one
        // (same convention as AdvancedAnalyticsRepository::columns()) since
        // the caller (AcademicStaffApiController::withStatus() ->
        // AcademicStaffManagementService::effectiveInvitationStatus())
        // does array access/type-hints against these rows.
        // s.* عمدًا مش مستخدمة — s.password_encrypted (migration
        // 2026_09_04_000000) ما لازمش تظهر في روستر عام؛ الوصول ليها بس
        // عبر AcademicStaffManagementService::revealPassword().
        return array_map(fn ($r) => (array) $r, DB::select(
            self::LISTING_COLUMNS . ", u.full_name, u.name_ar, u.name_en, u.email,
                    r.name_en AS rank_name_en, r.name_ar AS rank_name_ar,
                    f.name_en AS faculty_name_en, f.name_ar AS faculty_name_ar,
                    d.name_en AS department_name_en, d.name_ar AS department_name_ar
             FROM academic_staff s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN academic_ranks r ON r.id = s.academic_rank_id
             LEFT JOIN faculties f ON f.id = s.faculty_id
             LEFT JOIN departments d ON d.id = s.department_id
             WHERE s.university_id = ?
             ORDER BY u.full_name ASC",
            [$universityId]
        ));
    }

    /**
     * زي forUniversityWithDetails() بس مقيّدة بكلية واحدة — نسخة بورتال
     * الكلية.
     * @return array<int,array<string,mixed>>
     */
    public function forFacultyWithDetails($facultyId): array
    {
        // Same stdClass -> array cast as forUniversityWithDetails() above,
        // for the same reason.
        // نفس ملاحظة forUniversityWithDetails() فوق — s.* مش مستخدمة عمدًا.
        return array_map(fn ($r) => (array) $r, DB::select(
            self::LISTING_COLUMNS . ", u.full_name, u.name_ar, u.name_en, u.email,
                    r.name_en AS rank_name_en, r.name_ar AS rank_name_ar,
                    d.name_en AS department_name_en, d.name_ar AS department_name_ar
             FROM academic_staff s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN academic_ranks r ON r.id = s.academic_rank_id
             LEFT JOIN departments d ON d.id = s.department_id
             WHERE s.faculty_id = ?
             ORDER BY u.full_name ASC",
            [$facultyId]
        ));
    }

    public function countByStatusForFaculty($facultyId, string $status): int
    {
        return AcademicStaff::where('faculty_id', $facultyId)->where('status', $status)->count();
    }

    public function create(array $data): AcademicStaff
    {
        return AcademicStaff::create($data);
    }

    public function countByStatus($universityId, string $status): int
    {
        return AcademicStaff::where('university_id', $universityId)->where('status', $status)->count();
    }

    public function findByStaffNumber($universityId, string $staffNumber): ?AcademicStaff
    {
        return AcademicStaff::where('university_id', $universityId)->where('staff_number', $staffNumber)->first();
    }

    /**
     * بروفايل عضو هيئة تدريس مدموج مع اسم/إيميل الحساب + اسم الرتبة/
     * الكلية/القسم — لصفحة AcademicStaffSettings.jsx (`/academic-staff/
     * settings`), نفس نمط StudentRepository::withProfileDetails() بالظبط
     * بس مفتاحة بـ user_id (سطح الحساب اللوجن، مش لوكب بـ id إداري).
     */
    public function withProfileDetailsByUserId($userId): ?array
    {
        $row = DB::table('academic_staff as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('academic_ranks as r', 'r.id', '=', 's.academic_rank_id')
            ->leftJoin('faculties as f', 'f.id', '=', 's.faculty_id')
            ->leftJoin('departments as d', 'd.id', '=', 's.department_id')
            ->select(
                's.*',
                'u.full_name', 'u.name_ar', 'u.name_en', 'u.email', 'u.phone', 'u.avatar_path',
                'r.name_en as rank_name_en', 'r.name_ar as rank_name_ar',
                'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar',
                'd.name_en as department_name_en', 'd.name_ar as department_name_ar'
            )
            ->where('s.user_id', $userId)
            ->first();

        return $row ? (array) $row : null;
    }
}
