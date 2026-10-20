<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AIAnalysis.php القديمة — يطابق جدول `ai_analysis`
 * (migration 011) بالظبط. سجل تشغيل/حالة واحد لكل AI run (queued/
 * processing/completed/failed) لمشروع + analysis_type، منفصل عن جداول
 * النتيجة المنظّمة (ai_readiness_scores/ai_classifications/
 * startup_potential/improvement_suggestions) اللي فيها الناتج الفعلي.
 * `raw_result` بيحتفظ بالـ JSON الكامل اللي رجع من الموديل.
 */
class AIAnalysis extends Model
{
    protected $table = 'ai_analysis';

    /** الجدول فيه created_at بس (DEFAULT CURRENT_TIMESTAMP)، مفيهوش updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'analysis_type', 'status', 'raw_result',
        'model_used', 'requested_by', 'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    /** فك raw_result (عمود JSON) لمصفوفة. */
    public function result(): array
    {
        if (!$this->raw_result) {
            return [];
        }
        $decoded = is_string($this->raw_result) ? json_decode($this->raw_result, true) : $this->raw_result;
        return is_array($decoded) ? $decoded : [];
    }
}
