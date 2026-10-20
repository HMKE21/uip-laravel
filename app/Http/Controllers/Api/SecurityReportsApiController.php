<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ReportRepository;
use App\Services\AuditLogService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityReportsApiController.php
 * القديمة — سطح /api/v1/security/reports/* واحد، بند 25 batch 5
 * (Reports). طبقة JSON رفيعة فوق App\Repositories\ReportRepository +
 * App\Services\ReportService — نفس الريبو/السيرفيس اللي بورتالات
 * تانية (Data Analysis) بتستخدمها، بإعادة استخدام أنواع تقارير الأمان
 * الخاصة بيها (security_incident_summary، vulnerability_summary —
 * اتضافوا لـ ReportService في نفس البند ده). كل صف مربوط بـ
 * uip_user_id كمنشئ عبر ReportRepository::forUser/findOwned، زي
 * القديمة بالظبط.
 *
 * أبسط من DataAnalysisReportsApiController عمدًا — القديمة معندهاش
 * جدولة متكررة (schedules) ولا delete-selected/delete-all لبورتال
 * الأمان، فمفيش حاجة مختلقة هنا.
 *
 * RBAC: security_admin أو security_officer أو admin.
 */
class SecurityReportsApiController extends Controller
{
    private const ALLOWED_TYPES = ReportService::SECURITY_TYPES;
    private const ALLOWED_FORMATS = ['csv', 'pdf', 'xlsx', 'json', 'docx'];

    public function __construct(
        private ReportRepository $reports,
        private ReportService $reportService,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/security/reports */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can view reports.', null, 403);
        }

        return $this->apiSuccess(
            $this->reports->forUser($request->attributes->get('uip_user_id')),
            'Reports retrieved successfully.',
            200,
            ['allowed_types' => self::ALLOWED_TYPES, 'allowed_formats' => self::ALLOWED_FORMATS]
        );
    }

    /** POST /api/v1/security/reports/generate */
    public function generate(Request $request): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can generate reports.', null, 403);
        }

        $type = (string) $request->input('report_type', '');
        $format = (string) $request->input('format', 'csv');

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->apiError('Unknown report type.', ['report_type' => 'Must be one of ' . implode(', ', self::ALLOWED_TYPES) . '.'], 422);
        }
        if (!in_array($format, self::ALLOWED_FORMATS, true)) {
            return $this->apiError('Unknown export format.', ['format' => 'Must be one of ' . implode(', ', self::ALLOWED_FORMATS) . '.'], 422);
        }

        try {
            $report = $this->reportService->generate($type, $request->attributes->get('uip_user_id'), null, $format);
            return $this->apiSuccess($report->toArray(), 'Report generated successfully.', 201);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** DELETE /api/v1/security/reports/{id} */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can delete reports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $deleted = $this->reportService->delete($id, $userId);

        if ($deleted) {
            $this->auditLog->record($userId, 'security.report_delete', 'Report', $id, null, null, $request->ip());
            return $this->apiSuccess(null, 'Report deleted successfully.');
        }

        return $this->apiError('Report not found.', null, 404);
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
