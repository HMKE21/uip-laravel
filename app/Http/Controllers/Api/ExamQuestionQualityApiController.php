<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Services\ExamQuestionQualityService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;

/**
 * GET /api/v1/exam-system/exams/{id}/question-quality          — تحليل جودة أسئلة امتحان (صاحب الامتحان بس).
 * GET /api/v1/exam-system/question-banks/{id}/question-quality — تحليل أسئلة بنك مجمّع عبر كل الامتحانات (صاحب البنك بس).
 * عضو هيئة التدريس بس، وأي مورد مش بتاعه بيرجع 404 (نفس نمط باقي exam-system).
 */
class ExamQuestionQualityApiController extends Controller
{
    public function __construct(
        private ExamQuestionQualityService $quality,
        private ExamSystemService $examSystem,
        private AcademicStaffRepository $staffRepo
    ) {
    }

    private function resolveStaff(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return [null, $this->apiError('Only academic staff accounts can access the exam system.', null, 403)];
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return [null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        return [$staff, null];
    }

    public function forExam(Request $request, $examId)
    {
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }
        return $this->apiSuccess($this->quality->forExam($exam), 'Question quality retrieved successfully.');
    }

    public function forBank(Request $request, $bankId)
    {
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        $bank = $this->examSystem->findOwnedBank($bankId, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }
        return $this->apiSuccess($this->quality->forBank($bank), 'Question bank quality retrieved successfully.');
    }
}
