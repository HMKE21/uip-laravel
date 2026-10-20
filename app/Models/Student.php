<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `students` القديم بالظبط: العمود الأصلي (migration الأساسية)
 * + faculty_id/department_id (migration 100/102) +
 * study_start_date/expected_graduation_date (migration 103) +
 * group_id/invitation_status/invited_at/accepted_at/expires_at
 * (migration 099). الأعمدة النصية القديمة faculty/department متسيبة
 * عمدًا جنب الـ *_id الحقيقية — نفس قرار القديم بالظبط (اتفاصيل في
 * STUDENTS_API_CONTRACT.md).
 */
class Student extends Model
{
    protected $table = 'students';

    protected $fillable = [
        'user_id', 'university_id', 'student_number', 'faculty', 'department',
        'faculty_id', 'department_id',
        'study_start_date', 'expected_graduation_date',
        'academic_year', 'current_semester', 'gpa', 'bio', 'skills', 'social_links',
        'group_id', 'invitation_status', 'invited_at', 'accepted_at', 'expires_at',
    ];

    protected $casts = [
        'skills'                   => 'array',
        'social_links'             => 'array',
        'study_start_date'         => 'date',
        'expected_graduation_date' => 'date',
        'invited_at'               => 'datetime',
        'accepted_at'              => 'datetime',
        'expires_at'               => 'datetime',
    ];
}
