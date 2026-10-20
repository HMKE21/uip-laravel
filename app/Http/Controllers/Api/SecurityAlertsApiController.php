<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SecurityAlertRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityAlertsApiController.php القديمة —
 * سطح /api/v1/security/alerts/* بالكامل، بند 25 batch 2 (Alerts). دورة
 * حياة تنبيه حقيقية (open -> acknowledged -> resolved، أو escalated)،
 * بتعيد استخدام SecurityAlertRepository بالظبط زي القديمة — مفيش منطق
 * أعمال جديد.
 *
 * RBAC: security_admin أو security_officer أو admin — بيتأكد منه جوّه
 * كل ميثود نفسه (زي كل كنترولرز الأمان التانية).
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/userRole() -> $request
 * ->attributes->get('uip_user_id')/'uip_role'.
 */
class SecurityAlertsApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 25;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private SecurityAlertRepository $alerts,
        private UserRepository $users,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/security/alerts */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view alerts.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));

        $result = $this->alerts->search($filters, $page, $perPage);

        return $this->apiSuccess($result['rows'], 'Alerts retrieved successfully.', 200, [
            'page' => $page, 'perPage' => $perPage, 'total' => $result['total'],
            'open_count' => $this->alerts->countOpen(),
            'types' => SecurityAlertRepository::TYPES,
            'statuses' => SecurityAlertRepository::STATUSES,
            'severities' => SecurityAlertRepository::SEVERITIES,
            'filters' => $filters,
            'assignees' => array_merge(
                $this->users->allWithRoles(['role' => 'security_admin']),
                $this->users->allWithRoles(['role' => 'security_officer'])
            ),
        ]);
    }

    /** POST /api/v1/security/alerts/{id}/acknowledge */
    public function acknowledge(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can acknowledge alerts.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->alerts->acknowledge($id, $userId);

        if ($ok) {
            $this->auditLog->record($userId, 'alert.acknowledge', 'security_alert', $id, null, null, $request->ip());
        }

        return $ok
            ? $this->apiSuccess(null, 'Alert acknowledged.')
            : $this->apiError('Could not acknowledge that alert.', null, 422);
    }

    /** POST /api/v1/security/alerts/{id}/resolve */
    public function resolve(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can resolve alerts.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $note = trim((string) $request->input('resolution_note', '')) ?: null;
        $ok = $this->alerts->resolve($id, $userId, $note);

        if ($ok) {
            $this->auditLog->record($userId, 'alert.resolve', 'security_alert', $id, null, ['note' => $note], $request->ip());
        }

        return $ok
            ? $this->apiSuccess(null, 'Alert resolved.')
            : $this->apiError('Could not resolve that alert.', null, 422);
    }

    /** POST /api/v1/security/alerts/{id}/escalate */
    public function escalate(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can escalate alerts.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $note = trim((string) $request->input('escalation_note', '')) ?: null;
        $ok = $this->alerts->escalate($id, $userId, $note);

        if ($ok) {
            $this->auditLog->record($userId, 'alert.escalate', 'security_alert', $id, null, ['note' => $note], $request->ip());
        }

        return $ok
            ? $this->apiSuccess(null, 'Alert escalated.')
            : $this->apiError('Could not escalate that alert.', null, 422);
    }

    /** PATCH /api/v1/security/alerts/{id}/assign */
    public function assign(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can assign alerts.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $assignedTo = $request->input('assigned_to') ?: null;
        $ok = $this->alerts->assign($id, $assignedTo);

        if ($ok) {
            $this->auditLog->record($userId, 'alert.assign', 'security_alert', $id, null, ['assigned_to' => $assignedTo], $request->ip());
        }

        return $ok
            ? $this->apiSuccess(null, 'Alert assigned.')
            : $this->apiError('Could not assign that alert.', null, 422);
    }

    /** GET /api/v1/security/alerts/export — كل تنبيه مطابق للفلاتر الحالية، كـ CSV. */
    public function export(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can export alerts.', null, 403);
        }

        $filters = $this->filtersFromRequest($request);
        $rows = $this->alerts->searchAll($filters);

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Type', 'Severity', 'Title', 'Message', 'Source IP', 'Subject', 'Status', 'Assignee', 'Created At', 'Resolved At']);

        foreach ($rows as $a) {
            fputcsv($stream, [
                $a['type'], $a['severity'], $a['title'], $a['message'] ?? '',
                $a['source_ip'] ?? '', $a['subject_name'] ?? '', $a['status'],
                $a['assignee_name'] ?? '', $a['created_at'], $a['resolved_at'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'security-alerts-' . date('Y-m-d_His') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -- helpers --------------------------------------------------------------

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function filtersFromRequest(Request $request): array
    {
        return array_filter([
            'status'      => trim((string) $request->input('status', '')),
            'severity'    => trim((string) $request->input('severity', '')),
            'type'        => trim((string) $request->input('type', '')),
            'assigned_to' => trim((string) $request->input('assigned_to', '')),
            'q'           => trim((string) $request->input('q', '')),
            'date_from'   => trim((string) $request->input('date_from', '')),
            'date_to'     => trim((string) $request->input('date_to', '')),
        ], fn ($v) => $v !== '');
    }
}
