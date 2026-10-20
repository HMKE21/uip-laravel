<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/ReportSchedule.php القديمة — صف report_schedules
 * (migration 037، scope_id اتضاف بـ 050 لـ Scheduled Reports).
 */
class ReportSchedule extends Model
{
    protected $table = 'report_schedules';

    protected $fillable = [
        'report_type', 'frequency', 'format', 'custom_interval_days', 'recipient_email', 'scope_id', 'is_active',
        'last_run_at', 'last_report_id', 'next_run_at', 'created_by',
    ];
}
