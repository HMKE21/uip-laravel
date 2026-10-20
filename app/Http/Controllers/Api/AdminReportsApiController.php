<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ReportRepository;
use App\Services\AuditLogService;
use App\Services\ReportSchedulerService;
use App\Services\ReportService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminReportsApiController.php القديمة
 * — سطح /api/v1/admin/reports/* منفصل عن /api/v1/reports (فرع admin
 * جوّاها) اللي AdminReports.jsx بينادي عليه فعليًا. نفس
 * ReportRepository/ReportService/ReportSchedulerService، بس هنا كل
 * فعل (generate/schedule create/toggle/delete/run-now) بيتسجّل في
 * الـ audit log — زي الكنترولر القديم بالظبط.
 *
 * RBAC: uip.admin middleware بيغطي الجروب كله + فحص uip_role جوه كل
 * ميثود كمان (دفاع مزدوج، زي كل كنترولرز admin تانية في المشروع).
 */
class AdminReportsApiController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private ReportRepository $reports,
        private ReportService $reportService,
        private ReportSchedulerService $scheduler,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/admin/reports */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view reports.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $page = max(1, (int) $request->input('page', 1));

        $result = $this->reports->searchAdmin($filters, $page, self::PER_PAGE);

        return $this->apiSuccess($result['rows'], 'Reports retrieved successfully.', 200, [
            'page' => $page, 'perPage' => self::PER_PAGE, 'total' => $result['total'],
            'schedules'   => $this->scheduler->all(),
            'reportTypes' => ReportService::TYPES,
        ]);
    }

    /** POST /api/v1/admin/reports/generate */
    public function generate(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can generate reports.', null, 403);
        }

        $type = (string) $request->input('report_type', '');
        $format = (string) $request->input('format', 'csv');
        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $report = $this->reportService->generate($type, $userId, null, $format);
            $this->auditLog->record($userId, 'report.generate', 'Report', $report->id, null, ['report_type' => $type]);
            return $this->apiSuccess($report->toArray(), 'Report generated.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** POST /api/v1/admin/reports/schedules */
    public function scheduleCreate(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can schedule reports.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $schedule = $this->scheduler->create(
                (string) $request->input('report_type', ''),
                (string) $request->input('frequency', 'weekly'),
                (string) $request->input('recipient_email', ''),
                $userId
            );
            $this->auditLog->record($userId, 'report_schedule.create', 'ReportSchedule', $schedule->id, null, [
                'report_type' => $schedule->report_type,
                'frequency'   => $schedule->frequency,
            ]);
            return $this->apiSuccess($schedule->toArray(), 'Report schedule created.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** POST /api/v1/admin/reports/schedules/{id}/toggle */
    public function scheduleToggle(Request $request, int $id)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can update a schedule.', null, 403);
        }

        $ok = $this->scheduler->toggle($id);

        if ($ok) {
            $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'report_schedule.toggle', 'ReportSchedule', $id);
        }

        return $ok
            ? $this->apiSuccess(null, 'Schedule updated.')
            : $this->apiError('Schedule not found.', null, 404);
    }

    /** DELETE /api/v1/admin/reports/schedules/{id} */
    public function scheduleDelete(Request $request, int $id)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can delete a schedule.', null, 403);
        }

        $ok = $this->scheduler->delete($id);

        if ($ok) {
            $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'report_schedule.delete', 'ReportSchedule', $id);
        }

        return $ok
            ? $this->apiSuccess(null, 'Schedule deleted.')
            : $this->apiError('Schedule not found.', null, 404);
    }

    /** POST /api/v1/admin/reports/schedules/{id}/run-now */
    public function scheduleRunNow(Request $request, int $id)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can run a schedule now.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->scheduler->runNow($id, $userId);

        if ($ok) {
            $this->auditLog->record($userId, 'report_schedule.run_now', 'ReportSchedule', $id);
        }

        return $ok
            ? $this->apiSuccess(null, 'Schedule executed now.')
            : $this->apiError('Schedule not found.', null, 404);
    }

    private function filtersFromRequest(Request $request): array
    {
        return array_filter([
            'q'           => trim((string) $request->input('q', '')),
            'report_type' => trim((string) $request->input('report_type', '')),
            'format'      => trim((string) $request->input('format', '')),
            'status'      => trim((string) $request->input('status', '')),
            'date_from'   => trim((string) $request->input('date_from', '')),
            'date_to'     => trim((string) $request->input('date_to', '')),
        ], fn ($v) => $v !== '');
    }
}
