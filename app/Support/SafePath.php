<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * مساعد مركزي لمسارات الملفات (path traversal + حذف الملفات).
 *
 * التطبيع هنا "lexical" (بيحلّ . و .. من غير ما يلمس الـ filesystem) عن قصد: مجلدات الرفع ممكن تكون symlinks
 * لـ Volume (شوف PersistentStorage)، فـ realpath() كان هيطلّع المسار برّه الجذر ويكسر التخزين الدائم.
 * المسار النسبي اللي جاي من DB/مستخدم بيترفض لو فيه ".." أو NUL أو مسار مطلق/drive.
 */
final class SafePath
{
    /** اسم مسار مضمون إنه مش موجود: لو المسار اتفضح كغير آمن بنرجّعه بدل ما نرمي، فالكولر بيعمل is_file() ويرد 404. */
    private const INVALID = '.invalid-path-rejected';

    /** مسار نسبي نضيف ("uploads/a/b.pdf") أو null لو غير آمن (.. / NUL / مطلق / drive / فاضي). */
    public static function clean(?string $rel): ?string
    {
        if ($rel === null || $rel === '' || str_contains($rel, "\0")) {
            return null;
        }
        $rel = str_replace('\\', '/', $rel);
        if (preg_match('#^[A-Za-z]:#', $rel) === 1) { // C:\... أو C:foo
            return null;
        }
        $out = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $out[] = $seg;
        }

        return $out === [] ? null : implode('/', $out);
    }

    /** public_path() لمسار نسبي آمن. غير الآمن => مسار مش موجود (مش استثناء). */
    public static function publicPath(?string $rel): string
    {
        $c = self::clean($rel);
        if ($c === null) {
            self::reject('public_path', (string) $rel);

            return public_path(self::INVALID);
        }

        return public_path($c);
    }

    /** storage_path('app/…') لمسار نسبي آمن. */
    public static function storageAppPath(?string $rel): string
    {
        $c = self::clean($rel);
        if ($c === null) {
            self::reject('storage_path', (string) $rel);

            return storage_path('app/' . self::INVALID);
        }

        return storage_path('app/' . $c);
    }

    /** $base . '/' . $rel بحيث يفضل جوه $base (للجذور اللي مش public/storage). */
    public static function join(string $base, ?string $rel): string
    {
        $c = self::clean($rel);
        if ($c === null) {
            self::reject('join', (string) $rel);

            return rtrim($base, '/\\') . '/' . self::INVALID;
        }

        return rtrim($base, '/\\') . '/' . $c;
    }

    /** تطبيع lexical لمسار كامل (بيشيل . و ..). null لو NUL أو لو .. خرجت فوق الجذر. */
    public static function normalize(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $p = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('#^[A-Za-z]:#', $p) === 1) {
            $prefix = strtoupper(substr($p, 0, 2));
            $p = substr($p, 2);
        }
        $abs = str_starts_with($p, '/');
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }

        return $prefix . ($abs ? '/' : '') . implode('/', $out);
    }

    /** @return list<string> الجذور المسموح الحذف جواها */
    public static function roots(): array
    {
        $cands = [public_path(), storage_path(), database_path(), base_path('bootstrap/cache'), sys_get_temp_dir()];
        $up = (string) ini_get('upload_tmp_dir');
        if ($up !== '') {
            $cands[] = $up;
        }
        $persist = PersistentStorage::root();
        if ($persist !== null) {
            $cands[] = $persist;
        }
        $sqlite = config('database.connections.sqlite.database');
        if (is_string($sqlite) && $sqlite !== '' && $sqlite !== ':memory:') {
            $cands[] = dirname($sqlite);
        }
        $roots = [];
        foreach ($cands as $c) {
            foreach ([$c, @realpath($c) ?: null] as $v) {
                $n = is_string($v) ? self::normalize($v) : null;
                if ($n !== null && $n !== '' && $n !== '/' && !in_array($n, $roots, true)) {
                    $roots[] = $n;
                }
            }
        }

        return $roots;
    }

    /** المسار (بعد التطبيع) جوه واحد من الجذور (ومش هو الجذر نفسه)؟ */
    public static function within(string $path, ?array $roots = null): bool
    {
        $n = self::normalize($path);
        if ($n === null) {
            return false;
        }
        foreach ($roots ?? self::roots() as $root) {
            if (str_starts_with($n, rtrim($root, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * بديل unlink(): بيمسح ملف (أو symlink) بس لو جوه الجذور المسموحة. أي مسار فيه .. أو برّه الجذور = رفض + لوج.
     * مش بيرمي: true لو اتمسح، false غير كده (زي @unlink).
     */
    public static function delete(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        if (!self::within($path)) {
            self::reject('delete', $path);

            return false;
        }
        if (!is_file($path) && !is_link($path)) {
            return false;
        }

        return (new \Illuminate\Filesystem\Filesystem())->delete($path);
    }

    private static function reject(string $op, string $path): void
    {
        try {
            Log::warning('SafePath rejected an unsafe path', ['op' => $op, 'path' => mb_substr(str_replace("\0", '\\0', $path), 0, 200)]);
        } catch (\Throwable $e) {
            // اللوج مش لازم يكسر الطلب
        }
    }
}
