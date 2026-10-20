<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** منقولة من app/Models/Report.php القديمة — صف reports (migration 024). */
class Report extends Model
{
    protected $table = 'reports';

    protected $fillable = [
        'generated_by', 'schedule_id', 'report_type', 'format', 'file_path', 'parameters', 'status',
    ];
}
