<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdvancedAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisAdvancedAnalyticsApiController.php
 * القديمة — بند 24 batch 3 (Advanced Analytics، enhancement spec section
 * 11). سطح /api/v1/data-analysis/advanced-analytics واحد، بيعيد استخدام
 * AdvancedAnalyticsService::datasets()/geographicDistribution()/
 * userCohortRetention()/dataset()/columnOptions()/statistics()/
 * correlationMatrix()/pivot()/timeSeries()/comparative() بالظبط، فوق نفس
 * الست catalog datasets المسموحة اللي Data Explorer بيستخدمها. اختيار
 * الـ tab والمعاملات لسه GET-param driven بالظبط زي القديمة.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو admin.
 */
class DataAnalysisAdvancedAnalyticsApiController extends Controller
{
    public function __construct(private AdvancedAnalyticsService $analytics)
    {
    }

    /** GET /api/v1/data-analysis/advanced-analytics */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Advanced Analytics.', null, 403);
        }

        return $this->apiSuccess([
            'datasets'   => $this->analytics->datasets(),
            'geographic' => $this->analytics->geographicDistribution(),
            'cohort'     => $this->analytics->userCohortRetention(),
        ], 'Advanced Analytics overview retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/advanced-analytics/{key} */
    public function show(Request $request, string $key): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Advanced Analytics.', null, 403);
        }

        $dataset = $this->analytics->dataset($key);
        if (!$dataset) {
            return $this->apiError('Unknown dataset.', null, 404);
        }

        $options = $this->analytics->columnOptions($dataset);

        $tab = $request->input('tab', 'statistics');
        if (!in_array($tab, ['statistics', 'correlation', 'pivot', 'timeseries', 'comparative'], true)) {
            $tab = 'statistics';
        }

        $payload = [
            'dataset' => $dataset,
            'tab'     => $tab,
            'options' => $options,
        ];

        if ($tab === 'statistics') {
            $column = $request->input('column') ?: ($options['numeric'][0] ?? null);
            $payload['column'] = $column;
            $payload['stats'] = $column ? $this->analytics->statistics($dataset, $column) : null;
        }

        if ($tab === 'correlation') {
            $payload['matrix'] = $this->analytics->correlationMatrix($dataset, $options['numeric']);
        }

        if ($tab === 'pivot') {
            $rowDim = $request->input('row_dim') ?: ($options['categorical'][0] ?? null);
            $colDim = $request->input('col_dim') ?: ($options['categorical'][1] ?? ($options['categorical'][0] ?? null));
            $valueCol = $request->input('value_col') ?: null;
            $aggFn = $request->input('agg_fn', 'count');
            $payload['pivot_params'] = ['row_dim' => $rowDim, 'col_dim' => $colDim, 'value_col' => $valueCol, 'agg_fn' => $aggFn];
            $payload['pivot'] = ($rowDim && $colDim)
                ? $this->analytics->pivot($dataset, $rowDim, $colDim, $valueCol, $aggFn)
                : null;
        }

        if ($tab === 'timeseries') {
            $dateCol = $request->input('date_column') ?: ($options['date'][0] ?? null);
            $payload['date_column'] = $dateCol;
            $payload['series'] = $dateCol ? $this->analytics->timeSeries($dataset, $dateCol, 12) : null;
        }

        if ($tab === 'comparative') {
            $dateCol = $request->input('date_column') ?: ($options['date'][0] ?? null);
            $days = (int) $request->input('days', 30);
            $payload['date_column'] = $dateCol;
            $payload['comparative'] = $dateCol ? $this->analytics->comparative($dataset, $dateCol, $days) : null;
        }

        return $this->apiSuccess($payload, 'Dataset analysis retrieved successfully.');
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
