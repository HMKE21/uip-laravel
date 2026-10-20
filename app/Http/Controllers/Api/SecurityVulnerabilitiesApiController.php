<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Repositories\VulnerabilityRepository;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityVulnerabilitiesApiController.php
 * القديمة — سطح /api/v1/security/vulnerabilities/* بالكامل، بند 25
 * batch 3 (Vulnerabilities). list/filter, create, assign, status change,
 * deadlines, CSV export — بتعيد استخدام VulnerabilityRepository بالظبط
 * زي القديمة، مفيش منطق أعمال جديد.
 *
 * RBAC: security_admin أو security_officer أو admin.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/userRole() -> $request
 * ->attributes->get('uip_user_id')/'uip_role'.
 */
class SecurityVulnerabilitiesApiController extends Controller
{
    use Paginates;

    public function __construct(
        private VulnerabilityRepository $vulnerabilities,
        private UserRepository $users
    ) {
    }

    /**
     * GET /api/v1/security/vulnerabilities — `search`/`q` matches
     * title/description; `page`/`per_page` supported (Concerns\Paginates).
     * Pulls up to 500 matching rows from the repository and paginates
     * that set in memory.
     */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view vulnerabilities.', null, 403);
        }

        $status = $request->input('status') ?: null;

        $rows = $this->filterBySearch($request, $this->vulnerabilities->allWithAssignee(500, $status), ['title', 'description']);
        [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);

        return $this->apiSuccess(
            $items,
            'Vulnerabilities retrieved successfully.',
            200,
            array_merge($this->meta($page, $perPage, $total), [
                'filters'   => ['status' => $status],
                'assignees' => array_merge(
                    $this->users->allWithRoles(['role' => 'security_admin']),
                    $this->users->allWithRoles(['role' => 'security_officer'])
                ),
            ])
        );
    }

    /** POST /api/v1/security/vulnerabilities */
    public function store(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can record a vulnerability.', null, 403);
        }

        $data = $request->validate([
            'title'    => 'required',
            'severity' => 'required',
        ]);

        $vulnerability = $this->vulnerabilities->create([
            'title'              => $data['title'],
            'description'        => $request->input('description'),
            'affected_component' => $request->input('affected_component'),
            'severity'           => $data['severity'],
            'cvss_score'         => $request->input('cvss_score') ?: null,
            'deadline'           => $request->input('deadline') ?: null,
            'discovered_by'      => (int) $request->attributes->get('uip_user_id'),
        ]);

        return $this->apiSuccess($vulnerability->toArray(), 'Vulnerability recorded.', 201);
    }

    /** PATCH /api/v1/security/vulnerabilities/{id}/status */
    public function updateStatus(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can change a vulnerability\'s status.', null, 403);
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, VulnerabilityRepository::STATUSES, true)) {
            return $this->apiError('Invalid status.', ['status' => 'Must be one of open, in_progress, mitigated, resolved, accepted_risk.'], 422);
        }

        $this->vulnerabilities->updateStatus($id, $status);

        return $this->apiSuccess(null, 'Vulnerability status updated.');
    }

    /** PATCH /api/v1/security/vulnerabilities/{id}/deadline */
    public function setDeadline(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can set a deadline.', null, 403);
        }

        $deadline = trim((string) $request->input('deadline', ''));
        $this->vulnerabilities->setDeadline($id, $deadline ?: null);

        return $this->apiSuccess(null, 'Deadline updated.');
    }

    /** PATCH /api/v1/security/vulnerabilities/{id}/assign */
    public function assign(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can assign a vulnerability.', null, 403);
        }

        $this->vulnerabilities->assign($id, $request->input('assigned_to') ?: null);

        return $this->apiSuccess(null, 'Vulnerability assigned.');
    }

    /** GET /api/v1/security/vulnerabilities/export — every vulnerability matching the current filter, as CSV. */
    public function export(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can export vulnerabilities.', null, 403);
        }

        $status = $request->input('status') ?: null;
        $rows = $this->vulnerabilities->allWithAssigneeAll($status);

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Title', 'Affected Component', 'Severity', 'CVSS Score', 'Status', 'Assignee', 'Deadline', 'Discovered At', 'Resolved At']);

        foreach ($rows as $v) {
            fputcsv($stream, [
                $v['title'], $v['affected_component'] ?: '', $v['severity'], $v['cvss_score'] ?? '',
                $v['status'], $v['assignee_name'] ?? 'Unassigned', $v['deadline'] ?? '',
                $v['discovered_at'], $v['resolved_at'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'vulnerability-report-' . date('Y-m-d_His') . '.csv';

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
}