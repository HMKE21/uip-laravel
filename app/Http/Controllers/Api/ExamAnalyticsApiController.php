<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\ExamAnalyticsService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;

/**
 * سطح /api/v1/exam-system/* الخاص بالـ Round 8 (Phases 25/26/27/28) —
 * كونترولر منفصل عن ExamSystemApiController/ExamGradingApiController عمدًا
 * (زي ExamGradingApiController اتفصل عن ExamSystemApiController في Round 4)
 * لأن الـ RBAC هنا مختلف جوهريًا: مش "عضو هيئة تدريس بيدير امتحاناته هو"
 * بس — فيه كمان "كلية/جامعة بتشوف كل امتحانات نطاقها" (Phase 26)، فمحتاج
 * أدوار تانية غير academic_staff (faculty/university، نفس الأدوار
 * المستخدمة في DashboardsApiController::overview()).
 *
 * RBAC لكل ميثود:
 *  - examAnalytics(): academic_staff بس، وبس لامتحان بتاعه هو
 *    (findOwnedExam() نفسها من ExamSystemService — الملكية بتتفحص هنا
 *    زي أي مسار تصحيح/تعديل تاني).
 *  - facultyExams()/facultyDashboard(): role=faculty بس، وبس لكليته هو
 *    (FacultyRepository::findByUserId() -> faculty_id من الـ token، مش من
 *    الـ URL — كلية متقدرش تحط faculty_id تاني في المسار وتشوف بيانات
 *    كلية غيرها، حتى لو الراوت بتاخد {facultyId}).
 *  - universityExams()/universityDashboard(): role=university بس، بنفس
 *    منطق findByUserId().
 *  - instructorDashboard(): academic_staff بس، بياناته هو بس (staff->id
 *    من الـ token برضه).
 *  - studentDashboard(): role=student بس.
 */
class ExamAnalyticsApiController extends Controller
{
    public function __construct(
        private ExamAnalyticsService $analytics,
        private ExamSystemService $examSystem,
        private ExamRepository $exams,
        private AcademicStaffRepository $staffRepo,
        private FacultyRepository $facultyRepo,
        private UniversityRepository $universityRepo,
        private StudentRepository $studentRepo
    ) {
    }

    // -----------------------------------------------------------------
    // Phase 25/27 — Instructor exam analytics
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/exams/{id}/analytics */
    public function examAnalytics(Request $request, $examId)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can view exam analytics.', null, 403);
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return $this->apiError('No academic staff profile found for this account.', null, 404);
        }
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        return $this->apiSuccess($this->analytics->examAnalytics($exam), 'Exam analytics retrieved successfully.');
    }

    // -----------------------------------------------------------------
    // Phase 28 — Instructor dashboard
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/dashboard/instructor */
    public function instructorDashboard(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can view this dashboard.', null, 403);
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return $this->apiError('No academic staff profile found for this account.', null, 404);
        }

        return $this->apiSuccess($this->analytics->instructorDashboard($staff->id), 'Instructor dashboard retrieved successfully.');
    }

    // -----------------------------------------------------------------
    // Phase 28 — Student dashboard
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/dashboard/student */
    public function studentDashboard(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can view this dashboard.', null, 403);
        }
        $student = $this->studentRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$student) {
            return $this->apiError('No student profile found for this account.', null, 404);
        }

        return $this->apiSuccess($this->analytics->studentDashboard($student->id, $student->university_id), 'Student dashboard retrieved successfully.');
    }

    // -----------------------------------------------------------------
    // Phase 26 — College (faculty) results + dashboard
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/faculty/exams */
    public function facultyExams(Request $request)
    {
        [$faculty, $err] = $this->resolveFaculty($request);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->analytics->facultyExams($faculty->id), 'Faculty exam results retrieved successfully.');
    }

    /** GET /api/v1/exam-system/faculty/dashboard */
    public function facultyDashboard(Request $request)
    {
        [$faculty, $err] = $this->resolveFaculty($request);
        if ($err) {
            return $err;
        }

        $exams = $this->exams->forFaculty($faculty->id);

        return $this->apiSuccess($this->analytics->scopedDashboard($exams), 'Faculty dashboard retrieved successfully.');
    }

    private function resolveFaculty(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return [null, $this->apiError('Only college/faculty administrator accounts can view this data.', null, 403)];
        }
        $faculty = $this->facultyRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$faculty) {
            return [null, $this->apiError('No faculty profile found for this account.', null, 404)];
        }
        return [$faculty, null];
    }

    // -----------------------------------------------------------------
    // Phase 26 — University results + dashboard
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/university/exams */
    public function universityExams(Request $request)
    {
        [$university, $err] = $this->resolveUniversity($request);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->analytics->universityExamsScope($university->id), 'University exam results retrieved successfully.');
    }

    /** GET /api/v1/exam-system/university/dashboard */
    public function universityDashboard(Request $request)
    {
        [$university, $err] = $this->resolveUniversity($request);
        if ($err) {
            return $err;
        }

        $exams = $this->exams->forUniversityScope($university->id);

        return $this->apiSuccess($this->analytics->scopedDashboard($exams), 'University dashboard retrieved successfully.');
    }

    private function resolveUniversity(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return [null, $this->apiError('Only university administrator accounts can view this data.', null, 403)];
        }
        $university = $this->universityRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$university) {
            return [null, $this->apiError('No university profile found for this account.', null, 404)];
        }
        return [$university, null];
    }
}
