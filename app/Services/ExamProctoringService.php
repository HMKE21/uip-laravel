<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamProctoringSnapshot;
use App\Models\ExamStudentOverride;
use App\Repositories\ExamAttemptRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * مراقبة الكاميرا + تحقق الهوية.
 *
 * off | optional | required. identity_check_required=true بيرفع الوضع لـ required دايمًا.
 *  - لقطة بدء (سيلفي، + كارنيه لو الهوية مطلوبة) إجبارية عند إنشاء محاولة جديدة لو required ومش معفي الطالب.
 *  - لقطات دورية كل snapshot_interval_seconds (السيرفر بيفرض حد أدنى للفاصل وحد أقصى للعدد).
 *  - الهوية: pending لحد ما المدرس يقبل/يرفض. المدرس يقدر يعفي طالب (proctoring_waived).
 *
 * حدود صريحة: "flags" بتتحسب في متصفح الطالب (إضاءة/لقطة فاضية/وجه لو FaceDetector متاح) — مؤشر مساعد
 * للمراجعة مش دليل، وماتتحسبش مخالفة تلقائيًا. مفيش مطابقة وجه بين السيلفي والكارنيه — المراجعة بشرية.
 * الصور على disk خاص (مش public) وبتتقدّم بس لصاحب الامتحان.
 */
class ExamProctoringService
{
    public const DISK = 'local';
    public const MODES = ['off', 'optional', 'required'];
    public const MIN_INTERVAL = 15;
    public const MAX_INTERVAL = 600;
    public const MAX_IMAGE_BYTES = 1_500_000;
    public const MAX_PERIODIC_PER_ATTEMPT = 500;
    public const ALLOWED_FLAGS = ['too_dark', 'blank', 'no_face', 'multiple_faces'];
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private ExamAttemptRepository $attempts,
        private ExamSecurityService $security,
        private AuditLogService $auditLog
    ) {
    }

    public function examMode(Exam $exam): string
    {
        if ($exam->identity_check_required) {
            return 'required';
        }
        $mode = (string) ($exam->proctoring_mode ?? 'off');
        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public function isWaived(Exam $exam, $studentId): bool
    {
        $override = $this->attempts->overrideFor($exam->id, $studentId);
        return $override !== null && (bool) $override->proctoring_waived;
    }

    public function effectiveMode(Exam $exam, $studentId): string
    {
        $mode = $this->examMode($exam);
        return ($mode === 'off' || $this->isWaived($exam, $studentId)) ? 'off' : $mode;
    }

    public function requiresStartPhoto(Exam $exam, $studentId): bool
    {
        return $this->effectiveMode($exam, $studentId) === 'required';
    }

    public function identityRequired(Exam $exam, $studentId): bool
    {
        return (bool) $exam->identity_check_required && !$this->isWaived($exam, $studentId);
    }

    public function studentConfig(Exam $exam, $studentId): array
    {
        $mode = $this->effectiveMode($exam, $studentId);
        return [
            'mode'                      => $mode,
            'snapshot_interval_seconds' => $this->normalizeInterval($exam->snapshot_interval_seconds),
            'identity_check_required'   => $this->identityRequired($exam, $studentId),
            'waived'                    => $this->examMode($exam) !== 'off' && $mode === 'off',
        ];
    }

    public function normalizeInterval($value): int
    {
        $v = (int) ($value ?: 60);
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $v));
    }

    /** @throws \InvalidArgumentException */
    public function inspectImage(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The uploaded image is not valid.');
        }
        if ($file->getSize() === false || $file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException('The image is too large (max 1.5 MB).');
        }
        $mime = (string) $file->getMimeType();
        if (!isset(self::MIMES[$mime])) {
            throw new \InvalidArgumentException('Only JPEG, PNG or WebP images are accepted.');
        }
        $info = @getimagesize($file->getRealPath());
        if ($info === false || $info[0] < 80 || $info[1] < 80 || $info[0] > 4000 || $info[1] > 4000) {
            throw new \InvalidArgumentException('The image dimensions are not acceptable.');
        }
        return $mime;
    }

    /** @return string[] flags مسموحة بس. */
    public function sanitizeFlags($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('strval', $raw),
            fn ($f) => in_array($f, self::ALLOWED_FLAGS, true)
        )));
    }

    private function store(ExamAttempt $attempt, UploadedFile $file, string $kind, array $flags, ?string $ip): ExamProctoringSnapshot
    {
        $mime = $this->inspectImage($file);
        $dir = 'exam-proctoring/' . $attempt->exam_id . '/' . $attempt->id;
        $name = Str::uuid()->toString() . '.' . self::MIMES[$mime];
        $path = Storage::disk(self::DISK)->putFileAs($dir, $file, $name);
        if ($path === false) {
            throw new \RuntimeException('Failed to store the snapshot.');
        }

        try {
            return ExamProctoringSnapshot::create([
                'exam_attempt_id' => $attempt->id,
                'exam_id'         => $attempt->exam_id,
                'student_id'      => $attempt->student_id,
                'kind'            => $kind,
                'path'            => $path,
                'mime'            => $mime,
                'size_bytes'      => (int) $file->getSize(),
                'flags'           => $flags ?: null,
                'ip'              => $ip,
                'captured_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    /** لقطة/لقطات البدء — جوه transaction إنشاء المحاولة؛ أي ملف اتخزن قبل فشل بيتمسح. */
    public function storeStartPhotos(ExamAttempt $attempt, Exam $exam, UploadedFile $photo, ?UploadedFile $idCard, $flags, ?string $ip): void
    {
        $stored = [];
        $flags = $this->sanitizeFlags($flags);
        $identity = $this->identityRequired($exam, $attempt->student_id);
        try {
            $stored[] = $this->store($attempt, $photo, $identity ? 'identity' : 'periodic', $flags, $ip);
            if ($idCard && $identity) {
                $stored[] = $this->store($attempt, $idCard, 'id_card', [], $ip);
            }
        } catch (\Throwable $e) {
            foreach ($stored as $s) {
                Storage::disk(self::DISK)->delete($s->path);
            }
            throw $e;
        }

        if ($identity) {
            $attempt->identity_status = 'pending';
        }
        if ($flags) {
            $attempt->proctoring_flags_count = (int) $attempt->proctoring_flags_count + 1;
        }
        $attempt->save();
    }

    public function logStartEvents(ExamAttempt $attempt, Exam $exam, $flags): void
    {
        if ($this->identityRequired($exam, $attempt->student_id)) {
            $this->security->logSystemEvent($attempt, 'identity_submitted');
        }
        if ($f = $this->sanitizeFlags($flags)) {
            $this->security->logSystemEvent($attempt, 'proctoring_flag', ['kind' => 'start', 'flags' => $f]);
        }
    }

    /**
     * @return array{stored:bool, throttled:bool, flagged:bool}
     * @throws \InvalidArgumentException
     */
    public function uploadPeriodic(ExamAttempt $attempt, Exam $exam, UploadedFile $photo, $rawFlags, ?string $ip): array
    {
        if (!$attempt->isActive()) {
            throw new \InvalidArgumentException('This attempt is no longer in progress.');
        }
        if ($this->effectiveMode($exam, $attempt->student_id) === 'off') {
            throw new \InvalidArgumentException('Camera monitoring is not enabled for this exam.');
        }

        $interval = $this->normalizeInterval($exam->snapshot_interval_seconds);
        $minGap = max(10, (int) floor($interval / 2));

        $last = ExamProctoringSnapshot::where('exam_attempt_id', $attempt->id)->orderByDesc('captured_at')->first();
        if ($last && $last->captured_at->greaterThan(now()->subSeconds($minGap))) {
            return ['stored' => false, 'throttled' => true, 'flagged' => false];
        }
        $count = ExamProctoringSnapshot::where('exam_attempt_id', $attempt->id)->where('kind', 'periodic')->count();
        if ($count >= self::MAX_PERIODIC_PER_ATTEMPT) {
            return ['stored' => false, 'throttled' => true, 'flagged' => false];
        }

        $flags = $this->sanitizeFlags($rawFlags);
        $this->store($attempt, $photo, 'periodic', $flags, $ip);

        // آخر لقطة أقدم من 3 فواصل (+30ث) => الكاميرا اتقطعت فترة (إعلامي).
        if ($last && $last->captured_at->lessThan(now()->subSeconds($interval * 3 + 30))) {
            $this->security->logSystemEvent($attempt, 'proctoring_gap', [
                'seconds' => (int) $last->captured_at->diffInSeconds(now()),
            ]);
        }

        if ($flags) {
            $attempt->proctoring_flags_count = (int) $attempt->proctoring_flags_count + 1;
            $attempt->save();
            $this->security->logSystemEvent($attempt, 'proctoring_flag', ['kind' => 'periodic', 'flags' => $flags]);
        }

        return ['stored' => true, 'throttled' => false, 'flagged' => (bool) $flags];
    }

    public function integrityFor(ExamAttempt $attempt, ExamSessionService $sessions): array
    {
        $snaps = ExamProctoringSnapshot::where('exam_attempt_id', $attempt->id)->orderBy('captured_at')->get();

        return [
            'attempt_id' => $attempt->id,
            'session' => [
                'ip'           => $attempt->session_ip,
                'user_agent'   => $attempt->session_user_agent,
                'last_seen_at' => $attempt->session_last_seen_at,
                'claims_count' => (int) $attempt->session_claims_count,
                'log'          => $sessions->logForAttempt($attempt),
            ],
            'identity' => [
                'status'      => $attempt->identity_status ?: 'none',
                'reviewed_at' => $attempt->identity_reviewed_at,
                'note'        => $attempt->identity_review_note,
            ],
            'proctoring' => [
                'flags_count'     => (int) $attempt->proctoring_flags_count,
                'snapshots_count' => $snaps->count(),
                'snapshots'       => $snaps->map(fn (ExamProctoringSnapshot $s) => [
                    'id'          => $s->id,
                    'kind'        => $s->kind,
                    'flags'       => $s->flags ?: [],
                    'captured_at' => $s->captured_at,
                    'size_bytes'  => $s->size_bytes,
                ])->all(),
            ],
        ];
    }

    public function findSnapshot(ExamAttempt $attempt, $snapshotId): ?ExamProctoringSnapshot
    {
        return ExamProctoringSnapshot::where('exam_attempt_id', $attempt->id)->whereKey($snapshotId)->first();
    }

    public function snapshotContents(ExamProctoringSnapshot $snap): ?string
    {
        $disk = Storage::disk(self::DISK);
        return $disk->exists($snap->path) ? $disk->get($snap->path) : null;
    }

    /** @throws \InvalidArgumentException */
    public function reviewIdentity(ExamAttempt $attempt, string $decision, $staffUserId, ?string $note): ExamAttempt
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('Decision must be approve or reject.');
        }
        if (!in_array($attempt->identity_status, ['pending', 'approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('This attempt has no identity submission to review.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($decision === 'reject' && ($note === null || $note === '')) {
            throw new \InvalidArgumentException('A note is required when rejecting an identity.');
        }

        $old = $attempt->identity_status;
        $attempt->identity_status = $decision === 'approve' ? 'approved' : 'rejected';
        $attempt->identity_reviewed_by = $staffUserId;
        $attempt->identity_reviewed_at = now();
        $attempt->identity_review_note = ($note !== null && $note !== '') ? $note : null;
        $attempt->save();

        $this->security->logSystemEvent($attempt, $decision === 'approve' ? 'identity_approved' : 'identity_rejected', ['note' => $attempt->identity_review_note]);
        $this->auditLog->record(
            $staffUserId, 'exam.identity_reviewed', 'ExamAttempt', $attempt->id,
            ['identity_status' => $old],
            ['identity_status' => $attempt->identity_status, 'note' => $attempt->identity_review_note]
        );

        return $attempt;
    }

    public function setWaiver(Exam $exam, $studentId, bool $waived, $staffUserId, ?string $reason): ExamStudentOverride
    {
        $existing = $this->attempts->overrideFor($exam->id, $studentId);
        $data = ['proctoring_waived' => $waived, 'granted_by' => $staffUserId];
        if ($reason !== null && trim($reason) !== '') {
            $data['reason'] = trim($reason);
        }
        // صف override جديد بيتخلق بـ extra_attempts=0 (default) — مفيش إعادة بتتمنح بالغلط.
        $override = $this->attempts->saveOverride($exam->id, $studentId, $data);

        $this->auditLog->record(
            $staffUserId, 'exam.proctoring_waiver', 'Exam', $exam->id,
            ['proctoring_waived' => $existing ? (bool) $existing->proctoring_waived : false],
            ['student_id' => (int) $studentId, 'proctoring_waived' => $waived, 'reason' => $reason]
        );

        return $override;
    }
}
