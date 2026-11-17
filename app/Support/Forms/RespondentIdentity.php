<?php

namespace App\Support\Forms;

/**
 * Who-is-responding data collected by the form itself (not a question): name / email / mobile / national ID.
 * One place for the rules so the public form, drafts and submit all agree.
 *
 * Each field is 'required' | 'optional' | 'off'.
 */
final class RespondentIdentity
{
    public const FIELDS = ['name', 'email', 'phone', 'national_id'];
    public const MODES = ['required', 'optional', 'off'];

    /** unique_by key => identity field that must be collected for the rule to mean anything */
    public const UNIQUE_NEEDS = ['email' => 'email', 'phone' => 'phone', 'national_id' => 'national_id'];

    public static function defaults(): array
    {
        return ['name' => 'required', 'email' => 'off', 'phone' => 'off', 'national_id' => 'off', 'name_min_words' => 2];
    }

    /**
     * Effective identity config of a form: stored identity (or the legacy ask_name flag), with every
     * unique_by rule forcing its field to 'required' — otherwise the rule could never work.
     */
    public static function resolve(array $settings): array
    {
        $out = self::defaults();
        $stored = isset($settings['identity']) && is_array($settings['identity']) ? $settings['identity'] : null;
        if ($stored === null && array_key_exists('ask_name', $settings) && !filter_var($settings['ask_name'], FILTER_VALIDATE_BOOLEAN)) {
            $out['name'] = 'off';
        }
        if ($stored) {
            foreach (self::FIELDS as $f) {
                if (isset($stored[$f]) && in_array($stored[$f], self::MODES, true)) {
                    $out[$f] = $stored[$f];
                }
            }
            if (isset($stored['name_min_words'])) {
                $out['name_min_words'] = max(1, min(4, (int) $stored['name_min_words']));
            }
        }
        foreach ((array) ($settings['unique_by'] ?? []) as $u) {
            if (isset(self::UNIQUE_NEEDS[$u])) {
                $out[self::UNIQUE_NEEDS[$u]] = 'required';
            }
        }
        return $out;
    }

    /** @return array{0:?string,1:?string} [clean name, error key] — error is 'name_invalid' | 'name_short' | null */
    public static function name(?string $raw, int $minWords = 1): array
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $raw)));
        if ($v === '') {
            return [null, null];
        }
        // only letters (any alphabet), marks (Arabic tashkeel), spaces and . ' ’ -  — no digits, no symbols
        if (preg_match('/[^\p{L}\p{M} .\'’\-]/u', $v)) {
            return [null, 'name_invalid'];
        }
        $letters = preg_match_all('/\p{L}/u', $v);
        if ($letters < 2 || mb_strlen($v) > 190 || preg_match('/^(\p{L})\1+$/u', str_replace(' ', '', $v))) {
            return [null, 'name_invalid'];
        }
        $words = count(array_filter(preg_split('/[ \-]+/u', $v) ?: [], fn ($w) => preg_match('/\p{L}{2,}/u', $w)));
        if ($words < max(1, $minWords)) {
            return [null, 'name_short'];
        }
        return [$v, null];
    }

    public static function email(?string $raw): ?string
    {
        $e = mb_strtolower(trim((string) $raw));
        return $e !== '' && mb_strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    public static function message(string $key, bool $ar, int $minWords = 2): string
    {
        return match ($key) {
            'name_invalid' => $ar ? 'اكتب اسمًا صحيحًا بالحروف فقط (من غير أرقام أو رموز).' : 'Enter a real name using letters only (no digits or symbols).',
            'name_short' => $ar ? ($minWords >= 3 ? 'اكتب الاسم الثلاثي على الأقل.' : 'اكتب الاسم الثنائي على الأقل (الاسم الأول واسم الأب).') : "Please enter at least {$minWords} names.",
            'name_required' => $ar ? 'من فضلك اكتب اسمك.' : 'Please enter your name.',
            'email_required' => $ar ? 'من فضلك اكتب بريدك الإلكتروني.' : 'Please enter your email.',
            'email_invalid' => $ar ? 'أدخل بريدًا إلكترونيًا صحيحًا.' : 'Enter a valid email address.',
            'phone_required' => $ar ? 'من فضلك اكتب رقم الموبايل.' : 'Please enter your mobile number.',
            'phone_invalid' => $ar ? 'أدخل رقم موبايل مصري صحيح (مثال: 01012345678).' : 'Enter a valid Egyptian mobile number (e.g. 01012345678).',
            'national_id_required' => $ar ? 'من فضلك اكتب الرقم القومي.' : 'Please enter your national ID.',
            'national_id_invalid' => $ar ? 'الرقم القومي غير صحيح (14 رقم).' : 'Invalid national ID (14 digits).',
            default => $ar ? 'بيانات غير صحيحة.' : 'Invalid value.',
        };
    }
}
