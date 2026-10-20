<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamStudentOverride;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamTargetRepository;
use App\Repositories\StudentRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * صلاحيات المدرس على محاولات الطلاب: إلغاء محاولة، والسماح بإعادة الامتحان.
 * ملكية الامتحان بتتفحص في الـ controller (ExamGradingApiController) قبل أي
 * ميثود هنا، فالخدمة دي بتفترض إن المدرس مالك الامتحان.
 *
 * - cancelAttempt(): status=cancelled + السبب + مين ألغى. لو المحاولة كانت شغالة
 *   الطالب بيتفصل منها (أي حفظ/تسليم بيترفض لأن المحاولة مبقتش in_progress،
 *   وactive-attempt بيرجّع false فقفل الامتحان بيتفك). بتفضل في السجل
 *   وبتتحسب ضمن المحاولات المستخدمة إلا لو allowRetake=true.
 * - grantRetake(): بيزوّد extra_attempts للطالب ده على الامتحان ده، واختياريًا
 *   بيفتحله نافذة وصول خاصة (available_until) لو الامتحان خلص وقته.
 * - revokeRetake(): بيسحب المحاولات الإضافية اللي لسه ماتستخدمتش.
 * كل فعل بيتسجل في audit log وبيوصل للطالب كإشعار.
 */
class ExamAttemptManagementService
{
    public const MAX_EXTRA_ATTEMPTS = 10;
    /** أقصى دقايق إضافية في عملية واحدة، وأقصى مجموع على محاولة واحدة. */
    public const MAX_EXTRA_MINUTES = 240;
    public const MAX_TOTAL_EXTRA_MINUTES = 600;
    /** أقصى عدد عناصر في عملية جماعية واحدة. */
    public const MAX_BULK = 200;

    public function __construct(
        private ExamAttemptRepository $attempts,
        private ExamTargetRepository $targets,
        private StudentRepository $students,
        private NotificationService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     * @return array{attempt: ExamAttempt, override: ?ExamStudentOverride}
     */
    public function cancelAttempt(
        Exam $exam,
        ExamAttempt $attempt,
        $staffUserId,
        ?string $reason = null,
        bool $allowRetake = false,
        ?Carbon $availableUntil = null
    ): array {
        if ($attempt->status === 'cancelled') {
            throw new \InvalidArgumentException('This attempt is already cancelled.');
        }

        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;
        $oldStatus = $attempt->status;

        $attempt->status = 'cancelled';
        $attempt->cancelled_at = now();
        $attempt->cancelled_by = $staffUserId;
        $attempt->cancel_reason = $reason;
        $attempt->save();

        $this->auditLog->record(
            $staffUserId,
            'exam.attempt_cancelled',
            'ExamAttempt',
            $attempt->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled', 'reason' => $reason, 'allow_retake' => $allowRetake, 'exam_id' => $exam->id, 'student_id' => $attempt->student_id]
        );

        $override = null;
        if ($allowRetake) {
            $override = $this->grantRetake($exam, $attempt->student_id, 1, $availableUntil, $staffUserId, $reason, false);
        }

        $this->notifyStudent(
            $attempt->student_id,
            'exam_attempt_cancelled',
            'Exam attempt cancelled: ' . $exam->title,
            'Your attempt on "' . $exam->title . '" was cancelled by your instructor.'
                . ($reason ? ' Reason: ' . $reason : '')
                . ($allowRetake ? ' You have been allowed to retake the exam.' : ''),
            $allowRetake ? 'high' : 'normal'
        );

        return ['attempt' => $attempt, 'override' => $override];
    }

    /**
     * يسمح للطالب ده بمحاولات إضافية على الامتحان (إعادة). بتتراكم فوق اللي
     * اتمنح قبل كده، بحد أقصى MAX_EXTRA_ATTEMPTS.
     *
     * @throws \InvalidArgumentException
     */
    public function grantRetake(
        Exam $exam,
        $studentId,
        int $extraAttempts = 1,
        ?Carbon $availableUntil = null,
        $staffUserId = null,
        ?string $reason = null,
        bool $notify = true
    ): ExamStudentOverride {
        if ($extraAttempts < 1 || $extraAttempts > self::MAX_EXTRA_ATTEMPTS) {
            throw new \InvalidArgumentException('Extra attempts must be between 1 and ' . self::MAX_EXTRA_ATTEMPTS . '.');
        }

        $student = $this->students->find($studentId);
        if (!$student || (int) $student->university_id !== (int) $exam->university_id) {
            throw new \InvalidArgumentException('Student not found.');
        }
        if (!$this->targets->studentIsEligibleForExam($exam->id, $student->id)) {
            throw new \InvalidArgumentException('This student is not eligible for this exam.');
        }
        if ($availableUntil !== null && $availableUntil->lessThanOrEqualTo(now())) {
            throw new \InvalidArgumentException('The retake deadline must be in the future.');
        }

        $existing = $this->attempts->overrideFor($exam->id, $student->id);
        $oldExtra = $existing ? (int) $existing->extra_attempts : 0;
        $newExtra = min(self::MAX_EXTRA_ATTEMPTS, $oldExtra + $extraAttempts);

        $data = [
            'extra_attempts' => $newExtra,
            'granted_by'     => $staffUserId,
        ];
        if ($availableUntil !== null) {
            $data['available_until'] = $availableUntil;
        }
        if ($reason !== null && trim($reason) !== '') {
            $data['reason'] = trim($reason);
        }
        $override = $this->attempts->saveOverride($exam->id, $student->id, $data);

        $this->auditLog->record(
            $staffUserId,
            'exam.retake_granted',
            'Exam',
            $exam->id,
            ['extra_attempts' => $oldExtra],
            ['student_id' => $student->id, 'extra_attempts' => $newExtra, 'available_until' => $availableUntil?->toDateTimeString(), 'reason' => $reason]
        );

        if ($notify) {
            $this->notifyStudent(
                $student->id,
                'exam_retake_granted',
                'You can retake: ' . $exam->title,
                'Your instructor allowed you another attempt on "' . $exam->title . '".'
                    . ($availableUntil ? ' Available until ' . $availableUntil->toDayDateTimeString() . '.' : ''),
                'high'
            );
        }

        return $override;
    }

    /**
     * يسحب المحاولات الإضافية اللي لسه ماتستخدمتش (اللي اتستخدمت فعلًا مش بتتأثر).
     *
     * @throws \InvalidArgumentException
     */
    public function revokeRetake(Exam $exam, $studentId, $staffUserId = null): ExamStudentOverride
    {
        $override = $this->attempts->overrideFor($exam->id, $studentId);
        if (!$override || (int) $override->extra_attempts < 1) {
            throw new \InvalidArgumentException('This student has no extra attempts to revoke.');
        }

        $used = count($this->attempts->forStudentAndExam($studentId, $exam->id));
        $unused = ((int) $exam->max_attempts + (int) $override->extra_attempts) - $used;
        if ($unused < 1) {
            throw new \InvalidArgumentException('All extra attempts were already used.');
        }

        $old = (int) $override->extra_attempts;
        $override->extra_attempts = max(0, $old - $unused);
        $override->save();

        $this->auditLog->record(
            $staffUserId,
            'exam.retake_revoked',
            'Exam',
            $exam->id,
            ['extra_attempts' => $old],
            ['student_id' => (int) $studentId, 'extra_attempts' => (int) $override->extra_attempts]
        );

        $this->notifyStudent(
            $studentId,
            'exam_retake_revoked',
            'Retake withdrawn: ' . $exam->title,
            'Your instructor withdrew the extra attempt on "' . $exam->title . '".',
            'normal'
        );

        return $override;
    }

    // -----------------------------------------------------------------
    // وقت إضافي لمحاولة شغالة
    // -----------------------------------------------------------------

    /**
     * يزوّد دقايق على محاولة in_progress (ظروف خاصة / مشكلة نت) من غير ما المدرس يلغيها. الدقايق بتتضاف على
     * expires_at مباشرة، فالتايمر بتاع الطالب والـ auto-submit بيحترموها من غير أي منطق إضافي.
     *
     * @throws \InvalidArgumentException
     */
    public function addExtraTime(Exam $exam, ExamAttempt $attempt, int $minutes, $staffUserId = null, ?string $reason = null): ExamAttempt
    {
        if ($minutes < 1 || $minutes > self::MAX_EXTRA_MINUTES) {
            throw new \InvalidArgumentException('Extra time must be between 1 and ' . self::MAX_EXTRA_MINUTES . ' minutes.');
        }
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        $fresh = DB::transaction(function () use ($exam, $attempt, $minutes) {
            // قفل الصف عشان تسليم الطالب/الـ sweep ميتسابقوش مع الزيادة.
            $locked = ExamAttempt::with('exam')->lockForUpdate()->find($attempt->id);
            if (!$locked || (int) $locked->exam_id !== (int) $exam->id) {
                throw new \InvalidArgumentException('Attempt not found.');
            }
            if (!$locked->isActive()) {
                throw new \InvalidArgumentException('Extra time can only be added to an attempt that is still in progress.');
            }
            if ($locked->isHardExpired()) {
                throw new \InvalidArgumentException('This attempt has already run out of time.');
            }
            if ((int) $locked->extra_time_minutes + $minutes > self::MAX_TOTAL_EXTRA_MINUTES) {
                throw new \InvalidArgumentException('The total extra time for one attempt cannot exceed ' . self::MAX_TOTAL_EXTRA_MINUTES . ' minutes.');
            }

            $locked->expires_at = $locked->expires_at->copy()->addMinutes($minutes);
            $locked->extra_time_minutes = (int) $locked->extra_time_minutes + $minutes;
            $locked->save();
            return $locked;
        });

        $this->auditLog->record(
            $staffUserId,
            'exam.attempt_extra_time',
            'ExamAttempt',
            $fresh->id,
            null,
            ['minutes' => $minutes, 'total_extra_minutes' => (int) $fresh->extra_time_minutes, 'reason' => $reason, 'exam_id' => $exam->id, 'student_id' => $fresh->student_id]
        );

        $this->notifyStudent(
            $fresh->student_id,
            'exam_extra_time_granted',
            'Extra time added: ' . $exam->title,
            'Your instructor added ' . $minutes . ' minute(s) to your attempt on \"' . $exam->title . '\".' . ($reason ? ' Reason: ' . $reason : ''),
            'high'
        );

        // الأوبجكت اللي جه من برّه بيتحدّث كمان.
        $attempt->expires_at = $fresh->expires_at;
        $attempt->extra_time_minutes = $fresh->extra_time_minutes;

        return $fresh;
    }

    // -----------------------------------------------------------------
    // إجراءات جماعية — انقطاع نت / سيرفر بيأثر على مجموعة طلاب
    // -----------------------------------------------------------------
    // كل ميثود بتعدي على العناصر واحدة واحدة وبتجمّع النتيجة: فشل عنصر واحد عمره ما بيوقف الباقي.
    // الرد دايمًا {succeeded: [ids], failed: [{id, error}]}.

    /** @param int[] $attemptIds @return array{succeeded:int[],failed:array<int,array{id:int,error:string}>} */
    public function bulkCancel(Exam $exam, array $attemptIds, $staffUserId, ?string $reason = null, bool $allowRetake = false, ?Carbon $availableUntil = null): array
    {
        return $this->runBulk($this->normalizeIds($attemptIds), function (int $id) use ($exam, $staffUserId, $reason, $allowRetake, $availableUntil) {
            $attempt = $this->attempts->find($id);
            if (!$attempt || (int) $attempt->exam_id !== (int) $exam->id) {
                throw new \InvalidArgumentException('Attempt not found.');
            }
            $this->cancelAttempt($exam, $attempt, $staffUserId, $reason, $allowRetake, $availableUntil);
        });
    }

    /** @param int[] $studentIds @return array{succeeded:int[],failed:array<int,array{id:int,error:string}>} */
    public function bulkGrantRetake(Exam $exam, array $studentIds, int $extraAttempts = 1, ?Carbon $availableUntil = null, $staffUserId = null, ?string $reason = null): array
    {
        return $this->runBulk($this->normalizeIds($studentIds), function (int $id) use ($exam, $extraAttempts, $availableUntil, $staffUserId, $reason) {
            $this->grantRetake($exam, $id, $extraAttempts, $availableUntil, $staffUserId, $reason);
        });
    }

    /** @param int[] $attemptIds @return array{succeeded:int[],failed:array<int,array{id:int,error:string}>} */
    public function bulkAddExtraTime(Exam $exam, array $attemptIds, int $minutes, $staffUserId = null, ?string $reason = null): array
    {
        return $this->runBulk($this->normalizeIds($attemptIds), function (int $id) use ($exam, $minutes, $staffUserId, $reason) {
            $attempt = $this->attempts->find($id);
            if (!$attempt || (int) $attempt->exam_id !== (int) $exam->id) {
                throw new \InvalidArgumentException('Attempt not found.');
            }
            $this->addExtraTime($exam, $attempt, $minutes, $staffUserId, $reason);
        });
    }

    /** @param array<int,mixed> $ids @return int[] */
    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (count($ids) > self::MAX_BULK) {
            throw new \InvalidArgumentException('You can process at most ' . self::MAX_BULK . ' items at once.');
        }
        return $ids;
    }

    /** @param int[] $ids @return array{succeeded:int[],failed:array<int,array{id:int,error:string}>} */
    private function runBulk(array $ids, callable $action): array
    {
        $succeeded = [];
        $failed = [];
        foreach ($ids as $id) {
            try {
                $action($id);
                $succeeded[] = $id;
            } catch (\InvalidArgumentException $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    // -----------------------------------------------------------------
    // بحث الطلاب المؤهلين (لإدّي فرصة لطالب ماجاش)
    // -----------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>> طلاب مؤهلين للامتحان مع عدد محاولاتهم والاستثناء الحالي.
     */
    public function searchEligibleStudents(Exam $exam, ?string $query = null, bool $onlyWithoutAttempts = false, int $limit = 50): array
    {
        $rows = $this->targets->searchForExamSaved($exam->id, $exam->university_id, $query, $onlyWithoutAttempts, $limit);
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $counts = $this->attempts->attemptCountsByStudent($exam->id, $ids);
        $overrides = $this->attempts->overridesForExam($exam->id);

        return array_map(fn ($r) => [
            'id'              => (int) $r->id,
            'student_number'  => $r->student_number,
            'name'            => $r->full_name,
            'email'           => $r->email,
            'attempts_used'   => $counts[(int) $r->id] ?? 0,
            'extra_attempts'  => isset($overrides[(int) $r->id]) ? (int) $overrides[(int) $r->id]->extra_attempts : 0,
            'available_until' => isset($overrides[(int) $r->id]) ? $overrides[(int) $r->id]->available_until : null,
        ], $rows);
    }

    private function notifyStudent($studentId, string $type, string $title, string $body, string $priority): void
    {
        $student = $this->students->find($studentId);
        if ($student) {
            $this->notifications->notify($student->user_id, $type, $title, $body, null, $priority);
        }
    }
}
