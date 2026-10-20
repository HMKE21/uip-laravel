<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** تظلم طالب على درجة محاولة (كلية) أو سؤال بعينه. */
class ExamGradeAppeal extends Model
{
    protected $table = 'exam_grade_appeals';

    protected $fillable = [
        'exam_id', 'exam_attempt_id', 'exam_question_id', 'student_id', 'reason', 'status', 'response',
        'resolved_by_academic_staff_id', 'resolved_at', 'score_before', 'score_after',
    ];

    protected $casts = ['resolved_at' => 'datetime', 'score_before' => 'decimal:2', 'score_after' => 'decimal:2'];
}
