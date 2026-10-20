<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 6 (Phase 19). صف واحد لكل بند تقييم
 * جوه rubric واحد (مثال: "Technical Accuracy — 4 points"). مجموع
 * max_points لكل criteria بتاعة rubric واحد لازم يساوي questions.marks —
 * الفحص ده في ExamSystemService::saveRubric()، مش هنا.
 */
class ExamRubricCriterion extends Model
{
    protected $table = 'exam_rubric_criteria';

    protected $fillable = [
        'exam_rubric_id', 'label', 'max_points', 'sort_order',
    ];

    protected $casts = [
        'max_points' => 'decimal:2',
    ];

    public function rubric()
    {
        return $this->belongsTo(ExamRubric::class, 'exam_rubric_id');
    }
}
