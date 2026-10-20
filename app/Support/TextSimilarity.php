<?php

namespace App\Support;

/**
 * مقارنة نصوص (عربي/إنجليزي) بالـ word-shingles. نفس النص بعد التطبيع (تشكيل/همزات/ياء/تاء مربوطة/
 * علامات ترقيم) بيتقطع لكلمات، وكل ٣ كلمات ورا بعض = shingle. التشابه = Jaccard على مجموعات الـ shingles.
 */
final class TextSimilarity
{
    public const SHINGLE = 3;

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // تشكيل + تطويل
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
        ]);
        // أرقام عربية هندية -> لاتينية
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        return $text;
    }

    /** @return string[] */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', self::normalize($text), $m);
        return $m[0] ?? [];
    }

    /** @return array<int,true> مجموعة hashes للـ shingles (مفتاح = hash). */
    public static function shingles(array $words): array
    {
        $set = [];
        $n = count($words);
        for ($i = 0; $i + self::SHINGLE <= $n; $i++) {
            $set[crc32(implode(' ', array_slice($words, $i, self::SHINGLE)))] = true;
        }
        return $set;
    }

    /** @return array{similarity:float,matched_words:int} similarity 0..100 */
    public static function compare(array $shinglesA, array $shinglesB): array
    {
        if (!$shinglesA || !$shinglesB) {
            return ['similarity' => 0.0, 'matched_words' => 0];
        }
        $inter = count(array_intersect_key($shinglesA, $shinglesB));
        $union = count($shinglesA) + count($shinglesB) - $inter;
        return [
            'similarity'    => $union > 0 ? round($inter / $union * 100, 2) : 0.0,
            'matched_words' => $inter > 0 ? $inter + self::SHINGLE - 1 : 0,
        ];
    }
}
