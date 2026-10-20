<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `security_alerts` القديم بالظبط (migration 071) — دورة
 * حياة تنبيه حقيقية (open -> acknowledged -> resolved، أو escalated)،
 * مختلفة عن security_notifications (فيد read/unread بسيط) وعن
 * security_incidents (حالة مؤكدة تحت التحقيق).
 */
class SecurityAlert extends Model
{
    protected $table = 'security_alerts';

    protected $fillable = [
        'type', 'severity', 'title', 'message', 'source_ip', 'user_id',
        'status', 'assigned_to',
        'acknowledged_by', 'acknowledged_at',
        'resolved_by', 'resolved_at', 'resolution_note',
        'escalated_by', 'escalated_at', 'escalation_note',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
