<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exam & Assessment System — Round 1 (Foundation). راجع migration
 * 2026_08_28_150000_create_exam_system_foundation_tables.php لقرارات
 * التصميم كاملة (denormalization/ownership).
 *
 * بنك أسئلة واحد بيخص عضو هيئة تدريس واحد (created_by_academic_staff_id)،
 * ومربوط بجامعة إجباري + كلية/قسم اختياريين (نفس نمط academic_staff
 * نفسها). الأسئلة (Question) بتتبع البنك ده، مش الامتحان مباشرة — بنك
 * واحد ممكن يتغذي منه أكتر من امتحان (exam_questions pivot).
 */
class QuestionBank extends Model
{
    use SoftDeletes;

    protected $table = 'question_banks';

    protected $fillable = [
        'university_id', 'faculty_id', 'department_id', 'created_by_academic_staff_id',
        'title', 'subject', 'description', 'status',
    ];

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function creator()
    {
        return $this->belongsTo(AcademicStaff::class, 'created_by_academic_staff_id');
    }
}
