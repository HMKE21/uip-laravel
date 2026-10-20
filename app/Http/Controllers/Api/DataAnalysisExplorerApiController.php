<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\DataExplorerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisExplorerApiController.php
 * القديمة — بند 24 batch 4 (Data Explorer، enhancement spec section 2).
 * سطح /api/v1/data-analysis/data-explorer واحد، بيعيد استخدام نفس
 * نداءات DataExplorerService::browse()/recentlyOpened()/dataset()/
 * columns()/preview()/metadata()/relationships()/toggleFavorite()/
 * recordOpen() بالظبط اللي النسخة القديمة كانت بتستخدمها — نفس الست
 * catalog datasets المسموحة اللي Advanced Analytics وData Quality كمان
 * بيشاركوها. فلتر/ترتيب/تجميع/عينة/تصفح صفحات لسه GET-param driven
 * بالظبط زي القديمة.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو admin.
 */
class DataAnalysisExplorerApiController extends Controller
{
    public function __construct(
        private DataExplorerService $explorer,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/data-explorer */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Data Explorer.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        return $this->apiSuccess([
            'datasets' => $this->explorer->browse($userId),
            'recent'   => $this->explorer->recentlyOpened($userId, 6),
        ], 'Data Explorer catalog retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/data-explorer/{key} */
    public function show(Request $request, string $key): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Data Explorer.', null, 403);
        }

        $dataset = $this->explorer->dataset($key);
        if (!$dataset) {
            return $this->apiError('Unknown dataset.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $this->explorer->recordOpen($userId, $key);

        $tab = $request->input('tab', 'preview');
        if (!in_array($tab, ['preview', 'columns', 'relationships', 'metadata'], true)) {
            $tab = 'preview';
        }

        $filters = [];
        $filterColumns = (array) $request->input('filter_column', []);
        $filterOps = (array) $request->input('filter_op', []);
        $filterValues = (array) $request->input('filter_value', []);
        foreach ($filterColumns as $i => $col) {
            if ($col === '' || $col === null) {
                continue;
            }
            $filters[] = [
                'column' => $col,
                'op'     => $filterOps[$i] ?? 'eq',
                'value'  => $filterValues[$i] ?? null,
            ];
        }

        $previewOpts = [
            'search'   => $request->input('q', ''),
            'filters'  => $filters,
            'sort'     => $request->input('sort') ?: null,
            'sort_dir' => $request->input('dir', 'desc'),
            'group_by' => $request->input('group_by') ?: null,
            'sample'   => (bool) $request->input('sample', false),
            'page'     => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 25),
        ];

        return $this->apiSuccess([
            'dataset'       => $dataset,
            'tab'           => $tab,
            'columns'       => $this->explorer->columns($dataset),
            'preview'       => $tab === 'preview' ? $this->explorer->preview($dataset, $previewOpts) : null,
            'relationships' => $this->explorer->relationships($dataset),
            'metadata'      => $tab === 'metadata' ? $this->explorer->metadata($dataset) : null,
            'filters'       => array_merge(['tab' => $tab], $previewOpts, ['filters' => $filters]),
        ], 'Dataset retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/data-explorer/{key}/favorite */
    public function toggleFavorite(Request $request, string $key): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can favorite datasets.', null, 403);
        }

        $dataset = $this->explorer->dataset($key);
        if (!$dataset) {
            return $this->apiError('Unknown dataset.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $isNowFavorite = $this->explorer->toggleFavorite($userId, $key);

        $this->auditLog->record(
            $userId,
            $isNowFavorite ? 'data_explorer.favorite' : 'data_explorer.unfavorite',
            'dataset',
            null,
            null,
            ['dataset_key' => $key]
        );

        return $this->apiSuccess(['is_favorite' => $isNowFavorite], 'Favorite updated successfully.');
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
