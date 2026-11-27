<?php

namespace App\Support;

/**
 * بحث LIKE موحّد (عربي/إنجليزي) لكل استعلامات البحث في المنصة.
 *
 *  - الجملة بتتقسّم لكلمات؛ كل كلمة لازم تظهر في عمود واحد على الأقل من $columns (الكلمات بأي ترتيب، ومش لازم في نفس العمود).
 *  - "ال" التعريف في أول الكلمة بتتشال (الحوادث ← حوادث) فتطابق الشكلين.
 *  - بدائل الكتابة: ة ↔ ه ، ى ↔ ي (الألف/الهمزات/التشكيل بيتعاملوا معاهم collation الـ utf8mb4_unicode_ci نفسها).
 *  - % و _ و \ بتتهرّب (مش بتوسّع البحث).
 */
final class SearchLike
{
    private const MAX_TOKENS = 6;

    public static function escape(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /** @return string[] */
    public static function tokens(string $q): array
    {
        $q = trim(mb_substr($q, 0, 200));
        if ($q === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s+/u', $q) ?: [] as $tk) {
            if ($tk === '') {
                continue;
            }
            if (mb_strlen($tk) > 3 && mb_substr($tk, 0, 2) === 'ال') {
                $tk = mb_substr($tk, 2);
            }
            $out[] = $tk;
            if (count($out) >= self::MAX_TOKENS) {
                break;
            }
        }
        return $out;
    }

    /** @return string[] الكلمة + بدائلها الإملائية */
    public static function variants(string $tk): array
    {
        $v = [$tk];
        foreach ([['ة', 'ه'], ['ه', 'ة'], ['ى', 'ي'], ['ي', 'ى']] as [$a, $b]) {
            if (mb_strpos($tk, $a) !== false) {
                $v[] = str_replace($a, $b, $tk);
            }
        }
        return array_values(array_unique($v));
    }

    /**
     * يضيف شرط (AND بين الكلمات / OR بين الأعمدة) على $query (Query\Builder أو Eloquent).
     * بيرجّع false لو مفيش كلمات صالحة (الشرط ما اتضافش).
     *
     * @param string[] $columns أعمدة مؤهّلة بالجدول زي 'u.full_name'
     */
    public static function apply($query, array $columns, string $q): bool
    {
        $tokens = self::tokens($q);
        if (!$tokens || !$columns) {
            return false;
        }
        $query->where(function ($outer) use ($tokens, $columns) {
            foreach ($tokens as $tk) {
                $outer->where(function ($w) use ($tk, $columns) {
                    foreach ($columns as $col) {
                        foreach (self::variants($tk) as $v) {
                            $w->orWhere($col, 'like', '%' . self::escape($v) . '%');
                        }
                    }
                });
            }
        });
        return true;
    }
}
