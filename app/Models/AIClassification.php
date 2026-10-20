<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `ai_classifications` القديم بالظبط. مش unique لكل مشروع في
 * الـ schema، بس التطبيق دايمًا بيستخدم أحدث صف بس لكل مشروع (انظر
 * AIAnalysisRepository::classificationFor()).
 */
class AIClassification extends Model
{
    protected $table = 'ai_classifications';

    /**
     * إصلاح: الجدول فيه created_at بس، مفيهوش updated_at — من غير السطر
     * ده AIClassification::create() (بند 21) كان هيرمي "Unknown column
     * 'updated_at'".
     */
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'predicted_category', 'confidence',
        'alternative_categories', 'is_demo_data',
    ];

    protected $casts = [
        'alternative_categories' => 'array',
    ];

    /** @return string[] */
    public function alternatives(): array
    {
        return is_array($this->alternative_categories) ? array_values($this->alternative_categories) : [];
    }
}
