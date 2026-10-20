<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\GuardsExamSession;
use App\Http\Controllers\Controller;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\StudentRepository;
use App\Services\ExamAttemptService;
use App\Services\ExamProctoringService;
use App\Services\ExamSecurityService;
use App\Services\ExamSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/exam-system/{my-exams/{id}/attempts, attempts/*} — Round 3
 * (Attempts + Timer + Auto-save). resolveStudent() نفس نسخة
 * StudentExamApiController::resolveStudent() بالظبط (مكررة عمدًا زي باقي
 * الكونترولرز في المشروع ده — مفيش trait مشترك للـ RBAC helper الصغير ده،
 * راجع نفس القرار في ExamSystemApiController/StudentExamApiController).
 *
 * كل الميثودز هنا بتمر أول حاجة بـ findOwned() (الملكية = student_id بتاع
 * المحاولة) قبل أي حاجة — طالب تاني عمره ما يقدر يشوف أو يلمس محاولة مش
 * بتاعته حتى لو عرف الـ id (زي "Changing attempt ownership" المذكورة
 * صراحة في Phase الأمان بالسبك).
 */
class ExamAttemptApiController extends Controller
{
    use GuardsExamSession;

    /** الفرونت بيبعت heartbeat كل كام ثانية (لازم أقل بكتير من ExamSessionService::LIVE_TTL_SECONDS). */
    private const HEARTBEAT_SECONDS = 30;

    public function __construct(
        private ExamAttemptService $examAttempts,
        private ExamAttemptRepository $attemptsRepo,
        private StudentRepository $students,
        private ExamSessionService $sessions,
        private ExamProctoringService $proctoring,
        private ExamSecurityService $security
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

    /**
     * GET /api/v1/exam-system/active-attempt — هل الطالب جوّه امتحان دلوقتي؟
     * الفرونت بيسألها عند كل تحميل للـ layout عشان يفعّل وضع القفل (إخفاء الـ sidebar،
     * منع التنقل، تعطيل الـ AI) حتى لو الطالب فتح تاب تاني أو جهاز تاني أو كتب URL تاني.
     */
    public function activeAttempt(Request $request)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->attemptsRepo->activeAttemptForStudent($student->id);
        if (!$attempt) {
            return $this->apiSuccess(['active' => false], 'No active attempt.');
        }

        $attempt = $this->examAttempts->enforceTimer($attempt);
        if (!$attempt->isActive()) {
            return $this->apiSuccess(['active' => false], 'No active attempt.');
        }

        return $this->apiSuccess([
            'active'     => true,
            'attempt_id' => $attempt->id,
            'exam_id'    => $attempt->exam_id,
            'title'      => $attempt->exam?->title,
            'expires_at' => $attempt->expires_at,
            'in_grace'   => $attempt->inGrace(),
        ], 'Active attempt retrieved successfully.');
    }

    /** GET /api/v1/exam-system/my-exams/{id}/attempts — تاريخ محاولات الطالب على الامتحان ده. */
    public function indexForExam(Request $request, $examId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempts = array_map(fn ($a) => [
            'id'               => $a->id,
            'attempt_number'   => $a->attempt_number,
            'status'           => $a->status,
            'started_at'       => $a->started_at,
            'submitted_at'     => $a->submitted_at,
            'score'            => $a->score !== null ? (float) $a->score : null,
            'percentage'       => $a->percentage !== null ? (float) $a->percentage : null,
        ], $this->examAttempts->listAttempts($student->id, $examId));

        return $this->apiSuccess($attempts, 'Attempts retrieved successfully.');
    }

    /** POST /api/v1/exam-system/my-exams/{id}/attempts — يبدأ محاولة جديدة، أو يكمل واحدة شغالة لو موجودة. */
    public function start(Request $request, $examId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        // multipart (لقطة البدء/الكارنيه) أو JSON عادي. تفاصيل الصور بتتفحص في ExamProctoringService.
        $validator = Validator::make($request->all(), [
            'start_photo' => 'nullable|file|max:1500',
            'id_card'     => 'nullable|file|max:1500',
            'photo_flags' => 'nullable',
            'takeover'    => 'nullable|boolean',
            'access_password' => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        // حماية من تخمين الباسورد: 5 محاولات غلط كل 5 دقايق لكل طالب على كل امتحان.
        $limiterKey = 'exam-password:' . $student->id . ':' . (int) $examId;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many incorrect password attempts. Try again in ' . RateLimiter::availableIn($limiterKey) . ' seconds.',
                'data'    => ['code' => 'exam_password_locked', 'retry_after' => RateLimiter::availableIn($limiterKey)],
                'errors'  => null,
                'meta'    => (object) [],
            ], 429);
        }

        try {
            $result = $this->examAttempts->startAttempt($examId, $student->id, $student->university_id, [
                'start_photo' => $request->file('start_photo'),
                'id_card'     => $request->file('id_card'),
                'photo_flags' => $request->input('photo_flags', []),
                'ip'          => $request->ip(),
                'access_password' => $request->input('access_password'),
            ]);
            RateLimiter::clear($limiterKey);
        } catch (\InvalidArgumentException $e) {
            if (in_array($e->getCode(), [ExamAttemptService::ERR_PASSWORD_REQUIRED, ExamAttemptService::ERR_PASSWORD_INVALID], true)) {
                $invalid = $e->getCode() === ExamAttemptService::ERR_PASSWORD_INVALID;
                if ($invalid) {
                    RateLimiter::hit($limiterKey, 300);
                }
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data'    => ['code' => $invalid ? 'exam_password_invalid' : 'exam_password_required'],
                    'errors'  => null,
                    'meta'    => (object) [],
                ], 422);
            }
            if ($e->getCode() === ExamAttemptService::ERR_START_PHOTO_REQUIRED) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data'    => ['code' => 'start_photo_required'],
                    'errors'  => null,
                    'meta'    => (object) [],
                ], 422);
            }
            return $this->apiError($e->getMessage(), null, 422);
        }

        // الجهاز ده لازم ياخد الجلسة قبل ما نرجّع أي سؤال.
        $attempt = $result['attempt'];
        $claim = $this->sessions->claim($attempt, $this->sessionContext($request), $request->boolean('takeover'));
        if ($claim['status'] === 'conflict') {
            return $this->sessionErrorResponse('session_conflict', null, ['can_takeover' => true]);
        }
        if ($claim['takeover']) {
            // الاستحواذ على جلسة جهاز شغال = مخالفة (ممكن توصّل لحد الـ auto-submit).
            $violation = $this->security->recordSystemViolation($attempt, 'session_takeover', ['ip' => $request->ip()]);
            if ($violation['threshold_exceeded']) {
                $this->examAttempts->autoSubmitDueToViolations($attempt);
            }
        }

        $detail = $this->examAttempts->attemptDetail($attempt);
        $detail['session'] = [
            'enforced'          => $this->sessions->enforced($attempt->exam ?? $attempt->exam()->first()),
            'token'             => $claim['token'],
            'heartbeat_seconds' => self::HEARTBEAT_SECONDS,
            'takeover'          => $claim['takeover'],
        ];
        $message = $result['resumed'] ? 'Resuming your existing attempt.' : 'Exam attempt started.';

        return $this->apiSuccess($detail, $message, $result['resumed'] ? 200 : 201);
    }

    /** GET /api/v1/exam-system/attempts/{id} */
    public function show(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        return $this->apiSuccess($this->examAttempts->attemptDetail($attempt), 'Attempt retrieved successfully.');
    }

    /** PUT /api/v1/exam-system/attempts/{id}/answers/{examQuestionId} — Auto-save: create/update. */
    public function saveAnswer(Request $request, $id, $examQuestionId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        $validator = Validator::make($request->all(), [
            'selected_option_ids'   => 'nullable|array',
            // integer ids for mcq; true/false may arrive as 'true'/'false' — the service validates per question type.
            'selected_option_ids.*' => ['required', function ($attr, $value, $fail) {
                if (!is_scalar($value)) {
                    $fail('Invalid option.');
                }
            }],
            'answer_text'           => 'nullable|string|max:20000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->examAttempts->saveAnswer($attempt, $examQuestionId, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'deleted'                => $result['deleted'],
            'status'                 => $result['attempt']->status,
            'time_remaining_seconds' => $result['attempt']->remainingSeconds(),
            'in_grace'               => $result['attempt']->inGrace(),
            'grace_remaining_seconds' => $result['attempt']->graceRemainingSeconds(),
        ], $result['deleted'] ? 'Answer cleared.' : 'Answer saved.');
    }

    /** DELETE /api/v1/exam-system/attempts/{id}/answers/{examQuestionId} — Auto-save: explicit delete. */
    public function deleteAnswer(Request $request, $id, $examQuestionId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        try {
            $attempt = $this->examAttempts->deleteAnswer($attempt, $examQuestionId);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'status'                 => $attempt->status,
            'time_remaining_seconds' => $attempt->remainingSeconds(),
            'in_grace'               => $attempt->inGrace(),
            'grace_remaining_seconds' => $attempt->graceRemainingSeconds(),
        ], 'Answer cleared.');
    }

    /** POST /api/v1/exam-system/attempts/{id}/submit */
    public function submit(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        $attempt = $this->examAttempts->submitAttempt($attempt);

        return $this->apiSuccess($this->examAttempts->attemptDetail($attempt), 'Exam submitted successfully.');
    }

    /** GET /api/v1/exam-system/attempts/{id}/result — Round 4 (Phase 24). نتيجة الطالب، محكومة بـ result_visibility. */
    public function result(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        return $this->apiSuccess($this->examAttempts->studentResult($attempt), 'Result retrieved successfully.');
    }

    /** POST /api/v1/exam-system/attempts/{id}/heartbeat — بيثبّت الجلسة الحية وبيكشف بسرعة لو جهاز تاني استولى عليها. */
    public function heartbeat(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        $attempt = $this->examAttempts->enforceTimer($attempt);

        return $this->apiSuccess([
            'status'                  => $attempt->status,
            'time_remaining_seconds'  => $attempt->remainingSeconds(),
            'in_grace'                => $attempt->inGrace(),
            'grace_remaining_seconds' => $attempt->graceRemainingSeconds(),
            'expires_at'              => $attempt->expires_at,
        ], 'OK');
    }

    /** POST /api/v1/exam-system/attempts/{id}/snapshots — لقطة كاميرا دورية (multipart: photo + flags). */
    public function uploadSnapshot(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }
        if ($guard = $this->sessionGuard($request, $attempt, $this->sessions)) {
            return $guard;
        }

        $validator = Validator::make($request->all(), [
            'photo' => 'required|file|max:1500',
            'flags' => 'nullable',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $attempt = $this->examAttempts->enforceTimer($attempt);
        $exam = $attempt->exam ?? $attempt->exam()->first();

        try {
            $result = $this->proctoring->uploadPeriodic($attempt, $exam, $request->file('photo'), $request->input('flags', []), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($result, $result['stored'] ? 'Snapshot saved.' : 'Snapshot skipped.');
    }

    private function findOwnedAttempt($id, $studentId)
    {
        return $this->attemptsRepo->findOwned($id, $studentId);
    }
}
