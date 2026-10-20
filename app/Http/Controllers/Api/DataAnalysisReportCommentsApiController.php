<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataAnalysisReportComment;
use App\Models\DataAnalysisReportFile;
use App\Services\DataAnalysisCollaborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisReportCommentsApiController.php
 * القديمة — بند 24 batch 5 (Collaboration، enhancement spec section
 * 12). سطح /api/v1/data-analysis/report-files/{id}/comments و
 * /api/v1/data-analysis/comments/* واحد فوق
 * DataAnalysisCollaborationService بالظبط — نفس السيرفيس.
 *
 * RBAC: uip.auth بيغطي الجروب؛ isDataAnalyst() بيتأكد كمان في كل ميثود
 * — نفس قاعدة باقي كنترولرز DataAnalysis*.
 */
class DataAnalysisReportCommentsApiController extends Controller
{
    public function __construct(
        private DataAnalysisCollaborationService $service
    ) {
    }

    /** POST /api/v1/data-analysis/report-files/{id}/comments */
    public function store(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can comment on report files.', null, 403);
        }

        $id = (int) $id;
        $reportFile = DataAnalysisReportFile::find($id);
        if (!$reportFile) {
            return $this->apiError('Report file not found.', null, 404);
        }

        try {
            $comment = $this->service->addComment(
                (int) $request->attributes->get('uip_user_id'),
                $reportFile,
                (string) $request->input('body', ''),
                [
                    'parent_comment_id'  => $request->input('parent_comment_id') ?: null,
                    'is_note'            => (bool) $request->input('is_note'),
                    'mentioned_user_ids' => array_map('intval', (array) $request->input('mentioned_user_ids', [])),
                ],
                $request->ip()
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($comment->toArray(), 'Comment added successfully.', 201);
    }

    /** POST /api/v1/data-analysis/comments/{id}/note */
    public function markNote(Request $request, $id): JsonResponse
    {
        return $this->act($request, $id, fn (DataAnalysisReportComment $c) => $this->service->toggleNote((int) $request->attributes->get('uip_user_id'), $c, true, $request->ip()), 'Marked as a note.');
    }

    /** POST /api/v1/data-analysis/comments/{id}/unnote */
    public function unmarkNote(Request $request, $id): JsonResponse
    {
        return $this->act($request, $id, fn (DataAnalysisReportComment $c) => $this->service->toggleNote((int) $request->attributes->get('uip_user_id'), $c, false, $request->ip()), 'Note unmarked.');
    }

    /** POST /api/v1/data-analysis/comments/{id}/resolve */
    public function resolve(Request $request, $id): JsonResponse
    {
        return $this->act($request, $id, fn (DataAnalysisReportComment $c) => $this->service->resolveComment((int) $request->attributes->get('uip_user_id'), $c, $request->ip()), 'Comment resolved.');
    }

    /** POST /api/v1/data-analysis/comments/{id}/reopen */
    public function reopen(Request $request, $id): JsonResponse
    {
        return $this->act($request, $id, fn (DataAnalysisReportComment $c) => $this->service->reopenComment((int) $request->attributes->get('uip_user_id'), $c, $request->ip()), 'Comment reopened.');
    }

    private function act(Request $request, $id, callable $action, string $successMessage): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can manage comments.', null, 403);
        }

        $comment = DataAnalysisReportComment::find((int) $id);
        if (!$comment) {
            return $this->apiError('Comment not found.', null, 404);
        }

        try {
            $action($comment);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, $successMessage);
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
