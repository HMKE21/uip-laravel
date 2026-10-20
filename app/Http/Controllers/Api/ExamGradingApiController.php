<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGradeRepository;
use App\Services\ExamAttemptManagementService;
use App\Services\ExamGradingService;
use App\Services\ExamSystemService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/exam-system/exams/{id}/attempts/* — Round 4 (Grading Core،
 * Phases 17/22). كونترولر جديد منفصل عن ExamSystemApiController عمدًا (مش
 * إضافة ميثودز جواه) — هنا كل حاجة بتاخد examId في المسار وبتتفحص ملكية
 * الامتحان أولاً، بعدين ملكية المحاولة (إنها فعلاً تابعة للامتحان ده) —
 * نفس فكرة "Changing attempt ownership" اللي ExamAttemptApiController
 * بيمنعها للطالب، هنا بمنطقها المعكوس: مدرس تاني عمره ما يقدر يصحح أو
 * حتى يشوف محاولة على امتحان مش بتاعه، حتى لو عرف attemptId صح.
 *
 * requireAcademicStaff()/resolveStaff() نفس نسخة ExamSystemApiController
 * بالظبط (مكررة عمدًا — راجع نفس القرار هناك).
 */
class ExamGradingApiController extends Controller
{
    public function __construct(
        private ExamGradingService $grading,
        private ExamSystemService $examSystem,
        private ExamAttemptRepository $attemptsRepo,
        private ExamGradeRepository $gradesRepo,
        private AcademicStaffRepository $staffRepo,
        private ExamAttemptManagementService $management
    ) {
    }

    private function requireAcademicStaff(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can access the exam system.', null, 403);
        }
        return null;
    }

    private function resolveStaff(Request $request): array
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $staff = $this->staffRepo->findByUserId($userId);
        if (!$staff) {
            return [null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        return [$staff, null];
    }

    /** بترجع [exam, null] لو الامتحان تابع للمدرس ده، أو [null, JsonResponse] غير كده. */
    private function resolveOwnedExam(Request $request, $examId, $staff): array
    {
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return [null, $this->apiError('Exam not found.', null, 404)];
        }
        return [$exam, null];
    }

    /** بترجع [attempt, null] لو المحاولة موجودة وفعلاً تابعة للامتحان ده، أو [null, JsonResponse] غير كده. */
    private function resolveAttemptForExam($attemptId, $exam): array
    {
        $attempt = $this->attemptsRepo->find($attemptId);
        if (!$attempt || (int) $attempt->exam_id !== (int) $exam->id) {
            return [null, $this->apiError('Attempt not found.', null, 404)];
        }
        return [$attempt, null];
    }

    // -----------------------------------------------------------------
    // Attempts list / detail — /exams/{id}/attempts[/{attemptId}]
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/exams/{id}/attempts — ملخص كل محاولات الطلاب على الامتحان ده + تقدم التصحيح. */
    public function indexAttempts(Request $request, $examId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->grading->listAttemptsForExam($exam), 'Attempts retrieved successfully.');
    }

    /** GET /api/v1/exam-system/exams/{id}/attempts/{attemptId} — التفاصيل الكاملة (أسئلة + إجابات + درجات) لمحاولة واحدة. */
    public function showAttempt(Request $request, $examId, $attemptId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->grading->attemptDetailForInstructor($attempt), 'Attempt retrieved successfully.');
    }

    // -----------------------------------------------------------------
    // Attempt management — cancel an attempt / allow a retake
    // -----------------------------------------------------------------

    /** يحوّل "available_until" المبعوتة من الفرونت لـ Carbon، أو null لو فاضية. */
    private function parseUntil($value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    /**
     * POST /exams/{id}/attempts/{attemptId}/cancel
     * body: reason?, allow_retake? (bool), available_until? (datetime — بس مع allow_retake)
     */
    public function cancelAttempt(Request $request, $examId, $attemptId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'reason'          => 'nullable|string|max:1000',
            'allow_retake'    => 'nullable|boolean',
            'available_until' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors(), 422);
        }

        try {
            $result = $this->management->cancelAttempt(
                $exam,
                $attempt,
                (int) $request->attributes->get('uip_user_id'),
                $request->input('reason'),
                $request->boolean('allow_retake'),
                $this->parseUntil($request->input('available_until'))
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'            => $result['attempt']->id,
            'status'        => $result['attempt']->status,
            'cancelled_at'  => $result['attempt']->cancelled_at,
            'cancel_reason' => $result['attempt']->cancel_reason,
            'retake_granted' => $result['override'] !== null,
        ], 'Attempt cancelled successfully.');
    }

    /**
     * POST /exams/{id}/students/{studentId}/retake
     * body: extra_attempts? (1..10، default 1), available_until? (datetime), reason?
     */
    public function grantRetake(Request $request, $examId, $studentId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'extra_attempts'  => 'nullable|integer|min:1|max:' . ExamAttemptManagementService::MAX_EXTRA_ATTEMPTS,
            'available_until' => 'nullable|date',
            'reason'          => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors(), 422);
        }

        try {
            $override = $this->management->grantRetake(
                $exam,
                $studentId,
                (int) $request->input('extra_attempts', 1),
                $this->parseUntil($request->input('available_until')),
                (int) $request->attributes->get('uip_user_id'),
                $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'student_id'      => (int) $studentId,
            'extra_attempts'  => (int) $override->extra_attempts,
            'available_until' => $override->available_until,
        ], 'Retake granted successfully.');
    }

    /** DELETE /exams/{id}/students/{studentId}/retake — يسحب المحاولات الإضافية اللي لسه ماتستخدمتش. */
    public function revokeRetake(Request $request, $examId, $studentId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        try {
            $override = $this->management->revokeRetake($exam, $studentId, (int) $request->attributes->get('uip_user_id'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'student_id'     => (int) $studentId,
            'extra_attempts' => (int) $override->extra_attempts,
        ], 'Retake revoked successfully.');
    }

    /** @return array{0:?\App\Models\Exam,1:?\Illuminate\Http\JsonResponse} دور المدرس + ملكية الامتحان في خطوة واحدة. */
    private function ownedExamOrError(Request $request, $examId): array
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return [null, $err];
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return [null, $err];
        }
        return $this->resolveOwnedExam($request, $examId, $staff);
    }

    /** رد العمليات الجماعية: 200 لو في نجاح واحد على الأقل، 422 لو كله فشل. */
    private function bulkResponse(array $result, string $okMessage)
    {
        if ($result['succeeded'] === [] && $result['failed'] !== []) {
            return $this->apiError('No item could be processed.', $result, 422);
        }
        return $this->apiSuccess($result, $okMessage);
    }

    // -----------------------------------------------------------------
    // Extra time — POST /exams/{id}/attempts/{attemptId}/extra-time  { minutes, reason? }
    // -----------------------------------------------------------------

    public function addExtraTime(Request $request, $examId, $attemptId)
    {
        [$exam, $err] = $this->ownedExamOrError($request, $examId);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'minutes' => 'required|integer|min:1|max:' . ExamAttemptManagementService::MAX_EXTRA_MINUTES,
            'reason'  => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors()->toArray(), 422);
        }

        try {
            $updated = $this->management->addExtraTime(
                $exam, $attempt, (int) $request->input('minutes'),
                (int) $request->attributes->get('uip_user_id'), $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'                 => $updated->id,
            'expires_at'         => $updated->expires_at,
            'extra_time_minutes' => (int) $updated->extra_time_minutes,
        ], 'Extra time added successfully.');
    }

    // -----------------------------------------------------------------
    // Bulk actions — انقطاع نت/سيرفر: إجراء واحد على أكتر من طالب.
    // الرد: {succeeded: [ids], failed: [{id, error}]}
    // -----------------------------------------------------------------

    /** POST /exams/{id}/attempts/bulk-cancel  { attempt_ids[], reason?, allow_retake?, available_until? } */
    public function bulkCancel(Request $request, $examId)
    {
        [$exam, $err] = $this->ownedExamOrError($request, $examId);
        if ($err) {
            return $err;
        }
        $validator = Validator::make($request->all(), [
            'attempt_ids'     => 'required|array|min:1|max:' . ExamAttemptManagementService::MAX_BULK,
            'attempt_ids.*'   => 'integer',
            'reason'          => 'nullable|string|max:1000',
            'allow_retake'    => 'nullable|boolean',
            'available_until' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->management->bulkCancel(
                $exam, $request->input('attempt_ids'), (int) $request->attributes->get('uip_user_id'),
                $request->input('reason'), $request->boolean('allow_retake'), $this->parseUntil($request->input('available_until'))
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        return $this->bulkResponse($result, 'Attempts processed.');
    }

    /** POST /exams/{id}/retake/bulk  { student_ids[], extra_attempts?, available_until?, reason? } */
    public function bulkGrantRetake(Request $request, $examId)
    {
        [$exam, $err] = $this->ownedExamOrError($request, $examId);
        if ($err) {
            return $err;
        }
        $validator = Validator::make($request->all(), [
            'student_ids'     => 'required|array|min:1|max:' . ExamAttemptManagementService::MAX_BULK,
            'student_ids.*'   => 'integer',
            'extra_attempts'  => 'nullable|integer|min:1|max:' . ExamAttemptManagementService::MAX_EXTRA_ATTEMPTS,
            'available_until' => 'nullable|date',
            'reason'          => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->management->bulkGrantRetake(
                $exam, $request->input('student_ids'), (int) $request->input('extra_attempts', 1),
                $this->parseUntil($request->input('available_until')), (int) $request->attributes->get('uip_user_id'),
                $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        return $this->bulkResponse($result, 'Retakes processed.');
    }

    /** POST /exams/{id}/attempts/bulk-extra-time  { attempt_ids[], minutes, reason? } */
    public function bulkAddExtraTime(Request $request, $examId)
    {
        [$exam, $err] = $this->ownedExamOrError($request, $examId);
        if ($err) {
            return $err;
        }
        $validator = Validator::make($request->all(), [
            'attempt_ids'   => 'required|array|min:1|max:' . ExamAttemptManagementService::MAX_BULK,
            'attempt_ids.*' => 'integer',
            'minutes'       => 'required|integer|min:1|max:' . ExamAttemptManagementService::MAX_EXTRA_MINUTES,
            'reason'        => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->management->bulkAddExtraTime(
                $exam, $request->input('attempt_ids'), (int) $request->input('minutes'),
                (int) $request->attributes->get('uip_user_id'), $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        return $this->bulkResponse($result, 'Extra time processed.');
    }

    // -----------------------------------------------------------------
    // Student search — GET /exams/{id}/eligible-students?q=&without_attempts=1&limit=
    // -----------------------------------------------------------------

    public function searchEligibleStudents(Request $request, $examId)
    {
        [$exam, $err] = $this->ownedExamOrError($request, $examId);
        if ($err) {
            return $err;
        }
        $validator = Validator::make($request->query(), [
            'q'                => 'nullable|string|max:100',
            'without_attempts' => 'nullable|boolean',
            'limit'            => 'nullable|integer|min:1|max:200',
        ]);
        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors()->toArray(), 422);
        }

        return $this->apiSuccess(
            $this->management->searchEligibleStudents(
                $exam, $request->query('q'), $request->boolean('without_attempts'), (int) $request->query('limit', 50)
            ),
            'Students retrieved successfully.'
        );
    }

    // -----------------------------------------------------------------
    // Manual grading — /exams/{id}/attempts/{attemptId}/grades/{examQuestionId}
    // -----------------------------------------------------------------

    /** PUT — تصحيح يدوي لسؤال واحد (أو override على تصحيح أوتوماتيك). كل نداء بيسجل سطر في exam_grade_history. */
    public function gradeAnswer(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'marks_awarded' => 'required|numeric|min:0',
            'feedback'      => 'nullable|string|max:5000',
            'reason'        => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $grade = $this->grading->gradeManually(
                $attempt,
                $examQuestionId,
                (float) $request->input('marks_awarded'),
                $request->input('feedback'),
                $staff,
                $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'             => $grade->id,
            'marks_awarded'  => (float) $grade->marks_awarded,
            'max_marks'      => (float) $grade->max_marks,
            'feedback'       => $grade->feedback,
            'source'         => $grade->source,
            'graded_at'      => $grade->graded_at,
            'attempt_status' => $attempt->refresh()->status,
        ], 'Grade saved.');
    }

    /** GET — سجل تعديلات درجة سؤال واحد جوه محاولة (Phase 22). */
    public function gradeHistory(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $grade = $this->gradesRepo->findForQuestion($attempt->id, $examQuestionId);
        if (!$grade) {
            return $this->apiSuccess([], 'No grade recorded yet for this question.');
        }

        $history = array_map(fn ($h) => [
            'id'              => $h->id,
            'previous_score'  => $h->previous_score !== null ? (float) $h->previous_score : null,
            'new_score'       => (float) $h->new_score,
            'source'          => $h->source,
            'reason'          => $h->reason,
            'changed_at'      => $h->changed_at,
            'previous_feedback' => $h->previous_feedback,
            'new_feedback'    => $h->new_feedback,
        ], $this->grading->historyFor($grade));

        return $this->apiSuccess($history, 'Grade history retrieved successfully.');
    }

    /** POST — بيعيد تشغيل التصحيح الأوتوماتيك للأسئلة الموضوعية بس (بعد تعديل بنك الأسئلة مثلاً)، من غير ما يلمس أي درجة عدّلها المدرس يدويًا. */
    public function autoGrade(Request $request, $examId, $attemptId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        if (in_array($attempt->status, ['in_progress', 'cancelled'], true)) {
            return $this->apiError('This attempt has not been submitted yet.', null, 422);
        }

        @set_time_limit(0);
        $summary = $this->grading->autoGradeAttemptFull($attempt);

        return $this->apiSuccess($summary + [
            'regraded_count' => $summary['objective'],
            'attempt_status' => $attempt->refresh()->status,
        ], 'Automatic grading re-run.');
    }

    // -----------------------------------------------------------------
    // AI grading (Round 6, Phase 18/23) — /exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/accept-ai|ai-regrade
    // -----------------------------------------------------------------

    /** POST — المدرس بيقبل درجة الـ AI زي ما هي، وبتتحول لـ source=instructor (نفس فلسفة gradeManually بس من غير تعديل رقم). */
    public function acceptAiGrade(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $grade = $this->gradesRepo->findForQuestion($attempt->id, $examQuestionId);
        if (!$grade) {
            return $this->apiError('No grade recorded yet for this question.', null, 404);
        }

        try {
            $grade = $this->grading->acceptAiGrade($grade, $staff);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'             => $grade->id,
            'marks_awarded'  => (float) $grade->marks_awarded,
            'max_marks'      => (float) $grade->max_marks,
            'source'         => $grade->source,
            'graded_at'      => $grade->graded_at,
            'attempt_status' => $attempt->refresh()->status,
        ], 'AI grade accepted.');
    }

    /** POST — إعادة تشغيل التصحيح بالـ AI لسؤال essay/short_answer واحد (بعد تعديل model answer/rubric مثلاً). بيرجع الدرجة القديمة لغاية ما الـ job يخلص. */
    public function aiRegrade(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        try {
            $grade = $this->grading->regradeQuestionWithAi($attempt, $examQuestionId, $staff, true);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'             => $grade->id,
            'marks_awarded'  => $grade->marks_awarded !== null ? (float) $grade->marks_awarded : null,
            'max_marks'      => (float) $grade->max_marks,
            'source'         => $grade->source,
        ], 'AI regrade completed.');
    }

    // -----------------------------------------------------------------
    // Result publishing (result_visibility = 'manual') — /exams/{id}/publish-results
    // -----------------------------------------------------------------

    public function publishResults(Request $request, $examId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        $exam = $this->grading->publishResults($exam);

        return $this->apiSuccess(['results_published_at' => $exam->results_published_at], 'Results published to students.');
    }

    public function unpublishResults(Request $request, $examId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        $exam = $this->grading->unpublishResults($exam);

        return $this->apiSuccess(['results_published_at' => $exam->results_published_at], 'Results unpublished.');
    }
}
