<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIInsightsService;
use App\Services\AuditLogService;
use App\Services\Export\AiInsightsReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * منقولة من app/Controllers/Api/DataAnalysisAiInsightsApiController.php
 * القديمة — بند 24 batch 5 (AI Insights، enhancement spec section 4).
 * سطح /api/v1/data-analysis/ai-insights واحد، بإعادة استخدام نفس نداءات
 * AIInsightsService::isAvailable()/latestCompleted()/latestAny()/
 * history()/generate() — محرك الـ AI analytics الحقيقي فوق مقاييس
 * المنصة الحية. نفس عقد "أبدًا مش مختلق": نداء AI غير مُعدّ أو فاشل
 * بيرجّع apiError حقيقي، أبدًا مش تقرير شكله مختلق.
 *
 * RBAC: uip.auth بيغطي الجروب؛ isDataAnalyst() بيتأكد كمان في كل ميثود
 * (data_analyst أو admin) — نفس قاعدة باقي كنترولرز DataAnalysis*.
 */
class DataAnalysisAiInsightsApiController extends Controller
{
    public function __construct(
        private AIInsightsService $insights,
        private AuditLogService $auditLog,
        private AiInsightsReportExporter $exporter
    ) {
    }

    /** GET /api/v1/data-analysis/ai-insights */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view AI Insights.', null, 403);
        }

        $latest = $this->insights->latestCompleted();
        $latestAny = $this->insights->latestAny();

        return $this->apiSuccess([
            'available'   => $this->insights->isAvailable(),
            'latest'      => $latest,
            'result'      => $latest ? $this->decodeResult($latest['result_json'] ?? null) : null,
            'last_failed' => ($latestAny && $latestAny['status'] === 'failed') ? $latestAny : null,
            'history'     => $this->insights->history(50),
        ], 'AI Insights retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/ai-insights/regenerate */
    public function regenerate(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can regenerate AI Insights.', null, 403);
        }

        if (!$this->insights->isAvailable()) {
            return $this->apiError('AI provider is not configured yet — ask an admin to add an API key under Settings → AI.', null, 422);
        }

        $userId = $request->attributes->get('uip_user_id');

        try {
            $report = $this->insights->generate((int) $userId);
            $this->auditLog->record($userId, 'ai_insights.generate', 'ai_insight_report', $report->id, null, ['status' => 'completed']);

            return $this->apiSuccess([
                'result' => $this->decodeResult($report->result_json ?? null),
            ], 'AI Insights regenerated.');
        } catch (\Throwable $e) {
            $this->auditLog->record($userId, 'ai_insights.generate_failed', 'ai_insight_report', null, null, ['error' => $e->getMessage()]);
            return $this->apiError($e->getMessage(), null, 500);
        }
    }

    /**
     * GET /api/v1/data-analysis/ai-insights/export?format=pdf|docx|xlsx|csv|json|md&id=&lang=ar|en
     * بيصدّر تقرير واحد: الـ id لو اتبعت، وإلا آخر تقرير مكتمل. الفشل مش
     * بيتصدّر (مفيش نتيجة)، ومفيش أي محتوى بيتألّف — الملف مبني من result_json.
     */
    public function export(Request $request): JsonResponse|BinaryFileResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can export AI Insights.', null, 403);
        }

        $format = strtolower((string) $request->query('format', 'pdf'));
        if (!AiInsightsReportExporter::supports($format)) {
            return $this->apiError('Unsupported export format. Use one of: ' . implode(', ', array_keys(AiInsightsReportExporter::FORMATS)) . '.', null, 422);
        }

        $id = $request->query('id');
        $report = ($id !== null && $id !== '')
            ? (is_numeric($id) ? $this->insights->find((int) $id) : null)
            : $this->insights->latestCompleted();

        if (!$report) {
            return $this->apiError('AI Insights report not found.', null, 404);
        }
        if (($report['status'] ?? '') !== 'completed' || empty($report['result_json'])) {
            return $this->apiError('This generation attempt failed, so there is no report to export.', null, 422);
        }

        $lang = $request->query('lang') === 'ar' ? 'ar' : 'en';
        $userId = $request->attributes->get('uip_user_id');

        try {
            $path = $this->exporter->build($report, $format, $lang);
        } catch (\Throwable $e) {
            return $this->apiError('Could not build the export: ' . $e->getMessage(), null, 500);
        }

        $this->auditLog->record($userId, 'ai_insights.export', 'ai_insight_report', $report['id'], null, ['format' => $format]);

        $stamp = date('Ymd-His', strtotime((string) ($report['created_at'] ?? 'now')) ?: time());
        $filename = 'ai-insights-' . (int) $report['id'] . '-' . $stamp . '.' . $format;

        return response()->download($path, $filename, [
            'Content-Type'                  => AiInsightsReportExporter::FORMATS[$format],
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ])->deleteFileAfterSend(true);
    }

    /** DELETE /api/v1/data-analysis/ai-insights/{id} — يمسح تشغيلة واحدة من سجل التوليد. */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can delete AI Insights history.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $report = $this->insights->find((int) $id);
        if (!$report) {
            return $this->apiError('AI Insights report not found.', null, 404);
        }

        $this->insights->deleteByIds([(int) $id]);
        $this->auditLog->record($userId, 'ai_insights.delete', 'ai_insight_report', (int) $id, ['status' => $report['status'] ?? null], null);

        return $this->apiSuccess(['deleted' => 1], 'AI Insights entry deleted.');
    }

    /** POST /api/v1/data-analysis/ai-insights/delete-selected {ids:[...]} */
    public function destroySelected(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can delete AI Insights history.', null, 403);
        }

        $ids = array_values(array_unique(array_map('intval', array_filter((array) $request->input('ids', []), 'is_numeric'))));
        if (!$ids) {
            return $this->apiError('No entries selected.', null, 422);
        }

        $count = $this->insights->deleteByIds($ids);
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'ai_insights.delete_selected', 'ai_insight_report', null, null, ['ids' => $ids, 'deleted' => $count]);

        return $this->apiSuccess(['deleted' => $count], $count . ' entr' . ($count === 1 ? 'y' : 'ies') . ' deleted.');
    }

    /** POST /api/v1/data-analysis/ai-insights/delete-all — يفضّي سجل التوليد كله. */
    public function destroyAll(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can delete AI Insights history.', null, 403);
        }

        $count = $this->insights->deleteAll();
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'ai_insights.delete_all', 'ai_insight_report', null, null, ['deleted' => $count]);

        return $this->apiSuccess(['deleted' => $count], 'AI Insights history cleared (' . $count . ').');
    }

    private function decodeResult(?string $json): ?array
    {
        if (!$json) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
