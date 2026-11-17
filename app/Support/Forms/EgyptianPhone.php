<?php

namespace App\Support\Forms;

/**
 * Real Egyptian mobile numbers only: 010 Vodafone / 011 Etisalat / 012 Orange / 015 WE + 8 digits.
 * Accepts 01012345678, +201012345678, 00201012345678, 201012345678, Arabic digits, spaces/dashes.
 * Rejects obvious fakes (all same digit, 12345678 sequences).
 */
final class EgyptianPhone
{
    public const OPERATORS = ['010' => 'vodafone', '011' => 'etisalat', '012' => 'orange', '015' => 'we'];

    public static function digits(string $raw): string
    {
        $raw = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /** @return string|null canonical 01XXXXXXXXX or null when not a valid Egyptian mobile. */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/[^\d\s+().\-٠-٩۰-۹]/u', $raw)) {
            return null;
        }
        $d = self::digits($raw);
        if (str_starts_with($d, '0020')) {
            $d = '0' . substr($d, 4);
        } elseif (str_starts_with($d, '20') && strlen($d) === 12) {
            $d = '0' . substr($d, 2);
        } elseif (strlen($d) === 10 && $d[0] === '1') {
            $d = '0' . $d;
        }
        if (!preg_match('/^01[0125]\d{8}$/', $d)) {
            return null;
        }
        $tail = substr($d, 3);
        if (preg_match('/^(\d)\1{7}$/', $tail) || in_array($tail, ['12345678', '87654321', '01234567', '76543210'], true)) {
            return null;
        }
        return $d;
    }

    public static function operator(string $normalized): ?string
    {
        return self::OPERATORS[substr($normalized, 0, 3)] ?? null;
    }

    public static function international(string $normalized): string
    {
        return '+20' . substr($normalized, 1);
    }
}
