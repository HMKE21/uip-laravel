<?php

namespace App\Support;

/**
 * True/false answers are stored and graded internally as 'true' / 'false'.
 * Incoming values may be Arabic (صح / خطأ / غلط ...), any-case English, 1/0, bool,
 * or a selected option id from the option-based UI. This normalizes all of them.
 */
class TrueFalse
{
    private const TRUE_VALUES = ['true', 't', 'yes', 'y', '1', 'correct', 'right', 'صح', 'صحيح', 'صحيحه', 'صحيحة', 'صواب', 'نعم'];
    private const FALSE_VALUES = ['false', 'f', 'no', 'n', '0', 'incorrect', 'wrong', 'خطا', 'خطأ', 'خاطئ', 'خاطئه', 'خاطئة', 'غلط', 'خطاء', 'لا'];

    /** @return 'true'|'false'|null  null when not understood (never guess). */
    public static function normalize($value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $v = mb_strtolower(trim((string) $value), 'UTF-8');
        $v = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $v);
        $v = trim($v, " \t\n\r.,;:!?\"'()[]-_/\\");
        $v = str_replace(['أ', 'إ', 'آ'], 'ا', $v);

        if (in_array($v, self::TRUE_VALUES, true) || in_array(str_replace('ة', 'ه', $v), self::TRUE_VALUES, true)) {
            return 'true';
        }
        if (in_array($v, self::FALSE_VALUES, true) || in_array(str_replace('ة', 'ه', $v), self::FALSE_VALUES, true)) {
            return 'false';
        }
        return null;
    }
}
