<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataQualityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * منقولة من app/Controllers/Api/DataAnalysisDataQualityApiController.php
 * القديمة — بند 24 batch 3 (Data Quality Center، enhancement spec
 * section 6). سطح /api/v1/data-analysis/data-quality واحد، بيعيد استخدام
 * DataQualityService::overview()/overallScore()/forDataset()/exportRows()
 * — فحص حي rule-based فوق الست catalog datasets. export() بيرجع نفس
 * بايتات CSV اللي نسخة القديمة كانت بترجعها، بس متاحة عبر مصادقة الـ
 * JSON API (Bearer token) بدل session cookie اللي <a href> عادي كان
 * محتاجها.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو admin.
 */
class DataAnalysisDataQualityApiController extends Controller
{
    public function __construct(private DataQualityService $quality)
    {
    }

    /** GET /api/v1/data-analysis/data-quality */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Data Quality.', null, 403);
        }

        $reports = $this->quality->overview();

        return $this->apiSuccess([
            'reports'       => $reports,
            'overall_score' => $this->quality->overallScore($reports),
        ], 'Data quality overview retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/data-quality/{key}/export */
    public function export(Request $request, string $key)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can export Data Quality reports.', null, 403);
        }

        $report = $this->quality->forDataset($key);
        if (!$report) {
            return $this->apiError('Unknown dataset.', null, 404);
        }

        $rows = $this->quality->exportRows($report);

        $handle = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'data_quality_' . $key . '_' . date('Ymd_His') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
