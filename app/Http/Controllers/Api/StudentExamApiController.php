<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\StudentRepository;
use App\Services\StudentExamService;
use Illuminate\Http\Request;

/**
 * سطح /api/v1/exam-system/my-exams/* — Round 2. الطالب بيشوف بس الامتحانات
 * المنشورة/المجدولة اللي هو مستهدف بيها (exam_targets، راجع
 * ExamTargetRepository::examIdsForStudent()) — من غير محتوى الأسئلة
 * (راجع docblock StudentExamService لسبب ده). RBAC: role='student' بتتفحص
 * جوه requireStudent() زي StudentsApiController::me() بالظبط.
 */
class StudentExamApiController extends Controller
{
    public function __construct(
        private StudentExamService $studentExams,
        private StudentRepository $students
    ) {
    }

    private function resolveStudent(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return [null, $this->apiError('Only student accounts can access the exam system.', null, 403)];
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $student = $this->students->findByUserId($userId);
        if (!$student) {
            return [null, $this->apiError('Student profile not found for this account.', null, 404)];
        }

        return [$student, null];
    }

    /** GET /api/v1/exam-system/my-exams */
    public function index(Request $request)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $exams = $this->studentExams->listMyExams($student->id, $student->university_id);

        return $this->apiSuccess($exams, 'Your exams were retrieved successfully.');
    }

    /** GET /api/v1/exam-system/my-exams/{id} */
    public function show(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $summary = $this->studentExams->examSummaryForStudent($id, $student->id, $student->university_id);
        if (!$summary) {
            return $this->apiError('Exam not found.', null, 404);
        }

        return $this->apiSuccess($summary, 'Exam retrieved successfully.');
    }
}
