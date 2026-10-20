<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 3 (Auto-save). إجابة طالب واحدة على
 * سؤال واحد (exam_question_id) جوه محاولة واحدة (exam_attempt_id) —
 * unique(exam_attempt_id, exam_question_id) هي اللي بتخلي Save Answer
 * عملية upsert نضيفة (راجع ExamAttemptRepository::upsertAnswer). is_correct
 * وmarks_awarded عمدًا مش موجودين هنا لسه — التصحيح كله Round 4، ومفيش
 * داعي لعمود بيفضل null لحد ما الـ round بتاعه يوصل.
 */
class ExamAnswer extends Model
{
    protected $table = 'exam_answers';

    protected $fillable = [
        'exam_attempt_id', 'exam_question_id', 'selected_option_ids', 'answer_text', 'answered_at',
    ];

    protected $casts = [
        'selected_option_ids' => 'array',
        'answered_at'         => 'datetime',
    ];

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function examQuestion()
    {
        return $this->belongsTo(ExamQuestion::class);
    }
}
