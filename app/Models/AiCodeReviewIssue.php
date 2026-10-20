<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق موديل AiCodeReviewIssue القديم بالظبط (migration 123) — صف واحد
 * لكل نتيجة (finding) على AiCodeReviewResult، بديل الشكل القديم اللي كانت
 * كل issue فيه عنصر array غير مُنظّم جوه raw_result JSON. مفيش updated_at
 * في الجدول القديم (created_at بس).
 */
class AiCodeReviewIssue extends Model
{
    const UPDATED_AT = null;

    protected $table = 'ai_code_review_issues';

    protected $fillable = [
        'review_id', 'category', 'severity', 'file_name', 'line_number',
        'description', 'ai_recommendation', 'suggested_fix',
    ];
}
