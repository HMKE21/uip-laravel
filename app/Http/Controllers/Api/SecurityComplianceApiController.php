<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccessReviewService;
use App\Services\AuditChainService;
use App\Services\AuditRetentionService;
use App\Services\ComplianceService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * بند 6 — التدقيق والامتثال: /api/v1/security/compliance/*
 *  integrity  : سلسلة هاش سجلات التدقيق (فحص/ختم/نقاط تحقق)
 *  retention  : سياسة الاحتفاظ بالسجلات (إعدادات/معاينة/تشغيل) + legal hold
 *  access     : مصفوفة "مين يقدر يعمل إيه" + مراجعات الصلاحيات الدورية
 *  controls   : قوائم الامتثال (ISO 27001 / سياسة الجامعة / مخصص)
 *
 * الصلاحيات من الراوت: security.compliance.view (قراءة) / security.compliance.manage (أي كتابة). وكمان فحص الدور هنا
 * (security_admin / security_officer / admin) زي باقي كنترولرز الأمان.
 */
class SecurityComplianceApiController extends Controller
{
    public function __construct(
        private AuditChainService $chain,
        private AuditRetentionService $retention,
        private AccessReviewService $access,
        private ComplianceService $compliance,
        private PermissionService $permissions
    ) {
    }

    private function staff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function uid(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id ? (int) $id : null;
    }

    private function deny(): JsonResponse
    {
        return $this->apiError('Only Security Portal accounts can use audit & compliance.', null, 403);
    }

    /** GET /compliance/overview — كروت الصفحة الرئيسية */
    public function overview(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        $st = $this->chain->status();
        $ret = $this->retention->get();
        $acc = $this->access->state();
        $cmp = $this->compliance->overview();

        return $this->apiSuccess([
            'integrity'  => $st,
            'retention'  => $ret,
            'access'     => $acc,
            'compliance' => $cmp['summary'],
        ], 'Compliance overview retrieved successfully.', 200, [
            'can_manage' => $this->permissions->currentUserCan($this->uid($request), (string) $request->attributes->get('uip_role'), 'security.compliance.manage'),
        ]);
    }

    // ─────────────── Integrity ───────────────

    /** GET /compliance/integrity */
    public function integrity(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }

        return $this->apiSuccess([
            'status'        => $this->chain->status(),
            'verifications' => $this->chain->verifications(20),
            'checkpoints'   => $this->chain->checkpoints(20),
        ], 'Integrity status retrieved successfully.');
    }

    /** POST /compliance/integrity/verify */
    public function verify(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        @set_time_limit(300);
        $sealed = $this->chain->sealPending();
        $result = $this->chain->verify('manual', $this->uid($request));
        $result['sealed_before'] = $sealed;

        return $this->apiSuccess($result, $result['status'] === 'broken' ? 'Integrity issues found.' : 'Integrity check completed.');
    }

    /** POST /compliance/integrity/seal — يختم الصفوف القديمة (backfill) + نقطة تحقق */
    public function seal(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        @set_time_limit(300);
        $n = $this->chain->sealPending();
        $cp = $this->chain->checkpoint('manual', $this->uid($request));

        return $this->apiSuccess(['sealed' => $n, 'checkpoint' => $cp], "Sealed {$n} audit log entries.");
    }

    /** POST /compliance/integrity/checkpoint */
    public function checkpoint(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        $cp = $this->chain->checkpoint('manual', $this->uid($request));
        if (!$cp) {
            return $this->apiError('The audit chain is empty — nothing to checkpoint yet.', null, 422);
        }

        return $this->apiSuccess($cp, 'Checkpoint created.', 201);
    }

    // ─────────────── Retention ───────────────

    /** GET /compliance/retention */
    public function retentionShow(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }

        return $this->apiSuccess([
            'settings' => $this->retention->get(),
            'preview'  => $this->retention->preview(),
            'history'  => $this->retention->history(20),
        ], 'Retention policy retrieved successfully.');
    }

    /** PATCH /compliance/retention */
    public function retentionUpdate(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $new = $this->retention->update($request->all(), $this->uid($request), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['settings' => $new, 'preview' => $this->retention->preview()], 'Retention policy saved.');
    }

    /** POST /compliance/retention/run — تنفيذ يدوي (لازم confirm=true) */
    public function retentionRun(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        if (!filter_var($request->input('confirm'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->apiError('Confirm the deletion to run retention now.', ['confirm' => 'Required.'], 422);
        }
        @set_time_limit(600);
        try {
            $r = $this->retention->run($this->uid($request), false, $request->ip());
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($r, 'Retention run completed.');
    }

    // ─────────────── Access review ───────────────

    /** GET /compliance/access/matrix */
    public function accessMatrix(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }

        return $this->apiSuccess($this->access->matrix(), 'Access matrix retrieved successfully.');
    }

    /** GET /compliance/access */
    public function accessState(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }

        return $this->apiSuccess(['state' => $this->access->state(), 'reviews' => $this->access->reviews(30)], 'Access reviews retrieved successfully.');
    }

    /** PATCH /compliance/access/settings */
    public function accessSettings(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }

        return $this->apiSuccess($this->access->updateSettings($request->all(), $this->uid($request)), 'Access review settings saved.');
    }

    /** POST /compliance/access/reviews */
    public function reviewStart(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $id = $this->access->start((string) $request->input('title', ''), (string) $request->input('scope', 'privileged'), $request->input('due_at'), $this->uid($request));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->access->show($id), 'Access review started.', 201);
    }

    /** GET /compliance/access/reviews/{id} */
    public function reviewShow(Request $request, $id): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        $r = $this->access->show((int) $id);

        return $r ? $this->apiSuccess($r, 'Access review retrieved successfully.') : $this->apiError('Access review not found.', null, 404);
    }

    /** POST /compliance/access/reviews/{id}/items/{itemId}/decision */
    public function reviewDecide(Request $request, $id, $itemId): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->access->decide((int) $id, (int) $itemId, (string) $request->input('decision', ''), (string) $request->input('note', ''), $this->uid($request));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->access->show((int) $id), 'Decision saved.');
    }

    /** POST /compliance/access/reviews/{id}/items/{itemId}/remediate */
    public function reviewRemediate(Request $request, $id, $itemId): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->access->remediate((int) $id, (int) $itemId, $this->uid($request), $request->input('done', true) !== false && $request->input('done') !== 'false' && $request->input('done') !== 0);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->access->show((int) $id), 'Remediation updated.');
    }

    /** POST /compliance/access/reviews/{id}/complete */
    public function reviewComplete(Request $request, $id): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->access->complete((int) $id, $this->uid($request));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->access->show((int) $id), 'Access review completed.');
    }

    /** POST /compliance/access/reviews/{id}/cancel */
    public function reviewCancel(Request $request, $id): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->access->cancel((int) $id, $this->uid($request));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Access review cancelled.');
    }

    // ─────────────── Compliance controls ───────────────

    /** GET /compliance/controls?framework= */
    public function controls(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }

        return $this->apiSuccess($this->compliance->overview($request->query('framework')), 'Compliance controls retrieved successfully.');
    }

    /** PATCH /compliance/controls/{id} */
    public function controlUpdate(Request $request, $id): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->compliance->updateControl((int) $id, $request->all(), $this->uid($request), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->compliance->overview(), 'Control updated.');
    }

    /** POST /compliance/controls */
    public function controlStore(Request $request): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->compliance->addControl($request->all(), $this->uid($request), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->compliance->overview(), 'Control added.', 201);
    }

    /** DELETE /compliance/controls/{id} */
    public function controlDestroy(Request $request, $id): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            $this->compliance->deleteControl((int) $id, $this->uid($request), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->compliance->overview(), 'Control deleted.');
    }

    /** GET /compliance/controls/export?framework= */
    public function controlsExport(Request $request)
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        $rows = $this->compliance->exportRows($request->query('framework'));
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Framework', 'Code', 'Category', 'Control', 'Control (AR)', 'Status', 'Auto check', 'Auto check detail', 'Owner', 'Evidence note', 'Evidence link', 'Reviewed at', 'Reviewed by', 'Next review']);
        foreach ($rows as $r) {
            fputcsv($stream, array_map(fn ($v) => $this->csvSafe($v), array_values($r)));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="compliance-controls-' . date('Y-m-d_His') . '.csv"',
        ]);
    }

    /** يمنع CSV/Excel formula injection في القيم اللي المستخدم كتبها. */
    private function csvSafe($v): string
    {
        $s = (string) ($v ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $s : $s;
    }
}
