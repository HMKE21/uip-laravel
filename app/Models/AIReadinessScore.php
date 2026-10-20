<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `ai_readiness_scores` القديم بالظبط (migration 012). */
class AIReadinessScore extends Model
{
    protected $table = 'ai_readiness_scores';

    /**
     * إصلاح: الجدول فيه created_at بس (migration 012)، مفيهوش
     * updated_at — من غير السطر ده أي save()/create() (upsertReadiness،
     * بند 21) كان هيرمي "Unknown column 'updated_at'".
     */
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'overall_score', 'technical_score', 'market_score',
        'innovation_score', 'presentation_score', 'computed_at', 'is_demo_data',
    ];

    protected $casts = [
        'computed_at' => 'datetime',
    ];
}
