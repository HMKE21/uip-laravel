<?php

namespace App\Support;

/**
 * يفحص ملف PDF عن محتوى نشط (JavaScript، تشغيل برامج، ملفات مضمّنة، فورمات بتبعت بيانات...).
 * الأسماء المموّهة (/J#61vaScript) بتتفك، والـ streams المضغوطة (Flate) بتتفك وتتفحص كمان
 * (الـ ObjStm بيخبّي الكلمات دي جواه). PDF مشفّر = مينفعش يتفحص => بيتحسب مشبوه.
 */
class PdfActiveContentScanner
{
    private const MAX_SCAN_BYTES = 30 * 1024 * 1024;
    private const MAX_STREAMS = 400;
    private const MAX_INFLATED = 8 * 1024 * 1024;

    private const NAMES = ['JavaScript', 'JS', 'Launch', 'EmbeddedFile', 'EmbeddedFiles', 'RichMedia', 'SubmitForm', 'ImportData', 'XFA', 'GoToE'];

    /** @return array<int,string> أسماء المحتوى النشط اللي اتلقى (فاضي = نضيف) */
    public static function findings(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_SCAN_BYTES) {
            return $size === false ? [] : ['too_large_to_scan'];
        }
        $raw = (string) @file_get_contents($path);
        if ($raw === '' || !str_contains(substr($raw, 0, 1024), '%PDF')) {
            return [];
        }

        $found = self::scanText($raw);

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
            foreach (array_slice($m[1], 0, self::MAX_STREAMS) as $chunk) {
                $inflated = self::inflate($chunk);
                if ($inflated !== null) {
                    $found = array_merge($found, self::scanText($inflated));
                }
            }
        }

        return array_values(array_unique($found));
    }

    public static function isRisky(string $path): bool
    {
        return self::findings($path) !== [];
    }

    /** @return array<int,string> */
    private static function scanText(string $text): array
    {
        // فك تمويه أسماء PDF: /J#61vaScript -> /JavaScript
        $text = preg_replace_callback('/#([0-9a-fA-F]{2})/', fn ($x) => chr(hexdec($x[1])), $text) ?? $text;
        $out = [];
        $names = implode('|', self::NAMES);
        if (preg_match_all('/\/(' . $names . ')(?![A-Za-z0-9])/', $text, $m)) {
            $out = array_merge($out, $m[1]);
        }
        if (preg_match('/\/Encrypt(?![A-Za-z0-9])/', $text)) {
            $out[] = 'Encrypted';
        }

        return $out;
    }

    private static function inflate(string $chunk): ?string
    {
        if (strlen($chunk) < 8) {
            return null;
        }
        $out = @zlib_decode(substr($chunk, 0, self::MAX_INFLATED), self::MAX_INFLATED);
        if ($out === false) {
            $out = @gzuncompress($chunk, self::MAX_INFLATED);
        }

        return $out === false ? null : $out;
    }
}
