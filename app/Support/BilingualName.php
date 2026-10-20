<?php

namespace App\Support;

/**
 * Every person/entity on the platform carries two names: Arabic (name_ar) and
 * English (name_en). `users.full_name` is kept as the legacy single display
 * column (≈100 call sites read it — emails, lists, audit rows…) and is always
 * derived from the two real names here, never typed by hand.
 */
final class BilingualName
{
    public const MAX = 150;

    public static function clean($value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    public static function hasArabic(string $value): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $value);
    }

    public static function hasLatin(string $value): bool
    {
        return (bool) preg_match('/\p{Latin}/u', $value);
    }

    /**
     * Validate + normalise a name pair.
     *
     * @return array{ok:bool, errors:array<string,string>, name_ar:string, name_en:string, full_name:string}
     */
    public static function resolve($nameAr, $nameEn, string $locale = 'ar'): array
    {
        $ar = self::clean($nameAr);
        $en = self::clean($nameEn);
        $isAr = $locale === 'ar';
        $errors = [];

        if ($ar === '') {
            $errors['name_ar'] = $isAr ? 'الاسم بالعربي مطلوب.' : 'The Arabic name is required.';
        } elseif (mb_strlen($ar) < 2 || mb_strlen($ar) > self::MAX) {
            $errors['name_ar'] = $isAr ? 'الاسم بالعربي لازم يكون من 2 إلى 150 حرف.' : 'The Arabic name must be 2–150 characters.';
        } elseif (!self::hasArabic($ar)) {
            $errors['name_ar'] = $isAr ? 'اكتب الاسم بالحروف العربية.' : 'Write the Arabic name using Arabic letters.';
        }

        if ($en === '') {
            $errors['name_en'] = $isAr ? 'الاسم بالإنجليزي مطلوب.' : 'The English name is required.';
        } elseif (mb_strlen($en) < 2 || mb_strlen($en) > self::MAX) {
            $errors['name_en'] = $isAr ? 'الاسم بالإنجليزي لازم يكون من 2 إلى 150 حرف.' : 'The English name must be 2–150 characters.';
        } elseif (self::hasArabic($en) || !self::hasLatin($en)) {
            $errors['name_en'] = $isAr ? 'اكتب الاسم بالحروف الإنجليزية.' : 'Write the English name using English letters.';
        }

        return [
            'ok'        => !$errors,
            'errors'    => $errors,
            'name_ar'   => $ar,
            'name_en'   => $en,
            'full_name' => self::pick($ar, $en, $locale),
        ];
    }

    /**
     * Same as resolve(), but when neither name was supplied (old callers that
     * only know a single $fullName) it mirrors the legacy name into both.
     */
    public static function resolveOrLegacy($fullName, $nameAr, $nameEn, string $locale = 'ar'): array
    {
        if ($nameAr === null && $nameEn === null) {
            [$ar, $en] = self::fromLegacy($fullName);
            return ['ok' => true, 'errors' => [], 'name_ar' => $ar, 'name_en' => $en, 'full_name' => self::pick($ar, $en, $locale)];
        }
        return self::resolve($nameAr, $nameEn, $locale);
    }

    /** UI language of a request (X-Locale header sent by the React client, or ?locale=). */
    public static function localeOf($request): string
    {
        $l = (string) ($request->input('locale') ?: $request->header('X-Locale', 'ar'));
        return $l === 'en' ? 'en' : 'ar';
    }

    /** resolve() over a request's name_ar / name_en inputs. */
    public static function fromRequest($request, ?string $locale = null): array
    {
        return self::resolve($request->input('name_ar'), $request->input('name_en'), $locale ?? self::localeOf($request));
    }

    /** Name for a locale, falling back to the other one. */
    public static function pick($nameAr, $nameEn, string $locale = 'ar', string $fallback = ''): string
    {
        $ar = self::clean($nameAr);
        $en = self::clean($nameEn);
        $first = $locale === 'en' ? $en : $ar;
        $second = $locale === 'en' ? $ar : $en;
        return $first !== '' ? $first : ($second !== '' ? $second : $fallback);
    }

    /**
     * Legacy single-name input → [name_ar, name_en]. The one name goes to the
     * column matching its script and is mirrored into the other so nothing is
     * ever blank (used by old callers/imports and the backfill migration).
     *
     * @return array{0:string,1:string}
     */
    public static function fromLegacy($fullName): array
    {
        $name = self::clean($fullName);
        return [$name, $name];
    }

    /** Columns to write on `users` (and `supervisors`) for a resolved pair. */
    public static function columns(array $resolved): array
    {
        return [
            'full_name' => $resolved['full_name'],
            'name_ar'   => $resolved['name_ar'],
            'name_en'   => $resolved['name_en'],
        ];
    }
}
