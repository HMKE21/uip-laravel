<?php

namespace App\Services\Developer;

use Illuminate\Support\Str;

/** توليد وتجزئة الأسرار. الخام بيتعرض مرة واحدة بس؛ في الـ DB بيتخزن sha256 بس. */
class DevTokens
{
    public static function make(string $prefix, int $len = 40): string
    {
        return $prefix . Str::random($len);
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function pkceS256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function iso($v): ?string
    {
        if (!$v) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($v, 'UTC')->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
