<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Question Versioning. صف واحد = snapshot كامل لحالة سؤال معين (فيلدز
 * السؤال + خياراته لو MCQ) قبل تعديل أو حذف حصل عليه. راجع تعليق
 * migration الجدول ده للسبب/القرار الكامل.
 *
 * الجدول ده append-only بتصميمه — مفيش update()/delete() متوقع يتنادوا
 * عليه من التطبيق أبدًا، فمفيش updated_at (created_at بس، بيتسجل مرة
 * واحدة وقت الإنشاء).
 */
class ExamQuestionVersion extends Model
{
    public $timestamps = false;

    protected $table = 'exam_question_versions';

    protected $fillable = [
        'question_id', 'version_number', 'snapshot',
        'changed_by_academic_staff_id', 'change_type', 'created_at',
    ];

    protected $casts = [
        'snapshot'   => 'array',
        'created_at' => 'datetime',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(AcademicStaff::class, 'changed_by_academic_staff_id');
    }
}
