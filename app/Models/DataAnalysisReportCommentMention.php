<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/DataAnalysisReportCommentMention.php القديمة —
 * بند 24 batch 5 (Collaboration، enhancement spec section 12). تطابق
 * جدول `data_analysis_report_comment_mentions` (migration 079) — صف
 * واحد لكل زميل اتعمله @mention في تعليق/note على تقرير، بيتقرا من
 * DataAnalysisReportCommentRepository وبيتكتب من
 * DataAnalysisCollaborationService::addComment() جنب نداء
 * NotificationService::notify() لكل mention.
 */
class DataAnalysisReportCommentMention extends Model
{
    protected $table = 'data_analysis_report_comment_mentions';

    public $timestamps = false;

    protected $fillable = ['comment_id', 'mentioned_user_id'];
}
