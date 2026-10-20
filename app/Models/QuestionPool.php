<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exam & Assessment System — Round 7 (Phase 6). "بنك أسئلة كبير" فرعي
 * جوه question_bank واحد — pool واحد ممكن يتستخدم في أكتر من امتحان
 * (زي question_bank بالظبط). questions() هي الـ membership الفعلي
 * (question_pool_questions، pivot بسيط من غير أعمدة إضافية).
 */
class QuestionPool extends Model
{
    use SoftDeletes;

    protected $table = 'question_pools';

    protected $fillable = [
        'question_bank_id', 'created_by_academic_staff_id', 'name', 'description',
    ];

    public function bank()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'question_pool_questions')->withTimestamps();
    }

    public function examConfigs()
    {
        return $this->hasMany(ExamQuestionPool::class);
    }
}
