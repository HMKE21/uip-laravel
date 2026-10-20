<?php

namespace App\Services;

use App\Models\DataAnalysisReportComment;
use App\Models\DataAnalysisReportFile;
use App\Repositories\DataAnalysisReportCommentRepository;

/**
 * منقولة من app/Services/DataAnalysisCollaborationService.php القديمة
 * — بند 24 batch 5 (Collaboration، enhancement spec section 12، نص
 * ملف تقرير واحد منه). المنطق ورا Comments/Notes/@Mentions المتفرعة
 * فوق صف data_analysis_report_files. Messaging العام بتاع البورتال
 * (اللي يقابل "Team Workspaces" في الـ spec) موجود بالفعل عبر
 * MessagingService/DataAnalysisMessagingController — ده النص التاني،
 * مقيّد بملف تقرير واحد.
 *
 * كل إجراء بيتسجل عبر AuditLogService، وكل زميل متأثر بياخد إشعار عبر
 * NotificationService.
 */
class DataAnalysisCollaborationService
{
    public function __construct(
        private DataAnalysisReportCommentRepository $comments,
        private AuditLogService $auditLog,
        private NotificationService $notifications
    ) {
    }

    /**
     * @param array $opts parent_comment_id?:int, is_note?:bool, mentioned_user_ids?:array<int,int>
     */
    public function addComment(int $userId, DataAnalysisReportFile $reportFile, string $body, array $opts = [], ?string $ip = null): DataAnalysisReportComment
    {
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }

        $comment = $this->comments->create([
            'report_file_id'    => $reportFile->id,
            'parent_comment_id' => $opts['parent_comment_id'] ?? null,
            'user_id'           => $userId,
            'body'              => $body,
            'is_note'           => !empty($opts['is_note']) ? 1 : 0,
        ]);

        $action = !empty($opts['is_note']) ? 'data_analysis.note.add' : 'data_analysis.comment.add';
        $this->auditLog->record($userId, $action, 'data_analysis_report_file', $reportFile->id, null, null, $ip);

        if ((int) $reportFile->uploaded_by !== $userId) {
            $this->notifications->notify(
                $reportFile->uploaded_by,
                'data_analysis_comment_added',
                'New comment on: ' . $reportFile->title,
                'A teammate commented on your report.',
                '/data-analysis/report-files/' . $reportFile->id
            );
        }

        foreach (array_unique(array_map('intval', $opts['mentioned_user_ids'] ?? [])) as $mentionedUserId) {
            if ($mentionedUserId === $userId) {
                continue;
            }
            $this->comments->addMention((int) $comment->id, $mentionedUserId);
            $this->notifications->notify(
                $mentionedUserId,
                'data_analysis_comment_mention',
                'You were mentioned in: ' . $reportFile->title,
                'A teammate mentioned you in a comment.',
                '/data-analysis/report-files/' . $reportFile->id
            );
        }

        return $comment;
    }

    public function toggleNote(int $userId, DataAnalysisReportComment $comment, bool $isNote, ?string $ip = null): void
    {
        $this->comments->setNote((int) $comment->id, $isNote);
        $this->auditLog->record($userId, $isNote ? 'data_analysis.comment.mark_note' : 'data_analysis.comment.unmark_note', 'data_analysis_report_comment', $comment->id, null, null, $ip);
    }

    public function resolveComment(int $userId, DataAnalysisReportComment $comment, ?string $ip = null): void
    {
        $this->comments->resolve((int) $comment->id, $userId);
        $this->auditLog->record($userId, 'data_analysis.comment.resolve', 'data_analysis_report_comment', $comment->id, null, null, $ip);

        if ((int) $comment->user_id !== $userId) {
            $this->notifications->notify(
                $comment->user_id,
                'data_analysis_comment_resolved',
                'Your comment was resolved',
                null,
                '/data-analysis/report-files/' . $comment->report_file_id
            );
        }
    }

    public function reopenComment(int $userId, DataAnalysisReportComment $comment, ?string $ip = null): void
    {
        $this->comments->unresolve((int) $comment->id);
        $this->auditLog->record($userId, 'data_analysis.comment.reopen', 'data_analysis_report_comment', $comment->id, null, null, $ip);
    }

    /** @return array<int,array<string,mixed>> الزملاء القابلين للـ @mention في القايمة */
    public function teammatesExcept(int $userId): array
    {
        return $this->comments->teammatesExcept($userId);
    }

    /** @return array<int,array<string,mixed>> كل تعليقات/notes ملف تقرير، الأقدم أولًا */
    public function forFile(int $reportFileId): array
    {
        return $this->comments->forFile($reportFileId);
    }
}
