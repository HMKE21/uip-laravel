<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One university = one name. Rejects a new/renamed university whose Arabic or
 * English name matches an existing (non-deleted) university, comparing the
 * NORMALISED form so "جامعة النهضة", "جامعه النهضه " and "Nahda University" /
 * "University of Nahda" can't sneak in as duplicates.
 */
final class UniversityName
{
    /** Canonical comparison key for a name in either language. */
    public static function normalize($value): string
    {
        $s = mb_strtolower(BilingualName::clean($value));
        $s = preg_replace('/[\p{Mn}\x{0640}]/u', '', $s);                 // diacritics + tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);                 // punctuation
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $noise = ['جامعه', 'university', 'of', 'the', 'univ'];            // generic words, not identity
        $words = array_values(array_filter($words, fn ($w) => !in_array($w, $noise, true)));
        // "جامعة" glued as a prefix ("الجامعه") is rare; keep it simple.
        return implode(' ', $words);
    }

    /**
     * The two columns the DB unique indexes sit on (null when a name normalises to nothing,
     * and for soft-deleted universities, so a deleted name can be reused).
     *
     * @return array{name_key_ar:?string,name_key_en:?string}
     */
    public static function keys($nameAr, $nameEn): array
    {
        $ar = self::normalize($nameAr);
        $en = self::normalize($nameEn);
        return ['name_key_ar' => $ar !== '' ? $ar : null, 'name_key_en' => $en !== '' ? $en : null];
    }

    /**
     * @param int|null $ignoreUserId   the account being edited (its own row never conflicts)
     * @return array<string,string>    field => message (empty when the names are free)
     */
    public static function conflicts($nameAr, $nameEn, ?int $ignoreUserId = null, string $locale = 'ar'): array
    {
        $isAr = $locale === 'ar';
        $keyAr = self::normalize($nameAr);
        $keyEn = self::normalize($nameEn);
        $errors = [];

        $q = DB::table('universities')->whereNull('deleted_at')->select('user_id', 'official_name_ar', 'official_name_en');
        if ($ignoreUserId) {
            $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $ignoreUserId));
        }
        foreach ($q->get() as $row) {
            if ($keyAr !== '' && !isset($errors['name_ar'])
                && ($keyAr === self::normalize($row->official_name_ar) || $keyAr === self::normalize($row->official_name_en))) {
                $errors['name_ar'] = $isAr
                    ? 'توجد جامعة مسجلة بنفس هذا الاسم بالفعل: «' . ($row->official_name_ar ?: $row->official_name_en) . '».'
                    : 'A university with this name already exists: "' . ($row->official_name_en ?: $row->official_name_ar) . '".';
            }
            if ($keyEn !== '' && !isset($errors['name_en'])
                && ($keyEn === self::normalize($row->official_name_en) || $keyEn === self::normalize($row->official_name_ar))) {
                $errors['name_en'] = $isAr
                    ? 'توجد جامعة مسجلة بنفس هذا الاسم بالفعل: «' . ($row->official_name_en ?: $row->official_name_ar) . '».'
                    : 'A university with this name already exists: "' . ($row->official_name_en ?: $row->official_name_ar) . '".';
            }
            if (count($errors) === 2) {
                break;
            }
        }
        return $errors;
    }
}
