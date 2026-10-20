<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 6 (Phase 19). Rubric واحد بالظبط لكل
 * سؤال (unique question_id في الـ migration). criteria() مرتبة sort_order
 * — راجع docblock migration 2026_08_28_200000 للتفاصيل الكاملة.
 */
class ExamRubric extends Model
{
    protected $table = 'exam_rubrics';

    protected $fillable = [
        'question_id', 'created_by_academic_staff_id',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function criteria()
    {
        return $this->hasMany(ExamRubricCriterion::class)->orderBy('sort_order');
    }

    public function totalPoints(): float
    {
        return (float) $this->criteria->sum('max_points');
    }
}
