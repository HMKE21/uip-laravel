<?php

namespace App\Support;

/**
 * توحيد أسماء الأشخاص قبل المقارنة: بيشيل الألقاب (د. / دكتور / أ.د / Dr / Prof ...)
 * والتشكيل والتطويل، وبيوحّد الهمزات (أإآ→ا) والياء/الألف المقصورة (ى→ي)
 * والتاء المربوطة (ة→ه)، وبيشيل علامات الترقيم والمسافات الزايدة.
 * بيستخدم لربط اسم المشرف اللي الطالب كتبه بحساب الدكتور الحقيقي.
 */
final class PersonName
{
    private const TITLES = [
        'دكتور', 'الدكتور', 'دكتوره', 'الدكتوره', 'د', 'استاذ', 'الاستاذ', 'استاذه', 'ا', 'اد', 'م',
        'مهندس', 'المهندس', 'مهندسه', 'معيد', 'المعيد', 'dr', 'prof', 'professor', 'eng', 'mr', 'mrs', 'ms',
    ];

    public static function normalize(?string $name): string
    {
        $n = mb_strtolower((string) $name);
        $n = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $n);
        $n = strtr($n, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $n = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $n);
        $tokens = preg_split('/\s+/u', trim($n), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => !in_array($t, self::TITLES, true)));
        return implode(' ', $tokens);
    }

    /** تطابق تام بعد التوحيد، أو احتواء (الأقصر لازم يكون كلمتين على الأقل). */
    public static function matches(?string $typed, ?string $staffName): bool
    {
        $a = self::normalize($typed);
        $b = self::normalize($staffName);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $longer = $shorter === $a ? $b : $a;
        return count(explode(' ', $shorter)) >= 2 && str_contains($longer, $shorter);
    }

    /**
     * يلاقي حساب واحد بس من $staff (صفوف فيها full_name) يطابق الاسم المكتوب: تطابق تام بعد التوحيد
     * أولًا، وإلا احتواء. لو أكتر من واحد (أو ولا واحد) بيرجع null — مبنخمنش.
     * @param iterable<object> $staff
     * @return object|null
     */
    public static function resolveUnique(?string $typed, iterable $staff): ?object
    {
        $staff = is_array($staff) ? $staff : iterator_to_array($staff, false);
        $norm = self::normalize($typed);
        if ($norm === '') {
            return null;
        }
        $exact = array_values(array_filter($staff, fn ($s) => self::normalize($s->full_name) === $norm));
        if (count($exact) === 1) {
            return $exact[0];
        }
        if (count($exact) > 1) {
            return null;
        }
        $partial = array_values(array_filter($staff, fn ($s) => self::matches($typed, $s->full_name)));
        return count($partial) === 1 ? $partial[0] : null;
    }
}
