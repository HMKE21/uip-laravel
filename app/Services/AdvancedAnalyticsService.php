<?php

namespace App\Services;

use App\Repositories\AdvancedAnalyticsRepository;
use App\Repositories\DataExplorerRepository;

/**
 * منقولة من app/Services/AdvancedAnalyticsService.php القديمة — بند
 * 24 batch 3 (Advanced Analytics، enhancement spec section 11).
 * أوركستريشن خفيف فوق AdvancedAnalyticsRepository، بيستخدم نفس كتالوج
 * الست datasets بتاع Data Explorer (config data_explorer_datasets) عشان
 * كل تحليل هنا يكون محدود بنفس الجداول المسموحة والمحمية من الأعمدة
 * الحساسة. روابط الـ drill-through بترجع لنفس فلاتر Data Explorer
 * الموجودة بالفعل (filter_column[]/filter_op[]/filter_value[]) — مفيش
 * آلية drill جديدة كانت لازمة.
 */
class AdvancedAnalyticsService
{
    public function __construct(
        private AdvancedAnalyticsRepository $repo,
        private DataExplorerRepository $explorerRepo
    ) {
    }

    /** @return array<int,array<string,mixed>> الست catalog datasets المسموحة، لقائمة الاختيار */
    public function datasets(): array
    {
        $out = [];
        foreach ($this->explorerRepo->catalog() as $key => $entry) {
            $out[] = array_merge(['key' => $key], $entry);
        }
        return $out;
    }

    public function dataset(string $key): ?array
    {
        return $this->explorerRepo->dataset($key);
    }

    /** تصنيف أعمدة لـ dataset واحد، تُستخدم لتعبئة قوائم اختيار التحليل. */
    public function columnOptions(array $dataset): array
    {
        $sensitive = $dataset['sensitive_columns'] ?? [];
        return [
            'numeric'     => $this->repo->numericColumns($dataset['table'], $sensitive),
            'date'        => $this->repo->dateColumns($dataset['table'], $sensitive),
            'categorical' => $this->repo->categoricalColumns($dataset['table'], $sensitive),
        ];
    }

    public function statistics(array $dataset, string $column): array
    {
        return $this->repo->statistics($dataset['table'], $column);
    }

    public function correlationMatrix(array $dataset, array $numericColumns): array
    {
        return $this->repo->correlationMatrix($dataset['table'], $numericColumns);
    }

    public function pivot(array $dataset, string $rowDim, string $colDim, ?string $valueCol, string $aggFn): array
    {
        return $this->repo->pivot($dataset['table'], $rowDim, $colDim, $valueCol, $aggFn);
    }

    public function timeSeries(array $dataset, string $dateColumn, int $months = 12): array
    {
        return $this->repo->timeSeries($dataset['table'], $dateColumn, $months);
    }

    public function comparative(array $dataset, string $dateColumn, int $days = 30): array
    {
        return $this->repo->comparative($dataset['table'], $dateColumn, $days);
    }

    public function userCohortRetention(): array
    {
        return $this->repo->userCohortRetention();
    }

    /**
     * عدد الجامعات/المشاريع الحقيقي لكل دولة (repository)، مُثرى بإحداثيات
     * lat/lng ثابتة لو اسم الدولة اتطابق مع config/country_centroids.php
     * — بتُستخدم لرسم نقاط خريطة حقيقية. دولة مش موجودة في الجدول ده
     * لسه بترجع has_coordinates = false بدل موقع مُختلق.
     *
     * universities.country نص حر، فنفس الدولة الحقيقية ممكن تظهر بأكتر
     * من إملاء (مثلًا "مصر" و"Egypt") وGROUP BY u.country بتاعة
     * الـ repository بترجعهم كصفوف منفصلة. config/country_aliases.php
     * (لو موجود) بيربط الإملاءات العربية المعروفة باسمها الإنجليزي
     * القياسي (نفس المفتاح المستخدم بالظبط في
     * config/country_centroids.php)؛ الصفوف اللي بترجع لنفس الدولة
     * القياسية بتتجمع هنا، بعد ما العدادات المجمّعة اتحسبت بالفعل، فمفيش
     * عدد مُختلق — بس مجموع. إملاء مش مغطى في قايمة الـ aliases بيفضل
     * زي ما هو مخزّن بالظبط (نفس قاعدة "متختلقش" اللي فحص الـ centroid
     * ماشي عليها بالفعل).
     */
    public function geographicDistribution(): array
    {
        $centroids = config('country_centroids', []);
        $aliases = config('country_aliases', []);
        $rows = $this->repo->geographicDistribution();

        $merged = [];
        foreach ($rows as $row) {
            $raw = trim((string) $row['country']);
            $canonical = $aliases[$raw] ?? $raw;
            $groupKey = mb_strtolower($canonical);

            if (!isset($merged[$groupKey])) {
                $merged[$groupKey] = [
                    'country'          => $canonical,
                    'university_count' => 0,
                    'project_count'    => 0,
                ];
            }
            $merged[$groupKey]['university_count'] += (int) $row['university_count'];
            $merged[$groupKey]['project_count'] += (int) $row['project_count'];
        }

        $out = array_map(function ($row) use ($centroids) {
            $centroid = $centroids[mb_strtolower(trim($row['country']))] ?? null;
            $row['lat'] = $centroid[0] ?? null;
            $row['lng'] = $centroid[1] ?? null;
            $row['has_coordinates'] = $centroid !== null;
            return $row;
        }, array_values($merged));

        usort($out, fn ($a, $b) => $b['project_count'] <=> $a['project_count']
            ?: $b['university_count'] <=> $a['university_count']);

        return $out;
    }

    /** بتبني رابط drill-through آمن لـ Data Explorer، متفلتر مسبقًا على قيمة الخلية دي بالضبط. */
    public function drillThroughUrl(string $datasetKey, string $column, string $value): string
    {
        $query = http_build_query([
            'tab'           => 'preview',
            'filter_column' => [$column],
            'filter_op'     => ['eq'],
            'filter_value'  => [$value],
        ]);
        return '/data-analysis/data-explorer/' . $datasetKey . '?' . $query;
    }
}
