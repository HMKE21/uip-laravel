<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 4 (Grading Core). صف تصحيح واحد لسؤال
 * واحد جوه محاولة واحدة. راجع docblock migration 2026_08_28_180000
 * للتفاصيل الكاملة. isPending() == لسه محتاج تصحيح يدوي/AI.
 */
class ExamGrade extends Model
{
    protected $table = 'exam_grades';

    protected $fillable = [
        'exam_attempt_id', 'exam_question_id', 'exam_answer_id',
        'marks_awarded', 'max_marks', 'is_correct', 'feedback',
        'source', 'graded_by_academic_staff_id', 'graded_at',
    ];

    protected $casts = [
        'marks_awarded' => 'decimal:2',
        'max_marks'     => 'decimal:2',
        'is_correct'    => 'boolean',
        'graded_at'     => 'datetime',
    ];

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function examQuestion()
    {
        return $this->belongsTo(ExamQuestion::class);
    }

    public function answer()
    {
        return $this->belongsTo(ExamAnswer::class, 'exam_answer_id');
    }

    public function history()
    {
        return $this->hasMany(ExamGradeHistory::class)->orderByDesc('changed_at');
    }

    /** Round 6 — تشخيص AI الخام لهذا التصحيح، لو اتعمل عليه AI grading (Phase 18). */
    public function aiGrading()
    {
        return $this->hasOne(ExamAiGrading::class);
    }

    public function isPending(): bool
    {
        return $this->marks_awarded === null;
    }
}
