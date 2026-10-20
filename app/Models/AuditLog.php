<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `audit_logs` القديم بالظبط (migration 023). مفيش updated_at
 * في الجدول القديم (created_at بس)، فمعمول UPDATED_AT = null.
 */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id',
        'old_values', 'new_values', 'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
