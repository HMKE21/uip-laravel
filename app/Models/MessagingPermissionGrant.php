<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * موافقة صريحة لسماح مراسلة طالب↔طالب (116) — user_a_id دايمًا أصغر من
 * user_b_id (زوج مرتّب)، status: pending|approved|rejected. بند 18.
 * دورة الطلب/الموافقة الكاملة لسه معندهاش UI (شوف MessagingPermissionRepository)،
 * لكن الموديل هنا كامل عشان أي جزء لاحق يقدر يبني عليه من غير migration جديدة.
 */
class MessagingPermissionGrant extends Model
{
    protected $table = 'messaging_permission_grants';

    protected $fillable = [
        'user_a_id', 'user_b_id', 'status', 'requested_by', 'reason', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];
}
