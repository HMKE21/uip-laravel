<?php

namespace App\Support;

/**
 * Exam & Assessment System — Round 7 (Phase 7 "Random Option Order").
 * ترتيب اختيارات MCQ لازم يبقى مختلف من طالب لطالب، بس *ثابت* لنفس
 * الطالب طول عمر المحاولة (Resume/Reload مايعيدش الخلط، وإلا الطالب
 * هيحس إن اختياراته اتلخبطت). عشان كده مفيش أي persistence هنا ولا
 * shuffle() العادية (اللي بتعتمد على الـ PRNG state بتاع الـ request،
 * فمختلفة كل مرة) — بدل كده ترتيب حتمي (deterministic) مبني على hash
 * ثابت من (attempt_id, item_id)، فنفس المدخلات دايمًا بترجّع نفس
 * الترتيب من غير ما تلمس أي حالة عشوائية global.
 */
class AttemptShuffle
{
    /**
     * @param array<int,mixed> $items
     * @param callable $keyOf بيرجّع identifier ثابت (int|string) لكل عنصر — مثلاً option id
     * @return array<int,mixed> نفس العناصر، بترتيب حتمي مختلف لكل attemptId
     */
    public static function order(array $items, int $attemptId, callable $keyOf): array
    {
        $items = array_values($items);
        usort($items, function ($a, $b) use ($attemptId, $keyOf) {
            return strcmp(
                md5($attemptId . ':' . $keyOf($a)),
                md5($attemptId . ':' . $keyOf($b))
            );
        });
        return $items;
    }
}
