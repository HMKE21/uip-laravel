<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/DataAnalysisReportComment.php القديمة — بند 24
 * batch 5 (Collaboration، enhancement spec section 12). تطابق جدول
 * `data_analysis_report_comments` (migration 079) — Threaded عن طريق
 * parent_comment_id، قابل للحل باستقلالية، وقابل للتعليم كـ Note مستقل
 * (is_note) بدل تعليق-رد عادي — النص الخاص بملف تقرير واحد من قسم 12
 * (Collaboration) في المواصفات، من غير حقول الـ canvas pin/x/y.
 */
class DataAnalysisReportComment extends Model
{
    protected $table = 'data_analysis_report_comments';

    public $timestamps = false;

    protected $fillable = [
        'report_file_id', 'parent_comment_id', 'user_id', 'body',
        'is_note', 'is_resolved', 'resolved_by', 'resolved_at',
    ];
}
