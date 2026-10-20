<?php

namespace App\Services;

use App\Repositories\ForecastRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/ForecastingService.php القديمة — بند 24
 * batch 3 (Forecasting، enhancement spec section 5). توقع حقيقي فوق
 * السلاسل الشهرية الحية بتاعة ForecastRepository: انحدار خطي (ordinary
 * least squares) على آخر $historyMonths من البيانات الفعلية، متوقّع
 * قدام $forecastMonths، مع نطاق ثقة 95% حقيقي مبني على الـ residual
 * standard error بتاع الانحدار نفسه ومقاييس دقة داخل العينة (MAE/RMSE/
 * R²) — مش أرقام مُختلقة. "AI Explanation" اختياري ومنفصل: قراءة نثرية
 * قصيرة لنفس الأرقام الحقيقية عبر AIClient، بنفس عقد "أبدًا ماتختلقش،
 * ارمي لو فشل" بتاع AIInsightsService. مفيش حاجة هنا متخزّنة — كل نداء
 * بيتحسب من جديد من البيانات الحالية، فالتوقع دايمًا بيعكس "البيانات
 * دلوقتي" حسب فكرة الـ spec "إعادة التوليد كل ما البيانات تتغير"، من
 * غير ما نحتاج جدول تاريخ.
 */
class ForecastingService
{
    use ConfiguresAIClient;

    /**
     * key => [method, وحدة, تسمية en/ar, وصف en/ar]
     * unit: 'count' (أعداد صحيحة غير سالبة) أو 'percent' (0-100) أو 'score'.
     */
    private const METRICS = [
        'student_growth' => [
            'method' => 'studentGrowth', 'unit' => 'count',
            'label' => ['en' => 'Student Growth', 'ar' => 'نمو الطلاب'],
            'description' => [
                'en' => 'New student accounts registered per month.',
                'ar' => 'حسابات الطلاب الجدد المسجَّلة شهريًا.',
            ],
        ],
        'project_growth' => [
            'method' => 'projectGrowth', 'unit' => 'count',
            'label' => ['en' => 'Project Growth', 'ar' => 'نمو المشاريع'],
            'description' => [
                'en' => 'All projects submitted per month, across every status.',
                'ar' => 'كل المشاريع المُقدَّمة شهريًا بمختلف حالاتها.',
            ],
        ],
        'university_performance' => [
            'method' => 'universityPerformance', 'unit' => 'count',
            'label' => ['en' => 'University Performance', 'ar' => 'أداء الجامعات'],
            'description' => [
                'en' => 'Universities that reached verified status per month.',
                'ar' => 'الجامعات التي وصلت لحالة موثّقة شهريًا.',
            ],
        ],
        'innovation_trends' => [
            'method' => 'innovationTrends', 'unit' => 'count',
            'label' => ['en' => 'Innovation Trends', 'ar' => 'اتجاهات الابتكار'],
            'description' => [
                'en' => 'Projects actually published per month.',
                'ar' => 'المشاريع المنشورة فعليًا شهريًا.',
            ],
        ],
        'ai_readiness_trends' => [
            'method' => 'aiReadinessTrends', 'unit' => 'score',
            'label' => ['en' => 'AI Readiness Trends', 'ar' => 'اتجاهات جاهزية الذكاء الاصطناعي'],
            'description' => [
                'en' => 'Average AI Readiness Score (0-100) of projects first scored each month.',
                'ar' => 'متوسط درجة جاهزية الذكاء الاصطناعي (0-100) للمشاريع التي قُيِّمت لأول مرة كل شهر.',
            ],
        ],
    ];

    public function __construct(
        private ForecastRepository $repo,
        private AIClient $client,
        SettingRepository $settings
    ) {
        $this->applySettingsOverrides($this->client, $settings);
    }

    public function isAiAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /** @return array<string,array<string,mixed>> سجل المقاييس، key => label/description/unit */
    public function metrics(): array
    {
        return self::METRICS;
    }

    public function metric(string $key): ?array
    {
        return self::METRICS[$key] ?? null;
    }

    /**
     * حمولة توقع كاملة لمقياس واحد: السلسلة التاريخية، إسقاط للأمام مع
     * نطاق ثقة 95%، تشخيص دقة، مقارنة تاريخية حديث-مقابل-سابق، واتجاه
     * عام.
     * @throws \InvalidArgumentException لمفتاح مقياس غير معروف
     */
    public function forecast(string $key, int $historyMonths = 12, int $forecastMonths = 3): array
    {
        $meta = $this->metric($key);
        if (!$meta) {
            throw new \InvalidArgumentException("Unknown forecast metric: {$key}");
        }

        $history = $this->repo->{$meta['method']}($historyMonths);
        $values = array_map(fn ($p) => $p['value'], $history);
        $reg = $this->linearRegression($values);

        $isPercent = $meta['unit'] === 'percent';
        $clamp = function (float $v) use ($isPercent) {
            if ($isPercent) {
                return max(0.0, min(100.0, $v));
            }
            return max(0.0, $v);
        };

        $n = count($values);
        $lastMonth = $history ? $history[$n - 1]['month'] : date('Y-m');

        $forecastPoints = [];
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $x = $n - 1 + $i;
            $predicted = $reg['intercept'] + $reg['slope'] * $x;
            $predicted = $clamp($predicted);
            $margin = 1.96 * $reg['std_error'];
            $forecastPoints[] = [
                'month' => date('Y-m', strtotime($lastMonth . '-01 +' . $i . ' months')),
                'value' => round($predicted, 1),
                'lower' => round($clamp($predicted - $margin), 1),
                'upper' => round($clamp($predicted + $margin), 1),
            ];
        }

        $recentAvg = $this->average(array_slice($values, -3));
        $priorAvg = $this->average(array_slice($values, -6, 3));
        $comparisonPct = $priorAvg > 0 ? round((($recentAvg - $priorAvg) / $priorAvg) * 100, 1) : null;

        return [
            'key'                    => $key,
            'label'                  => $meta['label'],
            'description'            => $meta['description'],
            'unit'                   => $meta['unit'],
            'history'                => $history,
            'forecast'               => $forecastPoints,
            'trend_direction'        => $reg['slope'] > 0.01 ? 'up' : ($reg['slope'] < -0.01 ? 'down' : 'flat'),
            'confidence_level'       => 95,
            'accuracy'               => [
                'mae'  => round($reg['mae'], 2),
                'rmse' => round($reg['rmse'], 2),
                'r2'   => round($reg['r2'], 3),
            ],
            'historical_comparison'  => [
                'recent_3mo_avg' => round($recentAvg, 1),
                'prior_3mo_avg'  => round($priorAvg, 1),
                'change_pct'     => $comparisonPct,
            ],
            'generated_at' => date('c'),
        ];
    }

    /** @return array<int,array<string,mixed>> forecast() لكل مقياس مسجّل، لصفحة النظرة العامة */
    public function forecastAll(int $historyMonths = 12, int $forecastMonths = 3): array
    {
        $out = [];
        foreach (array_keys(self::METRICS) as $key) {
            $out[$key] = $this->forecast($key, $historyMonths, $forecastMonths);
        }
        return $out;
    }

    /**
     * انحدار خطي بسيط (least squares) على y=values، x=0..n-1. بترجع
     * الميل، التقاطع، الـ residual standard error (مستخدم في نطاق
     * الثقة)، وتشخيص دقة داخل العينة MAE/RMSE/R² صادق — انحدار حقيقي،
     * مش رقم ثقة جاهز.
     */
    private function linearRegression(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            $only = $values[0] ?? 0.0;
            return ['slope' => 0.0, 'intercept' => $only, 'std_error' => 0.0, 'mae' => 0.0, 'rmse' => 0.0, 'r2' => 0.0];
        }

        $xs = range(0, $n - 1);
        $xMean = array_sum($xs) / $n;
        $yMean = array_sum($values) / $n;

        $num = 0.0;
        $den = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $xMean) * ($values[$i] - $yMean);
            $den += ($x - $xMean) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $intercept = $yMean - $slope * $xMean;

        $residuals = [];
        $sst = 0.0;
        $sse = 0.0;
        foreach ($xs as $i => $x) {
            $predicted = $intercept + $slope * $x;
            $residual = $values[$i] - $predicted;
            $residuals[] = $residual;
            $sse += $residual ** 2;
            $sst += ($values[$i] - $yMean) ** 2;
        }

        $mae = array_sum(array_map('abs', $residuals)) / $n;
        $rmse = sqrt($sse / $n);
        $r2 = $sst > 0 ? max(0.0, 1 - ($sse / $sst)) : 0.0;
        // Residual standard error بـ (n-2) درجة حرية، معيارية للانحدار الخطي البسيط.
        $stdError = $n > 2 ? sqrt($sse / ($n - 2)) : $rmse;

        return ['slope' => $slope, 'intercept' => $intercept, 'std_error' => $stdError, 'mae' => $mae, 'rmse' => $rmse, 'r2' => $r2];
    }

    private function average(array $values): float
    {
        $values = array_values($values);
        return $values ? array_sum($values) / count($values) : 0.0;
    }

    /**
     * شرح AI قصير لتوقع اتحسب بالفعل (من forecast() فوق) — الموديل بس
     * بيسرد أرقام حقيقية اتديله، عمره ما بيخترع توقعه هو. بيرمي (عمرها
     * ماتختلق) لو الـ AI client مش مُعدّ أو النداء فشل، نفس عقد أي
     * خدمة AI-* تانية.
     * @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل
     */
    public function explain(array $forecastPayload): string
    {
        if (!$this->isAiAvailable()) {
            throw new \RuntimeException('AI provider is not configured yet — ask an admin to add an API key under Settings → AI.');
        }

        $language = app()->getLocale() === 'ar'
            ? 'Reply entirely in Modern Standard Arabic (Fusha).'
            : 'Reply entirely in English.';

        $system = 'You are a business intelligence analyst explaining a statistical forecast (ordinary least-squares linear '
            . 'regression) to a non-technical stakeholder. You are given the real historical monthly series, the projected '
            . 'next few months with a 95% confidence band, and accuracy diagnostics (MAE/RMSE/R²). Write a short, honest '
            . '3-5 sentence explanation: what the trend is, how confident the projection is (referencing R² and the '
            . 'confidence band plainly, e.g. "low confidence" if R² is weak), and one practical takeaway. Reference the '
            . 'actual numbers given — never invent figures beyond what is given. ' . $language;

        $user = json_encode($forecastPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $raw = $this->client->complete($system, (string) $user, 0.4);

        // نفس quirk الموديلات اللي بتعمل reasoning زي completeJson() في
        // ConfiguresAIClient: بعض المزوّدين (زي minimax-m3:cloud على
        // Ollama cloud) بيحطوا <think>...</think> قبل حتى رد نثري عادي.
        $clean = preg_replace('/<think>.*?<\/think>/is', '', $raw);
        return trim((string) $clean);
    }
}
