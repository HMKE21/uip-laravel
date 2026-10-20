<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamRepository;
use App\Repositories\StudentRepository;
use App\Services\ExamAppealService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * تظلم الطالب على الدرجة.
 * سطح الطالب (الملكية = صاحب المحاولة، غيره 404): GET my-appeals، GET/POST attempts/{id}/appeals،
 *   POST attempts/{id}/appeals/{appealId}/withdraw.
 * سطح المدرس (الملكية = صاحب الامتحان، غيره 404): GET exams/{id}/appeals، POST exams/{id}/appeals/{appealId}/resolve،
 *   PUT exams/{id}/appeal-settings.
 */
class ExamAppealApiController extends Controller
{
    public function __construct(
        private ExamAppealService $appeals,
        private ExamAttemptRepository $attemptsRepo,
        private ExamRepository $exams,
        private StudentRepository $students,
        private AcademicStaffRepository $staffRepo,
        private ExamSystemService $examSystem
    ) {
    }

    private function resolveStudent(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return [null, $this->apiError('Only student accounts can use appeals.', null, 403)];
        }
        $student = $this->students->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$student) {
            return [null, $this->apiError('Student profile not found for this account.', null, 404)];
        }
        return [$student, null];
    }

    /** @return array{0:mixed,1:mixed,2:mixed} [student, attempt, error] */
    private function resolveOwnAttempt(Request $request, $attemptId): array
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return [null, null, $err];
        }
        $attempt = $this->attemptsRepo->findOwned($attemptId, $student->id);
        if (!$attempt) {
            return [null, null, $this->apiError('Attempt not found.', null, 404)];
        }
        return [$student, $attempt, null];
    }

    /** @return array{0:mixed,1:mixed,2:mixed} [staff, exam, error] */
    private function resolveOwnedExam(Request $request, $examId): array
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return [null, null, $this->apiError('Only academic staff accounts can manage appeals.', null, 403)];
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

    // ---------------------------- student ----------------------------

    /** GET /my-appeals */
    public function mine(Request $request)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }
        return $this->apiSuccess($this->appeals->forStudent($student->id), 'Appeals retrieved successfully.');
    }

    /** GET /attempts/{id}/appeals */
    public function indexForAttempt(Request $request, $id)
    {
        [, $attempt, $err] = $this->resolveOwnAttempt($request, $id);
        if ($err) {
            return $err;
        }
        $exam = $this->exams->find($attempt->exam_id);
        return $this->apiSuccess($this->appeals->forAttempt($exam, $attempt), 'Appeals retrieved successfully.');
    }

    /** POST /attempts/{id}/appeals  body { reason, exam_question_id? } */
    public function store(Request $request, $id)
    {
        [$student, $attempt, $err] = $this->resolveOwnAttempt($request, $id);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:2000',
            'exam_question_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return $this->apiError('The given data was invalid.', $v->errors()->toArray(), 422);
        }
        $exam = $this->exams->find($attempt->exam_id);
        try {
            $appeal = $this->appeals->create(
                $exam, $attempt, $student->id,
                $request->filled('exam_question_id') ? (int) $request->input('exam_question_id') : null,
                trim((string) $request->input('reason'))
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['id' => $appeal->id, 'status' => $appeal->status], 'Appeal submitted.', 201);
    }

    /** POST /attempts/{id}/appeals/{appealId}/withdraw */
    public function withdraw(Request $request, $id, $appealId)
    {
        [, $attempt, $err] = $this->resolveOwnAttempt($request, $id);
        if ($err) {
            return $err;
        }
        try {
            $appeal = $this->appeals->withdraw($attempt, (int) $appealId);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, $e->getMessage() === 'Appeal not found.' ? 404 : 422);
        }
        return $this->apiSuccess(['id' => $appeal->id, 'status' => $appeal->status], 'Appeal withdrawn.');
    }

    // ---------------------------- instructor ----------------------------

    /** GET /exams/{id}/appeals?status= */
    public function index(Request $request, $examId)
    {
        [, $exam, $err] = $this->resolveOwnedExam($request, $examId);
        if ($err) {
            return $err;
        }
        $status = $request->query('status');
        if ($status !== null && !in_array($status, ExamAppealService::STATUSES, true)) {
            return $this->apiError('Invalid status filter.', null, 422);
        }
        return $this->apiSuccess([
            'appeals' => $this->appeals->listForExam($exam, $status),
            'counts'  => $this->appeals->countsForExam($exam),
            'settings' => ['appeals_enabled' => (bool) $exam->appeals_enabled, 'appeal_window_days' => (int) $exam->appeal_window_days],
        ], 'Appeals retrieved successfully.');
    }

    /** POST /exams/{id}/appeals/{appealId}/resolve  body { decision, response?, new_marks? } */
    public function resolve(Request $request, $examId, $appealId)
    {
        [$staff, $exam, $err] = $this->resolveOwnedExam($request, $examId);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'decision' => 'required|in:accepted,rejected',
            'response' => 'nullable|string|max:2000',
            'new_marks' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) {
            return $this->apiError('The given data was invalid.', $v->errors()->toArray(), 422);
        }
        try {
            $appeal = $this->appeals->resolve(
                $exam, (int) $appealId, $request->input('decision'), $request->input('response'),
                $request->filled('new_marks') ? (float) $request->input('new_marks') : null, $staff
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, $e->getMessage() === 'Appeal not found.' ? 404 : 422);
        }
        return $this->apiSuccess(['id' => $appeal->id, 'status' => $appeal->status], 'Appeal resolved.');
    }

    /** PUT /exams/{id}/appeal-settings  body { appeals_enabled, appeal_window_days } */
    public function settings(Request $request, $examId)
    {
        [$staff, $exam, $err] = $this->resolveOwnedExam($request, $examId);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), ['appeals_enabled' => 'required|boolean', 'appeal_window_days' => 'required|integer|min:1|max:90']);
        if ($v->fails()) {
            return $this->apiError('The given data was invalid.', $v->errors()->toArray(), 422);
        }
        $this->appeals->updateSettings($exam, $request->boolean('appeals_enabled'), (int) $request->input('appeal_window_days'), $staff);
        return $this->apiSuccess(['appeals_enabled' => $request->boolean('appeals_enabled'), 'appeal_window_days' => (int) $request->input('appeal_window_days')], 'Appeal settings updated.');
    }
}
