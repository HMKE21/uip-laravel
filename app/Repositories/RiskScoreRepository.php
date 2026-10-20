<?php

namespace App\Repositories;

use App\Models\RiskScore;

/**
 * منقولة جزئيًا من app/Repositories/RiskScoreRepository.php القديمة —
 * topRisks()/averageScore() بس دلوقتي (بند 25 batch 1، لداشبورد
 * الأمان). upsert()/countByLevel() هتتضاف لما RiskScoringService::
 * recalculate() (اللي RiskScoreController القديمة كانت بتنادي عليه من
 * زرار الداشبورد) يتقرر نطاقه — مفيش زرار فعلي في الفرونت React الحالي
 * بينادي عليه (شوف ملاحظة batch 1 في docblock الكنترولر)، فمش هخترعه
 * دلوقتي. القيم هنا كلها بتقرا الجدول الحقيقي بالظبط زي القديمة.
 */
class RiskScoreRepository
{
    /** @return array<int,array<string,mixed>> أعلى score الأول */
    public function topRisks(int $limit = 10): array
    {
        return RiskScore::orderByDesc('score')->limit(max(1, $limit))->get()->map(fn ($r) => $r->toArray())->all();
    }

    public function averageScore(): float
    {
        return (float) (RiskScore::avg('score') ?? 0);
    }
}
