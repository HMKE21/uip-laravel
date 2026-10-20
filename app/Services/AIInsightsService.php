<?php

namespace App\Services;

use App\Models\AIInsightReport;
use App\Repositories\AIInsightRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIInsightsService.php القديمة — بند 24 batch 5
 * (AI Insights، enhancement spec section 4). محرك الـ AI analytics
 * الحقيقي: بيبني digest مختصر لمقاييس المنصة الحية (AnalyticsService +
 * KpiService + DataQualityService — نفس الأرقام المحسوبة اللي ظاهرة
 * فعليًا في Analytics Dashboard/KPI Management/Data Quality Center)
 * وبيبعتها لـ App\Services\Ai\AIClient طالبًا تقرير JSON منظّم يغطي كل
 * قسم مذكور في الـ spec: insights, trends, correlations, anomalies,
 * recommendations, root cause analysis, executive summary, kpi
 * analysis, risk detection, opportunity detection, performance
 * recommendations.
 *
 * نفس عقد "أبدًا مش مختلق": لو الـ AI client مش مُعدّ أو النداء/الفك
 * فشل، بترمي — الكنترولر بيسجل صف history بحالة 'failed' (شوف
 * AIInsightRepository) وبيعرض خطأ حقيقي، أبدًا مش تقرير شكله مختلق.
 * تشغيلة ناجحة بتتسجل كصف 'completed' جديد (الـ history append-only،
 * فـ"regenerate كل ما البيانات تتغير" أبدًا مابيمسحش التقرير السابق).
 */
class AIInsightsService
{
    use ConfiguresAIClient;

    /** كل مفتاح متوقّع من الواجهة — رد completeJson() بيتطبّع عليه عشان رد جزئي من الـ AI أبدًا مايكسرش العرض. */
    private const SCHEMA_KEYS = [
        'executive_summary', 'health_overview', 'insights', 'trends', 'correlations', 'anomalies',
        'recommendations', 'root_cause_analysis', 'kpi_analysis', 'risks',
        'opportunities', 'data_limitations',
        // قديم: كان قسم منفصل بيكرر "recommendations" واتدمج فيها. بنحافظ على المفتاح عشان تقارير الـ history القديمة تفضل تتعرض.
        'performance_recommendations',
    ];

    /** المجالات اللي لازم health_overview يغطيها (area بالـ slug ده بالظبط، الواجهة بتترجمه). */
    private const HEALTH_AREAS = ['users', 'projects', 'universities', 'exams', 'integrity', 'security', 'ai_usage', 'visitors', 'data_quality'];

    private AIClient $client;

    public function __construct(
        AIClient $client,
        SettingRepository $settings,
        private AIInsightRepository $repo,
        private AnalyticsService $analytics,
        private KpiService $kpis,
        private DataQualityService $quality,
        private DataAnalysisAcademicService $academic,
        private AIInsightsDigest $digest
    ) {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $settings);
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    public function latestCompleted(): ?array
    {
        return $this->repo->latestCompleted();
    }

    public function latestAny(): ?array
    {
        return $this->repo->latestAny();
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $limit = 10): array
    {
        return $this->repo->history($limit);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->repo->find($id);
    }

    /** @param int[] $ids @return int عدد الصفوف اللي اتمسحت */
    public function deleteByIds(array $ids): int
    {
        return $this->repo->deleteByIds($ids);
    }

    /** @return int عدد الصفوف اللي اتمسحت */
    public function deleteAll(): int
    {
        return $this->repo->deleteAll();
    }

    /**
     * بتشغّل التحليل فوق digest بيانات حقيقية طازة وبتخزّن النتيجة. دايمًا
     * بتكتب صف history، حتى لو فشلت.
     * @throws \RuntimeException لو مش مُعدّ أو نداء/فك الـ AI فشل (بعد تسجيل الفشل)
     */
    public function generate(int $userId): AIInsightReport
    {
        $snapshot = $this->buildSnapshot();

        try {
            $language = app()->getLocale() === 'ar'
                ? 'Write the ENTIRE report in Modern Standard Arabic (Fusha) — every human-readable string value must be Arabic prose. Keep JSON keys and the fixed enum values ("up"/"down"/"flat", "strong"/"moderate"/"weak", "low"/"medium"/"high", "good"/"watch"/"critical", and the health "area" slugs) in English exactly as specified. Copy numbers, category names, KPI names and university names from the digest as given, without translating them.'
                : 'Write the entire report in English.';

            $user = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $areas = implode('", "', self::HEALTH_AREAS);

            $rules = 'You are a senior business-intelligence analyst for a university innovation & startup platform. '
                . 'You receive a JSON digest of REAL, live platform metrics covering: users (roles, status, activity, 2FA), user_growth, projects, universities, '
                . 'exams_and_academic (results, pass rate, score bands, at-risk students, integrity/appeals, doctor activity), security, ai_usage, visitors, KPIs, '
                . 'category growth and data quality. '
                . 'ACCURACY RULES (strict): '
                . '(1) Every claim must be backed by a number that is literally in the digest. Never invent, estimate or round a figure differently from the digest. '
                . '(2) Never compute new percentages or differences yourself — use the *_pct / *_change_pct fields provided. If a value is null, no comparison is possible: do not state one. '
                . '(3) A month or period flagged is_partial=true is unfinished. Never call it a drop, decline or low; compare only complete months or the 30-day windows. '
                . '(4) Do not claim causation. Phrase causes as "possible"/"may" and only when the digest supports them. '
                . '(5) A small base (under ~20) makes a percentage unreliable — say so or skip it. '
                . 'NO-FILLER RULES: each fact appears in exactly ONE section of the report — never restate the same finding in another section. '
                . 'Skip generic advice that would apply to any platform. Skip an item rather than pad: fewer, sharper items beat the maximum. '
                . 'A section with nothing meaningful gets an empty array. One concise sentence per item. '
                . $language . ' ';

            $partA = $rules
                . 'Reply with ONLY one JSON object with exactly these keys: '
                . '"executive_summary" (string, 2-3 sentences: overall state + the single most important thing needing attention), '
                . '"health_overview" (array of {"area":"' . $areas . '","status":"good"|"watch"|"critical","summary":string} — one entry per area that has data in the digest, a short statement with its key number; skip areas with no data), '
                . '"insights" (array, up to 5 strings — notable facts NOT already covered as a risk, anomaly or trend), '
                . '"trends" (array, up to 5 of {"label":string,"description":string,"direction":"up"|"down"|"flat"} — only real movements between comparable periods), '
                . '"anomalies" (array, up to 4 of {"description":string,"severity":"low"|"medium"|"high"} — values that clearly break from the rest of the data).';

            $partB = $rules
                . 'Reply with ONLY one JSON object with exactly these keys: '
                . '"correlations" (array, up to 3 of {"description":string,"strength":"strong"|"moderate"|"weak"} — only if two metrics in the digest genuinely move together; otherwise empty), '
                . '"risks" (array, up to 4 of {"description":string,"severity":"low"|"medium"|"high"} — forward-looking threats, not restated anomalies), '
                . '"opportunities" (array, up to 3 of {"description":string,"potential_impact":"low"|"medium"|"high"}), '
                . '"root_cause_analysis" (array, up to 3 of {"issue":string,"likely_cause":string} — only for high/medium problems, cause stated as possible), '
                . '"recommendations" (array, up to 6 of {"action":string,"priority":"low"|"medium"|"high","reason":string} — each tied to a specific number; order by priority; no overlap with the other sections\' wording), '
                . '"kpi_analysis" (array, one entry per KPI in the digest of {"kpi":string,"analysis":string}; empty array if the digest has no KPIs), '
                . '"data_limitations" (array of short strings — digest sections listed in data_gaps, null values, tiny samples or missing data that limit what can be concluded).';

            // نداءين بدل واحد: كل رد أصغر وأسرع وأقل عرضة لتخطي AI_TIMEOUT، والبرومبتين بياخدوا نفس الـ digest.
            $rawA = $this->completeJson($this->client, $partA, $user);
            $rawB = $this->completeJson($this->client, $partB, $user);

            $result = $this->normalize(array_merge($rawA, $rawB));
            $result['language'] = app()->getLocale() === 'ar' ? 'ar' : 'en'; // لغة التقرير، عشان الواجهة تعرف لو مختلفة عن لغة العرض

            return $this->repo->record([
                'status'             => 'completed',
                'result_json'        => json_encode($result, JSON_UNESCAPED_UNICODE),
                'data_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'model_used'         => 'configured-provider',
                'generated_by'       => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->repo->record([
                'status'             => 'failed',
                'result_json'        => null,
                'data_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'error_message'      => mb_substr($e->getMessage(), 0, 500),
                'model_used'         => null,
                'generated_by'       => $userId,
            ]);
            throw new \RuntimeException('AI Insights generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** بيتأكد كل مفتاح schema موجود بالشكل الصح، عشان الواجهة أبدًا ماتخمنش. */
    private function normalize(array $raw): array
    {
        $out = [];
        foreach (self::SCHEMA_KEYS as $key) {
            if ($key === 'executive_summary') {
                $out[$key] = is_string($raw[$key] ?? null) ? trim($raw[$key]) : '';
                continue;
            }
            $out[$key] = is_array($raw[$key] ?? null) ? array_values($raw[$key]) : [];
        }
        return $out;
    }

    /** digest حقيقي وحي — الحاجة الوحيدة المسموح للموديل يفكر فيها. */
    private function buildSnapshot(): array
    {
        $categoryDistribution = $this->analytics->categoryDistribution(8);
        $categoryGrowth = $this->analytics->categoryGrowthTrends(30, 8);

        $kpis = array_map(fn ($k) => [
            'name'            => $k['name'],
            'category'        => $k['category'],
            'unit'            => $k['unit'],
            'current_value'   => (float) $k['current_value'],
            'target_value'    => (float) $k['target_value'],
            'direction'       => $k['direction'],
            'trend'           => $k['trend'],
            'growth_rate_pct' => $k['growth_rate'],
            'achievement_pct' => $k['achievement_pct'],
            'alert_triggered' => $k['alert_triggered'],
        ], array_slice($this->kpis->list('active'), 0, 12)); // محدودة: بتخلي حجم البرومبت/الرد (وبالتالي الـ latency) محدود على المنصات اللي فيها KPIs كتير

        $qualityReports = $this->quality->overview();
        $qualitySummary = array_map(fn ($r) => [
            'dataset'      => $r['label']['en'] ?? $r['key'],
            'score'        => $r['score'],
            'total_rows'   => $r['total_rows'],
            'completeness' => $r['completeness']['pct'] ?? null,
        ], $qualityReports);

        $wide = $this->digest->build();
        $wide['notes'] = 'In any monthly series that has no is_partial flag (e.g. exams_and_academic.monthly_avg), the most recent month is the current month and may be incomplete — do not treat it as a decline.';

        return array_merge([
            'generated_at'          => date('c'),
            // platformOverview().new_users_this_month و userGrowthSeries() اتشالوا عن عمد: بيحسبوا الشهر الحالي الناقص كأنه كامل
            // (كان سبب "انخفاض حاد" غلط). الأرقام الصح في users / user_growth تحت.
        ], $wide, [
            'category_distribution' => array_map(fn ($r) => ['category' => $r['label']['en'], 'value' => $r['value']], $categoryDistribution),
            'category_growth_30d'   => array_map(fn ($r) => [
                'category' => $r['label']['en'], 'current' => $r['current'], 'previous' => $r['previous'], 'growth_pct' => $r['growth_pct'],
            ], $categoryGrowth),
            'kpis'                  => $kpis,
            // منصة الامتحانات (أرقام مجمّعة بس، من غير أسماء طلاب/دكاترة). أي monthly_avg فيها: آخر شهر ممكن يكون ناقص.
            'exams_and_academic'    => $this->academic->aiDigest(),
            'data_quality_overview' => [
                'overall_score' => $this->quality->overallScore($qualityReports),
                'datasets'      => $qualitySummary,
            ],
        ]);
    }
}
