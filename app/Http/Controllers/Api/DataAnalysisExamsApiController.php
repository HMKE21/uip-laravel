<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\DataAnalysisExamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data Analysis Portal — تحليلات الامتحانات والدرجات (قراءة بس).
 * /api/v1/data-analysis/exams/*. RBAC: data_analyst أو admin بس.
 * كل المنطق في DataAnalysisExamsService؛ هنا الصلاحيات وتنضيف الفلاتر.
 */
class DataAnalysisExamsApiController extends Controller
{
    public function __construct(
        private DataAnalysisExamsService $exams,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/exams/filters */
    public function filters(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        return $this->apiSuccess($this->exams->filters(), 'Exam analytics filters retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/exams/overview */
    public function overview(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        return $this->apiSuccess($this->exams->overview($this->filtersFrom($request)), 'Exam analytics retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/exams/students */
    public function students(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $result = $this->exams->students(
            $this->filtersFrom($request),
            trim((string) $request->input('q', '')),
            (string) $request->input('sort', 'avg_asc'),
            (string) $request->input('risk', ''),
            $page,
            $perPage
        );
        return $this->apiSuccess(
            ['items' => $result['items'], 'summary' => $result['summary']],
            'Student exam performance retrieved successfully.',
            200,
            ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]
        );
    }

    /** GET /api/v1/data-analysis/exams/exam/{id} */
    public function showExam(Request $request, $id): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $data = $this->exams->exam((int) $id);
        if (!$data) {
            return $this->apiError('Exam not found.', null, 404);
        }
        // بيانات طلاب (أسماء/درجات) — بنسجّل الاطلاع على امتحان بعينه.
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'data_analysis.exam_viewed', 'exam', (int) $id);
        return $this->apiSuccess($data, 'Exam analytics retrieved successfully.');
    }

    private function filtersFrom(Request $request): array
    {
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        return [
            'university_id' => (int) $request->input('university_id') ?: null,
            'faculty_id'    => (int) $request->input('faculty_id') ?: null,
            'course_id'     => (int) $request->input('course_id') ?: null,
            'exam_type'     => preg_match('/^[a-z_]{1,20}$/', (string) $request->input('exam_type')) ? (string) $request->input('exam_type') : null,
            'from'          => $date($request->input('from')),
            'to'            => $date($request->input('to')),
        ];
    }

    private function deny(Request $request): ?JsonResponse
    {
        if (!in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true)) {
            return $this->apiError('Only Data Analysis Portal accounts can view exam analytics.', null, 403);
        }
        return null;
    }
}
