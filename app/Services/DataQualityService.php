<?php

namespace App\Services;

use App\Repositories\DataExplorerRepository;
use App\Repositories\DataQualityRepository;
use Illuminate\Support\Facades\Cache;

/**
 * منقولة من app/Services/DataQualityService.php القديمة — بند 24
 * batch 3 (Data Quality Center، enhancement spec section 6). أوركستريشن
 * فوق DataQualityRepository عبر الست catalog datasets، وبتحوّل كل تقرير
 * لاقتراحات تنظيف مقروءة. زي توصيات KpiService، دي محرك rule-based
 * شفاف فوق الأرقام المحسوبة الحقيقية — مش نداء لموديل AI.
 */
class DataQualityService
{
    /**
     * overview() بتفحص كل catalog dataset بحثًا عن صفوف ناقصة/مكررة/
     * غير صالحة/شاذة إلخ — استعلامات aggregate حقيقية فوق جداول ممكن
     * تكون كبيرة، بتتكرر في كل مرة تُفتح فيها صفحة Data Quality Center.
     * مفيش حاجة في البورتال ده *بتكتب* في جداول المنصة الأساسية
     * (projects/users/universities/...) اللي بتقرر عليها — دي بتتغيّر في
     * كل حتة تانية من التطبيق — فمفيش مسار كتابة واحد نربط عليه
     * invalidation. TTL قصير هو المفاضلة الصح: الدرجة "حديثة خلال
     * CACHE_TTL_SECONDS"، مش قديمة للأبد، من غير ما نفحص ست جداول في
     * كل طلب.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private DataQualityRepository $quality,
        private DataExplorerRepository $explorer
    ) {
    }

    /** @return array<int,array<string,mixed>> تقرير واحد منقّى لكل catalog dataset */
    public function overview(): array
    {
        return Cache::remember('data_quality:overview', self::CACHE_TTL_SECONDS, function () {
            $out = [];
            foreach ($this->explorer->catalog() as $key => $entry) {
                $dataset = array_merge(['key' => $key], $entry);
                $report = $this->quality->report($dataset);
                $report['key'] = $key;
                $report['label'] = $dataset['label'];
                $report['icon'] = $dataset['icon'] ?? 'grid';
                $report['suggestions'] = $this->suggestions($report);
                $out[] = $report;
            }
            return $out;
        });
    }

    public function forDataset(string $key): ?array
    {
        $dataset = $this->explorer->dataset($key);
        if (!$dataset) {
            return null;
        }
        $report = $this->quality->report($dataset);
        $report['key'] = $key;
        $report['label'] = $dataset['label'];
        $report['icon'] = $dataset['icon'] ?? 'grid';
        $report['suggestions'] = $this->suggestions($report);
        return $report;
    }

    /** الدرجة الإجمالية للبورتال كله: متوسط درجة كل dataset. */
    public function overallScore(array $reports): ?float
    {
        $scores = array_filter(array_column($reports, 'score'), fn ($s) => $s !== null);
        return $scores ? round(array_sum($scores) / count($scores), 1) : null;
    }

    private function suggestions(array $report): array
    {
        $suggestions = [];

        if ($report['completeness']['pct'] !== null && $report['completeness']['pct'] < 90) {
            $worst = $report['completeness']['columns'][0] ?? null;
            if ($worst && $worst['missing_pct'] > 0) {
                $suggestions[] = sprintf(
                    'Review missing values in "%s" (%s%% empty) — consider a default value or a required-field rule.',
                    $worst['column'],
                    $worst['missing_pct']
                );
            }
        }

        if ($report['duplicates']['group_count'] > 0) {
            $suggestions[] = sprintf(
                '%d duplicate row group(s) found (%d rows total) — consider a deduplication pass or a uniqueness constraint.',
                $report['duplicates']['group_count'],
                $report['duplicates']['row_count']
            );
        }

        foreach ($report['outliers'] as $o) {
            $suggestions[] = sprintf(
                '%d statistical outlier(s) in "%s" (mean %.2f, stddev %.2f) — verify these are legitimate values.',
                $o['count'],
                $o['column'],
                $o['mean'],
                $o['stddev']
            );
        }

        foreach ($report['validity']['issues'] as $issue) {
            $suggestions[] = sprintf(
                '%d row(s) with an invalid %s format in "%s" — add format validation at input time.',
                $issue['invalid_count'],
                $issue['type'],
                $issue['column']
            );
        }

        if (($report['freshness']['days_since_last'] ?? 0) > 90) {
            $suggestions[] = sprintf(
                'No new records in %d days — check whether the data pipeline feeding this table is still running.',
                $report['freshness']['days_since_last']
            );
        }

        if (!$suggestions) {
            $suggestions[] = 'No issues detected by the built-in checks — data looks healthy.';
        }

        return $suggestions;
    }

    /** صفوف مسطّحة لتصدير CSV لتقرير dataset واحد. */
    public function exportRows(array $report): array
    {
        $rows = [
            ['Metric', 'Value'],
            ['Dataset', $report['key']],
            ['Total Rows', $report['total_rows']],
            ['Overall Score', $report['score'] ?? 'N/A'],
            ['Completeness %', $report['completeness']['pct'] ?? 'N/A'],
            ['Uniqueness %', $report['duplicates']['pct'] ?? 'N/A'],
            ['Duplicate Groups', $report['duplicates']['group_count']],
            ['Duplicate Rows', $report['duplicates']['row_count']],
            ['Validity %', $report['validity']['pct'] ?? 'N/A'],
            ['Days Since Last Record', $report['freshness']['days_since_last'] ?? 'N/A'],
        ];
        foreach ($report['outliers'] as $o) {
            $rows[] = ["Outliers in {$o['column']}", $o['count']];
        }
        foreach ($report['suggestions'] as $i => $s) {
            $rows[] = ['Suggestion ' . ($i + 1), $s];
        }
        return $rows;
    }
}
