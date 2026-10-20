<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ReportRepository;
use App\Services\ReportSchedulerService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisReportsApiController.php
 * القديمة — بند 24 batch 5 (Reports Suite). سطح /api/v1/data-analysis/
 * reports/* واحد لتوليد تقارير المحلل + الجدولة المتكررة، بإعادة
 * استخدام ReportRepository/ReportService/ReportSchedulerService بالظبط
 * زي ما DataAnalysisDashboardApiController وباقي كنترولرز البورتال
 * بتعمل. أنواع التقارير هي الأنواع platform-wide الآمنة لمحلل بيانات
 * (مفيش incident/vulnerability — ده سطح تقارير بورتال Security الخاص).
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو
 * admin. كل صف مربوط بـ uip_user_id كمنشئ، عمرها مش قيمة جاية من العميل.
 */
class DataAnalysisReportsApiController extends Controller
{
    private const ALLOWED_TYPES = ['analytics_platform_export', 'platform_usage_summary', 'platform_growth_report'];

    public function __construct(
        private ReportRepository $reports,
        private ReportService $reportService,
        private ReportSchedulerService $scheduler
    ) {
    }

    /** GET /api/v1/data-analysis/reports — تقارير المحلل نفسه + جدولاته (مع تاريخ التنفيذ). */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view reports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        $schedules = $this->scheduler->forCreator($userId);
        foreach ($schedules as &$schedule) {
            $schedule['history'] = $this->reports->forSchedule($schedule['id']);
        }
        unset($schedule);

        return $this->apiSuccess([
            'reports'   => $this->reports->forUser($userId),
            'schedules' => $schedules,
        ], 'Reports retrieved successfully.', 200, ['allowed_types' => self::ALLOWED_TYPES]);
    }

    /** POST /api/v1/data-analysis/reports/generate */
    public function generate(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can generate a report.', null, 403);
        }

        $type = (string) $request->input('report_type', '');
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->apiError('Unknown report type.', ['report_type' => 'Must be one of ' . implode(', ', self::ALLOWED_TYPES) . '.'], 422);
        }

        try {
            $this->reportService->generate($type, $request->attributes->get('uip_user_id'));
            return $this->apiSuccess(null, 'Report generated.', 201);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** DELETE /api/v1/data-analysis/reports/{id} */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete a report.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $report = $this->reports->findOwned($id, $userId);
        if (!$report) {
            return $this->apiError('Report not found.', null, 404);
        }

        $this->unlinkReportFile($report->file_path);
        $this->reports->delete($report->id, $userId);

        return $this->apiSuccess(null, 'Report deleted.');
    }

    /** POST /api/v1/data-analysis/reports/delete-selected */
    public function destroySelected(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete reports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $ids = (array) $request->input('ids', []);
        $ids = array_values(array_filter($ids, fn ($id) => is_numeric($id)));

        if (!$ids) {
            return $this->apiError('No reports selected.', null, 422);
        }

        $rows = $this->reports->forUserByIds($userId, $ids);
        foreach ($rows as $row) {
            $this->unlinkReportFile($row['file_path'] ?? null);
        }

        $count = $this->reports->deleteByIds($ids, $userId);

        return $this->apiSuccess(['deleted' => $count], $count . ' report(s) deleted.');
    }

    /** POST /api/v1/data-analysis/reports/delete-all */
    public function destroyAll(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete reports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $rows = $this->reports->forUser($userId, 1000);
        foreach ($rows as $row) {
            $this->unlinkReportFile($row['file_path'] ?? null);
        }

        $count = $this->reports->deleteAllForUser($userId);

        return $this->apiSuccess(['deleted' => $count], 'All reports deleted (' . $count . ').');
    }

    /** POST /api/v1/data-analysis/reports/schedules */
    public function scheduleCreate(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can schedule a report.', null, 403);
        }

        $type = (string) $request->input('report_type', '');
        $frequency = (string) $request->input('frequency', '');
        $format = (string) $request->input('format', 'csv');
        $recipientEmail = (string) $request->input('recipient_email', '');
        $customIntervalDays = $request->input('custom_interval_days');
        $customIntervalDays = $customIntervalDays !== null && $customIntervalDays !== '' ? (int) $customIntervalDays : null;

        try {
            $this->scheduler->create($type, $frequency, $recipientEmail, $request->attributes->get('uip_user_id'), null, self::ALLOWED_TYPES, $format, $customIntervalDays);
            return $this->apiSuccess(null, 'Schedule created.', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** PATCH /api/v1/data-analysis/reports/schedules/{id} */
    public function scheduleUpdate(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can update a schedule.', null, 403);
        }

        $frequency = (string) $request->input('frequency', '');
        $format = (string) $request->input('format', 'csv');
        $recipientEmail = (string) $request->input('recipient_email', '');
        $customIntervalDays = $request->input('custom_interval_days');
        $customIntervalDays = $customIntervalDays !== null && $customIntervalDays !== '' ? (int) $customIntervalDays : null;

        $ok = $this->scheduler->updateOwnedByCreator($id, $request->attributes->get('uip_user_id'), $frequency, $recipientEmail, $format, $customIntervalDays);

        return $ok
            ? $this->apiSuccess(null, 'Schedule updated.')
            : $this->apiError('Could not update that schedule.', null, 422);
    }

    /** POST /api/v1/data-analysis/reports/schedules/{id}/toggle */
    public function scheduleToggle(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can toggle a schedule.', null, 403);
        }

        $ok = $this->scheduler->toggleOwnedByCreator($id, $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Schedule updated.')
            : $this->apiError('Could not update that schedule.', null, 422);
    }

    /** DELETE /api/v1/data-analysis/reports/schedules/{id} */
    public function scheduleDelete(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete a schedule.', null, 403);
        }

        $ok = $this->scheduler->deleteOwnedByCreator($id, $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Schedule deleted.')
            : $this->apiError('Could not delete that schedule.', null, 422);
    }

    /** POST /api/v1/data-analysis/reports/schedules/{id}/run-now */
    public function scheduleRunNow(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can run a schedule.', null, 403);
        }

        $ok = $this->scheduler->runNowOwnedByCreator($id, $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Schedule run.')
            : $this->apiError('Could not run that schedule.', null, 422);
    }

    // -- helpers --------------------------------------------------------------

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }

    /** تنظيف ديسك بأفضل جهد — ملف مفقود/متشال بالفعل أبدًا مايوقفش حذف صف الـ DB. */
    private function unlinkReportFile(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }
        $fullPath = public_path(ltrim($relativePath, '/'));
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
