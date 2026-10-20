<?php

namespace App\Services;

use App\Repositories\DataAnalysisReportFileRepository;

/**
 * منقولة من app/Services/TeamWorkspaceService.php القديمة — بند 24
 * batch 6 (Collaboration — Team Workspaces، enhancement spec section
 * 12). بورتال Data Analysis كان دايمًا مساحة فريق واحدة مشتركة — ملفات
 * التقارير، الـ segments، ودلوقتي saved dashboards كلها unscoped/
 * قابلة للمشاركة بالتصميم (شوف docblock DataAnalysisReportFileService).
 * اللي كان ناقص هو hub واحد بيظهر الحالة المشتركة دي بدل ما تكون
 * متفرقة على صفحات منفصلة. السيرفيس ده بيجمّع مصادر بيانات حقيقية
 * موجودة بالفعل (SavedDashboardService، MessagingService،
 * DataAnalysisReportFileRepository، DataAnalysisCollaborationService،
 * AuditLogService) — مفيش بيانات مختلقة، مفيش جدول جديد.
 */
class TeamWorkspaceService
{
    /** بادئات الـ actions اللي بتكوّن Activity Timeline الخاص بفريق بورتال Data Analysis. */
    private const ACTIVITY_PREFIXES = [
        'data_analysis.', 'saved_dashboard.', 'kpi.', 'segment.',
        'data_export.', 'report.', 'report_schedule.', 'export_schedule.',
    ];

    public function __construct(
        private SavedDashboardService $dashboards,
        private DataAnalysisReportFileRepository $reportFiles,
        private DataAnalysisCollaborationService $collaboration,
        private MessagingService $messaging,
        private AuditLogService $auditLog
    ) {
    }

    public function teammates(int $userId): array
    {
        return $this->collaboration->teammatesExcept($userId);
    }

    /** كل dashboard متشارك حاليًا مع الفريق — بتوع الكولر نفسه المشتركين + بتوع الباقيين. */
    public function sharedDashboards(int $userId): array
    {
        $ownShared = array_map(function ($d) {
            $d['owner_name'] = 'You';
            return $d;
        }, array_filter($this->dashboards->listFor($userId), fn ($d) => !empty($d['is_shared'])));

        return array_merge(array_values($ownShared), $this->dashboards->sharedByOthers($userId));
    }

    public function recentMessages(int $userId, int $limit = 5): array
    {
        return array_slice($this->messaging->inboxForUser($userId), 0, $limit);
    }

    public function activityTimeline(int $limit = 30): array
    {
        return $this->auditLog->forActionPrefixes(self::ACTIVITY_PREFIXES, $limit);
    }

    public function stats(int $userId): array
    {
        return [
            'teammates'         => count($this->teammates($userId)),
            'shared_dashboards' => count($this->sharedDashboards($userId)),
            'active_reports'    => $this->reportFiles->totalActive(),
        ];
    }
}
