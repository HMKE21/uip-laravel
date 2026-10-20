<?php

namespace App\Services\Faq;

/**
 * منقولة حرف بحرف من app/Services/Faq/ArabicNormalizer.php القديمة.
 * تطبيع نص محلي وخفيف + كشف لغة، لاستخدام مطابقة الـ FAQ بس
 * (FaqResolverService) — أبدًا ملهاش أي تأثير على النص المخزّن فعليًا
 * في تاريخ المحادثة، AiAssistantService دايمًا بيخزن رسالة اليوزر الخام
 * زي ما هي. مفيش استدعاء AI API هنا خالص — كل حاجة local.
 */
class ArabicNormalizer
{
    /** تشكيل عربي (tashkeel) + تطويل، بتتشال بالكامل قبل المطابقة. */
    private const DIACRITICS = "/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}\x{0640}]/u";

    /**
     * تطبيع نص حر لغرض مطابقة الـ FAQ بس:
     *  - توحيد أشكال الألف (أ إ آ ٱ -> ا)
     *  - توحيد التاء المربوطة -> هاء (ة -> ه) والألف المقصورة -> ياء (ى -> ي)
     *  - شيل التشكيل + التطويل
     *  - تصغير حروف اللاتيني
     *  - شيل علامات الترقيم/الاستفهام، ضغط الحروف المكررة والمسافات
     */
    public function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;

        // أشكال الألف -> ألف عادية (أ إ آ ٱ -> ا)
        $text = preg_replace('/[\x{0622}\x{0623}\x{0625}\x{0671}]/u', "\u{0627}", $text) ?? $text;
        // تاء مربوطة -> هاء (ة -> ه)
        $text = str_replace("\u{0629}", "\u{0647}", $text);
        // ألف مقصورة -> ياء (ى -> ي)
        $text = str_replace("\u{0649}", "\u{064A}", $text);
        // الهمزة المفردة (ء) إشارة ضعيفة لوحدها — بتتشال.
        $text = str_replace("\u{0621}", '', $text);

        // شيل علامات الترقيم (عربي + لاتيني)، خلي الحروف/الأرقام/المسافات بس.
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

        // ضغط 3+ حروف متكررة (تسامح مع الأخطاء الطباعية/التشديد).
        $text = preg_replace('/(.)\1{2,}/u', '$1$1', $text) ?? $text;

        // ضغط المسافات.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * تخمين لغة محلي وخفيف: 'ar' لو أغلب النص عربي، 'en' لو أغلبه
     * لاتيني، 'mixed' لو الاتنين ظاهرين بنسبة معقولة، 'unknown' لو مفيش
     * أي منهم (أرقام/إيموجي بس مثلًا).
     */
    public function detectLanguage(string $rawText): string
    {
        $arabicCount = preg_match_all('/[\x{0600}-\x{06FF}]/u', $rawText);
        $latinCount = preg_match_all('/[A-Za-z]/u', $rawText);

        if ($arabicCount === 0 && $latinCount === 0) {
            return 'unknown';
        }
        if ($arabicCount > 0 && $latinCount > 0) {
            $ratio = $arabicCount / max(1, $arabicCount + $latinCount);
            if ($ratio > 0.75) {
                return 'ar';
            }
            if ($ratio < 0.25) {
                return 'en';
            }
            return 'mixed';
        }
        return $arabicCount > 0 ? 'ar' : 'en';
    }

    /**
     * تحويل صغير ومحافظ من Arabizi/فرانكو-آراب -> حروف عربية، للأرقام
     * القليلة اللي المستخدمين المصريين بيستخدموها كحروف عادة (زي
     * "ezay a3mel project?"). بيتطبق كـ variant إضافي جنب العادي بس —
     * ميقدرش يخلي المطابقة أعنف من مسار النص العربي الأصلي، بس ممكن
     * يمسك صياغة كانت هتفوت غيره.
     */
    public function transliterateArabizi(string $normalizedText): string
    {
        $map = [
            '3' => "\u{0639}", // ع
            '7' => "\u{062D}", // ح
            '2' => "\u{0621}", // ء (بتتشال تاني في normalize())
            '5' => "\u{062E}", // خ
            '8' => "\u{0642}", // ق
            '6' => "\u{0637}", // ط
            '9' => "\u{0635}", // ص
        ];
        return strtr($normalizedText, $map);
    }
}
