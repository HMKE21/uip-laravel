<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/Supervisor.php القديمة — بند 9 (Supervisors).
 * يطابق جدول `supervisors` (migration 046، مرقّى لحساب login حقيقي بـ
 * migration 095).
 * user_id بيربط صف الروستر ده بحساب users بتاعه؛ permissions قايمة CSV
 * لصلاحيات supervisor.* (شوف SupervisorManagementService::PERMISSIONS).
 */
class Supervisor extends Model
{
    protected $table = 'supervisors';

    protected $fillable = [
        'university_id', 'user_id', 'full_name', 'name_ar', 'name_en', 'email', 'department', 'title',
        'permissions', 'invitation_status', 'status',
        'invited_at', 'accepted_at', 'expires_at', 'activated_at',
    ];

    protected $casts = [
        'invited_at'   => 'datetime',
        'accepted_at'  => 'datetime',
        'expires_at'   => 'datetime',
        'activated_at' => 'datetime',
    ];
}
