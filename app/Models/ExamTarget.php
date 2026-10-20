<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 2 (Targeting). صف قاعدة استهداف واحد
 * لامتحان واحد — راجع docblock migration 2026_08_28_160000 لمعنى كل
 * عمود ومنطق الـ OR/AND الكامل بين الصفوف.
 */
class ExamTarget extends Model
{
    protected $table = 'exam_targets';

    protected $fillable = [
        'exam_id', 'faculty_id', 'department_id',
        'academic_year', 'group_id', 'student_id',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** true لو الصف ده مقيّد بطالب واحد بالتحديد بدل فلاتر. */
    public function isStudentSpecific(): bool
    {
        return $this->student_id !== null;
    }

    /** true لو الصف من غير أي فلتر خالص — معناه "كل طلاب الجامعة". */
    public function isUniversityWide(): bool
    {
        return $this->student_id === null
            && $this->faculty_id === null
            && $this->department_id === null
            && $this->academic_year === null
            && $this->group_id === null;
    }
}
