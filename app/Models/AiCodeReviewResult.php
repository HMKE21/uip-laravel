<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق موديل AiCodeReviewResult القديم بالظبط (migration 031، ومدّت
 * أعمدتها migration 123 بالسكورات الستة/version/previous_review_id/
 * أعمدة قرار الأدمن). مفيش updated_at في الجدول القديم (created_at بس).
 *
 * التحليل اللي GithubCodeReviewService بيشغّله فعليًا Static/heuristic
 * حقيقي (+ تحليل AI حقيقي لو الموديل متظبط) على بيانات GitHub حية —
 * is_demo_data دايمًا 0 هنا، كل صف مراجعة حقيقية لريبو حقيقي.
 */
class AiCodeReviewResult extends Model
{
    const UPDATED_AT = null;

    protected $table = 'ai_code_review_results';

    protected $fillable = [
        'project_id', 'repository_id', 'status', 'issues_found', 'summary', 'raw_result', 'is_demo_data',
        'overall_score', 'security_score', 'performance_score', 'maintainability_score', 'architecture_score', 'quality_score',
        'version', 'previous_review_id',
        'reviewed_by', 'approved_at', 'admin_notes', 'score_overridden', 'override_reason',
    ];

    public function decodedResult(): array
    {
        $decoded = json_decode((string) $this->raw_result, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{overall:?int,security:?int,performance:?int,maintainability:?int,architecture:?int,quality:?int} */
    public function scores(): array
    {
        return [
            'overall'         => $this->overall_score !== null ? (int) $this->overall_score : null,
            'security'        => $this->security_score !== null ? (int) $this->security_score : null,
            'performance'     => $this->performance_score !== null ? (int) $this->performance_score : null,
            'maintainability' => $this->maintainability_score !== null ? (int) $this->maintainability_score : null,
            'architecture'    => $this->architecture_score !== null ? (int) $this->architecture_score : null,
            'quality'         => $this->quality_score !== null ? (int) $this->quality_score : null,
        ];
    }
}
