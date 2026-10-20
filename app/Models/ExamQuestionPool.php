<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 7 (Phase 6/7). إعداد pool واحد على
 * امتحان واحد — "اختار N سؤال عشوائي من الـ pool ده بـ X درجة للسؤال".
 * راجع docblock migration 2026_08_28_210000 لتفاصيل difficulty_distribution/
 * topic_distribution، وQuestionSelectionService::draw() لمنطق السحب نفسه.
 */
class ExamQuestionPool extends Model
{
    protected $table = 'exam_question_pools';

    protected $fillable = [
        'exam_id', 'question_pool_id', 'questions_to_select', 'marks_per_question',
        'difficulty_distribution', 'topic_distribution', 'sort_order',
    ];

    protected $casts = [
        'marks_per_question'       => 'decimal:2',
        'difficulty_distribution'  => 'array',
        'topic_distribution'       => 'array',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function pool()
    {
        return $this->belongsTo(QuestionPool::class, 'question_pool_id');
    }
}
