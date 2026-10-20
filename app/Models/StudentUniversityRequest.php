<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `student_university_requests` القديم بالظبط. طلب طالب
 * للانضمام لجامعة — `students.university_id` بيتحط بس لما الصف هنا
 * يبقى status='approved' (انظر StudentJoinRequestService). مفيش
 * updated_at في الجدول القديم (created_at بس) — زي ProjectDiscussionMessage
 * بالظبط؛ من غيرها decide() (اللي بتعمل save() لتحديث status/reviewed_at)
 * كانت هتفشل بنفس الـ "Unknown column 'updated_at'" اللي submitRequest() فشلت بيه.
 */
class StudentUniversityRequest extends Model
{
    const UPDATED_AT = null;

    protected $table = 'student_university_requests';

    protected $fillable = [
        'student_id', 'university_id', 'faculty_id', 'department_id',
        'status', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];
}
