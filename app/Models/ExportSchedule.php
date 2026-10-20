<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/ExportSchedule.php القديمة — بند 24 batch 2
 * (Export Center — Scheduled Exports، enhancement spec section 7).
 * تطابق جدول `export_schedules` (migration 078). جدولة حقيقية متكررة
 * لإعادة توليد أحد أنواع DataExportService::ALLOWED_TYPES، منطاقة على
 * المحلل اللي أنشأها (نفس شكل ReportSchedule، جدول/موديل منفصل لأن
 * Export Center عنده كتالوج أنواع خاص بيه وبيكتب في `data_exports` مش
 * `reports`).
 */
class ExportSchedule extends Model
{
    protected $table = 'export_schedules';

    public $timestamps = false;

    protected $fillable = [
        'export_type', 'frequency', 'format', 'custom_interval_days', 'recipient_email',
        'is_active', 'last_run_at', 'last_export_id', 'next_run_at', 'created_by',
    ];
}