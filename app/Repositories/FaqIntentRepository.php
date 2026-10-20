<?php

namespace App\Repositories;

use App\Models\FaqIntent;
use App\Models\FaqUnmatchedLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/FaqIntentRepository.php القديمة —
 * activeIntents() (المسار الساخن) متخزّن بـ Cache::remember() عشان الـ
 * resolver ميرجعش يستعلم الجدول مع كل رسالة شات؛ كل ميثود كتابة تحت
 * بتنادي forgetCache() فورًا بعد الحفظ.
 */
class FaqIntentRepository
{
    private const CACHE_KEY = 'faq:active_intents';
    private const CACHE_TTL = 300; // 5 دقايق — قصيرة بما يكفي إن أي forgetCache() فاتت تتصلح لوحدها بسرعة

    /** @return array<int,array> كل intent فعّال (is_active=1)، مفكوك، للمطابقة داخل الذاكرة في الـ resolver. */
    public function activeIntents(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return FaqIntent::where('is_active', 1)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (FaqIntent $r) => $r->toArray())
                ->all();
        });
    }

    /** @return array<int,array> كل intent (فعّال أو لأ)، لشاشة إدارة الأدمن. */
    public function all(): array
    {
        return FaqIntent::orderBy('category')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (FaqIntent $r) => $r->toArray())
            ->all();
    }

    public function find(int $id): ?FaqIntent
    {
        return FaqIntent::find($id);
    }

    public function findByKey(string $intentKey): ?FaqIntent
    {
        return FaqIntent::where('intent_key', $intentKey)->first();
    }

    public function create(array $data, ?int $createdBy): FaqIntent
    {
        $intent = FaqIntent::create($this->prepareForWrite($data) + [
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);
        $this->forgetCache();
        return $intent;
    }

    public function update(int $id, array $data, ?int $updatedBy): ?FaqIntent
    {
        $intent = FaqIntent::find($id);
        if (!$intent) {
            return null;
        }
        $intent->fill($this->prepareForWrite($data) + ['updated_by' => $updatedBy]);
        $intent->save();
        $this->forgetCache();
        return $intent;
    }

    public function delete(int $id): bool
    {
        $intent = FaqIntent::find($id);
        if (!$intent) {
            return false;
        }
        $deleted = (bool) $intent->delete();
        $this->forgetCache();
        return $deleted;
    }

    public function setActive(int $id, bool $active): ?FaqIntent
    {
        $intent = FaqIntent::find($id);
        if (!$intent) {
            return null;
        }
        $intent->fill(['is_active' => $active ? 1 : 0]);
        $intent->save();
        $this->forgetCache();
        return $intent;
    }

    /** عداد hit بأفضل جهد — مايتسمحش أبدًا يوقف أو يبوظ الرد الفعلي. */
    public function recordHit(int $id): void
    {
        try {
            DB::table('faq_intents')->where('id', $id)->update([
                'hit_count'   => DB::raw('hit_count + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Analytics بس — ماينفعش عداد يبوظ رد الشات.
        }
    }

    /**
     * بيعمل upsert لسؤال فايت (miss) في faq_unmatched_log، وبيزوّد
     * hit_count لو نفس النص المطبّع اتشاف قبل كده.
     */
    public function recordUnmatched(string $normalized, string $sample, string $language, ?string $role, ?string $portal, ?string $bestCandidateKey, ?float $bestCandidateScore): void
    {
        if ($normalized === '') {
            return;
        }
        try {
            $existing = FaqUnmatchedLog::where('normalized_question', $normalized)->first();
            if ($existing) {
                $existing->fill([
                    'hit_count'    => (int) $existing->hit_count + 1,
                    'last_seen_at' => now(),
                ]);
                $existing->save();
                return;
            }
            FaqUnmatchedLog::create([
                'normalized_question'  => mb_substr($normalized, 0, 500),
                'sample_question'      => mb_substr($sample, 0, 500),
                'language'             => $language,
                'role'                 => $role,
                'portal'               => $portal,
                'best_candidate_key'   => $bestCandidateKey,
                'best_candidate_score' => $bestCandidateScore,
                'hit_count'            => 1,
                'last_seen_at'         => now(),
                'created_at'           => now(),
            ]);
        } catch (\Throwable $e) {
            // Analytics بس — ماينفعش فشل تسجيل يبان لليوزر في الشات.
        }
    }

    /** @return array<int,array> أكتر الأسئلة الفايتة تكرارًا، لشاشة الأدمن. */
    public function topUnmatched(int $limit = 50): array
    {
        return DB::table('faq_unmatched_log')
            ->orderByDesc('hit_count')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** بيحوّل أعمدة الـ array-shaped لـ JSON وبيطبّع role/threshold قبل الكتابة. */
    private function prepareForWrite(array $data): array
    {
        $out = $data;
        foreach (['aliases_ar', 'aliases_en', 'aliases_mixed', 'keywords'] as $col) {
            if (isset($out[$col]) && is_array($out[$col])) {
                $out[$col] = json_encode(array_values(array_filter(array_map('trim', $out[$col]), fn ($v) => $v !== '')), JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($out['role']) && is_array($out['role'])) {
            $out['role'] = $out['role'] ? implode(',', array_map('trim', $out['role'])) : null;
        }
        if (array_key_exists('is_active', $out)) {
            $out['is_active'] = !empty($out['is_active']) ? 1 : 0;
        }
        if (isset($out['confidence_threshold'])) {
            $out['confidence_threshold'] = max(0.0, min(1.0, (float) $out['confidence_threshold']));
        }
        if (isset($out['priority'])) {
            $out['priority'] = (int) $out['priority'];
        }
        return $out;
    }
}
