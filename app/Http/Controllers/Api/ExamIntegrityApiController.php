<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamTargetRepository;
use App\Repositories\StudentRepository;
use App\Services\ExamProctoringService;
use App\Services\ExamSessionService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح المدرس لنزاهة الامتحان: سجل الجلسات (IP/جهاز)، لقطات الكاميرا، مراجعة الهوية، وإعفاء طالب من الكاميرا.
 * كل ميثود بتفحص ملكية الامتحان الأول (findOwnedExam) وبعدين إن المحاولة/اللقطة تابعة للامتحان ده بالذات.
 */
class ExamIntegrityApiController extends Controller
{
    public function __construct(
        private ExamProctoringService $proctoring,
        private ExamSessionService $sessions,
        private ExamSystemService $examSystem,
        private ExamAttemptRepository $attemptsRepo,
        private AcademicStaffRepository $staffRepo,
        private StudentRepository $students,
        private ExamTargetRepository $targets
    ) {
    }

    private function resolveOwned(Request $request, $examId): array
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

    private function resolveAttempt($exam, $attemptId): array
    {
        $attempt = $this->attemptsRepo->find($attemptId);
        if (!$attempt || (int) $attempt->exam_id !== (int) $exam->id) {
            return [null, $this->apiError('Attempt not found.', null, 404)];
        }
        return [$attempt, null];
    }

    /** GET exams/{id}/attempts/{attemptId}/integrity — جلسات + هوية + قايمة اللقطات (metadata بس). */
    public function show(Request $request, $examId, $attemptId)
    {
        [$staff, $exam, $err] = $this->resolveOwned($request, $examId);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttempt($exam, $attemptId);
        if ($err) {
            return $err;
        }

        $data = $this->proctoring->integrityFor($attempt, $this->sessions);
        $data['exam'] = [
            'single_session_enabled'  => (bool) $exam->single_session_enabled,
            'proctoring_mode'         => $this->proctoring->examMode($exam),
            'identity_check_required' => (bool) $exam->identity_check_required,
            'student_waived'          => $this->proctoring->isWaived($exam, $attempt->student_id),
        ];

        return $this->apiSuccess($data, 'Integrity data retrieved successfully.');
    }

    /** GET exams/{id}/attempts/{attemptId}/snapshots/{snapshotId} — الصورة نفسها (binary). */
    public function snapshot(Request $request, $examId, $attemptId, $snapshotId)
    {
        [$staff, $exam, $err] = $this->resolveOwned($request, $examId);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttempt($exam, $attemptId);
        if ($err) {
            return $err;
        }

        $snap = $this->proctoring->findSnapshot($attempt, $snapshotId);
        $contents = $snap ? $this->proctoring->snapshotContents($snap) : null;
        if (!$snap || $contents === null) {
            return $this->apiError('Snapshot not found.', null, 404);
        }

        return response($contents, 200, [
            'Content-Type'           => $snap->mime,
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST exams/{id}/attempts/{attemptId}/identity-review — decision (approve|reject), note? (إجباري مع reject). */
    public function reviewIdentity(Request $request, $examId, $attemptId)
    {
        [$staff, $exam, $err] = $this->resolveOwned($request, $examId);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttempt($exam, $attemptId);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'note'     => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $attempt = $this->proctoring->reviewIdentity(
                $attempt,
                $request->input('decision'),
                (int) $request->attributes->get('uip_user_id'),
                $request->input('note')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'attempt_id'      => $attempt->id,
            'identity_status' => $attempt->identity_status,
            'reviewed_at'     => $attempt->identity_reviewed_at,
            'note'            => $attempt->identity_review_note,
        ], 'Identity review saved.');
    }

    /** PUT exams/{id}/students/{studentId}/proctoring-waiver — waived (bool), reason? */
    public function setWaiver(Request $request, $examId, $studentId)
    {
        [$staff, $exam, $err] = $this->resolveOwned($request, $examId);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'waived' => 'required|boolean',
            'reason' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $student = $this->students->find($studentId);
        if (!$student || (int) $student->university_id !== (int) $exam->university_id
            || !$this->targets->studentIsEligibleForExam($exam->id, $student->id)) {
            return $this->apiError('Student not found.', null, 404);
        }

        $override = $this->proctoring->setWaiver(
            $exam, $student->id, $request->boolean('waived'),
            (int) $request->attributes->get('uip_user_id'), $request->input('reason')
        );

        return $this->apiSuccess([
            'student_id'        => $student->id,
            'proctoring_waived' => (bool) $override->proctoring_waived,
        ], $override->proctoring_waived ? 'Student exempted from camera monitoring.' : 'Camera monitoring restored for this student.');
    }
}
