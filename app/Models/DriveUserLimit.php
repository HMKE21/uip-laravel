<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** تخصيص "جهازي" لمستخدم واحد (حصة / حد ملف / حالة / منع مشاركة أو رفع). راجع DriveAdminSettings::limitsFor. */
class DriveUserLimit extends Model
{
    protected $table = 'drive_user_limits';

    protected $fillable = [
        'user_id', 'quota_mb', 'max_file_mb', 'status', 'reason', 'note', 'sharing_blocked', 'uploads_blocked', 'updated_by',
    ];

    protected $casts = [
        'quota_mb'        => 'integer',
        'max_file_mb'     => 'integer',
        'sharing_blocked' => 'boolean',
        'uploads_blocked' => 'boolean',
    ];
}
