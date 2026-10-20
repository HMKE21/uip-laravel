<?php

namespace App\Services;

use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\StudentUniversityRequestRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/StudentJoinRequestService.php القديمة. مسار
 * الجامعة (universityIdForUser/pendingQueue/fullQueue/approve/reject)
 * بيخدم UniversityJoinRequestsApiController، ومسار الطالب (submitRequest/
 * statusForStudent) بيخدم StudentsApiController::joinRequest()/me() —
 * الطالب بيختار جامعته من صفحة البروفايل بتاعته ويقدّم الطلب.
 *
 * لحد ما طلب هنا يتقبل، students.university_id فاضل NULL — يعني الطالب
 * لسه يقدر يعمل مسودات مشاريع، بس مش يقدّمها لمراجعة الجامعة (مفيش
 * university_id يتحط عليها)، ومش هيظهر في صفحة طلاب الجامعة.
 */
class StudentJoinRequestService
{
    public function __construct(
        private StudentUniversityRequestRepository $requests,
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private NotificationService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    /** يحوّل مستخدم جامعة (users.id) لـ universities.id بتاعته. */
    public function universityIdForUser($userId): ?int
    {
        $university = $this->universities->findByUserId($userId);
        return $university?->id;
    }

    /** @return array<int,array<string,mixed>> شكله لصفحة طابور الجامعة */
    public function pendingQueue($universityId, string $locale = 'en'): array
    {
        return array_map(fn ($r) => $this->toRow($r, $locale), $this->requests->pendingForUniversity($universityId));
    }

    /** @return array<int,array<string,mixed>> كل الطلبات (أي حالة) لتبويب "السجل" */
    public function fullQueue($universityId, string $locale = 'en'): array
    {
        return array_map(fn ($r) => $this->toRow($r, $locale), $this->requests->forUniversity($universityId));
    }

    /** تفاصيل طلب واحد لصاحب الجامعة (زر "الملف") — null لو الطلب مش بتاع جامعته. */
    public function detail($requestId, $universityId, string $locale = 'en'): ?array
    {
        $r = $this->requests->findForUniversity($requestId, $universityId);
        if (!$r) {
            return null;
        }
        return $this->toRow($r, $locale) + [
            'phone'            => $r['phone'] ?? null,
            'bio'              => $r['bio'] ?? null,
            'current_semester' => isset($r['current_semester']) ? (int) $r['current_semester'] : null,
        ];
    }

    public function approve($requestId, $universityId, $reviewerId): bool
    {
        $request = $this->requests->decide($requestId, $universityId, 'approved', $reviewerId);
        if (!$request) {
            return false;
        }

        $student = $this->students->find($request->student_id);
        if ($student) {
            // نفس الشكل المستهدف في StudentManagementService::invite(): الـ
            // FK ids الحقيقية هي مصدر الحقيقة، والأعمدة النصية القديمة
            // faculty/department متسيبة متزامنة معاها عشان أي كود قديم
            // لسه بيقراها (فلاتر/قالب CSV/اختيار نطاق المشرف) يفضل شغال.
            $faculty = $request->faculty_id ? $this->faculties->find($request->faculty_id) : null;
            $department = $request->department_id ? $this->departments->find($request->department_id) : null;

            $student->fill([
                'university_id' => $universityId,
                'faculty_id'    => $faculty?->id,
                'department_id' => $department?->id,
                'faculty'       => $faculty ? $faculty->name('en') : $student->faculty,
                'department'    => $department ? $department->name('en') : $student->department,
            ]);
            $student->save();
        }

        $university = $this->universities->find($universityId);
        $this->notifications->notify(
            $student?->user_id,
            'university_join_approved',
            'Your university joined request was approved',
            $university ? 'You are now linked to ' . $university->name('en') . '.' : null,
            '/student/profile'
        );

        $this->auditLog->record(
            $reviewerId,
            'join_request.approved',
            'StudentUniversityRequest',
            (int) $requestId,
            ['status' => 'pending'],
            [
                'status' => 'approved', 'student_id' => $request->student_id, 'university_id' => $universityId,
                'faculty_id' => $request->faculty_id, 'department_id' => $request->department_id,
            ]
        );

        Log::info('University approved student join request', ['request_id' => $requestId, 'university_id' => $universityId]);
        return true;
    }

    public function reject($requestId, $universityId, $reviewerId): bool
    {
        $request = $this->requests->decide($requestId, $universityId, 'rejected', $reviewerId);
        if (!$request) {
            return false;
        }

        $student = $this->students->find($request->student_id);

        $this->notifications->notify(
            $student?->user_id,
            'university_join_rejected',
            'Your university joined request was declined',
            'You can choose a different university and try again.',
            '/student/profile'
        );

        $this->auditLog->record(
            $reviewerId,
            'join_request.rejected',
            'StudentUniversityRequest',
            (int) $requestId,
            ['status' => 'pending'],
            ['status' => 'rejected', 'student_id' => $request->student_id, 'university_id' => $universityId]
        );

        Log::info('University rejected student join request', ['request_id' => $requestId, 'university_id' => $universityId]);
        return true;
    }

    /**
     * الطالب بيقدّم طلب انضمام لجامعة من صفحة البروفايل بتاعته
     * (Student\StudentProfileController::linkUniversity() القديمة).
     * مرفوض لو الطالب مرتبط بجامعة بالفعل، أو معاه طلب pending لسه ماتقررش
     * فيه. الجامعة/الكلية/القسم بيتأكد منهم قبل الحفظ.
     * @return array{success:bool,message:string,request_id?:int}
     */
    public function submitRequest($userId, int $universityId, ?int $facultyId = null, ?int $departmentId = null): array
    {
        $student = $this->students->findByUserId($userId);
        if (!$student) {
            return ['success' => false, 'message' => 'Student not found.'];
        }

        if ($student->university_id) {
            return ['success' => false, 'message' => 'You are already linked to a university.'];
        }

        if ($this->requests->hasPending((int) $student->id)) {
            return ['success' => false, 'message' => 'You already have a pending join request. Please wait for it to be reviewed.'];
        }

        $university = $this->universities->find($universityId);
        if (!$university) {
            return ['success' => false, 'message' => 'Selected university was not found.'];
        }

        if ($facultyId && (!($faculty = $this->faculties->find($facultyId)) || (int) $faculty->university_id !== (int) $universityId)) {
            return ['success' => false, 'message' => 'Selected faculty does not belong to that university.'];
        }
        if ($departmentId && (!($department = $this->departments->find($departmentId)) || (int) $department->faculty_id !== (int) $facultyId)) {
            return ['success' => false, 'message' => 'Selected department does not belong to that faculty.'];
        }

        $request = $this->requests->create((int) $student->id, $universityId, $facultyId, $departmentId);

        $this->notifications->notify(
            $university->user_id ?? null,
            'university_join_requested',
            'New student join request',
            null,
            '/university/join-requests'
        );

        $this->auditLog->record(
            $userId,
            'join_request.submitted',
            'StudentUniversityRequest',
            (int) $request->id,
            null,
            ['status' => 'pending', 'student_id' => $student->id, 'university_id' => $universityId]
        );

        Log::info('Student submitted university join request', ['request_id' => $request->id, 'university_id' => $universityId]);

        return ['success' => true, 'message' => 'Your join request has been sent.', 'request_id' => (int) $request->id];
    }

    /**
     * أحدث حالة طلب انضمام للطالب — لعرضها في صفحة البروفايل بتاعته
     * ("Your join request to X is awaiting the university's review.").
     */
    public function statusForStudent(int $studentId, string $locale = 'en'): ?array
    {
        $row = $this->requests->latestForStudent($studentId);
        return $row ? $this->toRow($row, $locale) : null;
    }

    private function toRow($r, string $locale = 'en'): array
    {
        $universityName = $locale === 'ar' ? ($r['req_university_ar'] ?: $r['req_university_en']) : ($r['req_university_en'] ?: $r['req_university_ar']);
        $facultyName = $locale === 'ar' ? ($r['req_faculty_ar'] ?: $r['req_faculty_en']) : ($r['req_faculty_en'] ?: $r['req_faculty_ar']);
        $departmentName = $locale === 'ar' ? ($r['req_department_ar'] ?: $r['req_department_en']) : ($r['req_department_en'] ?: $r['req_department_ar']);

        return [
            'id'             => (int) $r['id'],
            'student_id'     => (int) $r['student_id'],
            'student_name'   => $r['full_name'],
            'email'          => $r['email'],
            'student_number' => $r['student_number'] ?: '—',
            'university'     => $universityName ?: '—',
            'faculty'        => $facultyName ?: '—',
            'department'     => $departmentName ?: '—',
            'academic_year'  => $r['academic_year'] ?: '—',
            'status'         => $r['status'],
            'requested_at'   => $r['created_at'] ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—',
        ];
    }
}
