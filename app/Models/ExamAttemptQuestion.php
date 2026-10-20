<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 7 (Phase 7). صف واحد لكل سؤال فعليًا
 * ظهر لمحاولة طالب بعينها — sort_order هو الترتيب الفعلي اللي الطالب
 * شافه (بعد أي خلط، لو exams.randomize_questions=true)، منفصل عن
 * exam_questions.sort_order (ترتيب التأليف الأصلي). راجع docblock
 * migration 2026_08_28_210000 وQuestionSelectionService للتفاصيل الكاملة.
 * exam_question_id لسه هو اللي exam_answers/exam_grades بيتكلموا معاه —
 * الجدول ده مجرد "أي exam_questions rows تخص المحاولة دي، وبأي ترتيب"،
 * مفيش أي تغيير على الجداول التانية.
 */
class ExamAttemptQuestion extends Model
{
    protected $table = 'exam_attempt_questions';

    protected $fillable = [
        'exam_attempt_id', 'exam_question_id', 'sort_order',
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
