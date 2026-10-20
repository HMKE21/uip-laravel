<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `graduation_records` القديم بالظبط (migration 109) — بند
 * 14 (Graduation). صف واحد لكل طالب متخرج (UNIQUE student_id). أعمدة
 * الدرجة/الكلية/القسم/البرنامج SNAPSHOT وقت الاعتماد، مش قراءة حية من
 * الـ FK — شوف تعليق الـ migration وGraduationService لتفاصيل السبب.
 */
class GraduationRecord extends Model
{
    protected $table = 'graduation_records';

    protected $fillable = [
        'student_id', 'university_id', 'status', 'graduation_date', 'final_gpa',
        'degree_title_ar', 'degree_title_en',
        'faculty_name_ar', 'faculty_name_en',
        'department_name_ar', 'department_name_en',
        'certificate_number', 'notes',
        'approved_by', 'approved_at',
        'revoked_by', 'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'graduation_date' => 'date',
        'final_gpa'       => 'float',
        'approved_at'     => 'datetime',
        'revoked_at'      => 'datetime',
    ];
}
