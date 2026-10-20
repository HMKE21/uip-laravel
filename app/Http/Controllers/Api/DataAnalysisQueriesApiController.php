<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\QueryBuilderService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisQueriesApiController.php
 * القديمة — بند 24 batch 4 (SQL Query Builder، enhancement spec section
 * 3). سطح /api/v1/data-analysis/queries/* واحد للـ builder المرئي
 * (drag & drop) + محرر SQL الخام فوق نفس الست catalog datasets
 * المسموحة بتاعة Data Explorer، بيعيد استخدام QueryBuilderService
 * بالظبط — شوف docblock الخدمة دي لموديل أمان التنفيذ. export() بتعيد
 * تشغيل الاستعلام على السيرفر بدل ما تثق في نتيجة جاهزة جاية من
 * العميل، نفس القديمة.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو admin.
 */
class DataAnalysisQueriesApiController extends Controller
{
    public function __construct(
        private QueryBuilderService $builder,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/queries — الكتالوج، تلميحات العلاقات، القوالب، الاستعلامات المحفوظة، والتاريخ الأخير. */
    public function index(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can use the query builder.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        return $this->apiSuccess([
            'catalog'   => $this->builder->catalog(),
            'relations' => $this->builder->relationshipHints(),
            'templates' => $this->builder->templates(),
            'saved'     => $this->builder->savedQueries($userId),
            'history'   => $this->builder->history($userId, 20),
        ], 'Query builder data retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/queries/run — بتنفذ spec من الـ builder أو نص SQL خام. */
    public function run(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can run a query.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $mode = (string) $request->input('mode', 'raw');
        $savedQueryId = $request->input('saved_query_id') ?: null;

        if ($mode === 'builder') {
            $spec = (array) $request->input('spec', []);
            $result = $this->builder->runBuilder($userId, $spec, $savedQueryId);
        } else {
            $sql = (string) $request->input('sql', '');
            $result = $this->builder->runRaw($userId, $sql, $savedQueryId);
        }

        $this->auditLog->record(
            $userId,
            $result['ok'] ? 'query_builder.run' : 'query_builder.run_failed',
            'query',
            $savedQueryId,
            null,
            ['mode' => $mode, 'row_count' => $result['row_count'] ?? null]
        );

        return $this->apiSuccess($result, $result['ok'] ? 'Query executed successfully.' : 'Query failed.', $result['ok'] ? 200 : 422);
    }

    /** POST /api/v1/data-analysis/queries/save — بتحفظ نص الاستعلام الحالي (وحالة الـ builder اختياري) كـ Saved Query. */
    public function save(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can save a query.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $name = (string) $request->input('name', '');
        $sql = (string) $request->input('sql', '');

        if (trim($sql) === '') {
            return $this->apiError('Nothing to save yet — run a query first.', ['sql' => 'Required.'], 422);
        }

        $saved = $this->builder->saveQuery(
            $userId,
            $name,
            $sql,
            $request->input('description') ?: null,
            $request->input('builder_state') ?: null,
            $request->input('dataset_key') ?: null,
            (bool) $request->input('is_shared', false)
        );

        $this->auditLog->record($userId, 'query_builder.save', 'saved_query', $saved->id, null, ['name' => $name]);

        return $this->apiSuccess($saved->toArray(), 'Query saved.', 201);
    }

    /** GET /api/v1/data-analysis/queries/saved/{id} — بتجيب استعلام محفوظ واحد (لـ "Load into editor"). */
    public function showSaved(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view a saved query.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $query = $this->builder->findSavedQuery($id);

        if (!$query || ((int) $query->user_id !== $userId && !$query->is_shared)) {
            return $this->apiError('Query not found.', null, 404);
        }

        $data = $query->toArray();
        $data['builder_state'] = $data['builder_state'] ? json_decode($data['builder_state'], true) : null;

        return $this->apiSuccess($data, 'Saved query retrieved successfully.');
    }

    /** DELETE /api/v1/data-analysis/queries/saved/{id} */
    public function destroySaved(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete a saved query.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $deleted = $this->builder->deleteSavedQuery($userId, $id);

        if ($deleted) {
            $this->auditLog->record($userId, 'query_builder.delete_saved', 'saved_query', $id);
        }

        return $deleted
            ? $this->apiSuccess(null, 'Query deleted.')
            : $this->apiError('Query not found.', null, 404);
    }

    /** GET /api/v1/data-analysis/queries/history — آخر تاريخ تشغيل. */
    public function history(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view query history.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        return $this->apiSuccess($this->builder->history($userId, 30), 'Query history retrieved successfully.');
    }

    /**
     * POST /api/v1/data-analysis/queries/export — بتعيد تشغيل الاستعلام
     * المعطى (spec builder أو SQL خام) وبتبث نتيجته كملف، بدل ما تثق في
     * حمولة نتيجة جاهزة جاية من العميل.
     */
    public function export(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can export a query result.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $format = strtolower((string) $request->input('format', 'csv'));
        $mode = (string) $request->input('mode', 'raw');

        if ($mode === 'builder') {
            $spec = $request->input('spec');
            if (!is_array($spec)) {
                $spec = json_decode((string) $request->input('spec_json', '[]'), true) ?: [];
            }
            $result = $this->builder->runBuilder($userId, $spec);
        } else {
            $result = $this->builder->runRaw($userId, (string) $request->input('sql', ''));
        }

        if (!$result['ok']) {
            return $this->apiError($result['error'] ?? 'Query failed.', null, 422);
        }

        $filename = 'query_result_' . date('Ymd_His');

        if ($format === 'json') {
            return response(
                json_encode($result['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                200,
                [
                    'Content-Type'        => 'application/json; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '.json"',
                ]
            );
        }

        $handle = fopen('php://temp', 'w+');
        if ($result['columns']) {
            fputcsv($handle, $result['columns']);
        }
        foreach ($result['rows'] as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
        ]);
    }

    // -- helpers --------------------------------------------------------------

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
