<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 1. اختيار واحد جوه سؤال mcq/multi_select
 * (true_false مش بتستخدم الجدول ده — راجع Question::optionsForDisplay()).
 */
class QuestionOption extends Model
{
    protected $table = 'question_options';

    protected $fillable = [
        'question_id', 'option_text', 'is_correct', 'sort_order',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
