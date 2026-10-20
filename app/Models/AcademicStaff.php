<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AcademicStaff.php القديمة — بند 10. يطابق جدول
 * `academic_staff` (migration 101 + 104 لأعمدة الدعوة، + migration
 * 2026_09_04_000000 لـ password_encrypted). صف واحد لكل عضو هيئة تدريس،
 * دايمًا مربوط بحساب users حقيقي (user_id). faculty_id/department_id
 * nullable — مش كل عضو هيئة تدريس تحت مستوى قسم (ممكن يكون إداري على
 * مستوى الجامعة بس).
 *
 * password_encrypted: كلمة مرور الدعوة الحالية مشفّرة (Crypt::encryptString،
 * reversible) — $hidden عشان toArray()/toJson() العادية (زي show()) ما
 * تسربهاش؛ الوصول ليها بس عبر
 * AcademicStaffManagementService::revealPassword(). القوائم المجمّعة
 * (forUniversityWithDetails/forFacultyWithDetails) بتستخدم SELECT صريح
 * أصلًا فمش بترجعها حتى قبل الـ hidden ده.
 */
class AcademicStaff extends Model
{
    protected $table = 'academic_staff';

    protected $fillable = [
        'user_id', 'university_id', 'faculty_id', 'department_id',
        'academic_rank_id', 'staff_number', 'bio', 'status',
        'invitation_status', 'invited_at', 'accepted_at', 'expires_at',
        'password_encrypted',
    ];

    protected $hidden = ['password_encrypted'];

    protected $casts = [
        'invited_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
