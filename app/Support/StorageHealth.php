<?php

namespace App\Support;

use App\Services\FileUploadPolicyService;

/**
 * تقرير تشغيلي واحد بيجاوب على سؤالين: (1) الملفات والسجلات فعلًا على Volume ولا بتتمسح مع النشر؟
 * (2) إعدادات PHP كافية لرفع الحد الأقصى المسموح (سياسة الرفع، الافتراضي 50MB)؟
 *
 * مهم: القيم بتتقرا من الـ SAPI اللي بينفّذ الكود. من الـ CLI (railway shell / artisan) ممكن تختلف عن
 * الويب، فاستخدم endpoint الأدمن GET /api/v1/admin/system/storage-health للقيم الحقيقية للويب.
 */
final class StorageHealth
{
    public static function report(): array
    {
        $warnings = [];
        $requiredBytes = self::requiredBytes();

        // ---- PHP limits
        $php = ['sapi' => PHP_SAPI, 'ini_file' => php_ini_loaded_file() ?: null, 'version' => PHP_VERSION];
        $limitsOk = true;
        foreach (['upload_max_filesize', 'post_max_size'] as $name) {
            $raw = ini_get($name);
            $bytes = ($raw === false || $raw === '') ? null : ini_parse_quantity($raw);
            $unlimited = $bytes !== null && $bytes <= 0;
            $ok = $unlimited || ($bytes !== null && $bytes >= $requiredBytes);
            $php[$name] = ['value' => $raw === false ? null : $raw, 'bytes' => $unlimited ? null : $bytes, 'unlimited' => $unlimited, 'ok' => $ok];
            if (!$ok) {
                $limitsOk = false;
                $warnings[] = "{$name} = " . var_export($raw, true) . ' is below the required ' . self::human($requiredBytes) . '.';
            }
        }
        foreach (['max_file_uploads', 'memory_limit', 'max_execution_time', 'max_input_time'] as $name) {
            $php[$name] = ini_get($name) === false ? null : ini_get($name);
        }

        // ---- Persistence
        $root = PersistentStorage::root();
        $paths = [];
        $allPersistent = true;
        $rootIsMount = $root !== null && PersistentStorage::isMountPoint($root);

        $plan = $root !== null ? PersistentStorage::plan() : [];
        if ($root === null) {
            foreach ((array) config('persistence.links', []) as $rel => $_) {
                $plan[base_path(trim((string) $rel, '/'))] = null;
            }
        }
        foreach ($plan as $abs => $target) {
            $isLink = is_link($abs);
            $isMount = PersistentStorage::isMountPoint($abs);
            $linkTarget = $isLink ? @readlink($abs) : null;
            $persistent = $isMount || ($isLink && $root !== null && $rootIsMount && $linkTarget === $target);
            $allPersistent = $allPersistent && $persistent;
            $paths[] = [
                'path'       => ltrim(str_replace(base_path(), '', $abs), '/'),
                'exists'     => file_exists($abs),
                'symlink_to' => $linkTarget ?: null,
                'mount_point' => $isMount,
                'writable'   => is_dir($abs) && is_writable($abs),
                'persistent' => $persistent,
            ];
            if (!$persistent) {
                $warnings[] = ltrim(str_replace(base_path(), '', $abs), '/') . ' is NOT on a persistent volume — its files are lost on every deploy.';
            }
        }

        $volume = null;
        if ($root !== null) {
            if (!$rootIsMount) {
                $warnings[] = "PERSISTENT_STORAGE_PATH={$root} is not a mounted volume (it is on the container's own disk).";
            }
            $volume = [
                'path'        => $root,
                'is_mount'    => $rootIsMount,
                'writable'    => is_dir($root) && is_writable($root),
                'free_bytes'  => is_dir($root) ? (int) @disk_free_space($root) : null,
                'total_bytes' => is_dir($root) ? (int) @disk_total_space($root) : null,
                'sentinel'    => self::sentinel($root),
            ];
        }

        return [
            'ok'             => $limitsOk && $allPersistent && $warnings === [],
            'required_upload_bytes' => $requiredBytes,
            'php'            => $php,
            'persistence'    => ['configured' => $root !== null, 'all_persistent' => $allPersistent, 'volume' => $volume, 'paths' => $paths],
            'warnings'       => $warnings,
        ];
    }

    private static function requiredBytes(): int
    {
        try {
            return max(1, (int) app(FileUploadPolicyService::class)->defaultMaxKb()) * 1024;
        } catch (\Throwable) {
            return max(1, (int) config('upload.max_size_kb', 51200)) * 1024;
        }
    }

    /**
     * ملف صغير على الـ Volume بيسجّل أول deployment شافه. لو بعد نشر جديد لقيناه لسه موجود بـ deployment
     * مختلف => الـ Volume فعلًا بيعدّي من نشر لنشر (ده الإثبات العملي، مش بس إن المسار متركّب).
     */
    private static function sentinel(string $root): ?array
    {
        $file = $root . '/.persist-sentinel.json';
        $current = (string) (getenv('RAILWAY_DEPLOYMENT_ID') ?: '');
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        if (!is_array($data)) {
            if (!is_dir($root) || !is_writable($root)) {
                return null;
            }
            $data = ['first_deployment' => $current ?: null, 'created_at' => gmdate('c')];
            @file_put_contents($file, json_encode($data));
        }

        $first = $data['first_deployment'] ?? null;
        return [
            'created_at'          => $data['created_at'] ?? null,
            'first_deployment'    => $first,
            'current_deployment'  => $current ?: null,
            'survived_redeploy'   => ($first && $current) ? $first !== $current : null,
        ];
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
