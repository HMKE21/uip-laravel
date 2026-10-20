<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 3 (Attempts). محاولة طالب واحدة على
 * امتحان واحد. راجع docblock migration 2026_08_28_170000 لمعنى كل عمود
 * ومنطق status/expires_at. isExpired()/remainingSeconds() هي اللي
 * ExamAttemptService::enforceTimer() بيتكئ عليها — الحساب دايمًا بالساعة
 * السيرفر (now())، مفيش أي وقت جاي من العميل بيتصدق.
 */
class ExamAttempt extends Model
{
    protected $table = 'exam_attempts';

    protected $fillable = [
        'exam_id', 'student_id', 'attempt_number', 'status',
        'started_at', 'last_activity_at', 'expires_at', 'submitted_at',
        'auto_submitted', 'violations_count', 'score', 'percentage',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
        'extra_time_minutes', 'is_late', 'late_penalty_percent', 'score_before_penalty',
        'session_token_hash', 'session_last_seen_at', 'session_ip', 'session_user_agent', 'session_device_id',
        'session_claims_count', 'identity_status', 'identity_reviewed_by', 'identity_reviewed_at',
        'identity_review_note', 'proctoring_flags_count',
    ];

    /** hash الـ token بتاع الجلسة عمره ما يطلع في أي response (toArray/JSON). */
    protected $hidden = ['session_token_hash'];

    protected $casts = [
        'started_at'       => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at'       => 'datetime',
        'submitted_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
        'session_last_seen_at' => 'datetime',
        'identity_reviewed_at' => 'datetime',
        'session_claims_count' => 'integer',
        'proctoring_flags_count' => 'integer',
        'auto_submitted'   => 'boolean',
        'is_late'          => 'boolean',
        'extra_time_minutes' => 'integer',
        'late_penalty_percent' => 'decimal:2',
        'score_before_penalty' => 'decimal:2',
        'score'            => 'decimal:2',
        'percentage'       => 'decimal:2',
    ];

    /** الحالات اللي المحاولة لسه "شغالة" فيها — الطالب لسه ممكن يحفظ إجابات. */
    public const ACTIVE_STATUSES = ['in_progress'];

    /** الحالات اللي بتعتبر "خلصت" فعليًا (submitted بأي شكل) — يقصون المحاولة من عد "attempts remaining". */
    public const FINISHED_STATUSES = ['submitted', 'auto_submitted', 'expired', 'cancelled', 'grading', 'graded'];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function answers()
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function grades()
    {
        return $this->hasMany(ExamGrade::class, 'exam_attempt_id');
    }

    public function securityEvents()
    {
        return $this->hasMany(ExamSecurityEvent::class, 'exam_attempt_id')->orderBy('occurred_at');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** الموعد المعدّل (expires_at) عدى — بغض النظر عن فترة السماح. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    /** فترة السماح بالدقايق (سياسة التسليم المتأخر بتاعة الامتحان). 0 = مفيش. */
    public function graceMinutes(): int
    {
        return max(0, (int) ($this->exam?->late_grace_minutes ?? 0));
    }

    /** آخر لحظة يقدر الطالب يسلّم فيها (expires_at + فترة السماح). */
    public function graceEndsAt()
    {
        return $this->expires_at?->copy()->addMinutes($this->graceMinutes());
    }

    /** الوقت الأساسي خلص بس لسه جوه فترة السماح — المحاولة لسه شغالة والتسليم دلوقتي متأخر. */
    public function inGrace(): bool
    {
        return $this->isActive()
            && $this->graceMinutes() > 0
            && $this->isExpired()
            && now()->lessThan($this->graceEndsAt());
    }

    /** الوقت + فترة السماح خلصوا كلهم — المحاولة لازم تتسلّم أوتوماتيك. */
    public function isHardExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->graceEndsAt());
    }

    /** ثواني فترة السماح المتبقية (0 لو مش جوه فترة سماح). */
    public function graceRemainingSeconds(): int
    {
        return $this->inGrace() ? max(0, (int) floor(now()->diffInSeconds($this->graceEndsAt(), false))) : 0;
    }

    /** ثواني متبقية (0 لو خلصت أو الوقت عدى) — دايمًا محسوبة من ساعة السيرفر. */
    public function remainingSeconds(): int
    {
        if (!$this->isActive() || $this->expires_at === null) {
            return 0;
        }
        return max(0, (int) floor(now()->diffInSeconds($this->expires_at, false)));
    }
}
