<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\GuardsExamSession;
use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\StudentRepository;
use App\Services\ExamAttemptService;
use App\Services\ExamProctoringService;
use App\Services\ExamSessionService;
use App\Services\ExamSecurityService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح Round 5 (Secure Exam Mode + Security Events، Phases 13-16) —
 * جزءين مختلفين في نفس الكونترولر عمدًا (زي ما exam-system/* عمومًا
 * بتجمع سطوح متعددة تحت prefix واحد): الطالب بيبعت أحداث أمان وقت
 * محاولته (POST attempts/{id}/security-events)، والمدرس بيراجع التايم
 * لاين بتاعة محاولة معينة على امتحانه (GET exams/{id}/attempts/{attemptId}/
 * security-events). resolveStudent()/requireAcademicStaff()/resolveStaff()
 * نفس النسخ المكررة في ExamAttemptApiController/ExamGradingApiController
 * بالظبط — نفس القرار الموثق هناك.
 *
 * recordEvent() هي اللي بتربط ExamSecurityService (تسجيل + عد المخالفات)
 * بـ ExamAttemptService (auto-submit فعلي لو threshold_exceeded) — القرار
 * ده متعمد يبقى هنا في الكونترولر مش جوه أي من الخدمتين، عشان نتجنب
 * circular dependency بينهم (راجع docblock ExamSecurityService).
 */
class ExamSecurityApiController extends Controller
{
    use GuardsExamSession;

    public function __construct(
        private ExamSecurityService $security,
        private ExamAttemptService $examAttempts,
        private ExamAttemptRepository $attemptsRepo,
        private StudentRepository $students,
        private ExamSystemService $examSystem,
        private AcademicStaffRepository $staffRepo,
        private ExamSessionService $sessions,
        private ExamProctoringService $proctoring
    ) {
    }

    // -----------------------------------------------------------------
    // Student — POST /api/v1/exam-system/attempts/{id}/security-events
    // -----------------------------------------------------------------

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

    /** POST — تسجيل حدث أمان جاي من متصفح الطالب وقت محاولة شغالة (Phases 13-15). لو تخطى max_violations، المحاولة بتتحول auto_submitted فورًا في نفس الـ request. */
    public function recordEvent(Request $request, $attemptId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->attemptsRepo->findOwned($attemptId, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string|in:' . implode(',', ExamSecurityService::CLIENT_EVENTS),
            'metadata'   => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            // الكاميرا الإجبارية: رفضها/إيقافها بيتحسب مخالفة (الاختيارية والمعفي منها: إعلامي بس).
            $exam = $attempt->exam ?? $attempt->exam()->first();
            $cameraRequired = $exam && $this->proctoring->effectiveMode($exam, $student->id) === 'required';
            $result = $this->security->recordClientEvent($attempt, $request->input('metadata'), $request->input('event_type'), $cameraRequired);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $autoSubmitted = false;
        if ($result['threshold_exceeded']) {
            $attempt = $this->examAttempts->autoSubmitDueToViolations($attempt);
            $autoSubmitted = true;
        }

        return $this->apiSuccess([
            'violations_count'     => $result['violations_count'],
            'max_violations'       => $result['max_violations'],
            'violations_remaining' => $result['max_violations'] !== null ? max(0, $result['max_violations'] - $result['violations_count']) : null,
            'is_violation'         => $result['event']->is_violation,
            'attempt_status'       => $attempt->status,
            'auto_submitted'       => $autoSubmitted,
        ], $autoSubmitted ? 'Maximum violations exceeded — exam auto-submitted.' : 'Security event recorded.');
    }

    // -----------------------------------------------------------------
    // Instructor — GET /api/v1/exam-system/exams/{id}/attempts/{attemptId}/security-events
    // -----------------------------------------------------------------

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

    /** GET — التايم لاين الكامل لمحاولة واحدة، للمدرس صاحب الامتحان بس. */
    public function timeline(Request $request, $examId, $attemptId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $attempt = $this->attemptsRepo->find($attemptId);
        if (!$attempt || (int) $attempt->exam_id !== (int) $exam->id) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        return $this->apiSuccess($this->security->timelineForAttempt($attempt), 'Security events retrieved successfully.');
    }
}
