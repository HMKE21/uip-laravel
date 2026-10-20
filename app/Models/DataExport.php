<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `data_exports` القديم بالظبط (migration 042 + email_to/
 * batch_id عبر 077 + schedule_id عبر 078) — سجل تدقيق لكل تصدير CSV/
 * Excel/PDF من بورتال Data Analysis. الجدول فيه created_at بس، مفيهوش
 * updated_at.
 */
class DataExport extends Model
{
    protected $table = 'data_exports';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'export_type', 'format', 'filters', 'file_path', 'status',
        'completed_at', 'email_to', 'batch_id', 'schedule_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];
}
