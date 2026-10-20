<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `security_notifications` القديم بالظبط (migration 041) —
 * فيد مخصص لداشبورد الأمان، منفصل عن جدول notifications العام عشان مايتخلطش
 * بضوضاء بورتالات تانية.
 */
class SecurityNotification extends Model
{
    const UPDATED_AT = null;

    protected $table = 'security_notifications';

    protected $fillable = [
        'title', 'message', 'severity', 'source', 'source_id', 'is_read', 'recipient_role',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];
}
