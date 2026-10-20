<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 5 (Phase 16 — Security Event Log).
 * سطر log واحد بيوثق حدث أمان واحد وقت محاولة امتحان — append-only،
 * عمره ما بيتعدل أو يتمسح. راجع docblock migration
 * 2026_08_28_190000 للتفاصيل الكاملة. من غير timestamps تلقائية عمدًا
 * (occurred_at هي المرجع الوحيد للوقت، نفس exam_grade_history.changed_at).
 */
class ExamSecurityEvent extends Model
{
    protected $table = 'exam_security_events';

    public $timestamps = false;

    protected $fillable = [
        'exam_attempt_id', 'exam_id', 'student_id',
        'event_type', 'is_violation', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'is_violation' => 'boolean',
        'metadata'     => 'array',
        'occurred_at'  => 'datetime',
    ];

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
