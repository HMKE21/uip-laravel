<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `project_approvals` القديم بالظبط — صف واحد لكل قرار مراجعة
 * وله مرحلة مستقلة (stage: supervisor_review / faculty_review / university_review /
 * admin_review — شوف App\Support\ApprovalFlow) ودورة تقديم (review_round). منفصل عن
 * عمود `status` بتاع المشروع نفسه.
 */
class ProjectApproval extends Model
{
    protected $table = 'project_approvals';
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'reviewer_id', 'stage', 'review_round', 'decision', 'comments', 'decided_at',
        'ai_output_acknowledged', 'ai_output_snapshot',
    ];

    protected $casts = [
        'ai_output_acknowledged' => 'boolean',
        'decided_at'             => 'datetime',
    ];
}
