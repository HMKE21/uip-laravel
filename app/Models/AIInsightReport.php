<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AIInsightReport.php القديمة — بند 24 batch 5
 * (AI Insights، enhancement spec section 4). تطابق جدول
 * `ai_insight_reports` (migration 081) — صف واحد لكل تشغيلة "Regenerate"
 * لمحرك AI Insights على مستوى المنصة كلها — سجل append-only، نفس شكل
 * App\Models\AIAnalysis بس للمنصة كلها بدل مشروع واحد. مفيش updated_at
 * في الجدول ده (created_at بس).
 */
class AIInsightReport extends Model
{
    protected $table = 'ai_insight_reports';

    public $timestamps = false;

    protected $fillable = [
        'status', 'result_json', 'data_snapshot_json',
        'error_message', 'model_used', 'generated_by',
    ];

    /** فك result_json ترجيعه array. */
    public function result(): array
    {
        if (!$this->result_json) {
            return [];
        }
        $decoded = json_decode((string) $this->result_json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** فك data_snapshot_json ترجيعه array. */
    public function snapshot(): array
    {
        if (!$this->data_snapshot_json) {
            return [];
        }
        $decoded = json_decode((string) $this->data_snapshot_json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
