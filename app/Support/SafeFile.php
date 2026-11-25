<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * نقطة التسليم الوحيدة للملفات (download / inline / Storage disk) — بديل response()->download/file في الـ controllers.
 *
 * ليه مركزي؟ كل مسار ملف جاي من DB أو من service (حتى لو الـ service بتبنيه من ids) بيعدّي هنا قبل ما يوصل لأي دالة
 * بتفتح ملف: لو المسار مش ملف عادي موجود فعلاً جوه الجذور المسموحة (public / storage / temp / الـ Volume الدائم) أو لو فيه NUL
 * أو اسمه dotfile => 404 JSON بنفس شكل apiError. التحقق بالـ realpath (بيحلّ .. و symlinks)، فمفيش traversal ولا
 * symlink يهرّب برّه الجذور. الـ controllers ما بقاش فيها أي sink مباشر لمسار ملف.
 */
final class SafeFile
{
    /** @return list<string> الجذور المسموح التسليم منها (مطبّعة بـ "/"، ومعاها نسخة الـ realpath لو الجذر symlink) */
    public static function roots(): array
    {
        $cands = [public_path(), storage_path(), sys_get_temp_dir()];
        $up = (string) ini_get('upload_tmp_dir');
        if ($up !== '') {
            $cands[] = $up;
        }
        $persist = PersistentStorage::root();
        if ($persist !== null) {
            $cands[] = $persist;
        }
        $roots = [];
        foreach ($cands as $c) {
            foreach ([$c, @realpath($c) ?: null] as $v) {
                $n = is_string($v) ? SafePath::normalize($v) : null;
                if ($n !== null && $n !== '' && $n !== '/' && !in_array($n, $roots, true)) {
                    $roots[] = $n;
                }
            }
        }

        return $roots;
    }

    /**
     * المسار الحقيقي (realpath) لملف عادي موجود جوه الجذور المسموحة، أو null لأي حاجة تانية.
     */
    public static function resolve(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, "\0")) {
            return null;
        }
        $real = @realpath($path);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $n = SafePath::normalize($real);
        if ($n === null || str_starts_with(basename($n), '.')) {
            return null;
        }
        foreach (self::roots() as $root) {
            if (str_starts_with($n, rtrim($root, '/') . '/')) {
                return $real;
            }
        }

        return null;
    }

    /** نفس شكل Controller::apiError(). */
    public static function notFound(string $message = 'File not found.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => null,
            'meta'    => (object) [],
        ], 404);
    }

    /**
     * تنزيل (attachment). $deleteAfter لملفات الـ temp اللي الـ service عملتها (zip / pdf / csv).
     *
     * @param array<string,string> $headers
     */
    public static function download(?string $path, ?string $name = null, array $headers = [], bool $deleteAfter = false): JsonResponse|BinaryFileResponse
    {
        $real = self::resolve($path);
        if ($real === null) {
            self::reject('download', (string) $path);

            return self::notFound();
        }
        $response = response()->download($real, $name, $headers);
        if ($deleteAfter) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    /**
     * عرض inline (response()->file) — الـ headers (Content-Type / Content-Disposition / CSP) من الكولر.
     *
     * @param array<string,string> $headers
     */
    public static function inline(?string $path, array $headers = []): JsonResponse|BinaryFileResponse
    {
        $real = self::resolve($path);
        if ($real === null) {
            self::reject('inline', (string) $path);

            return self::notFound();
        }

        return response()->file($real, $headers);
    }

    /**
     * تنزيل من Storage disk (المسار من DB). الـ disk لازم يكون من الـ disks المعرّفة في config/filesystems.php،
     * والمسار نسبي نضيف (SafePath::clean) وموجود فعلاً.
     *
     * @param array<string,string> $headers
     */
    public static function storageDownload(?string $diskName, ?string $path, ?string $name = null, array $headers = []): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rel = SafePath::clean($path);
        $known = array_keys((array) config('filesystems.disks', []));
        if ($rel === null || $diskName === null || !in_array($diskName, $known, true)) {
            self::reject('storage', (string) $path);

            return self::notFound();
        }
        $disk = Storage::disk($diskName);
        if (!$disk->exists($rel)) {
            return self::notFound();
        }

        return $disk->download($rel, $name, $headers);
    }

    private static function reject(string $op, string $path): void
    {
        try {
            Log::warning('SafeFile refused to serve a path', ['op' => $op, 'path' => mb_substr(str_replace("\0", '\\0', $path), 0, 200)]);
        } catch (\Throwable $e) {
            // اللوج مش لازم يكسر الطلب
        }
    }
}
