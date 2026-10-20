<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Export\CsvWriter;
use App\Services\Export\ExportLocalizer;
use App\Services\Export\JsonWriter;
use App\Services\Export\PdfWriter;
use App\Services\Export\ReportDocumentWriter;
use App\Services\Export\SpreadsheetWriter;

/**
 * تقرير تحليل الذكاء الاصطناعي لمشروع، بكل صيغ التصدير (نفس محرّكات تقرير مراجعة الكود):
 * csv | xlsx | pdf | json | docx | html | md | txt | xml — بلغة الطلب (ar/en).
 */
class AIAnalysisReportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf', 'json', 'docx', 'html', 'md', 'txt', 'xml'];

    public function __construct(private AIAnalysisService $analysis)
    {
    }

    /** @return array<string,mixed>|null null لو مفيش أي نتيجة تحليل محفوظة. */
    public function build(Project $project): ?array
    {
        $a = $this->analysis->getLatest($project);
        $readiness = $a['readiness'];
        $classification = $a['classification'];
        $potential = $a['startup_potential'];
        $suggestions = $a['suggestions']->all();
        $summary = $a['summary'];
        $codeReview = app(CodeReviewReportService::class)->build($project, ExportLocalizer::isArabic());
        if (!$readiness && !$classification && !$potential && !$suggestions && !$summary && !$codeReview) {
            return null;
        }
        return [
            'project' => [
                'uuid'   => (string) $project->uuid,
                'title'  => (string) ($project->title_ar ?: $project->title_en),
                'title_en' => (string) $project->title_en,
                'status' => (string) $project->status,
            ],
            'readiness'      => $readiness ? $readiness->toArray() : null,
            'classification' => $classification ? array_merge($classification->toArray(), ['alternatives' => $classification->alternatives()]) : null,
            'potential'      => $potential ? array_merge($potential->toArray(), ['risks' => $potential->risks()]) : null,
            'suggestions'    => array_map(fn ($s) => $s->toArray(), $suggestions),
            'summary'        => $summary,
            'code_review'    => $codeReview,
        ];
    }

    /** @return array{0:string,1:array,2:array,3:array} [title, meta, sections, chart] */
    public function toExportParts(array $r, bool $ar): array
    {
        $t = fn (string $s) => ExportLocalizer::text($s);
        $dash = fn ($v) => ($v === null || $v === '' || $v === []) ? '-' : (is_array($v) ? implode('، ', $v) : (string) $v);
        $num = fn ($v) => $v === null ? '-' : (string) round((float) $v);

        $title = 'AI Analysis Report - ' . $r['project']['title'];
        $meta = [
            'Project'      => $r['project']['title'],
            'Status'       => $r['project']['status'],
            'Generated At' => date('Y-m-d H:i:s'),
        ];

        $sections = [];
        if (!empty($r['summary'])) {
            $sections[] = ['title' => 'AI Project Summary', 'header' => ['Summary'], 'rows' => [[$r['summary']]]];
        }
        $s = $r['readiness'];
        $chart = ['labels' => [], 'values' => []];
        if ($s) {
            $sections[] = ['title' => 'AI Readiness Score', 'header' => ['Metric', 'Score'], 'rows' => [
                [$t('Overall'), $num($s['overall_score'] ?? null)],
                [$t('Technical'), $num($s['technical_score'] ?? null)],
                [$t('Market'), $num($s['market_score'] ?? null)],
                [$t('Innovation'), $num($s['innovation_score'] ?? null)],
                [$t('Presentation'), $num($s['presentation_score'] ?? null)],
            ]];
            $chart = [
                'labels' => ['Overall', 'Technical', 'Market', 'Innovation', 'Presentation'],
                'values' => [(float) ($s['overall_score'] ?? 0), (float) ($s['technical_score'] ?? 0), (float) ($s['market_score'] ?? 0), (float) ($s['innovation_score'] ?? 0), (float) ($s['presentation_score'] ?? 0)],
            ];
        }
        if ($c = $r['classification']) {
            $sections[] = ['title' => 'Classification', 'header' => ['Category', 'Confidence %', 'Alternatives'],
                'rows' => [[$dash($c['predicted_category'] ?? null), $num($c['confidence'] ?? null), $dash($c['alternatives'] ?? [])]]];
        }
        if ($p = $r['potential']) {
            $sections[] = ['title' => 'Practical Value', 'header' => ['Score', 'Audience / Scope', 'Strengths'],
                'rows' => [[$num($p['potential_score'] ?? null), $dash($p['market_size_estimate'] ?? null), $dash($p['competitive_edge'] ?? null)]]];
            if (!empty($p['risks'])) {
                $sections[] = ['title' => 'Limitations & Risks', 'header' => ['Item'], 'rows' => array_map(fn ($x) => [(string) $x], $p['risks'])];
            }
        }
        if ($r['suggestions']) {
            $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
            $list = $r['suggestions'];
            usort($list, fn ($a, $b) => ($rank[$a['priority'] ?? 'medium'] ?? 1) <=> ($rank[$b['priority'] ?? 'medium'] ?? 1));
            $sections[] = ['title' => 'Improvement Suggestions', 'header' => ['Priority', 'Category', 'Suggestion'],
                'rows' => array_map(fn ($x) => [$t(ucfirst((string) ($x['priority'] ?? 'medium'))), $t(ucfirst(str_replace('_', ' ', (string) ($x['category'] ?? 'other')))), (string) $x['suggestion']], $list)];
        }

        // + تقرير مراجعة الكود الكامل (ملاحظات بملف/سطر، كود المشكلة والإصلاح، نقاط القوة، تقسيم الهيكل، اللغات) لو موجود
        if (!empty($r['code_review'])) {
            [, , $crSections, $crChart] = app(CodeReviewReportService::class)->toExportParts($r['code_review'], $ar);
            foreach ($crSections as $sec) {
                $sec['title'] = 'Code Review - ' . $sec['title'];
                $sections[] = $sec;
            }
            if (!$chart['labels']) {
                $chart = $crChart;
            }
        }

        return [$title, $meta, $sections, $chart];
    }

    /** @return array{0:string,1:string,2:string}|null [content, contentType, filename] */
    public function render(Project $project, string $format, bool $ar): ?array
    {
        $report = $this->build($project);
        if (!$report) {
            return null;
        }
        if (!in_array($format, self::FORMATS, true)) {
            $format = 'pdf';
        }
        [$title, $meta, $sections, $chart] = $this->toExportParts($report, $ar);
        [$title, $meta, $sections] = ExportLocalizer::report($title, $meta, $sections);

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $name = 'ai_analysis_' . preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $project->uuid) . '_' . date('Ymd_His') . '.' . $format;
        $path = $tmpDir . '/' . $name;

        try {
            switch ($format) {
                case 'csv':
                    $csv = array_map(fn ($s) => ['title' => $s['title'], 'header' => $s['header'], 'rows' => $s['rows']], $sections);
                    array_unshift($csv, ['title' => $title, 'header' => array_keys($meta), 'rows' => [array_values($meta)]]);
                    CsvWriter::writeSections($path, $csv);
                    $mime = 'text/csv';
                    break;
                case 'xlsx':
                    $header = ExportLocalizer::headers(['Field', 'Value']);
                    $rows = [];
                    foreach ($meta as $k => $v) { $rows[] = [$k, $v]; }
                    foreach ($sections as $section) {
                        $rows[] = ['', ''];
                        $rows[] = ['## ' . $section['title'], ''];
                        if ($section['header']) { $rows[] = $section['header']; }
                        foreach ($section['rows'] as $r) { $rows[] = array_values($r); }
                    }
                    $maxCols = max(array_map('count', array_merge([$header], $rows)));
                    $header = array_pad($header, $maxCols, '');
                    $rows = array_map(fn ($r) => array_pad($r, $maxCols, ''), $rows);
                    SpreadsheetWriter::write($path, $header, $rows, $ar);
                    $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
                case 'json':
                    JsonWriter::write($path, ['title' => $title, 'meta' => $meta, 'sections' => $sections, 'report' => $report]);
                    $mime = 'application/json';
                    break;
                case 'pdf':
                    PdfWriter::writeReport($path, $title, $meta, $sections, $chart);
                    $mime = 'application/pdf';
                    break;
                default:
                    ReportDocumentWriter::write($format, $path, $title, $meta, $sections, $ar);
                    $mime = ReportDocumentWriter::mime($format);
            }
            $content = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
        return [$content, $mime, $name];
    }
}
