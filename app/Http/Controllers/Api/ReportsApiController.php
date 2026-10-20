<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ReportRepository;
use App\Repositories\UniversityRepository;
use App\Services\ReportSchedulerService;
use App\Services\ReportService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/ReportsApiController.php القديمة —
 * سطح /api/v1/reports/* واحد مشترك بين University
 * (كل واحد بأنواعه بس عن طريق ReportService::*_TYPES)، + فرع admin
 * (كل ReportService::TYPES، فلترة/ترقيم صفحات كامل). مفيش استعلامات
 * جديدة — نفس ReportService/ReportRepository/ReportSchedulerService
 * اللي كل بورتال بيستخدمهم بالظبط.
 *
 * أي دور تاني (student/faculty/security) بياخد 403 — مفيش صفحة Reports
 * لأي منهم في الفرونت أصلًا.
 *
 * RBAC: uip.auth بتغطي الروت؛ كل فرع بيحل نطاقه من uip_user_id/uip_role
 * — عمرها ما تاخد id من العميل. الأدمن بس اللي بيشوف كل التقارير/
 * الجداول platform-wide.
 */
class ReportsApiController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private ReportRepository $reports,
        private ReportService $reportService,
        private ReportSchedulerService $scheduler,
        private UniversityRepository $universities
    ) {
    }

    // -- List -----------------------------------------------------------------

    /**
     * GET /api/v1/reports — admin: تاريخ كامل مفلتر + مُرقّم صفحات (q/
     * report_type/format/status/date range، page/per_page) + كل جدولة.
     * باقي الأدوار: تاريخهم بس + جداولهم (مع تاريخ تنفيذ لكل جدولة).
     */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') === 'admin') {
            $filters = array_filter([
                'q'           => trim((string) $request->input('q', '')),
                'report_type' => trim((string) $request->input('report_type', '')),
                'format'      => trim((string) $request->input('format', '')),
                'status'      => trim((string) $request->input('status', '')),
                'date_from'   => trim((string) $request->input('date_from', '')),
                'date_to'     => trim((string) $request->input('date_to', '')),
            ], fn ($v) => $v !== '');
            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, min(100, (int) $request->input('per_page', self::PER_PAGE)));

            $result = $this->reports->searchAdmin($filters, $page, $perPage);

            return $this->apiSuccess([
                'reports'   => $result['rows'],
                'schedules' => $this->scheduler->all(),
            ], 'Reports retrieved successfully.', 200, [
                'page' => $page, 'perPage' => $perPage, 'total' => $result['total'],
            ]);
        }

        $role = $this->resolveScope($request);
        if (!$role) {
            return $this->apiError('Only admin or university accounts can view reports.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $schedules = $this->scheduler->forCreator($userId);
        foreach ($schedules as &$schedule) {
            $schedule['history'] = $this->reports->forSchedule($schedule['id']);
        }
        unset($schedule);

        return $this->apiSuccess([
            'reports'   => $this->reports->forUser($userId),
            'schedules' => $schedules,
        ], 'Reports retrieved successfully.', 200, ['allowed_types' => $role['types']]);
    }

    // -- Generate ---------------------------------------------------------------

    /** POST /api/v1/reports/generate — body: report_type, format (csv|pdf|xlsx|json|docx، افتراضي csv). */
    public function generate(Request $request)
    {
        $type = (string) $request->input('report_type', '');
        $format = (string) $request->input('format', 'csv');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($request->attributes->get('uip_role') === 'admin') {
            return $this->doGenerate($type, $userId, null, $format);
        }

        $role = $this->resolveScope($request);
        if (!$role) {
            return $this->apiError('Only admin or university accounts can generate reports.', null, 403);
        }
        if (!in_array($type, $role['types'], true)) {
            return $this->apiError('That report type is not available for your account.', null, 422);
        }

        return $this->doGenerate($type, $userId, $role['scope_id'], $format);
    }

    private function doGenerate(string $type, int $userId, ?int $scopeId, string $format)
    {
        try {
            $report = $this->reportService->generate($type, $userId, $scopeId, $format);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($report->toArray(), 'Report generated successfully.', 201);
    }

    // -- Delete -------------------------------------------------------------------

    /** DELETE /api/v1/reports/{id} — تقارير الكولر بس (بتشيل صف الداتابيز + الملف من الديسك). */
    public function delete(Request $request, int $id)
    {
        $ok = $this->reportService->delete($id, (int) $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Report deleted successfully.')
            : $this->apiError('Report not found.', null, 404);
    }

    /** POST /api/v1/reports/delete-selected — body: ids (array). تقارير الكولر بس. */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $ids = array_values(array_filter($ids, fn ($id) => is_numeric($id)));

        if (!$ids) {
            return $this->apiError('No reports selected.', null, 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $rows = $this->reports->forUserByIds($userId, $ids);
        foreach ($rows as $row) {
            $this->unlinkReportFile($row['file_path'] ?? null);
        }

        $count = $this->reports->deleteByIds($ids, $userId);

        return $this->apiSuccess(['deleted' => $count], $count . ' report(s) deleted.');
    }

    /** POST /api/v1/reports/delete-all — كل تقارير الكولر. */
    public function destroyAll(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $rows = $this->reports->forUser($userId, 1000);
        foreach ($rows as $row) {
            $this->unlinkReportFile($row['file_path'] ?? null);
        }

        $count = $this->reports->deleteAllForUser($userId);

        return $this->apiSuccess(['deleted' => $count], 'All reports deleted (' . $count . ').');
    }

    // -- Schedules ------------------------------------------------------------------

    /** POST /api/v1/reports/schedules — body: report_type, frequency (daily|weekly|monthly|custom), recipient_email, format, custom_interval_days. */
    public function scheduleCreate(Request $request)
    {
        $type = (string) $request->input('report_type', '');
        $frequency = (string) $request->input('frequency', 'weekly');
        $recipientEmail = (string) $request->input('recipient_email', '');
        $format = (string) $request->input('format', 'csv');
        $customIntervalDays = $request->input('custom_interval_days') !== null ? (int) $request->input('custom_interval_days') : null;
        $userId = (int) $request->attributes->get('uip_user_id');

        $allowedTypes = null;
        $scopeId = null;

        if ($request->attributes->get('uip_role') !== 'admin') {
            $role = $this->resolveScope($request);
            if (!$role) {
                return $this->apiError('Only admin or university accounts can schedule reports.', null, 403);
            }
            $allowedTypes = $role['types'];
            $scopeId = $role['scope_id'];
        }

        try {
            $schedule = $this->scheduler->create($type, $frequency, $recipientEmail, $userId, $scopeId, $allowedTypes, $format, $customIntervalDays);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($schedule->toArray(), 'Report schedule created successfully.', 201);
    }

    /** PATCH /api/v1/reports/schedules/{id} — body: frequency, recipient_email, format, custom_interval_days. */
    public function scheduleUpdate(Request $request, int $id)
    {
        $ok = $this->scheduler->updateOwnedByCreator(
            $id,
            (int) $request->attributes->get('uip_user_id'),
            (string) $request->input('frequency', 'weekly'),
            (string) $request->input('recipient_email', ''),
            (string) $request->input('format', 'csv'),
            $request->input('custom_interval_days') !== null ? (int) $request->input('custom_interval_days') : null
        );

        return $ok
            ? $this->apiSuccess(null, 'Report schedule updated successfully.')
            : $this->apiError('Schedule not found or invalid input.', null, 422);
    }

    /** POST /api/v1/reports/schedules/{id}/toggle */
    public function scheduleToggle(Request $request, int $id)
    {
        $ok = $request->attributes->get('uip_role') === 'admin'
            ? $this->scheduler->toggle($id)
            : $this->scheduler->toggleOwnedByCreator($id, (int) $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Report schedule updated successfully.')
            : $this->apiError('Schedule not found.', null, 404);
    }

    /** DELETE /api/v1/reports/schedules/{id} */
    public function scheduleDelete(Request $request, int $id)
    {
        $ok = $request->attributes->get('uip_role') === 'admin'
            ? $this->scheduler->delete($id)
            : $this->scheduler->deleteOwnedByCreator($id, (int) $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Report schedule deleted successfully.')
            : $this->apiError('Schedule not found.', null, 404);
    }

    /** POST /api/v1/reports/schedules/{id}/run-now — بتنفّذها فورًا وبتعيد جدولتها، زي ما الـ cron كان هيعمل. */
    public function scheduleRunNow(Request $request, int $id)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $request->attributes->get('uip_role') === 'admin'
            ? $this->scheduler->runNow($id, $userId)
            : $this->scheduler->runNowOwnedByCreator($id, $userId);

        return $ok
            ? $this->apiSuccess(null, 'Report schedule executed successfully.')
            : $this->apiError('Schedule not found.', null, 404);
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * بتحل scope_id + الأنواع المسموحة لكل دور غير-أدمن عنده صفحة
     * Reports. بترجع null لو الكولر معندهوش وصول Reports خالص.
     * @return array{scope_id:int,types:array}|null
     */
    private function resolveScope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $university = $this->universities->findByUserId($userId);
            return $university ? ['scope_id' => (int) $university->id, 'types' => ReportService::UNIVERSITY_TYPES] : null;
        }

        return null;
    }

    /** تنظيف ديسك بأفضل مجهود — ملف مفقود/راح بالفعل ميمنعش حذف صف الداتابيز. */
    private function unlinkReportFile(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }
        $fullPath = public_path($relativePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
