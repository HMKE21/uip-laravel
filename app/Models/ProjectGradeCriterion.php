<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `project_grade_criteria` القديم بالظبط (migration 108) —
 * سطر واحد لكل بند تقييم داخل ProjectGrade. مفيش rubric ثابت على
 * مستوى المنصة؛ كل مشرف بيكتب معاييره بنفسه لكل مشروع — شوف
 * ProjectGradingService::saveGrade()/replaceCriteria().
 */
class ProjectGradeCriterion extends Model
{
    protected $table = 'project_grade_criteria';

    protected $fillable = [
        'project_grade_id', 'criterion', 'max_score', 'score', 'comments', 'sort_order',
    ];

    protected $casts = [
        'max_score' => 'float',
        'score'     => 'float',
    ];
}
