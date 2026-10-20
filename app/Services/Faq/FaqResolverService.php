<?php

namespace App\Services\Faq;

use App\Repositories\FaqIntentRepository;

/**
 * منقولة حرف بحرف من app/Services/Faq/FaqResolverService.php القديمة.
 * الماتشر الطبقي: exact -> alias -> keyword-combination -> fuzzy. كل
 * intent ليه confidence_threshold خاص بيه بيقرر لو أفضل مرشح اتلاقى
 * واثق بما يكفي عشان يترد بيه؛ أي حاجة أقل من كده (أو مفيش مرشح خالص)
 * بترجّع matched=false عشان المستدعي يكمل على مسار الـ AI العادي زي ما هو.
 *
 * بتقرا بس FaqIntentRepository::activeIntents() (متخزّنة كاش بالفعل) —
 * الكلاس ده مالوش أي DB I/O خاص بيه وأبدًا مبينادي AI API.
 */
class FaqResolverService
{
    public function __construct(
        private FaqIntentRepository $repo,
        private ArabicNormalizer $normalizer
    ) {
    }

    /**
     * @param array{portal?:string,role?:string} $context سياق آمن-RBAC
     *   الكنترولر جهّزه بالفعل لهذا اليوزر المُوثّق — بيُستخدم بس لفلترة
     *   الـ intents الخاصة بـ role معيّن.
     * @return array{matched:bool,intent:?array,confidence:float,language:string,
     *   normalized:string,best_candidate_key:?string,best_score:?float}
     */
    public function resolve(string $rawMessage, array $context = []): array
    {
        $language = $this->normalizer->detectLanguage($rawMessage);
        $normalized = $this->normalizer->normalize($rawMessage);

        $result = [
            'matched'            => false,
            'intent'             => null,
            'confidence'         => 0.0,
            'language'           => $language === 'unknown' ? 'en' : $language,
            'normalized'         => $normalized,
            'best_candidate_key' => null,
            'best_score'         => null,
        ];

        // قصير جدًا عشان نطابقه بأمان (مبدأ: مش متأكد -> ماتخمّنش).
        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return $result;
        }

        $arabiziVariant = $this->normalizer->normalize($this->normalizer->transliterateArabizi($normalized));
        $role = $context['role'] ?? null;

        $bestScore = 0.0;
        $bestIntent = null;

        foreach ($this->repo->activeIntents() as $intent) {
            if (!$this->roleAllowed($intent, $role)) {
                continue;
            }

            $variants = $this->intentVariants($intent);
            if (!$variants) {
                continue;
            }

            $score = $this->scoreIntent($normalized, $arabiziVariant, $variants, $intent);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIntent = $intent;
            }
        }

        $result['best_candidate_key'] = $bestIntent['intent_key'] ?? null;
        $result['best_score'] = $bestIntent ? round($bestScore, 3) : null;

        if ($bestIntent === null) {
            return $result;
        }

        $threshold = (float) ($bestIntent['confidence_threshold'] ?? 0.75);
        if ($bestScore >= $threshold) {
            $result['matched'] = true;
            $result['intent'] = $bestIntent;
            $result['confidence'] = round($bestScore, 3);

            // رسايل متخلطة اللغة: نفضّل العربي لو الـ intent ليه جواب
            // عربي، غير كده نرجع للإنجليزي.
            if ($result['language'] === 'mixed') {
                $result['language'] = ($bestIntent['answer_ar'] ?? '') !== '' ? 'ar' : 'en';
            }
        }

        return $result;
    }

    private function roleAllowed(array $intent, ?string $role): bool
    {
        $roles = $intent['role'] ?? [];
        if (!$roles) {
            return true; // NULL/فاضي = كل الـ roles
        }
        return $role !== null && in_array($role, $roles, true);
    }

    /** @return array<int,string> كل صياغة مطبّعة لازم الـ intent ده يتطابق معاها. */
    private function intentVariants(array $intent): array
    {
        $raw = array_merge(
            [$intent['question_ar'] ?? '', $intent['question_en'] ?? ''],
            $intent['aliases_ar'] ?? [],
            $intent['aliases_en'] ?? [],
            $intent['aliases_mixed'] ?? []
        );

        $variants = [];
        foreach ($raw as $phrase) {
            $n = $this->normalizer->normalize((string) $phrase);
            if ($n !== '') {
                $variants[$n] = true;
            }
        }
        return array_keys($variants);
    }

    private function scoreIntent(string $normalized, string $arabiziVariant, array $variants, array $intent): float
    {
        $variantSet = array_flip($variants);

        // طبقة 1/2 — تطابق تام مع السؤال المطبّع أو أحد الـ aliases.
        if (isset($variantSet[$normalized]) || ($arabiziVariant !== $normalized && isset($variantSet[$arabiziVariant]))) {
            return 0.97;
        }

        // طبقة 3 — إشارات تركيبة-كلمات-مفتاحية مطلوبة.
        $keywordScore = $this->keywordScore($normalized, $intent['keywords'] ?? []);

        // طبقة 4 — مطابقة ضبابية محلية خفيفة مقابل كل variant معروف.
        $fuzzyScore = $this->fuzzyScore($normalized, $variants);

        return max($keywordScore, $fuzzyScore);
    }

    /**
     * كل عنصر keyword ممكن يكون token واحد (إشارة ضعيفة لوحده) أو تركيبة
     * "token+token" يعني كل التوكنز بتاعتها لازم تظهر في الرسالة. تركيبة
     * 2+ توكن بس هي اللي ممكن تنتج score قائم بذاته؛ العناصر المفردة بس
     * بتزوّد تركيبة حقيقية اتطابقت بالفعل شوية.
     */
    private function keywordScore(string $normalized, array $keywordEntries): float
    {
        if (!$keywordEntries) {
            return 0.0;
        }

        $comboHits = 0;
        $bestComboSize = 0;
        $singleHits = 0;

        foreach ($keywordEntries as $entry) {
            $tokens = array_values(array_filter(array_map('trim', explode('+', (string) $entry))));
            if (!$tokens) {
                continue;
            }
            $allPresent = true;
            foreach ($tokens as $t) {
                if ($t !== '' && mb_strpos($normalized, $t) === false) {
                    $allPresent = false;
                    break;
                }
            }
            if (!$allPresent) {
                continue;
            }
            if (count($tokens) >= 2) {
                $comboHits++;
                $bestComboSize = max($bestComboSize, count($tokens));
            } else {
                $singleHits++;
            }
        }

        if ($comboHits === 0) {
            return 0.0; // مفيش أي إشارة multi-token خالص -> الكلمات المفتاحية ماتفعّلش لوحدها
        }

        $score = 0.72
            + min(0.15, ($comboHits - 1) * 0.06)
            + min(0.08, max(0, $bestComboSize - 2) * 0.03)
            + min(0.05, $singleHits * 0.01);

        return min(0.93, $score);
    }

    /** @param array<int,string> $variants */
    private function fuzzyScore(string $normalized, array $variants): float
    {
        $len = mb_strlen($normalized);
        if ($len < 4) {
            return 0.0; // قصير جدًا عشان نطابقه ضبابيًا بأمان
        }

        $best = 0.0;
        foreach ($variants as $variant) {
            $variantLen = mb_strlen($variant);
            if ($variantLen === 0 || abs($variantLen - $len) > max(8, (int) ($variantLen * 0.4))) {
                continue; // فرق طول كبير جدًا -> مش خطأ طباعي محتمل من التاني
            }
            similar_text($normalized, $variant, $percent);
            $ratio = $percent / 100;
            if ($ratio > $best) {
                $best = $ratio;
            }
        }

        if ($best < 0.80) {
            return 0.0; // أقل من كده، خطر زيادة عن اللازم
        }

        return min(0.93, $best * 0.95);
    }
}
