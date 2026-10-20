<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlockedIpRepository;
use App\Services\SecurityAuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * منقولة من app/Controllers/Api/SecurityLogsApiController.php القديمة —
 * سطح /api/v1/security/logs/* بالكامل، بند 25 batch 4 (Logs). غلاف JSON
 * رفيع فوق SecurityAuditLogService (يدمج جدول audit_logs + ملفات
 * storage/logs/security/*.log في استريم واحد مفلتر/مرقّم) وBlockedIpRepository
 * — نفس السيرفيسات بالظبط اللي الكنترولر القديم (والنسخة web القديمة)
 * كانت بتستخدمها.
 *
 * RBAC: security_admin/security_officer/admin لعرض/تصدير اللوجات،
 * security_admin/admin بس لفك حظر IP — بيتأكد منه جوّه كل ميثود نفسه
 * زي باقي كنترولرز الأمان.
 *
 * فرق شكلي فقط عن القديمة: Session::userRole() -> $request->attributes
 * ->get('uip_role')، وResponse الخام لـ export() بقت Illuminate\Http\Response.
 */
class SecurityLogsApiController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private SecurityAuditLogService $unifiedLogs,
        private BlockedIpRepository $blockedIps
    ) {
    }

    private function filtersFromRequest(Request $request): array
    {
        return array_filter([
            'q'          => trim((string) $request->input('q', '')),
            'user'       => trim((string) $request->input('user', '')),
            'role'       => trim((string) $request->input('role', '')),
            'university' => trim((string) $request->input('university', '')),
            'ip'         => trim((string) $request->input('ip', '')),
            'device'     => trim((string) $request->input('device', '')),
            'browser'    => trim((string) $request->input('browser', '')),
            'os'         => trim((string) $request->input('os', '')),
            'module'     => trim((string) $request->input('module', '')),
            'action'     => trim((string) $request->input('action', '')),
            'event_type' => trim((string) $request->input('event_type', '')),
            'severity'   => trim((string) $request->input('severity', '')),
            'status'     => trim((string) $request->input('status', '')),
            'source'     => trim((string) $request->input('source', 'all')),
            'date_from'  => trim((string) $request->input('date_from', '')),
            'date_to'    => trim((string) $request->input('date_to', '')),
        ], fn ($v) => $v !== '');
    }

    /** GET /api/v1/security/logs */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can view audit & security logs.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $page = max(1, (int) $request->input('page', 1));
        $view = $request->input('view', 'table') === 'timeline' ? 'timeline' : 'table';

        $result = $this->unifiedLogs->search($filters, $page, self::PER_PAGE);

        return $this->apiSuccess($result['rows'], 'Logs retrieved successfully.', 200, [
            'page'           => $result['page'],
            'totalPages'     => $result['totalPages'],
            'total'          => $result['total'],
            'timelineGroups' => $view === 'timeline' ? $this->unifiedLogs->groupByDay($result['rows']) : [],
            'viewMode'       => $view,
            'blockedIps'     => $this->blockedIps->all(),
        ]);
    }

    /** GET /api/v1/security/logs/export?format=csv|json */
    public function export(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can export logs.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $format = $request->input('format', 'csv') === 'json' ? 'json' : 'csv';
        $rows = $this->unifiedLogs->searchAll($filters);

        if ($format === 'json') {
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $filename = 'security-audit-logs-' . date('Y-m-d_His') . '.json';
            return new Response($json, 200, [
                'Content-Type'        => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Source', 'Time', 'Actor', 'Email', 'Role', 'IP', 'Device', 'Browser', 'OS', 'Module', 'Event Type', 'Action', 'Severity', 'Status']);

        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['source'] ?? '',
                $row['time'] ?? '',
                $row['actor'] ?? $row['user'] ?? '',
                $row['email'] ?? '',
                $row['role_label'] ?? $row['role'] ?? '',
                $row['ip'] ?? '',
                $row['device'] ?? '',
                $row['browser'] ?? '',
                $row['os'] ?? '',
                $row['module'] ?? '',
                $row['event_type'] ?? '',
                $row['action'] ?? ($row['event'] ?? ''),
                $row['severity'] ?? '',
                $row['status'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'security-audit-logs-' . date('Y-m-d_His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** POST /api/v1/security/logs/blocked-ips/{id}/unblock */
    public function unblockIp(Request $request, int $id)
    {
        if (!in_array($request->attributes->get('uip_role'), ['security_admin', 'admin'], true)) {
            return $this->apiError('Only a Security Administrator can unblock IP addresses.', null, 403);
        }

        $this->blockedIps->unblock($id);

        return $this->apiSuccess($this->blockedIps->all(), 'IP address unblocked successfully.');
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
