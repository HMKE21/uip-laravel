<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** إشارة تشابه بين إجابتين مقاليتين — للمراجعة البشرية فقط. */
class ExamSimilarityFlag extends Model
{
    protected $table = 'exam_similarity_flags';

    protected $fillable = [
        'exam_id', 'exam_question_id', 'attempt_a_id', 'attempt_b_id', 'similarity', 'matched_words',
        'status', 'reviewed_by_academic_staff_id', 'reviewed_at', 'review_note',
    ];

    protected $casts = ['similarity' => 'decimal:2', 'matched_words' => 'integer', 'reviewed_at' => 'datetime'];
}
