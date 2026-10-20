<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/SupervisorAssignment.php القديمة — بند 9
 * (Supervisors). يطابق جدول `supervisor_assignments` (migration 096).
 * صف واحد = نطاق واحد مُسند لمشرف: إما مطابقة faculty/department/
 * academic_year/group نصية، أو مشروع واحد مُعيّن بالاسم.
 */
class SupervisorAssignment extends Model
{
    protected $table = 'supervisor_assignments';

    protected $fillable = [
        'supervisor_id', 'university_id', 'scope_type', 'scope_value', 'project_id',
    ];
}
