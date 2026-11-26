<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataAnalysisProjectsDeepService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data Analysis Portal — المشاريع بعمق (بند 4، قراءة بس).
 * /api/v1/data-analysis/project-insights/*. RBAC: data_analyst أو admin بس.
 * كل المنطق في DataAnalysisProjectsDeepService؛ هنا الصلاحيات وتنضيف
 * الفلاتر. كل الأرقام تجميعية — مفيش بيانات شخصية ولا تعليقات بتتعرض.
 */
class DataAnalysisProjectsApiController extends Controller
{
    public function __construct(private DataAnalysisProjectsDeepService $projects)
    {
    }

    /** GET /api/v1/data-analysis/project-insights/filters */
    public function filters(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        return $this->apiSuccess($this->projects->filters(), 'Project insights filters retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/project-insights/overview */
    public function overview(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        return $this->apiSuccess($this->projects->overview($this->filtersFrom($request)), 'Project insights overview retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/project-insights/section/{key} */
    public function section(Request $request, string $key): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        if (!in_array($key, DataAnalysisProjectsDeepService::SECTIONS, true)) {
            return $this->apiError('Unknown project insights section.', null, 404);
        }
        return $this->apiSuccess($this->projects->section($key, $this->filtersFrom($request)), 'Project insights section retrieved successfully.');
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
            return $this->apiError('Only Data Analysis Portal accounts can view project insights.', null, 403);
        }
        return null;
    }
}
