<?php

namespace App\Services;

use App\Repositories\KpiRepository;
use Illuminate\Support\Facades\Cache;

/**
 * منقولة من app/Services/KpiService.php القديمة — بند 24 batch 3 (KPI
 * Management، enhancement spec section 10). أوركستريشن + مقاييس مشتقة
 * فوق KpiRepository. كل الأرقام (trend/growth rate/achievement %/alert
 * state) محسوبة من current_value/target_value/history الحقيقية بتاعة
 * الـ KPI — مفيش حاجة وهمية.
 *
 * "AI Recommendations" هنا عمدًا محرك rule-based شفاف فوق الأرقام
 * الحقيقية (اتجاه الـ trend + achievement %)، مش نداء لـ LLM — المحرك
 * الحقيقي المدعوم بالذكاء الاصطناعي في الـ spec (section 4) شغلانة
 * أكبر ومنفصلة، ومش بديل ضمني هنا.
 */
class KpiService
{
    /**
     * enrich() بتعمل استعلام تاريخ حقيقي لكل KPI، فتكلفة list() بتكبر
     * مع عدد الـ KPIs. متخزّنة cache لكل status ('active'/'archived') —
     * TTL قصير كشبكة أمان، بس كل مسار كتابة في الكلاس ده
     * (create/update/delete/recordValue) بينسى المفتاحين فورًا بعد
     * الكتابة، فالمحلل دايمًا بيشوف تعديله بنفسه في نفس اللحظة بدل ما
     * يستنى انتهاء الـ TTL.
     */
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_KEYS = ['kpi:list:active', 'kpi:list:archived'];

    public function __construct(private KpiRepository $repo)
    {
    }

    /** @return array<int,array<string,mixed>> KPIs مع trend/growth/achievement/alert/recommendation/history */
    public function list(string $status = 'active'): array
    {
        return Cache::remember("kpi:list:{$status}", self::CACHE_TTL_SECONDS, function () use ($status) {
            return array_map([$this, 'enrich'], $this->repo->all($status));
        });
    }

    public function get(int $id): ?array
    {
        $kpi = $this->repo->find($id);
        return $kpi ? $this->enrich($kpi) : null;
    }

    public function create(array $attributes): int
    {
        $id = $this->repo->create($attributes);
        $this->forgetCache();
        return $id;
    }

    public function update(int $id, array $attributes): bool
    {
        $result = $this->repo->update($id, $attributes);
        $this->forgetCache();
        return $result;
    }

    public function delete(int $id): bool
    {
        $result = $this->repo->delete($id);
        $this->forgetCache();
        return $result;
    }

    public function recordValue(int $kpiId, float $value, ?string $note, ?int $recordedBy): void
    {
        $this->repo->recordValue($kpiId, $value, $note, $recordedBy);
        $this->forgetCache();
    }

    public function assignableUsers(): array
    {
        return $this->repo->assignableUsers();
    }

    private function forgetCache(): void
    {
        foreach (self::CACHE_KEYS as $key) {
            Cache::forget($key);
        }
    }

    private function enrich(array $kpi): array
    {
        $history = $this->repo->history((int) $kpi['id'], 60);
        $current = (float) $kpi['current_value'];
        $target = (float) $kpi['target_value'];
        $higherBetter = $kpi['direction'] === 'higher_better';

        // Growth rate + trend من آخر قيمتين حقيقيتين مسجّلتين.
        $growthRate = null;
        $trend = 'flat';
        if (count($history) >= 2) {
            $prev = (float) $history[count($history) - 2]['value'];
            $last = (float) $history[count($history) - 1]['value'];
            if ($prev != 0.0) {
                $growthRate = (($last - $prev) / abs($prev)) * 100;
            }
            if ($last > $prev) {
                $trend = $higherBetter ? 'up' : 'down';
            } elseif ($last < $prev) {
                $trend = $higherBetter ? 'down' : 'up';
            }
        }

        // Achievement %: قد إيه current قريب من target، مع مراعاة
        // الاتجاه. لـ KPIs من نوع "الأقل أفضل" (زي معدل خطأ بسقف
        // مستهدف)، الـ achievement بتتحسن كل ما القيمة تقرب من الهدف
        // من فوق.
        $achievement = null;
        if ($target != 0.0) {
            $achievement = $higherBetter
                ? ($current / $target) * 100
                : max(0, (2 - ($current / $target))) * 100; // متماثل حول الهدف لمقياس سقف
            $achievement = max(0, min(200, $achievement));
        }

        // Alert: مهمة بس لو المحلل فعّلها وحدد threshold. بتشتغل لما
        // الأداء يعدي الاتجاه الغلط بالنسبة لاتجاه الـ KPI ده.
        $alertTriggered = false;
        if (!empty($kpi['alert_enabled']) && $kpi['alert_threshold'] !== null) {
            $threshold = (float) $kpi['alert_threshold'];
            $alertTriggered = $higherBetter ? ($current < $threshold) : ($current > $threshold);
        }

        return array_merge($kpi, [
            'history'         => $history,
            'growth_rate'     => $growthRate,
            'trend'           => $trend,
            'achievement_pct' => $achievement,
            'alert_triggered' => $alertTriggered,
            'recommendation'  => $this->recommend($achievement, $trend, $alertTriggered),
        ]);
    }

    /** توصية rule-based شفافة من الأرقام المحسوبة الحقيقية. */
    private function recommend(?float $achievement, string $trend, bool $alertTriggered): string
    {
        if ($alertTriggered) {
            return 'Alert threshold crossed — investigate root cause and consider a corrective action plan.';
        }
        if ($achievement === null) {
            return 'Set a target value to unlock achievement tracking and recommendations.';
        }
        if ($achievement >= 100) {
            return $trend === 'down'
                ? 'Target met, but momentum is slipping — monitor closely to sustain performance.'
                : 'Target achieved. Consider raising the target to keep driving improvement.';
        }
        if ($achievement >= 75) {
            return $trend === 'up'
                ? 'On track and improving — maintain current initiatives.'
                : 'Close to target but flat or declining — a small push could close the gap.';
        }
        if ($achievement >= 40) {
            return 'Meaningful gap to target — review what is driving the shortfall and prioritize it.';
        }
        return 'Significantly behind target — this KPI needs focused attention and a recovery plan.';
    }
}
