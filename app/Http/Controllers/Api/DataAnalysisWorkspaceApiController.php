<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisWorkspaceApiController.php
 * القديمة — بند 24 batch 6 (Collaboration — Team Workspace،
 * enhancement spec section 12). سطح /api/v1/data-analysis/workspace
 * واحد، بإعادة استخدام TeamWorkspaceService::stats()/teammates()/
 * sharedDashboards()/recentMessages()/activityTimeline() بالظبط —
 * نفسها مجمّعة من مصادر حقيقية موجودة بالفعل (SavedDashboardService،
 * MessagingService، DataAnalysisReportFileRepository،
 * DataAnalysisCollaborationService، AuditLogService — شوف docblock
 * السيرفيس). مفيش حاجة مختلقة هنا — نقل JSON بس.
 *
 * الـ Messaging والـ Notifications العامة بتاعة البورتال (اللي كانت
 * DataAnalysisMessagingController/DataAnalysisNotificationController
 * القديمة مجرد wrappers رفيعة حواليهم) مش محتاجة نقل مستقل — الفرونت
 * React بيستخدم سطح /api/v1/messaging/* و/api/v1/notifications/* العام
 * المشترك بين كل البورتالات مباشرة (نفس ما كان بيحصل في القديم عبر
 * الـ redirects)، وده منقول بالفعل (بند 18/19).
 *
 * RBAC: uip.auth بيغطي الجروب؛ isDataAnalyst() بيتأكد كمان (data_analyst
 * أو admin) — نفس قاعدة باقي كنترولرز DataAnalysis*.
 */
class DataAnalysisWorkspaceApiController extends Controller
{
    public function __construct(
        private TeamWorkspaceService $workspace
    ) {
    }

    /** GET /api/v1/data-analysis/workspace */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view this workspace.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        return $this->apiSuccess([
            'stats'             => $this->workspace->stats($userId),
            'teammates'         => $this->workspace->teammates($userId),
            'shared_dashboards' => $this->workspace->sharedDashboards($userId),
            'recent_messages'   => $this->workspace->recentMessages($userId),
            'activity'          => $this->workspace->activityTimeline(30),
        ], 'Workspace data retrieved successfully.');
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
