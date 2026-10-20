<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 4 (Phase 22 — Grade History). سطر
 * log واحد بيوثق تغيير درجة واحد على صف exam_grades — append-only،
 * عمره ما بيتعدل أو يتمسح. من غير updated_at عمدًا (راجع docblock
 * الـ migration).
 */
class ExamGradeHistory extends Model
{
    protected $table = 'exam_grade_history';

    public $timestamps = false;

    protected $fillable = [
        'exam_grade_id', 'previous_score', 'new_score', 'source',
        'changed_by_academic_staff_id', 'reason', 'changed_at',
        'previous_feedback', 'new_feedback',
    ];

    protected $casts = [
        'previous_score' => 'decimal:2',
        'new_score'      => 'decimal:2',
        'changed_at'     => 'datetime',
    ];

    public function grade()
    {
        return $this->belongsTo(ExamGrade::class, 'exam_grade_id');
    }
}
