<?php

namespace App\Repositories;

use App\Models\StudentUniversityRequest;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/StudentUniversityRequestRepository.php
 * القديمة. pendingForUniversity()/forUniversity()/decide() بتخدم جانب
 * الجامعة (UniversityJoinRequestsApiController عبر StudentJoinRequestService)،
 * و create()/latestForStudent()/hasPending() بتخدم جانب الطالب نفسه وهو
 * بيقدّم الطلب من صفحة البروفايل بتاعته (StudentJoinRequestService::
 * submitRequest()/statusForStudent()).
 */
class StudentUniversityRequestRepository
{
    /**
     * SELECT مشترك للاستعلامين تحت: بيانات حساب الطالب (full_name/email)
     * + اسم الكلية/القسم *المطلوبين* (عبر r.faculty_id/
     * department_id، migration 105) — مش s.faculty/s.department
     * اللي بتفضل NULL لحد ما الطلب ده بالظبط يتقبل.
     */
    private function baseQuery()
    {
        return DB::table('student_university_requests as r')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'r.university_id')
            ->leftJoin('faculties as f', 'f.id', '=', 'r.faculty_id')
            ->leftJoin('departments as d', 'd.id', '=', 'r.department_id')
            ->select(
                'r.*',
                's.student_number', 's.academic_year',
                'u.full_name', 'u.email',
                'uni.official_name_en as req_university_en', 'uni.official_name_ar as req_university_ar',
                'f.name_en as req_faculty_en', 'f.name_ar as req_faculty_ar',
                'd.name_en as req_department_en', 'd.name_ar as req_department_ar'
            );
    }

    /** طلبات الانضمام قيد الانتظار لجامعة معينة. @return array<int,array<string,mixed>> */
    public function pendingForUniversity($universityId): array
    {
        return $this->baseQuery()
            ->where('r.university_id', $universityId)
            ->where('r.status', 'pending')
            ->orderBy('r.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** كل الطلبات (أي حالة) لجامعة معينة — تبويب "كل الطلبات". @return array<int,array<string,mixed>> */
    public function forUniversity($universityId): array
    {
        return $this->baseQuery()
            ->where('r.university_id', $universityId)
            ->orderByDesc('r.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** طلب واحد (أي حالة) مقيّد بجامعة الكولر + تفاصيل إضافية للطالب (تليفون/نبذة). */
    public function findForUniversity($id, $universityId): ?array
    {
        $row = $this->baseQuery()
            ->addSelect('u.phone', 's.bio', 's.current_semester')
            ->where('r.id', $id)
            ->where('r.university_id', $universityId)
            ->first();

        return $row ? (array) $row : null;
    }

    /** بتحرك بس طلب pending؛ طلب اتقرر فيه قبل كده متتلمسش (idempotent). */
    public function decide($id, $universityId, string $status, $reviewerId): ?StudentUniversityRequest
    {
        $request = StudentUniversityRequest::find($id);
        if (!$request || (int) $request->university_id !== (int) $universityId || $request->status !== 'pending') {
            return null;
        }

        $request->fill([
            'status'      => $status,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);
        $request->save();

        return $request;
    }

    /** بيقدّم الطالب طلب انضمام جديد لجامعة (status='pending' افتراضيًا). */
    public function create(int $studentId, int $universityId, ?int $facultyId, ?int $departmentId): StudentUniversityRequest
    {
        return StudentUniversityRequest::create([
            'student_id'    => $studentId,
            'university_id' => $universityId,
            'faculty_id'    => $facultyId,
            'department_id' => $departmentId,
            'status'        => 'pending',
        ]);
    }

    /** آخر طلب قدّمه الطالب (أي حالة) — لعرضه في صفحة البروفايل بتاعته. */
    public function latestForStudent(int $studentId): ?array
    {
        $row = $this->baseQuery()
            ->where('r.student_id', $studentId)
            ->orderByDesc('r.created_at')
            ->first();

        return $row ? (array) $row : null;
    }

    /** فيه طلب pending حاليًا للطالب ده؟ (بيمنع تكرار الطلبات). */
    public function hasPending(int $studentId): bool
    {
        return StudentUniversityRequest::where('student_id', $studentId)->where('status', 'pending')->exists();
    }
}
