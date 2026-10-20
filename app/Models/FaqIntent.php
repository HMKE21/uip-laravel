<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/FaqIntent.php القديمة — جدول faq_intents
 * (database/migrations/2_faq_smart_answer_layer.sql على السيرفر
 * القديم، الجدول موجود بالفعل في القاعدة). صف واحد = intent واحد
 * (مثلًا ADD_GRADUATION_PROJECT) بسؤال/جواب عربي+إنجليزي، aliases/
 * keywords (JSON)، استهداف role، أولوية، وحد أدنى ثقة. شوف
 * App\Services\Faq\FaqResolverService للمطابقة وApp\Repositories\
 * FaqIntentRepository للقراءة المخزّنة (cached).
 */
class FaqIntent extends Model
{
    protected $table = 'faq_intents';

    protected $fillable = [
        'intent_key', 'category', 'role', 'question_ar', 'question_en',
        'answer_ar', 'answer_en', 'aliases_ar', 'aliases_en', 'aliases_mixed',
        'keywords', 'priority', 'confidence_threshold', 'is_active',
        'hit_count', 'last_hit_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active'             => 'boolean',
        'priority'               => 'integer',
        'confidence_threshold'   => 'float',
        'hit_count'              => 'integer',
        'last_hit_at'            => 'datetime',
    ];

    /** بيفكّ أعمدة الـ JSON list لأراي عادية عشان الـ resolver/admin API — نفس toArray() القديمة بالظبط. */
    public function toArray()
    {
        $row = parent::toArray();
        foreach (['aliases_ar', 'aliases_en', 'aliases_mixed', 'keywords'] as $col) {
            $raw = $row[$col] ?? null;
            $row[$col] = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        }
        $row['role'] = $row['role'] !== null && $row['role'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string) $row['role']))))
            : [];
        $row['is_active'] = (bool) ($row['is_active'] ?? false);
        $row['confidence_threshold'] = (float) ($row['confidence_threshold'] ?? 0.75);
        $row['priority'] = (int) ($row['priority'] ?? 0);
        $row['hit_count'] = (int) ($row['hit_count'] ?? 0);
        return $row;
    }
}
