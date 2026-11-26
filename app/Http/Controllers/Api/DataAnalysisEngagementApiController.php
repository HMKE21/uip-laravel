<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataAnalysisEngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data Analysis Portal — التفاعل والنشاط (بند 6، قراءة بس).
 * /api/v1/data-analysis/engagement/*. RBAC: data_analyst أو admin بس.
 * كل المنطق في DataAnalysisEngagementService؛ هنا الصلاحيات وتنضيف
 * الفلاتر. كل الأرقام تجميعية — مفيش بيانات شخصية بتتعرض.
 */
class DataAnalysisEngagementApiController extends Controller
{
    public function __construct(private DataAnalysisEngagementService $engagement)
    {
    }

    /** GET /api/v1/data-analysis/engagement/filters */
    public function filters(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        return $this->apiSuccess($this->engagement->filters(), 'Engagement filters retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/engagement/overview */
    public function overview(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        return $this->apiSuccess($this->engagement->overview($this->filtersFrom($request)), 'Engagement overview retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/engagement/section/{key} */
    public function section(Request $request, string $key): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        if (!in_array($key, DataAnalysisEngagementService::SECTIONS, true)) {
            return $this->apiError('Unknown engagement section.', null, 404);
        }
        $data = $this->engagement->section($key, $this->filtersFrom($request));
        return $this->apiSuccess($data, 'Engagement section retrieved successfully.');
    }

    private function filtersFrom(Request $request): array
    {
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        return [
            'university_id' => (int) $request->input('university_id') ?: null,
            'from'          => $date($request->input('from')),
            'to'            => $date($request->input('to')),
        ];
    }

    private function deny(Request $request): ?JsonResponse
    {
        if (!in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true)) {
            return $this->apiError('Only Data Analysis Portal accounts can view engagement analytics.', null, 403);
        }
        return null;
    }
}
