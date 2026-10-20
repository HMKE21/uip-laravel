<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * منقولة من app/Controllers/Api/AdminSecurityLogsApiController.php
 * القديمة — بند 25 batch 8 (Admin > Security Logs، admin-specific).
 * سطح /api/v1/admin/security-logs/* بس — لوحة الأدمن المستقلة اللي
 * بتعرض السجل الأمني الخام (log files تحت storage/logs/security/*.log،
 * بدون جدول DB) عبر SecurityLogService::recent()/search() بالظبط، نفس
 * Admin\AdminSecurityLogController القديمة كانت بتعمله للـ view الـ
 * server-rendered. export() بيطلع نفس CSV بالظبط.
 *
 * مختلفة عن SecurityLogsApiController (بند 25 batch 4، /api/v1/security/logs/*)
 * اللي بتدمج log الأمان مع جدول audit_logs في استريم موحّد للـ Security
 * Portal (security_admin/security_officer/admin) — هنا سطح أدمن مستقل
 * بس، مفيش دمج، مفيش pagination من DB، بالظبط زي القديمة. مفيش حاجة
 * مختلَقة.
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php).
 */
class AdminSecurityLogsApiController extends Controller
{
    private const PER_PAGE = 25;
    private const SEARCH_DAYS = 90;

    public function __construct(private SecurityLogService $securityLogs)
    {
    }

    /** GET /api/v1/admin/security-logs */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view security logs.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $page = max(1, (int) $request->input('page', 1));

        $all = $filters ? $this->securityLogs->search($filters, self::SEARCH_DAYS) : $this->securityLogs->recent();

        $total = count($all);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $rows = array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->apiSuccess($rows, 'Security logs retrieved successfully.', 200, [
            'page' => $page, 'perPage' => self::PER_PAGE, 'total' => $total,
            'criticalCount' => count(array_filter($all, fn ($s) => $s['severity'] === 'critical')),
            'warningCount'  => count(array_filter($all, fn ($s) => $s['severity'] === 'warning')),
            'distinctIps'   => count(array_unique(array_filter(array_column($all, 'ip'), fn ($ip) => $ip !== '—'))),
        ]);
    }

    /** GET /api/v1/admin/security-logs/export */
    public function export(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can export security logs.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $rows = $this->securityLogs->search($filters, self::SEARCH_DAYS);

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Severity', 'Event', 'Event Type', 'Module', 'Status', 'User', 'IP', 'Device', 'Browser', 'OS', 'Time']);

        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['severity'] ?? '', $row['event'] ?? '', $row['event_type'] ?? '', $row['module'] ?? '',
                $row['status'] ?? '', $row['user'] ?? '', $row['ip'] ?? '', $row['device'] ?? '',
                $row['browser'] ?? '', $row['os'] ?? '', $row['time'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="security-log-' . date('Y-m-d_His') . '.csv"',
        ]);
    }

    private function filtersFromRequest(Request $request): array
    {
        return array_filter([
            'q'          => trim((string) $request->input('q', '')),
            'user'       => trim((string) $request->input('user', '')),
            'ip'         => trim((string) $request->input('ip', '')),
            'severity'   => trim((string) $request->input('severity', '')),
            'event_type' => trim((string) $request->input('event_type', '')),
            'module'     => trim((string) $request->input('module', '')),
            'status'     => trim((string) $request->input('status', '')),
            'date_from'  => trim((string) $request->input('date_from', '')),
            'date_to'    => trim((string) $request->input('date_to', '')),
        ], fn ($v) => $v !== '');
    }
}
