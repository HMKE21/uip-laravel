<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\CodeReviewReportService;
use App\Services\Export\CsvWriter;
use App\Services\Export\ExportLocalizer;
use App\Services\Export\JsonWriter;
use App\Services\Export\PdfWriter;
use App\Services\Export\ReportDocumentWriter;
use App\Services\Export\SpreadsheetWriter;
use Illuminate\Http\Request;

/**
 * تقرير مراجعة الكود لمشروع — بيوصل لكل أطراف المشروع (الطالب/الفريق، الدكتور، المعيد، المشرف،
 * الكلية، الجامعة، الأدمن) بنفس البيانات، وكل واحد بيشوف بس المشاريع اللي في نطاقه
 * (CodeReviewReportService::resolveProject — نفس شروط صفحات كل دور).
 *
 *   GET /api/v1/code-review-report/{uuid}            → التقرير JSON
 *   GET /api/v1/code-review-report/{uuid}/export     → ملف (format=csv|xlsx|pdf|json|docx|html|md|txt|xml, lang=ar|en)
 */
class CodeReviewReportApiController extends Controller
{
    private const CORE_FORMATS = ['csv', 'xlsx', 'pdf', 'json'];

    public function __construct(
        private CodeReviewReportService $service,
        private AuditLogService $auditLog
    ) {
    }

    private function load(Request $request, string $uuid)
    {
        $role = (string) $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');
        $project = $this->service->resolveProject($role, $userId, $uuid);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        return $project;
    }

    public function show(Request $request, string $uuid)
    {
        $project = $this->load($request, $uuid);
        if (!$project instanceof \App\Models\Project) {
            return $project;
        }
        $report = $this->service->build($project, ExportLocalizer::isArabic());
        return $this->apiSuccess($report, $report ? 'Code review report retrieved successfully.' : 'No code review has been run yet.');
    }

    public function export(Request $request, string $uuid)
    {
        $project = $this->load($request, $uuid);
        if (!$project instanceof \App\Models\Project) {
            return $project;
        }

        $ar = ExportLocalizer::isArabic();
        $report = $this->service->build($project, $ar);
        if (!$report) {
            return $this->apiError('No code review has been run yet.', null, 404);
        }

        $format = strtolower((string) $request->input('format', 'pdf'));
        if (!in_array($format, array_merge(self::CORE_FORMATS, ReportDocumentWriter::FORMATS), true)) {
            $format = 'pdf';
        }

        [$title, $meta, $sections, $chart] = $this->service->toExportParts($report, $ar);
        [$title, $meta, $sections] = ExportLocalizer::report($title, $meta, $sections);

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $filename = 'code_review_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $uuid) . '_v' . $report['review']['version'];
        $path = $tmpDir . '/' . $filename . '_' . date('Ymd_His') . '.' . $format;

        try {
            switch ($format) {
                case 'csv':
                    $csv = array_map(fn ($s) => ['title' => $s['title'], 'header' => $s['header'], 'rows' => $s['rows']], $sections);
                    array_unshift($csv, ['title' => $title, 'header' => array_keys($meta), 'rows' => [array_values($meta)]]);
                    CsvWriter::writeSections($path, $csv);
                    $contentType = 'text/csv';
                    break;
                case 'xlsx':
                    $header = ExportLocalizer::headers(['Field', 'Value']);
                    $rows = [];
                    foreach ($meta as $k => $v) {
                        $rows[] = [$k, $v];
                    }
                    foreach ($sections as $section) {
                        $rows[] = ['', ''];
                        $rows[] = ['## ' . $section['title'], ''];
                        if ($section['header']) {
                            $rows[] = $section['header'];
                        }
                        foreach ($section['rows'] as $r) {
                            $rows[] = array_values($r);
                        }
                    }
                    $maxCols = max(array_map('count', array_merge([$header], $rows)));
                    $header = array_pad($header, $maxCols, '');
                    $rows = array_map(fn ($r) => array_pad($r, $maxCols, ''), $rows);
                    SpreadsheetWriter::write($path, $header, $rows, $ar);
                    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
                case 'json':
                    JsonWriter::write($path, ['title' => $title, 'meta' => $meta, 'sections' => $sections, 'report' => $report]);
                    $contentType = 'application/json';
                    break;
                case 'pdf':
                    PdfWriter::writeReport($path, $title, $meta, $sections, $chart);
                    $contentType = 'application/pdf';
                    break;
                default: // docx | html | md | txt | xml
                    ReportDocumentWriter::write($format, $path, $title, $meta, $sections, $ar);
                    $contentType = ReportDocumentWriter::mime($format);
                    break;
            }
        } catch (\RuntimeException $e) {
            @unlink($path);
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record(
            $request->attributes->get('uip_user_id'),
            'code_review.export_report',
            'Project',
            $project->id,
            null,
            ['format' => $format, 'role' => $request->attributes->get('uip_role')],
            $request->ip()
        );

        $content = file_get_contents($path);
        @unlink($path);

        return response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
    }
}
