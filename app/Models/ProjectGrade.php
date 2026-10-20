<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `project_grades` القديم بالظبط (migration 108) — بند 14
 * (Graduation)، جنب GraduationRecord. صف واحد لكل مشروع (UNIQUE
 * project_id)، بيتعدل في مكانه بدل ما يتكرر — شوف ProjectGradingService.
 */
class ProjectGrade extends Model
{
    protected $table = 'project_grades';

    protected $fillable = [
        'project_id', 'graded_by', 'total_score', 'max_score', 'letter_grade',
        'status', 'overall_comments', 'graded_at', 'revision', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected $casts = [
        'total_score' => 'float',
        'max_score'   => 'float',
        'graded_at'   => 'datetime',
        'reopened_at' => 'datetime',
    ];
}
