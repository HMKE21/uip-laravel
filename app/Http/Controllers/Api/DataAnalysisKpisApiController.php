<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisKpisApiController.php
 * القديمة — بند 24 batch 3 (KPI Management، enhancement spec section
 * 10). سطح /api/v1/data-analysis/kpis/* واحد لعمليات الـ CRUD الكاملة
 * (إنشاء/تعديل/حذف/تصنيف/تخصيص/تحديد هدف/إعداد تنبيهات) بالإضافة
 * لتسجيل قيم جديدة، بإعادة استخدام KpiService بالظبط — مدعومة بجداول
 * kpis/kpi_history حقيقية (migration 075). trend/growth rate/
 * achievement % محسوبة من الـ history الحقيقي على مستوى الـ service.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو
 * admin.
 */
class DataAnalysisKpisApiController extends Controller
{
    public function __construct(
        private KpiService $kpis,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/kpis */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view KPIs.', null, 403);
        }

        $status = $request->input('status', 'active');
        if (!in_array($status, ['active', 'archived'], true)) {
            $status = 'active';
        }

        return $this->apiSuccess([
            'kpis'  => $this->kpis->list($status),
            'users' => $this->kpis->assignableUsers(),
        ], 'KPIs retrieved successfully.', 200, ['status' => $status]);
    }

    /** POST /api/v1/data-analysis/kpis */
    public function store(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can create a KPI.', null, 403);
        }

        $data = $request->validate([
            'name'          => 'required',
            'target_value'  => 'required|numeric',
            'current_value' => 'required|numeric',
        ]);

        $direction = $request->input('direction', 'higher_better');
        if (!in_array($direction, ['higher_better', 'lower_better'], true)) {
            $direction = 'higher_better';
        }

        $alertEnabled = (bool) $request->input('alert_enabled', false);
        $alertThreshold = $request->input('alert_threshold');

        $userId = $request->attributes->get('uip_user_id');

        $id = $this->kpis->create([
            'name'            => $data['name'],
            'description'     => $request->input('description') ?: null,
            'category'        => $request->input('category') ?: null,
            'unit'            => $request->input('unit') ?: null,
            'current_value'   => (float) $data['current_value'],
            'target_value'    => (float) $data['target_value'],
            'direction'       => $direction,
            'alert_enabled'   => $alertEnabled ? 1 : 0,
            'alert_threshold' => ($alertEnabled && $alertThreshold !== null && $alertThreshold !== '') ? (float) $alertThreshold : null,
            'status'          => 'active',
            'created_by'      => $userId,
            'assigned_to'     => $request->input('assigned_to') ?: null,
        ]);

        $this->auditLog->record($userId, 'kpi.create', 'kpi', $id, null, ['name' => $data['name']]);

        return $this->apiSuccess(['id' => $id], 'KPI created.', 201);
    }

    /** PATCH /api/v1/data-analysis/kpis/{id} */
    public function update(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can update a KPI.', null, 403);
        }

        $id = (int) $id;
        $existing = $this->kpis->get($id);
        if (!$existing) {
            return $this->apiError('KPI not found.', null, 404);
        }

        $data = $request->validate([
            'name'         => 'required',
            'target_value' => 'required|numeric',
        ]);

        $direction = $request->input('direction', $existing['direction']);
        if (!in_array($direction, ['higher_better', 'lower_better'], true)) {
            $direction = $existing['direction'];
        }

        $alertEnabled = (bool) $request->input('alert_enabled', false);
        $alertThreshold = $request->input('alert_threshold');

        $this->kpis->update($id, [
            'name'            => $data['name'],
            'description'     => $request->input('description') ?: null,
            'category'        => $request->input('category') ?: null,
            'unit'            => $request->input('unit') ?: null,
            'target_value'    => (float) $data['target_value'],
            'direction'       => $direction,
            'alert_enabled'   => $alertEnabled ? 1 : 0,
            'alert_threshold' => ($alertEnabled && $alertThreshold !== null && $alertThreshold !== '') ? (float) $alertThreshold : null,
            'assigned_to'     => $request->input('assigned_to') ?: null,
        ]);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'kpi.update', 'kpi', $id, ['name' => $existing['name']], ['name' => $data['name']]);

        return $this->apiSuccess(null, 'KPI updated.');
    }

    /** POST /api/v1/data-analysis/kpis/{id}/record-value */
    public function recordValue(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can record a KPI value.', null, 403);
        }

        $id = (int) $id;
        $existing = $this->kpis->get($id);
        if (!$existing) {
            return $this->apiError('KPI not found.', null, 404);
        }

        $data = $request->validate(['value' => 'required|numeric']);
        $userId = $request->attributes->get('uip_user_id');

        $this->kpis->recordValue($id, (float) $data['value'], $request->input('note') ?: null, $userId);

        $this->auditLog->record(
            $userId,
            'kpi.record_value',
            'kpi',
            $id,
            ['previous_value' => $existing['current_value']],
            ['value' => $data['value']]
        );

        return $this->apiSuccess(null, 'New value recorded.', 201);
    }

    /** POST /api/v1/data-analysis/kpis/{id}/archive */
    public function archive(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can archive a KPI.', null, 403);
        }

        $id = (int) $id;
        $this->kpis->update($id, ['status' => 'archived']);
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'kpi.archive', 'kpi', $id, null, ['status' => 'archived']);

        return $this->apiSuccess(null, 'KPI archived.');
    }

    /** POST /api/v1/data-analysis/kpis/{id}/restore */
    public function restore(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can restore a KPI.', null, 403);
        }

        $id = (int) $id;
        $this->kpis->update($id, ['status' => 'active']);
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'kpi.restore', 'kpi', $id, null, ['status' => 'active']);

        return $this->apiSuccess(null, 'KPI restored.');
    }

    /** DELETE /api/v1/data-analysis/kpis/{id} */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete a KPI.', null, 403);
        }

        $id = (int) $id;
        $existing = $this->kpis->get($id);

        if (!$this->kpis->delete($id)) {
            return $this->apiError('KPI not found.', null, 404);
        }

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'kpi.delete', 'kpi', $id, $existing, null);

        return $this->apiSuccess(null, 'KPI deleted.');
    }

    // -- helpers --------------------------------------------------------------

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
