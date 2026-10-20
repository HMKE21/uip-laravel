<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectRepository;
use App\Services\DataAnalysisAcademicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisSearchApiController.php
 * القديمة — بند 24 batch 7 (Search). سطح /api/v1/data-analysis/search
 * واحد، بإعادة استخدام نفس استعلامات ProjectRepository::searchAll() اللي
 * الصفحة server-rendered القديمة (app/Views/data-analysis/search-results.php)
 * كانت بتستخدمها — بحث platform-wide، من غير أي فلتر status/verification
 * عمدًا، نفس القديمة بالظبط (شوف docblock الميثودز نفسها في كل ريبو).
 * quick() منقولة كمان (كانت للـ topbar palette القديمة) — الفرونت
 * الحالي (DataAnalysisSearchResults.jsx) بيستخدم index() بس، لكن quick()
 * اتنقلت زيها زي القديمة بالظبط لو فيه استهلاك تاني ليها لاحقًا.
 *
 * RBAC: uip.auth بيغطي الجروب؛ isDataAnalyst() بيتأكد كمان (data_analyst
 * أو admin) — نفس قاعدة باقي كنترولرز DataAnalysis*.
 */
class DataAnalysisSearchApiController extends Controller
{
    private const QUICK_LIMIT = 5;

    public function __construct(
        private ProjectRepository $projects,
        private DataAnalysisAcademicService $academic
    ) {
    }

    /** GET /api/v1/data-analysis/search */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can use this search.', null, 403);
        }

        $q = trim((string) $request->input('q', ''));
        $projects = [];
        $academic = ['exams' => [], 'students' => [], 'doctors' => [], 'courses' => []];

        if ($q !== '') {
            $projects = $this->projects->searchAll($q);
            $academic = $this->academic->search($q, 10);
        }

        return $this->apiSuccess([
            'query'       => $q,
            'projects'    => $projects,
        ] + $academic, 'Search results retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/search/quick */
    public function quick(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can use this search.', null, 403);
        }

        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return $this->apiSuccess(['query' => $q, 'projects' => [], 'exams' => [], 'students' => [], 'doctors' => [], 'courses' => []]);
        }

        $projects = array_map(function ($p) {
            return [
                'title' => $p['title_en'] ?: $p['title_ar'],
                'meta'  => trim(($p['owner_name'] ?? '') . ($p['category'] ? ' · ' . $p['category'] : '')),
            ];
        }, $this->projects->searchAll($q, self::QUICK_LIMIT));

        $academic = $this->academic->search($q, self::QUICK_LIMIT);

        return $this->apiSuccess([
            'query'       => $q,
            'projects'    => $projects,
            'exams'       => array_map(fn ($e) => ['title' => $e['title'], 'meta' => trim(($e['exam_type'] ?? '') . ' · ' . ($e['status'] ?? ''), ' ·')], $academic['exams']),
            'students'    => array_map(fn ($s) => ['title' => $s['full_name'], 'meta' => (string) ($s['student_number'] ?? '')], $academic['students']),
            'doctors'     => array_map(fn ($d) => ['title' => $d['full_name'], 'meta' => (string) ($d['staff_number'] ?? '')], $academic['doctors']),
            'courses'     => array_map(fn ($c) => ['title' => $c['name_en'] ?: $c['name_ar'], 'meta' => (string) $c['code']], $academic['courses']),
        ]);
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
