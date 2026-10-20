<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `security_incidents` القديم بالظبط (migration 041).
 * related_log_ref مرجع نصي حر (مش FK) — مفيش جدول security_logs أصلًا،
 * السجل الحقيقي ملف-محور (شوف SecurityLogRepository).
 */
class SecurityIncident extends Model
{
    protected $table = 'security_incidents';

    protected $fillable = [
        'reference_code', 'title', 'description', 'category', 'severity', 'status',
        'source_ip', 'affected_user_id', 'related_log_ref', 'assigned_to', 'created_by',
        'detected_at', 'resolved_at',
    ];
}
