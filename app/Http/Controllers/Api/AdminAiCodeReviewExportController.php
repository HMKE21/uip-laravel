<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AiCodeReviewIssueRepository;
use App\Repositories\AiCodeReviewRepository;
use App\Repositories\UniversityRepository;
use App\Services\AuditLogService;
use App\Services\Export\CsvWriter;
use App\Services\Export\ExportLocalizer;
use App\Services\Export\JsonWriter;
use App\Services\Export\PdfWriter;
use App\Services\Export\SpreadsheetWriter;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Admin/AdminAiCodeReviewExportController.php
 * القديمة — بند 26 (Admin) batch 6. كل تقرير مبني من بيانات حقيقية
 * موجودة بالفعل (AiCodeReviewRepository/AiCodeReviewIssueRepository) —
 * مافيش جدول تجميع محسوب مسبقًا لده، فكل export بيشغّل الـ rollup query
 * الحقيقي وقت الطلب (AiCodeReviewRepository::universityRollup()/
 * facultyRollup()). التقارير بتضم Administrator Notes و Historical
 * Comparison (previous_review_id/version) لأن دلوقتي عندهم مصدر حقيقي.
 *
 * شكل التنزيل (ملف مؤقت -> Response بـ Content-Disposition -> unlink)
 * بيطابق نفس نمط التصدير المستخدم في باقي كنترولرز الـ Portfolio —
 * نفس الاتفاقية لكل تصدير متعدد-الصيغ في هذا الكودبيس، بس هنا كل
 * الصيغ الأربعة (csv/xlsx/pdf/json) مش CSV بس.
 *
 * مسجّلة تحت نفس prefix /api/v1/admin/ai-code-review زي
 * AdminAiCodeReviewApiController (RBAC: uip.auth + uip.admin — routes/api.php).
 */
class AdminAiCodeReviewExportController extends Controller
{
    private const FORMATS = ['csv', 'xlsx', 'pdf', 'json'];

    public function __construct(
        private AiCodeReviewRepository $reviews,
        private AiCodeReviewIssueRepository $issues,
        private UniversityRepository $universities,
        private AuditLogService $auditLog
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can export AI code review reports.', null, 403);
        }
        return null;
    }

    /** تقرير مشروع واحد: سكورات/issues/قرار الأدمن لآخر مراجعة + تاريخ النسخ الكامل. */
    public function exportProject(Request $request, $projectId)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $projectId = (int) $projectId;
        $format = $this->resolvedFormat($request);

        $chain = $this->reviews->allForProject($projectId); // الأحدث أولاً (version DESC)
        if (!$chain) {
            return $this->apiError('No code review found for this project.', null, 404);
        }
        $latest = $chain[0];
        $latestIssues = $this->issues->forReview((int) $latest->id);

        $meta = [
            'Project ID'     => (string) $projectId,
            'Latest Version' => (string) ($latest->version ?? 1),
            'Status'         => (string) $latest->status,
            'Generated At'   => date('Y-m-d H:i:s'),
        ];

        $sections = [
            $this->scoresSection($latest),
            $this->issuesSection($latestIssues),
            $this->adminDecisionSection($latest),
            $this->historySection($chain),
        ];

        $filename = 'ai_code_review_project_' . $projectId . '_v' . ($latest->version ?? 1);
        $chart = $this->chartFromScores($latest);

        return $this->respondExport($request, $format, $filename, 'AI Code Review - Project Report', $meta, $sections, $chart, [
            'action' => 'ai_code_review.export_project',
            'subject_type' => 'Project',
            'subject_id' => $projectId,
        ]);
    }

    /** تقرير تجميع جامعة: متوسط السكورات + النتائج المجمّعة عبر أحدث مراجعة لكل مشروع. */
    public function exportUniversity(Request $request, $universityId)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $universityId = (int) $universityId;
        $format = $this->resolvedFormat($request);

        $university = $this->universities->find($universityId);
        if (!$university) {
            return $this->apiError('University not found.', null, 404);
        }

        $rollup = $this->reviews->universityRollup($universityId);
        $reviewIds = array_map(fn ($r) => (int) $r['id'], $rollup['rows']);
        $issues = $this->issues->forReviews($reviewIds);
        $severity = $this->issues->severityCountsForReviews($reviewIds);

        $uniName = $university->official_name_en ?: $university->official_name_ar;
        $meta = [
            'University'         => (string) $uniName,
            'Reviewed Projects'  => (string) $rollup['reviewed_projects'],
            'Completed / Failed' => $rollup['completed'] . ' / ' . $rollup['failed'],
            'Total Issues'       => (string) count($issues),
            'Generated At'       => date('Y-m-d H:i:s'),
        ];

        $sections = [
            $this->averagesSection($rollup['averages']),
            $this->severitySection($severity),
            $this->issuesSection($issues, true),
        ];

        $filename = 'ai_code_review_university_' . $universityId;
        $chart = $this->chartFromAverages($rollup['averages']);

        return $this->respondExport($request, $format, $filename, 'AI Code Review - University Report: ' . $uniName, $meta, $sections, $chart, [
            'action' => 'ai_code_review.export_university',
            'subject_type' => 'University',
            'subject_id' => $universityId,
        ]);
    }

    /** تقرير تجميع كلية — نفس شكل تقرير الجامعة، مقصور بـ students.faculty (نص حر). */
    public function exportFaculty(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $faculty = trim((string) $request->input('faculty', ''));
        if ($faculty === '') {
            return $this->apiError('A faculty name is required.', null, 422);
        }
        $universityId = $request->input('university_id', '') !== '' ? (int) $request->input('university_id') : null;
        $format = $this->resolvedFormat($request);

        $rollup = $this->reviews->facultyRollup($faculty, $universityId);
        $reviewIds = array_map(fn ($r) => (int) $r['id'], $rollup['rows']);
        $issues = $this->issues->forReviews($reviewIds);
        $severity = $this->issues->severityCountsForReviews($reviewIds);

        $meta = [
            'Faculty'            => $faculty,
            'Reviewed Projects'  => (string) $rollup['reviewed_projects'],
            'Completed / Failed' => $rollup['completed'] . ' / ' . $rollup['failed'],
            'Total Issues'       => (string) count($issues),
            'Generated At'       => date('Y-m-d H:i:s'),
        ];

        $sections = [
            $this->averagesSection($rollup['averages']),
            $this->severitySection($severity),
            $this->issuesSection($issues, true),
        ];

        $filename = 'ai_code_review_faculty_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $faculty);
        $chart = $this->chartFromAverages($rollup['averages']);

        return $this->respondExport($request, $format, $filename, 'AI Code Review - Faculty Report: ' . $faculty, $meta, $sections, $chart, [
            'action' => 'ai_code_review.export_faculty',
            'subject_type' => 'Faculty',
            'subject_id' => null,
        ]);
    }

    /** تقرير مقارنة: فرق السكور + قوائم issues لنسختين محددتين (بيطابق compareVersions()). */
    public function exportComparison(Request $request, $idA, $idB)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $idA = (int) $idA;
        $idB = (int) $idB;
        $format = $this->resolvedFormat($request);

        $reviewA = $this->reviews->find($idA);
        $reviewB = $this->reviews->find($idB);
        if (!$reviewA || !$reviewB) {
            return $this->apiError('One or both reviews not found.', null, 404);
        }

        $scoresA = $reviewA->scores();
        $scoresB = $reviewB->scores();
        $diffRows = [];
        foreach ($scoresA as $key => $valA) {
            $valB = $scoresB[$key] ?? null;
            $diff = ($valA !== null && $valB !== null) ? $valB - $valA : null;
            $diffRows[] = [ExportLocalizer::text(ucfirst($key)), $valA ?? '-', $valB ?? '-', $diff !== null ? ($diff >= 0 ? '+' . $diff : (string) $diff) : '-'];
        }

        $meta = [
            'Version A'    => 'v' . ($reviewA->version ?? 1) . ' (' . $reviewA->created_at . ')',
            'Version B'    => 'v' . ($reviewB->version ?? 1) . ' (' . $reviewB->created_at . ')',
            'Generated At' => date('Y-m-d H:i:s'),
        ];

        $sections = [
            ['title' => 'Score Comparison', 'header' => ['Metric', 'Version A', 'Version B', 'Change'], 'rows' => $diffRows],
            $this->issuesSection($this->issues->forReview($idA), true, 'Version A Findings'),
            $this->issuesSection($this->issues->forReview($idB), true, 'Version B Findings'),
        ];

        $filename = 'ai_code_review_comparison_' . $idA . '_vs_' . $idB;
        $chart = $this->chartFromScores($reviewB, $scoresB);

        return $this->respondExport($request, $format, $filename, 'AI Code Review - Version Comparison', $meta, $sections, $chart, [
            'action' => 'ai_code_review.export_comparison',
            'subject_type' => 'AiCodeReviewResult',
            'subject_id' => $idB,
        ]);
    }

    // -- بناء الأقسام ---------------------------------------------------

    private function scoresSection($review): array
    {
        $s = $review->scores();
        return ['title' => 'Scores', 'header' => ['Metric', 'Score'], 'rows' => [
            [ExportLocalizer::text('Overall'), $s['overall'] ?? '-'],
            [ExportLocalizer::text('Security'), $s['security'] ?? '-'],
            [ExportLocalizer::text('Performance'), $s['performance'] ?? '-'],
            [ExportLocalizer::text('Maintainability'), $s['maintainability'] ?? '-'],
            [ExportLocalizer::text('Architecture'), $s['architecture'] ?? '-'],
            [ExportLocalizer::text('Quality'), $s['quality'] ?? '-'],
        ]];
    }

    private function averagesSection(array $averages): array
    {
        return ['title' => 'Average Scores', 'header' => ['Metric', 'Average'], 'rows' => [
            [ExportLocalizer::text('Overall'), $averages['overall'] ?? '-'],
            [ExportLocalizer::text('Security'), $averages['security'] ?? '-'],
            [ExportLocalizer::text('Performance'), $averages['performance'] ?? '-'],
            [ExportLocalizer::text('Maintainability'), $averages['maintainability'] ?? '-'],
            [ExportLocalizer::text('Architecture'), $averages['architecture'] ?? '-'],
            [ExportLocalizer::text('Quality'), $averages['quality'] ?? '-'],
        ]];
    }

    private function severitySection(array $counts): array
    {
        $rows = [];
        foreach ($counts as $sev => $c) {
            $rows[] = [ExportLocalizer::text(ucfirst($sev)), $c];
        }
        return ['title' => 'Issues by Severity', 'header' => ['Severity', 'Count'], 'rows' => $rows];
    }

    /** @param \App\Models\AiCodeReviewIssue[] $issues */
    private function issuesSection(array $issues, bool $includeProject = false, string $title = 'Findings'): array
    {
        $header = ['Severity', 'Category', 'File', 'Line', 'Description', 'Recommendation'];
        $rows = array_map(fn ($i) => [
            ExportLocalizer::isArabic() ? ExportLocalizer::text(ucfirst((string) $i->severity)) : $i->severity, $i->category, $i->file_name ?: '-', $i->line_number ?? '-',
            $i->description, $i->ai_recommendation ?: '-',
        ], $issues);
        return ['title' => $title, 'header' => $header, 'rows' => $rows];
    }

    private function adminDecisionSection($review): array
    {
        return ['title' => 'Administrator Decision', 'header' => ['Field', 'Value'], 'rows' => [
            [ExportLocalizer::text('Reviewed By (User ID)'), $review->reviewed_by ?? '-'],
            [ExportLocalizer::text('Approved At'), $review->approved_at ?? ExportLocalizer::text('Not yet approved')],
            [ExportLocalizer::text('Score Overridden'), ((int) ($review->score_overridden ?? 0)) === 1 ? ExportLocalizer::text('Yes') : ExportLocalizer::text('No')],
            [ExportLocalizer::text('Override Reason'), $review->override_reason ?: '-'],
            [ExportLocalizer::text('Admin Notes'), $review->admin_notes ?: '-'],
        ]];
    }

    /** @param \App\Models\AiCodeReviewResult[] $chain الأحدث أولاً */
    private function historySection(array $chain): array
    {
        $rows = array_map(fn ($r) => [
            'v' . ($r->version ?? 1), $r->status, $r->overall_score ?? '-', $r->issues_found, $r->created_at,
        ], $chain);
        return ['title' => 'Historical Comparison (All Versions)', 'header' => ['Version', 'Status', 'Overall Score', 'Issues', 'Date'], 'rows' => $rows];
    }

    private function chartFromScores($review, ?array $scoresOverride = null): array
    {
        $s = $scoresOverride ?? $review->scores();
        return [
            'labels' => ['Overall', 'Security', 'Performance', 'Maintain.', 'Arch.', 'Quality'],
            'values' => [$s['overall'], $s['security'], $s['performance'], $s['maintainability'], $s['architecture'], $s['quality']],
        ];
    }

    private function chartFromAverages(array $averages): array
    {
        return [
            'labels' => ['Overall', 'Security', 'Performance', 'Maintain.', 'Arch.', 'Quality'],
            'values' => [$averages['overall'], $averages['security'], $averages['performance'], $averages['maintainability'], $averages['architecture'], $averages['quality']],
        ];
    }

    // -- توزيع الصيغة / آلية التنزيل --------------------------------

    private function resolvedFormat(Request $request): string
    {
        $format = strtolower((string) $request->input('format', 'pdf'));
        return in_array($format, self::FORMATS, true) ? $format : 'pdf';
    }

    /**
     * @param array<string,string> $meta
     * @param array<int,array{title:string,header:string[],rows:array<int,array<int,mixed>>}> $sections
     * @param array{labels:string[],values:array<int,?int>} $chart
     * @param array{action:string,subject_type:string,subject_id:mixed} $audit
     */
    private function respondExport(Request $request, string $format, string $filename, string $title, array $meta, array $sections, array $chart, array $audit)
    {
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $path = $tmpDir . '/' . $filename . '_' . date('Ymd_His') . '.' . $format;

        // لغة الملف من lang في الريكوست — بتترجم العناوين وأسماء الأعمدة ومفاتيح الـ meta بس.
        [$title, $meta, $sections] = ExportLocalizer::report($title, $meta, $sections);
        $ar = ExportLocalizer::isArabic();

        try {
            switch ($format) {
                case 'csv':
                    $csvSections = array_map(fn ($s) => ['title' => $s['title'], 'header' => $s['header'], 'rows' => $s['rows']], $sections);
                    array_unshift($csvSections, ['title' => $title, 'header' => array_keys($meta), 'rows' => [array_values($meta)]]);
                    CsvWriter::writeSections($path, $csvSections);
                    $contentType = 'text/csv';
                    break;

                case 'xlsx':
                    // شيت مسطّح واحد: صفوف meta، ثم كل قسم تحت صف علامة "## Title" (SpreadsheetWriter شيت واحد بس).
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
                            $rows[] = array_pad(array_values($r), count($header), '');
                        }
                    }
                    // توسيع الـ header لأعرض صف مُستخدم فعليًا عشان الأعمدة ماتتقصّش.
                    $maxCols = max(array_map('count', array_merge([$header], $rows)));
                    $header = array_pad($header, $maxCols, '');
                    SpreadsheetWriter::write($path, $header, $rows, $ar);
                    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;

                case 'json':
                    JsonWriter::write($path, [
                        'title' => $title,
                        'meta'  => $meta,
                        'sections' => $sections,
                    ]);
                    $contentType = 'application/json';
                    break;

                default: // pdf
                    PdfWriter::writeReport($path, $title, $meta, $sections, $chart);
                    $contentType = 'application/pdf';
                    break;
            }
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record(
            $request->attributes->get('uip_user_id'),
            $audit['action'],
            $audit['subject_type'],
            $audit['subject_id'],
            null,
            ['format' => $format],
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
