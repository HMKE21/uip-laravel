<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 6 (Phase 18). صف تشخيصي واحد لكل
 * exam_grades (1-1، unique exam_grade_id) — بيخزن ناتج الـ AI الخام
 * (score/confidence/feedback/strengths/missing_concepts/reasoning_summary)
 * منفصل عن exam_grades نفسها (اللي هي مصدر الحقيقة للدرجة النهائية
 * المعتمدة). راجع docblock migration 2026_08_28_200000 والخدمة
 * ExamGradingService::runAiGrading() للتفاصيل الكاملة.
 */
class ExamAiGrading extends Model
{
    protected $table = 'exam_ai_gradings';

    protected $fillable = [
        'exam_grade_id', 'status', 'marks_awarded', 'max_marks', 'confidence',
        'feedback', 'strengths', 'missing_concepts', 'reasoning_summary',
        'model', 'error_message', 'requested_at', 'completed_at',
    ];

    protected $casts = [
        'marks_awarded'     => 'decimal:2',
        'max_marks'         => 'decimal:2',
        'confidence'        => 'decimal:2',
        'strengths'         => 'array',
        'missing_concepts'  => 'array',
        'requested_at'      => 'datetime',
        'completed_at'      => 'datetime',
    ];

    public function grade()
    {
        return $this->belongsTo(ExamGrade::class, 'exam_grade_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }
}
