<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Repositories\VulnerabilityRepository;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Support\Cvss31;
use Illuminate\Http\Request;

/**
 * سطح /api/v1/security/vulnerabilities/* — إدارة ثغرات ناضجة (بند 5):
 *  - CVSS v3.1 (vector → درجة محسوبة في السيرفر)، الأصل المتأثر (نوع + معرّف)، CVE، خطة الإصلاح.
 *  - مسؤول الإصلاح (assigned_to) + موعد استحقاق (تلقائي حسب الخطورة لو محدش حدّده) + حالة SLA.
 *  - دورة الحالات: open → in_progress → pending_verification (تم الإصلاح) → verified (تم التحقق)،
 *    و«verified» مينفعش تتحط يدويًا — لازم نتيجة إعادة فحص ناجحة (retest) من شخص غير مسؤول الإصلاح.
 *  - إعادة الفحص بعد الإصلاح: لو فشلت الثغرة بترجع in_progress وبيتسجّل في الجدول الزمني.
 *  - جدول زمني لكل تغيير + audit log + إشعارات للمسؤول.
 *
 * RBAC: security_admin أو security_officer أو admin.
 */
class SecurityVulnerabilitiesApiController extends Controller
{
    use Paginates;

    public function __construct(
        private VulnerabilityRepository $vulnerabilities,
        private UserRepository $users,
        private AuditLogService $auditLog,
        private NotificationService $notifications
    ) {
    }

    /** الحالات اللي تنفع يتعمل لها طلب/نتيجة إعادة فحص. */
    private const RETESTABLE = ['pending_verification', 'mitigated', 'resolved'];

    /**
     * GET /api/v1/security/vulnerabilities — `search`/`q`, `status`, `overdue=1`؛ page/per_page.
     * الـ meta فيها summary (كروت الأرقام) + assignees + assets (للاقتراح).
     */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view vulnerabilities.', null, 403);
        }

        $status = $request->input('status') ?: null;
        $all = $this->vulnerabilities->allWithAssignee(500, $status);

        if ($request->boolean('overdue')) {
            $all = array_values(array_filter($all, fn ($v) => $this->vulnerabilities->slaInfo($v)['state'] === 'breached'));
        }
        $all = array_map(fn ($v) => $this->decorate($v), $all);

        $rows = $this->filterBySearch($request, $all, ['title', 'description', 'reference_code', 'affected_component', 'asset_identifier', 'cve_id', 'assignee_name']);
        [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);

        return $this->apiSuccess(
            $items,
            'Vulnerabilities retrieved successfully.',
            200,
            array_merge($this->meta($page, $perPage, $total), [
                'filters'     => ['status' => $status],
                'summary'     => $this->vulnerabilities->summary(),
                'assets'      => $this->vulnerabilities->knownAssets(),
                'asset_types' => VulnerabilityRepository::ASSET_TYPES,
                'assignees'   => array_merge(
                    $this->users->allWithRoles(['role' => 'security_admin']),
                    $this->users->allWithRoles(['role' => 'security_officer'])
                ),
            ])
        );
    }

    /** GET /api/v1/security/vulnerabilities/{id} — تفاصيل + جدول زمني + إعادات الفحص. */
    public function show(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view vulnerabilities.', null, 403);
        }
        $row = $this->vulnerabilities->findWithNames($id);
        if (!$row) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }

        return $this->apiSuccess($this->decorate($row) + [
            'timeline' => $this->vulnerabilities->timeline($id),
            'retests'  => $this->vulnerabilities->retests($id),
        ]);
    }

    /** GET /api/v1/security/vulnerabilities/cvss?vector=… — حساب الدرجة (للمعاينة). */
    public function cvss(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can use the CVSS calculator.', null, 403);
        }
        $res = Cvss31::parse((string) $request->input('vector', ''));
        if (!$res) {
            return $this->apiError('Invalid CVSS v3.1 vector.', ['vector' => 'Expected e.g. CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H'], 422);
        }

        return $this->apiSuccess($res);
    }

    /** POST /api/v1/security/vulnerabilities */
    public function store(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can record a vulnerability.', null, 403);
        }

        $request->validate([
            'title'    => 'required|string|max:200',
            'severity' => 'required|in:' . implode(',', VulnerabilityRepository::SEVERITIES),
        ]);

        $userId = (int) $request->attributes->get('uip_user_id');
        $fields = $this->readFields($request, true);
        if ($fields instanceof \Illuminate\Http\JsonResponse) {
            return $fields;
        }

        $severity = (string) $request->input('severity');
        $autoDeadline = false;
        if (empty($fields['deadline'])) {
            $fields['deadline'] = now()->addDays($this->vulnerabilities->defaultSlaDays($severity))->toDateString();
            $autoDeadline = true;
        }

        $vulnerability = $this->vulnerabilities->create($fields + [
            'title'         => (string) $request->input('title'),
            'severity'      => $severity,
            'discovered_by' => $userId,
        ]);

        $this->vulnerabilities->addEvent($vulnerability->id, $userId, 'created', 'Vulnerability recorded (' . $severity . ')' . ($autoDeadline ? ' — due date set by SLA to ' . $fields['deadline'] . '.' : '.'));
        $this->auditLog->record($userId, 'vulnerability.create', 'vulnerability', $vulnerability->id, null, [
            'reference' => $vulnerability->reference_code, 'severity' => $severity,
        ], $request->ip());

        if (!empty($fields['assigned_to'])) {
            $this->notifyOwner((int) $fields['assigned_to'], $userId, $vulnerability->id, $vulnerability->reference_code, (string) $vulnerability->title, $severity);
            $this->vulnerabilities->addEvent($vulnerability->id, $userId, 'assignment', 'Assigned as remediation owner.');
        }

        return $this->apiSuccess($this->decorate($vulnerability->toArray()) + ['deadline_auto' => $autoDeadline], 'Vulnerability recorded.', 201);
    }

    /** PATCH /api/v1/security/vulnerabilities/{id} — تعديل بيانات الثغرة (من غير الحالة). */
    public function update(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can edit a vulnerability.', null, 403);
        }
        $old = $this->vulnerabilities->findWithNames($id);
        if (!$old) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }

        $request->validate([
            'title'    => 'sometimes|required|string|max:200',
            'severity' => 'sometimes|required|in:' . implode(',', VulnerabilityRepository::SEVERITIES),
        ]);

        $userId = (int) $request->attributes->get('uip_user_id');
        $fields = $this->readFields($request, false);
        if ($fields instanceof \Illuminate\Http\JsonResponse) {
            return $fields;
        }
        foreach (['title', 'severity'] as $k) {
            if ($request->has($k)) {
                $fields[$k] = (string) $request->input($k);
            }
        }
        if (!$fields) {
            return $this->apiError('Nothing to update.', null, 422);
        }

        $this->vulnerabilities->updateFields($id, $fields);

        $changed = [];
        foreach ($fields as $k => $v) {
            if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = ['from' => $old[$k] ?? null, 'to' => $v];
            }
        }
        if ($changed) {
            $parts = [];
            foreach ($changed as $k => $c) {
                $parts[] = $k . ': ' . ($c['from'] === null || $c['from'] === '' ? '—' : mb_strimwidth((string) $c['from'], 0, 40, '…')) . ' → ' . ($c['to'] === null || $c['to'] === '' ? '—' : mb_strimwidth((string) $c['to'], 0, 40, '…'));
            }
            $this->vulnerabilities->addEvent($id, $userId, 'updated', implode(' · ', $parts));
            $this->auditLog->record($userId, 'vulnerability.update', 'vulnerability', $id, array_map(fn ($c) => $c['from'], $changed), array_map(fn ($c) => $c['to'], $changed), $request->ip());

            if (isset($changed['assigned_to']) && !empty($fields['assigned_to'])) {
                $this->notifyOwner((int) $fields['assigned_to'], $userId, (int) $id, (string) $old['reference_code'], (string) ($fields['title'] ?? $old['title']), (string) ($fields['severity'] ?? $old['severity']));
            }
        }

        return $this->apiSuccess($this->decorate($this->vulnerabilities->findWithNames($id)), 'Vulnerability updated.');
    }

    /**
     * PATCH /api/v1/security/vulnerabilities/{id}/status
     * pending_verification بيفتح طلب إعادة فحص تلقائي؛ verified بس عبر /retest/result؛ accepted_risk محتاج reason.
     */
    public function updateStatus(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can change a vulnerability\'s status.', null, 403);
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, VulnerabilityRepository::STATUSES, true)) {
            return $this->apiError('Invalid status.', ['status' => 'Must be one of ' . implode(', ', VulnerabilityRepository::STATUSES) . '.'], 422);
        }
        if ($status === 'verified') {
            return $this->apiError('A fix can only be marked verified by recording a passing re-test.', ['status' => 'Use the re-test action (Record re-test result → Passed).'], 422);
        }

        $vuln = $this->vulnerabilities->findWithNames($id);
        if (!$vuln) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }
        if ($vuln['status'] === $status) {
            return $this->apiSuccess(null, 'Vulnerability status updated.');
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($status === 'accepted_risk' && mb_strlen($reason) < 5) {
            return $this->apiError('A reason is required to accept a risk.', ['reason' => 'Explain why the risk is accepted (at least 5 characters).'], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $this->vulnerabilities->updateStatus($id, $status);

        if ($status === 'accepted_risk') {
            $this->vulnerabilities->updateFields($id, ['accepted_risk_reason' => $reason]);
        }
        if (in_array($status, ['open', 'in_progress'], true)) {
            $this->vulnerabilities->cancelOpenRetests($id);
        }
        if ($status === 'pending_verification' && !$this->vulnerabilities->openRetest($id)) {
            $this->vulnerabilities->createRetest($id, $userId, null, 'Auto-requested when the fix was reported.');
            $this->vulnerabilities->addEvent($id, $userId, 'retest_requested', 'Re-test requested after the fix.');
        }

        $this->vulnerabilities->addEvent($id, $userId, 'status_change', $vuln['status'] . ' → ' . $status . ($reason !== '' ? ' — ' . $reason : ''));
        $this->auditLog->record($userId, 'vulnerability.status', 'vulnerability', $id, ['status' => $vuln['status']], ['status' => $status], $request->ip());

        return $this->apiSuccess(null, 'Vulnerability status updated.');
    }

    /** PATCH /api/v1/security/vulnerabilities/{id}/deadline */
    public function setDeadline(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can set a deadline.', null, 403);
        }

        $deadline = trim((string) $request->input('deadline', ''));
        if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            return $this->apiError('Invalid date.', ['deadline' => 'Use YYYY-MM-DD.'], 422);
        }
        $old = $this->vulnerabilities->findWithNames($id);
        $this->vulnerabilities->setDeadline($id, $deadline ?: null);

        if ($old && (string) ($old['deadline'] ?? '') !== $deadline) {
            $userId = (int) $request->attributes->get('uip_user_id');
            $this->vulnerabilities->addEvent($id, $userId, 'deadline_change', 'Due date: ' . ($old['deadline'] ? substr((string) $old['deadline'], 0, 10) : '—') . ' → ' . ($deadline ?: '—'));
            $this->auditLog->record($userId, 'vulnerability.deadline', 'vulnerability', $id, ['deadline' => $old['deadline']], ['deadline' => $deadline ?: null], $request->ip());
        }

        return $this->apiSuccess(null, 'Deadline updated.');
    }

    /** PATCH /api/v1/security/vulnerabilities/{id}/assign — مسؤول الإصلاح. */
    public function assign(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can assign a vulnerability.', null, 403);
        }
        $old = $this->vulnerabilities->findWithNames($id);
        if (!$old) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }

        $assignee = $request->input('assigned_to') ?: null;
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->vulnerabilities->assign($id, $assignee);

        if ((string) ($old['assigned_to'] ?? '') !== (string) ($assignee ?? '')) {
            $this->vulnerabilities->addEvent($id, $userId, 'assignment', $assignee ? 'Remediation owner assigned.' : 'Remediation owner removed.');
            $this->auditLog->record($userId, 'vulnerability.assign', 'vulnerability', $id, ['assigned_to' => $old['assigned_to']], ['assigned_to' => $assignee], $request->ip());
            if ($assignee) {
                $this->notifyOwner((int) $assignee, $userId, (int) $id, (string) $old['reference_code'], (string) $old['title'], (string) $old['severity']);
            }
        }

        return $this->apiSuccess(null, 'Vulnerability assigned.');
    }

    /** POST /api/v1/security/vulnerabilities/{id}/comment */
    public function comment(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can comment.', null, 403);
        }
        if (!$this->vulnerabilities->find($id)) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }
        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return $this->apiError('Comment is empty.', ['message' => 'Write something first.'], 422);
        }

        $this->vulnerabilities->addEvent($id, (int) $request->attributes->get('uip_user_id'), 'comment', mb_substr($message, 0, 2000));

        return $this->apiSuccess(null, 'Comment added.', 201);
    }

    /** POST /api/v1/security/vulnerabilities/{id}/retest — طلب إعادة فحص بعد الإصلاح. */
    public function requestRetest(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can request a re-test.', null, 403);
        }
        $vuln = $this->vulnerabilities->findWithNames($id);
        if (!$vuln) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }
        if (!in_array($vuln['status'], self::RETESTABLE, true)) {
            return $this->apiError('Mark the vulnerability as fixed (pending verification) before requesting a re-test.', null, 422);
        }
        if ($this->vulnerabilities->openRetest($id)) {
            return $this->apiError('A re-test is already requested.', null, 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $tester = $request->input('assigned_to') ?: null;
        $notes = trim((string) $request->input('notes', '')) ?: null;

        $this->vulnerabilities->createRetest($id, $userId, $tester, $notes);
        if ($vuln['status'] !== 'pending_verification') {
            $this->vulnerabilities->updateStatus($id, 'pending_verification');
        }
        $this->vulnerabilities->addEvent($id, $userId, 'retest_requested', 'Re-test requested' . ($notes ? ' — ' . $notes : '.'));
        $this->auditLog->record($userId, 'vulnerability.retest.request', 'vulnerability', $id, null, ['assigned_to' => $tester], $request->ip());

        if ($tester) {
            $this->notifications->notify((int) $tester, 'security_vulnerability_retest', 'Re-test requested: ' . $vuln['reference_code'], (string) $vuln['title'], '/security/vulnerabilities?open=' . (int) $id, 'high');
        }

        return $this->apiSuccess(null, 'Re-test requested.', 201);
    }

    /**
     * POST /api/v1/security/vulnerabilities/{id}/retest/result — {result: passed|failed, notes}
     * passed → verified. failed → in_progress (الثغرة لسه موجودة) + إشعار للمسؤول.
     * مسؤول الإصلاح مينفعش يتحقق من إصلاحه بنفسه (إلا الأدمن).
     */
    public function retestResult(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can record a re-test result.', null, 403);
        }
        $vuln = $this->vulnerabilities->findWithNames($id);
        if (!$vuln) {
            return $this->apiError('Vulnerability not found.', null, 404);
        }

        $result = (string) $request->input('result', '');
        if (!in_array($result, ['passed', 'failed'], true)) {
            return $this->apiError('Invalid result.', ['result' => 'Must be passed or failed.'], 422);
        }
        $notes = trim((string) $request->input('notes', ''));
        if ($result === 'failed' && mb_strlen($notes) < 3) {
            return $this->apiError('Describe what still fails.', ['notes' => 'Notes are required when a re-test fails.'], 422);
        }
        if (!in_array($vuln['status'], self::RETESTABLE, true)) {
            return $this->apiError('This vulnerability is not waiting for verification.', null, 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $role = (string) $request->attributes->get('uip_role');
        if ($result === 'passed' && !empty($vuln['assigned_to']) && (int) $vuln['assigned_to'] === $userId && $role !== 'admin') {
            return $this->apiError('The remediation owner cannot verify their own fix.', ['result' => 'Ask another security staff member to run the re-test.'], 422);
        }

        $retest = $this->vulnerabilities->openRetest($id);
        $retestId = $retest ? (int) $retest['id'] : $this->vulnerabilities->createRetest($id, $userId, $userId, null);
        $this->vulnerabilities->completeRetest($retestId, $result, $userId, $notes ?: null);

        $count = (int) ($vuln['retest_count'] ?? 0) + 1;
        if ($result === 'passed') {
            $this->vulnerabilities->updateFields($id, [
                'status' => 'verified', 'verified_at' => now(), 'verified_by' => $userId,
                'resolved_at' => $vuln['resolved_at'] ?: now(), 'retest_count' => $count, 'last_retest_result' => 'passed',
            ]);
        } else {
            $this->vulnerabilities->updateFields($id, [
                'status' => 'in_progress', 'fixed_at' => null, 'resolved_at' => null, 'verified_at' => null, 'verified_by' => null,
                'retest_count' => $count, 'last_retest_result' => 'failed',
            ]);
            if (!empty($vuln['assigned_to'])) {
                $this->notifications->notify((int) $vuln['assigned_to'], 'security_vulnerability_retest_failed', 'Re-test failed: ' . $vuln['reference_code'], $notes, '/security/vulnerabilities?open=' . (int) $id, 'urgent');
            }
        }

        $this->vulnerabilities->addEvent($id, $userId, 'retest_' . $result, ($result === 'passed' ? 'Re-test passed — fix verified.' : 'Re-test failed — back to in progress.') . ($notes ? ' ' . $notes : ''));
        $this->auditLog->record($userId, 'vulnerability.retest.' . $result, 'vulnerability', $id, ['status' => $vuln['status']], ['status' => $result === 'passed' ? 'verified' : 'in_progress'], $request->ip());

        return $this->apiSuccess(null, $result === 'passed' ? 'Fix verified.' : 'Re-test recorded — vulnerability reopened.');
    }

    /** GET /api/v1/security/vulnerabilities/export — كل الثغرات حسب الفلتر الحالي، CSV. */
    public function export(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can export vulnerabilities.', null, 403);
        }

        $status = $request->input('status') ?: null;
        $rows = $this->vulnerabilities->allWithAssigneeAll($status);

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'Reference', 'Title', 'Affected Component', 'Asset Type', 'Asset Identifier', 'CVE', 'Severity', 'CVSS Score', 'CVSS Vector',
            'Status', 'Remediation Owner', 'Deadline', 'SLA', 'Discovered At', 'Fixed At', 'Verified At', 'Verified By', 'Re-tests', 'Last Re-test',
        ]);

        foreach ($rows as $v) {
            $sla = $this->vulnerabilities->slaInfo($v);
            fputcsv($stream, [
                $v['reference_code'] ?? '', $v['title'], $v['affected_component'] ?: '', $v['asset_type'] ?? '', $v['asset_identifier'] ?? '',
                $v['cve_id'] ?? '', $v['severity'], $v['cvss_score'] ?? '', $v['cvss_vector'] ?? '',
                $v['status'], $v['assignee_name'] ?? 'Unassigned', $v['deadline'] ?? '', $sla['state'],
                $v['discovered_at'], $v['fixed_at'] ?? '', $v['verified_at'] ?? '', $v['verified_by_name'] ?? '',
                $v['retest_count'] ?? 0, $v['last_retest_result'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'vulnerability.export', 'vulnerability', null, null, ['status' => $status, 'rows' => count($rows)], $request->ip());

        $filename = 'vulnerability-report-' . date('Y-m-d_His') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -- helpers --------------------------------------------------------------

    /**
     * يقرا ويتحقق من حقول الثغرة المشتركة بين create و update. بيرجّع array (فاضي لو مفيش حاجة اتبعتت)
     * أو JsonResponse 422. لو في cvss_vector صالح، الدرجة بتتحسب من السيرفر (مش من الـ client).
     *
     * @return array<string,mixed>|\Illuminate\Http\JsonResponse
     */
    private function readFields(Request $request, bool $creating)
    {
        $out = [];
        $text = ['description', 'affected_component', 'asset_identifier', 'cve_id', 'source', 'remediation_plan'];
        $max = ['affected_component' => 150, 'asset_identifier' => 200, 'cve_id' => 30, 'source' => 40];

        foreach ($text as $k) {
            if ($request->has($k)) {
                $v = trim((string) $request->input($k, ''));
                if (isset($max[$k]) && mb_strlen($v) > $max[$k]) {
                    return $this->apiError('Value too long.', [$k => 'Max ' . $max[$k] . ' characters.'], 422);
                }
                $out[$k] = $v === '' ? null : $v;
            }
        }

        if (!empty($out['cve_id']) && !preg_match('/^CVE-\d{4}-\d{4,}$/i', (string) $out['cve_id'])) {
            return $this->apiError('Invalid CVE id.', ['cve_id' => 'Format: CVE-2026-12345.'], 422);
        }
        if (!empty($out['cve_id'])) {
            $out['cve_id'] = strtoupper((string) $out['cve_id']);
        }

        if ($request->has('asset_type')) {
            $type = trim((string) $request->input('asset_type', ''));
            if ($type !== '' && !in_array($type, VulnerabilityRepository::ASSET_TYPES, true)) {
                return $this->apiError('Invalid asset type.', ['asset_type' => 'Must be one of ' . implode(', ', VulnerabilityRepository::ASSET_TYPES) . '.'], 422);
            }
            $out['asset_type'] = $type === '' ? null : $type;
        }

        if ($request->has('cvss_vector') && trim((string) $request->input('cvss_vector')) !== '') {
            $parsed = Cvss31::parse((string) $request->input('cvss_vector'));
            if (!$parsed) {
                return $this->apiError('Invalid CVSS v3.1 vector.', ['cvss_vector' => 'Expected e.g. CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H'], 422);
            }
            $out['cvss_vector'] = $parsed['vector'];
            $out['cvss_score'] = $parsed['score'];
        } elseif ($request->has('cvss_vector')) {
            $out['cvss_vector'] = null;
            if ($request->has('cvss_score') && $request->input('cvss_score') !== '' && $request->input('cvss_score') !== null) {
                $score = $request->input('cvss_score');
                if (!is_numeric($score) || $score < 0 || $score > 10) {
                    return $this->apiError('Invalid CVSS score.', ['cvss_score' => 'Must be between 0 and 10.'], 422);
                }
                $out['cvss_score'] = round((float) $score, 1);
            } else {
                $out['cvss_score'] = null;
            }
        } elseif ($request->has('cvss_score')) {
            $score = $request->input('cvss_score');
            if ($score === '' || $score === null) {
                $out['cvss_score'] = null;
            } elseif (!is_numeric($score) || $score < 0 || $score > 10) {
                return $this->apiError('Invalid CVSS score.', ['cvss_score' => 'Must be between 0 and 10.'], 422);
            } else {
                $out['cvss_score'] = round((float) $score, 1);
            }
        }

        if ($request->has('deadline')) {
            $d = trim((string) $request->input('deadline', ''));
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return $this->apiError('Invalid date.', ['deadline' => 'Use YYYY-MM-DD.'], 422);
            }
            $out['deadline'] = $d === '' ? null : $d;
        }

        if ($request->has('assigned_to')) {
            $a = $request->input('assigned_to');
            if ($a !== null && $a !== '') {
                if (!$this->users->findById((int) $a)) {
                    return $this->apiError('Unknown user.', ['assigned_to' => 'Choose an existing user.'], 422);
                }
                $out['assigned_to'] = (int) $a;
            } else {
                $out['assigned_to'] = null;
            }
        }

        return $out;
    }

    /** يضيف sla_state/days_left للصف. */
    private function decorate(array $v): array
    {
        $sla = $this->vulnerabilities->slaInfo($v);
        $v['sla_state'] = $sla['state'];
        $v['days_left'] = $sla['days_left'];

        return $v;
    }

    private function notifyOwner(int $ownerId, int $actorId, int $vulnId, ?string $reference, string $title, string $severity): void
    {
        if ($ownerId === $actorId) {
            return;
        }
        $this->notifications->notify(
            $ownerId,
            'security_vulnerability_assigned',
            'Vulnerability assigned to you: ' . ($reference ?: '#' . $vulnId),
            $title . ' (' . $severity . ')',
            '/security/vulnerabilities?open=' . $vulnId,
            in_array($severity, ['critical', 'high'], true) ? 'high' : 'normal'
        );
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
