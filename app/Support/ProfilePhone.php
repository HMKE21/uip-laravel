<?php

namespace App\Support;

/**
 * Self-service phone number for every portal's own-profile form.
 * Free-form (users type it the way they write it), but sanity-checked:
 * digits plus + ( ) - . and spaces, 6–30 characters. Empty clears it.
 */
final class ProfilePhone
{
    /**
     * @return array{ok:bool, present:bool, value:?string, error:?string}
     *   present=false means the request did not carry a phone at all (leave the column alone).
     */
    public static function fromRequest($request, string $locale = 'ar'): array
    {
        if (!$request->has('phone')) {
            return ['ok' => true, 'present' => false, 'value' => null, 'error' => null];
        }

        $raw = trim((string) $request->input('phone', ''));
        if ($raw === '') {
            return ['ok' => true, 'present' => true, 'value' => null, 'error' => null];
        }

        $value = preg_replace('/\s+/', ' ', $raw);
        $digits = preg_replace('/\D+/', '', $value);
        $valid = preg_match('/^[0-9+().\-\s]{6,30}$/', $value) === 1 && strlen($digits) >= 6 && strlen($digits) <= 18;
        if (!$valid) {
            return [
                'ok'      => false,
                'present' => true,
                'value'   => null,
                'error'   => $locale === 'en' ? 'Enter a valid phone number.' : 'اكتب رقم تليفون صحيح.',
            ];
        }

        return ['ok' => true, 'present' => true, 'value' => $value, 'error' => null];
    }
}
