<?php

namespace App\Support;

use ZipArchive;

/**
 * أدوات "جهازي" الخالصة (من غير DB ولا Laravel — عشان تتختبر لوحدها: php tests/Harness/drive_archive_harness.php):
 * تنضيف الأسماء، اسم فريد "name (2).ext"، وقراءة zip بأمان (zip-slip / zip-bomb / symlink).
 */
final class DriveArchive
{
    /** ملفات نظام بتتجاهل في أي أرشيف جاي من ماك/ويندوز. */
    private const JUNK = '#(^|/)(__MACOSX|\.DS_Store|Thumbs\.db|desktop\.ini)(/|$)#i';

    /** اسم صالح لملف/مجلد، أو '' لو مش ممكن يتصلّح ('.' / '..' / فاضي). */
    public static function cleanName(string $name, int $max = 200): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('#[\\\\/:*?"<>|]#u', '_', $name) ?? '';
        $name = rtrim(trim($name), ". \t");
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        if (mb_strlen($name, 'UTF-8') > $max) {
            $ext  = (string) pathinfo($name, PATHINFO_EXTENSION);
            $keep = $ext !== '' && mb_strlen($ext, 'UTF-8') <= 12 ? '.' . $ext : '';
            $name = mb_substr($name, 0, $max - mb_strlen($keep, 'UTF-8'), 'UTF-8') . $keep;
        }
        return $name;
    }

    /** "a.txt" → "a (2).txt" → "a (3).txt" لحد أول اسم $exists بيرجّع عنده false. */
    public static function uniqueName(string $name, callable $exists): string
    {
        if (!$exists($name)) {
            return $name;
        }
        $dot  = strrpos($name, '.');
        $base = ($dot === false || $dot === 0) ? $name : substr($name, 0, $dot);
        $ext  = ($dot === false || $dot === 0) ? '' : substr($name, $dot);
        for ($i = 2; $i < 10000; $i++) {
            $try = "$base ($i)$ext";
            if (!$exists($try)) {
                return $try;
            }
        }
        return $base . ' (' . bin2hex(random_bytes(3)) . ')' . $ext;
    }

    /**
     * مسار نسبي جاي من المستخدم/zip → مقاطع نضيفة. null لو فيه محاولة هروب (.. / مسار مطلق / drive letter / NUL).
     * @return string[]|null
     */
    public static function segments(string $path): ?array
    {
        if ($path === '' || str_contains($path, "\0")) {
            return $path === '' ? [] : null;
        }
        $path = str_replace('\\', '/', $path);
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:#', $path)) {
            return null;
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $clean = self::cleanName($seg);
            if ($clean === '') {
                return null;
            }
            $out[] = $clean;
        }
        return $out;
    }

    public static function isJunk(string $entryName): bool
    {
        return (bool) preg_match(self::JUNK, str_replace('\\', '/', $entryName));
    }

    /**
     * يفحص zip قبل أي فك: عدد العناصر، الحجم الإجمالي المعلن، zip-slip، symlink. بيرجّع قائمة عناصر نضيفة:
     * [['index'=>int,'segments'=>string[],'dir'=>bool,'size'=>int]].  أي خرق => DriveException.
     * @return array<int,array{index:int,segments:string[],dir:bool,size:int}>
     */
    public static function scan(ZipArchive $zip, int $maxEntries, int $maxBytes): array
    {
        if ($zip->numFiles > $maxEntries + 2000) { // سماحية للـ junk
            throw new DriveException('The archive has too many entries.', 422);
        }
        $out = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $raw = (string) $st['name'];
            if (self::isJunk($raw)) {
                continue;
            }
            $isDir = str_ends_with($raw, '/') || str_ends_with($raw, '\\');
            $segs  = self::segments($raw);
            if ($segs === null) {
                throw new DriveException('The archive contains an unsafe path.', 422);
            }
            if ($segs === []) {
                continue;
            }
            // symlink: opsys UNIX + mode 0120000
            $opsys = 0; $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
                && ((($attr >> 16) & 0170000) === 0120000)) {
                continue;
            }
            $size = $isDir ? 0 : (int) $st['size'];
            $total += $size;
            if ($total > $maxBytes) {
                throw new DriveException('The archive is too large once extracted.', 413);
            }
            $out[] = ['index' => $i, 'segments' => $segs, 'dir' => $isDir, 'size' => $size];
            if (count($out) > $maxEntries) {
                throw new DriveException('The archive has too many entries.', 422);
            }
        }
        return $out;
    }

    /**
     * ينسخ عنصر من الـ zip لملف محلي مع سقف بايتات فعلي أثناء القراءة (الحجم المعلن ممكن يتزوّر).
     * @return int عدد البايتات المكتوبة
     */
    public static function copyEntry(ZipArchive $zip, int $index, string $destPath, int $budgetBytes): int
    {
        $in = $zip->getStreamIndex($index);
        if (!$in) {
            throw new DriveException('Could not read an entry from the archive.', 422);
        }
        $out = fopen($destPath, 'wb');
        if (!$out) {
            fclose($in);
            throw new DriveException('Could not write the extracted file.', 500);
        }
        $n = 0;
        try {
            while (!feof($in)) {
                $buf = fread($in, 1 << 16);
                if ($buf === false) {
                    break;
                }
                $n += strlen($buf);
                if ($n > $budgetBytes) {
                    throw new DriveException('The archive is too large once extracted.', 413);
                }
                fwrite($out, $buf);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        return $n;
    }
}
