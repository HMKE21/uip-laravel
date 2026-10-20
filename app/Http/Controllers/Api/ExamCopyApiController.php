<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Services\ExamCopyService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/v1/exam-system/exams/{id}/duplicate  — نسخ امتحان (لازم تكون صاحبه، غير كده 404).
 * PUT  /api/v1/exam-system/exams/{id}/template   — تعليم/إلغاء علامة "قالب".
 */
class ExamCopyApiController extends Controller
{
    public function __construct(
        private ExamCopyService $copies,
        private ExamSystemService $examSystem,
        private AcademicStaffRepository $staffRepo
    ) {
    }

    /** @return array{0:mixed,1:mixed,2:mixed} [staff, exam, error] */
    private function resolveOwnedExam(Request $request, $examId): array
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return [null, null, $this->apiError('Only academic staff accounts can access the exam system.', null, 403)];
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return [null, null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return [null, null, $this->apiError('Exam not found.', null, 404)];
        }
        return [$staff, $exam, null];
    }

    public function duplicate(Request $request, $examId)
    {
        [$staff, $exam, $err] = $this->resolveOwnedExam($request, $examId);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'title' => 'nullable|string|max:200',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'academic_year' => 'nullable|string|max:20',
            'semester' => 'nullable|string|max:20',
            'copy_targets' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $r = $this->copies->duplicate($exam, $staff, $request->only(['title', 'start_at', 'end_at', 'academic_year', 'semester']) + ['copy_targets' => $request->boolean('copy_targets')]);

        return $this->apiSuccess([
            'id' => $r['exam']->id,
            'title' => $r['exam']->title,
            'status' => $r['exam']->status,
            'copied_questions' => $r['copied_questions'],
            'skipped_questions' => $r['skipped_questions'],
            'copied_pools' => $r['copied_pools'],
            'copied_targets' => $r['copied_targets'],
        ], 'Exam duplicated successfully.', 201);
    }

    public function template(Request $request, $examId)
    {
        [$staff, $exam, $err] = $this->resolveOwnedExam($request, $examId);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), ['is_template' => 'required|boolean']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        try {
            $exam = $this->copies->setTemplate($exam, $request->boolean('is_template'), $staff);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['id' => $exam->id, 'is_template' => (bool) $exam->is_template], 'Template flag updated.');
    }
}
