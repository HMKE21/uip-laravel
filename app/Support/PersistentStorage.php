<?php

namespace App\Support;

/**
 * يربط مسارات الكتابة اللي جوه الـ container (public/uploads، storage/app/private، ...) بـ Volume واحد
 * عن طريق symlinks — شوف config/persistence.php. كله idempotent وآمن:
 *  - مبيحوّلش مجلد لـ symlink غير لما يتأكد إن الهدف موجود وقابل للكتابة وإن المحتوى القديم اتنقل بالكامل.
 *  - لو المسار نفسه Volume مركّب عليه مباشرةً (mount point) بيسيبه زي ما هو.
 *  - lock على مستوى الملف عشان أكتر من process يبدأوا مع بعض مايتسابقوش.
 */
final class PersistentStorage
{
    private static bool $ensured = false;

    public static function root(): ?string
    {
        $p = config('persistence.path');
        if (!is_string($p) || trim($p) === '') {
            return null;
        }
        return rtrim(trim($p), '/') ?: null;
    }

    /** @return array<string,string> مسار مطلق للـ link => مسار مطلق للهدف جوه الـ Volume */
    public static function plan(): array
    {
        $root = self::root();
        if ($root === null) {
            return [];
        }
        $out = [];
        foreach ((array) config('persistence.links', []) as $rel => $sub) {
            $out[base_path(trim((string) $rel, '/'))] = $root . '/' . trim((string) $sub, '/');
        }
        return $out;
    }

    /** true لو $path مجلد حقيقي على filesystem مختلف عن أبوه (يعني Volume/mount مركّب عليه). */
    public static function isMountPoint(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return false;
        }
        $a = @stat($path);
        $b = @stat(dirname($path));
        return $a !== false && $b !== false && $a['dev'] !== $b['dev'];
    }

    /** حالة سريعة من غير أي كتابة: كل المسارات متربطة/مركّبة بالفعل؟ */
    public static function isLinked(): bool
    {
        foreach (self::plan() as $link => $target) {
            if (!(is_link($link) && @readlink($link) === $target && is_dir($target)) && !self::isMountPoint($link)) {
                return false;
            }
        }
        return self::root() !== null;
    }

    /**
     * بيتنادى من AppServiceProvider::boot() (مرة لكل process، ورخيص: lstat بس لو كله متربط).
     * أي فشل بيتسجّل في error_log ومبيكسرش الـ request.
     */
    public static function ensure(): void
    {
        if (self::$ensured || self::root() === null) {
            return;
        }
        self::$ensured = true;
        // وقت الـ build على Railway المتغيرات موجودة لكن الـ Volume لسه مش مركّب: مبنلمسش حاجة (من غير
        // آثار جانبية في الـ image). التحذير بيظهر في ops:storage / storage-health.
        if (!self::isMountPoint((string) self::root()) || self::isLinked()) {
            return;
        }
        foreach (self::apply() as $rel => $status) {
            if (str_starts_with($status, 'error')) {
                error_log("[persistent-storage] {$rel}: {$status}");
            }
        }
    }

    /**
     * @param bool $requireMount true (الافتراضي): مبيربطش غير لو $root فعلًا Volume مركّب — عشان مننشئ
     *                           symlinks على disk الـ container بالغلط. false للاختبارات بس.
     * @return array<string,string> المسار النسبي => ok | linked | mounted | busy | error: ...
     */
    public static function apply(bool $requireMount = true): array
    {
        $root = self::root();
        if ($root === null) {
            return [];
        }

        $results = [];
        if ($requireMount && !self::isMountPoint($root)) {
            foreach (array_keys(self::plan()) as $link) {
                $results[self::rel($link)] = "error: {$root} is not a mounted volume — mount the Railway Volume at this path first";
            }
            return $results;
        }

        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            foreach (array_keys(self::plan()) as $link) {
                $results[self::rel($link)] = "error: volume path {$root} does not exist and could not be created";
            }
            return $results;
        }

        $lockFile = $root . '/.persist.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            foreach (array_keys(self::plan()) as $link) {
                $results[self::rel($link)] = 'busy';
            }
            return $results;
        }

        try {
            foreach (self::plan() as $link => $target) {
                $results[self::rel($link)] = self::applyOne($link, $target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $results;
    }

    private static function applyOne(string $link, string $target): string
    {
        if (self::isMountPoint($link)) {
            return 'mounted';
        }

        if (is_link($link)) {
            $current = @readlink($link);
            if ($current !== $target) {
                return 'error: symlink points to ' . var_export($current, true) . ' (expected ' . $target . ')';
            }
            // الـ Volume اتركّب فاضي (أو الهدف اتمسح): نعيد إنشاء مجلد الهدف بدل ما الرفع يتكسر.
            return (is_dir($target) || @mkdir($target, 0775, true) || is_dir($target)) ? 'ok' : 'error: could not create ' . $target;
        }

        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            return 'error: could not create ' . $target;
        }
        if (!is_writable($target)) {
            return 'error: ' . $target . ' is not writable by the app user (set RAILWAY_RUN_UID=0 on the service)';
        }

        if (file_exists($link)) {
            if (!is_dir($link)) {
                return 'error: ' . $link . ' exists and is not a directory';
            }
            if (!self::moveContents($link, $target)) {
                return 'error: could not move existing files from ' . $link . ' into the volume (left untouched)';
            }
            if (!@rmdir($link)) {
                return 'error: ' . $link . ' is not empty after moving (left untouched)';
            }
        } else {
            $parent = dirname($link);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                return 'error: could not create ' . $parent;
            }
        }

        return @symlink($target, $link) ? 'linked' : 'error: symlink() failed for ' . $link;
    }

    /** ينقل محتوى مجلد لمجلد تاني (rename، وcopy+unlink لو filesystem مختلف). مبيكتبش فوق ملف موجود. */
    private static function moveContents(string $from, string $to): bool
    {
        $ok = true;
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $from . '/' . $name;
            $dst = $to . '/' . $name;

            if (is_dir($src) && !is_link($src)) {
                if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
                    $ok = false;
                    continue;
                }
                if (self::moveContents($src, $dst) && @rmdir($src)) {
                    continue;
                }
                $ok = false;
                continue;
            }

            if (file_exists($dst)) {
                $ok = false; // مبنكتبش فوق حاجة موجودة في الـ Volume
                continue;
            }
            if (@rename($src, $dst)) {
                continue;
            }
            if (@copy($src, $dst) && @unlink($src)) {
                continue;
            }
            $ok = false;
        }
        return $ok;
    }

    private static function rel(string $absolute): string
    {
        $base = rtrim(base_path(), '/') . '/';
        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }
}
