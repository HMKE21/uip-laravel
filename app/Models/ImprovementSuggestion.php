<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/ImprovementSuggestion.php القديمة — يطابق جدول
 * `improvement_suggestions` (migration 015) بالظبط. صفوف كتير لكل مشروع؛
 * كل run جديد بيستبدل الدفعة القديمة كلها — شوف
 * AIAnalysisRepository::replaceSuggestions().
 */
class ImprovementSuggestion extends Model
{
    protected $table = 'improvement_suggestions';

    /** الجدول فيه created_at بس، مفيهوش updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'suggestion', 'category', 'priority', 'is_demo_data',
    ];

    protected $casts = [
        'is_demo_data' => 'boolean',
    ];
}
