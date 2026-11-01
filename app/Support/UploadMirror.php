<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * نسخة دائمة (S3) لكل ملفات الرفع.
 *
 * المشكلة: ديسك Railway مؤقت — أي Deploy/Restart بيمسح storage/ و public/uploads، فالصف يفضل في الداتابيز والملف يختفي
 * ("الملف غير موجود على السيرفر"). الحل: الديسك المحلي بقى "كاش" والـ S3 هو المصدر الدائم:
 *   - push()    : بعد ما الملف يتخزن محليًا (أو يتعدّل) بنرفعه على S3.
 *   - restore() : لو الملف مش موجود محليًا (بعد Deploy) بنرجّعه من S3 تلقائيًا أول ما حد يطلبه.
 *   - forget()  : مسح الملف من S3 مع مسحه محليًا.
 * كل العمليات fail-open: أي فشل في S3 بيتسجّل في اللوج ومابيوقّفش الرفع/العرض.
 *
 * بيشتغل تلقائيًا لو AWS_BUCKET متظبط. لإيقافه: UPLOAD_MIRROR_DISK=off — أو اختار disk تاني: UPLOAD_MIRROR_DISK=<name>.
 */
class UploadMirror
{
    public static function diskName(): ?string
    {
        $v = config('upload.mirror.disk');
        if (!is_string($v) || trim($v) === '' || in_array(strtolower(trim($v)), ['off', 'false', '0', 'none'], true)) {
            return null;
        }
        return trim($v);
    }

    public static function enabled(): bool
    {
        return self::diskName() !== null;
    }

    private static function disk(): ?Filesystem
    {
        $name = self::diskName();
        if ($name === null) {
            return null;
        }
        try {
            return Storage::disk($name);
        } catch (\Throwable $e) {
            Log::warning('UploadMirror: disk unavailable', ['disk' => $name, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** مفتاح الملف في الـ bucket: <prefix>/<relative path> */
    public static function key(string $relativePath): string
    {
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        $prefix = trim((string) config('upload.mirror.prefix', 'app-files'), '/');
        return ($prefix !== '' ? $prefix . '/' : '') . $rel;
    }

    private static function safeRel(string $relativePath): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        return $rel;
    }

    /** يرفع الملف المحلي على S3 (stream — مفيش تحميل للملف كله في الرام). */
    public static function push(string $relativePath, ?string $localPath = null): bool
    {
        $disk = self::disk();
        $rel = self::safeRel($relativePath);
        if (!$disk || $rel === null) {
            return false;
        }
        $local = $localPath ?? self::localPath($rel);
        if (!$local || !is_file($local)) {
            return false;
        }
        try {
            $h = fopen($local, 'rb');
            if ($h === false) {
                return false;
            }
            try {
                $ok = $disk->put(self::key($rel), $h);
            } finally {
                if (is_resource($h)) {
                    fclose($h);
                }
            }
            if (!$ok) {
                Log::warning('UploadMirror: push returned false', ['path' => $rel]);
            }
            return (bool) $ok;
        } catch (\Throwable $e) {
            Log::warning('UploadMirror: push failed', ['path' => $rel, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** يرجّع الملف من S3 إلى $localPath. true لو الملف بقى موجود محليًا. */
    public static function restore(string $relativePath, string $localPath): bool
    {
        $disk = self::disk();
        $rel = self::safeRel($relativePath);
        if (!$disk || $rel === null) {
            return false;
        }
        try {
            $key = self::key($rel);
            if (!$disk->exists($key)) {
                return false;
            }
            $in = $disk->readStream($key);
            if (!is_resource($in)) {
                return false;
            }
            $dir = dirname($localPath);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                fclose($in);
                return false;
            }
            // اكتب في ملف مؤقت ثم rename ذرّي، عشان طلب تاني متزامن ما يقراش ملف ناقص.
            $tmp = $localPath . '.restore.' . bin2hex(random_bytes(4));
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                fclose($in);
                return false;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            if (!@rename($tmp, $localPath)) {
                @unlink($tmp);
                return is_file($localPath);
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('UploadMirror: restore failed', ['path' => $rel, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function forget(string $relativePath): void
    {
        $disk = self::disk();
        $rel = self::safeRel($relativePath);
        if (!$disk || $rel === null) {
            return;
        }
        try {
            $disk->delete(self::key($rel));
        } catch (\Throwable $e) {
            Log::warning('UploadMirror: delete failed', ['path' => $rel, 'error' => $e->getMessage()]);
        }
    }

    /** المسار المحلي الحالي لملف نسبي (خاص أو عام)، أو null لو مش موجود. */
    private static function localPath(string $rel): ?string
    {
        if (\App\Services\FileUploadService::isPrivatePath($rel)) {
            $private = \App\Services\FileUploadService::privateRoot() . '/' . $rel;
            if (is_file($private)) {
                return $private;
            }
        }
        $public = public_path($rel);
        return is_file($public) ? $public : null;
    }
}
