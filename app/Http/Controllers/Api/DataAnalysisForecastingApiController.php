<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisForecastingApiController.php
 * القديمة — بند 24 batch 3 (Forecasting، enhancement spec section 5).
 * سطح /api/v1/data-analysis/forecasting واحد، بيعيد استخدام
 * ForecastingService::metrics()/forecastAll()/metric()/forecast()/
 * explain()/isAiAvailable() بالظبط — توقع OLS حقيقي فوق السلاسل الشهرية
 * الحية بتاعة ForecastRepository. النسخة القديمة (server-rendered) كانت
 * بتحفظ شرح AI في Session عشان يعيش بعد redirect؛ هنا بيترجع مباشرة في
 * الـ response body (مفيش redirect في API من نوع JSON)، فمفيش حاجة
 * بتتخزن في Session.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو admin.
 */
class DataAnalysisForecastingApiController extends Controller
{
    public function __construct(private ForecastingService $forecasting)
    {
    }

    /** GET /api/v1/data-analysis/forecasting */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Forecasting.', null, 403);
        }

        return $this->apiSuccess([
            'metrics'      => $this->forecasting->metrics(),
            'forecasts'    => $this->forecasting->forecastAll(),
            'ai_available' => $this->forecasting->isAiAvailable(),
        ], 'Forecasting overview retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/forecasting/{key} */
    public function show(Request $request, string $key): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view Forecasting.', null, 403);
        }

        $meta = $this->forecasting->metric($key);
        if (!$meta) {
            return $this->apiError('Unknown forecast metric.', null, 404);
        }

        return $this->apiSuccess([
            'key'          => $key,
            'forecast'     => $this->forecasting->forecast($key),
            'ai_available' => $this->forecasting->isAiAvailable(),
        ], 'Forecast retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/forecasting/{key}/explain */
    public function explain(Request $request, string $key): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can generate forecast explanations.', null, 403);
        }

        $meta = $this->forecasting->metric($key);
        if (!$meta) {
            return $this->apiError('Unknown forecast metric.', null, 404);
        }

        if (!$this->forecasting->isAiAvailable()) {
            return $this->apiError('AI provider is not configured yet — ask an admin to add an API key under Settings → AI.', null, 422);
        }

        try {
            $payload = $this->forecasting->forecast($key);
            $explanation = $this->forecasting->explain($payload);
            return $this->apiSuccess(['explanation' => $explanation], 'AI explanation generated.');
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), null, 500);
        }
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
