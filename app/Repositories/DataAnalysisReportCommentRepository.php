<?php

namespace App\Repositories;

use App\Models\DataAnalysisReportComment;
use App\Models\DataAnalysisReportCommentMention;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/DataAnalysisReportCommentRepository.php
 * القديمة — بند 24 batch 5 (Collaboration، enhancement spec section
 * 12). الوصول لبيانات `data_analysis_report_comments` و
 * `data_analysis_report_comment_mentions` (migration 079). نقاش
 * threaded + حالة note/resolve خلف Comments/Notes/@Mentions لكل ملف
 * تقرير.
 */
class DataAnalysisReportCommentRepository
{
    public function create(array $data): DataAnalysisReportComment
    {
        return DataAnalysisReportComment::create($data);
    }

    public function find(int $id): ?DataAnalysisReportComment
    {
        return DataAnalysisReportComment::find($id);
    }

    /** @return array<int,array<string,mixed>> كل تعليقات/notes ملف تقرير، الأقدم أولًا، مع أسماء المعلّق/المُحلّ + أسماء الـ mentions */
    public function forFile(int $reportFileId): array
    {
        $rows = DB::select(
            'SELECT darc.*, u.full_name AS commenter_name, ru.full_name AS resolver_name
             FROM data_analysis_report_comments darc
             INNER JOIN users u ON u.id = darc.user_id
             LEFT JOIN users ru ON ru.id = darc.resolved_by
             WHERE darc.report_file_id = ?
             ORDER BY darc.created_at ASC',
            [$reportFileId]
        );

        $rows = array_map(fn ($r) => (array) $r, $rows);
        foreach ($rows as &$row) {
            $mentions = DB::select(
                'SELECT u.id, u.full_name FROM data_analysis_report_comment_mentions darcm
                 INNER JOIN users u ON u.id = darcm.mentioned_user_id
                 WHERE darcm.comment_id = ?',
                [$row['id']]
            );
            $row['mentions'] = array_map(fn ($m) => (array) $m, $mentions);
        }
        unset($row);

        return $rows;
    }

    public function addMention(int $commentId, int $mentionedUserId): void
    {
        DataAnalysisReportCommentMention::create([
            'comment_id'        => $commentId,
            'mentioned_user_id' => $mentionedUserId,
        ]);
    }

    public function setNote(int $commentId, bool $isNote): void
    {
        DB::update('UPDATE data_analysis_report_comments SET is_note = ? WHERE id = ?', [$isNote ? 1 : 0, $commentId]);
    }

    public function resolve(int $commentId, int $resolverId): void
    {
        DB::update(
            'UPDATE data_analysis_report_comments SET is_resolved = 1, resolved_by = ?, resolved_at = NOW() WHERE id = ?',
            [$resolverId, $commentId]
        );
    }

    public function unresolve(int $commentId): void
    {
        DB::update('UPDATE data_analysis_report_comments SET is_resolved = 0, resolved_by = NULL, resolved_at = NULL WHERE id = ?', [$commentId]);
    }

    /**
     * زملاء البورتال النشطين غير $userId، لقايمة اختيار الـ @mention —
     * كل مستخدم عنده دور 'data_analyst' (أو 'admin')، نفس نطاق "فريق
     * البورتال المشترك كله" اللي إجراءات DataAnalysisReportFileController
     * بتستخدمه (مفيش جدول "data_analysts" مخصص للـ join عليه).
     * @return array<int,array<string,mixed>>
     */
    public function teammatesExcept(int $userId): array
    {
        $rows = DB::select(
            "SELECT DISTINCT u.id, u.full_name FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.slug IN ('data_analyst', 'admin')
               AND u.status = 'active' AND u.deleted_at IS NULL AND u.id != ?
             ORDER BY u.full_name",
            [$userId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }
}
