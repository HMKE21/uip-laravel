<?php

namespace App\Services;

use App\Support\ChunkedUploadedFile;

/**
 * رفع الملفات الكبيرة على أجزاء (زي تيليجرام) — للشات.
 *
 *  init     : بيسجّل الملف (الاسم/الحجم)، بيرجّع upload_id + حجم الجزء.
 *  putChunk : بيكتب الجزء رقم N في مكانه جوه ملف واحد (fseek) — مفيش مرحلة تجميع، فالمساحة المطلوبة = حجم الملف مرة واحدة.
 *  status   : الأجزاء اللي وصلت (الـ client بيكمّل من الناقص بعد انقطاع النت أو إعادة فتح التطبيق).
 *  consume  : بيرجّع ChunkedUploadedFile جاهز يتبعت لـ MessagingService (الملف بيتنقل مكانه، والمجلد بيتمسح).
 *
 * التخزين: storage/app/chunked/{userId}/{uploadId}/{data.bin,meta.json}. القديم (> ttl_hours) بيتمسح عند أي init.
 */
class ChunkedUploadService
{
    public function __construct(private FileUploadService $uploads, private FileUploadPolicyService $policy)
    {
    }

    /** storage/app/chunked (والعميل: .../chunked/t5) — ids المستخدمين بتتكرر بين العملاء. */
    private function base(): string
    {
        return \App\Services\Tenancy\TenantStorage::storagePath('app/chunked');
    }

    private function root(int $userId): string
    {
        return $this->base() . '/' . $userId;
    }

    private function dir(int $userId, string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new \InvalidArgumentException('Invalid upload id.');
        }
        return $this->root($userId) . '/' . $id;
    }

    private function meta(int $userId, string $id): array
    {
        $file = $this->dir($userId, $id) . '/meta.json';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $meta = $raw ? json_decode($raw, true) : null;
        if (!is_array($meta)) {
            throw new \RuntimeException('Upload not found or expired. Please start it again.');
        }
        return $meta;
    }

    private function cfg(string $key)
    {
        return config('messaging.chunked.' . $key);
    }

    /** @param string $purpose 'messages' (الافتراضي) | 'course_video' (مكتبة فيديوهات المادة) */
    public function init(int $userId, string $name, int $size, ?string $mime, string $purpose = 'messages'): array
    {
        $name = basename(trim($name));
        $video = $purpose === 'course_video';
        $max = $video ? (int) config('upload.course_video_max_mb', 0) * 1048576 : (int) $this->cfg('max_bytes');
        if ($name === '' || $size <= 0) {
            throw new \InvalidArgumentException('File name and size are required.');
        }
        if ($max > 0 && $size > $max) { // 0 = من غير حد
            throw new \RuntimeException('File is larger than the allowed ' . round($max / 1048576) . ' MB.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $this->policy->effectiveAllowed((array) config($video ? 'upload.allowed.course_videos' : 'upload.allowed.messages', []));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new \RuntimeException('File type not allowed.');
        }

        // الملفات الكبيرة (> 25MB) للوسائط والأرشيف والمستندات الثنائية بس — svg/json/كود بيتقرأوا كاملين في الذاكرة عند الفحص.
        $bulky = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'mp3', 'wav', 'ogg', 'm4a', 'zip', 'rar', '7z', 'pdf', 'docx', 'xlsx', 'pptx'];
        $nonBulkyMb = (int) $this->cfg('non_bulky_max_mb'); // 0 = من غير حد
        if (!$video && $nonBulkyMb > 0 && $size > $nonBulkyMb * 1048576 && !in_array($ext, $bulky, true)) {
            throw new \RuntimeException('Files of this type cannot be larger than ' . $nonBulkyMb . ' MB.');
        }

        $this->prune();
        $free = @disk_free_space(storage_path('app'));
        if ($free !== false && $free < $size * 1.2 + 64 * 1048576) {
            throw new \RuntimeException('The server storage is full right now. Please try again later.');
        }

        $id = bin2hex(random_bytes(16));
        $dir = $this->dir($userId, $id);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not prepare the upload.');
        }
        $chunk = $video ? (int) config('upload.course_video_chunk_mb', 2) * 1048576 : (int) $this->cfg('chunk_bytes');
        $meta = [
            'name' => $name, 'size' => $size, 'mime' => $mime, 'chunk' => $chunk,
            'total' => (int) ceil($size / $chunk), 'received' => [], 'created' => time(), 'purpose' => $purpose,
        ];
        // ملف بالحجم الكامل (sparse) — الأجزاء بتتكتب في أماكنها.
        $fh = fopen($dir . '/data.bin', 'wb');
        ftruncate($fh, $size);
        fclose($fh);
        file_put_contents($dir . '/meta.json', json_encode($meta));

        return ['upload_id' => $id, 'name' => $name, 'size' => $size, 'chunk_bytes' => $chunk, 'total_chunks' => $meta['total'], 'received' => []];
    }

    public function status(int $userId, string $id): array
    {
        $m = $this->meta($userId, $id);
        return ['upload_id' => $id, 'name' => $m['name'], 'size' => (int) $m['size'], 'chunk_bytes' => $m['chunk'], 'total_chunks' => $m['total'], 'received' => array_values($m['received']), 'complete' => count($m['received']) === $m['total']];
    }

    /** @param string $tmpPath مسار الجزء المرفوع (ملف PHP tmp) */
    public function putChunk(int $userId, string $id, int $index, string $tmpPath): array
    {
        $dir = $this->dir($userId, $id);
        $lock = fopen($dir . '/meta.json', 'c+');
        if (!$lock) {
            throw new \RuntimeException('Upload not found or expired. Please start it again.');
        }
        flock($lock, LOCK_EX);
        try {
            $m = json_decode((string) stream_get_contents($lock), true);
            if (!is_array($m)) {
                throw new \RuntimeException('Upload not found or expired. Please start it again.');
            }
            if ($index < 0 || $index >= $m['total']) {
                throw new \InvalidArgumentException('Chunk index out of range.');
            }
            $expected = $index === $m['total'] - 1 ? $m['size'] - $m['chunk'] * ($m['total'] - 1) : $m['chunk'];
            $got = (int) @filesize($tmpPath);
            if ($got !== $expected) {
                throw new \RuntimeException("Chunk {$index} has the wrong size ({$got} instead of {$expected}). Please retry.");
            }
            $in = fopen($tmpPath, 'rb');
            $out = fopen($dir . '/data.bin', 'r+b');
            fseek($out, $index * $m['chunk']);
            stream_copy_to_stream($in, $out);
            fflush($out);
            fclose($in);
            fclose($out);

            if (!in_array($index, $m['received'], true)) {
                $m['received'][] = $index;
                sort($m['received']);
            }
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, json_encode($m));
            fflush($lock);
            return ['received' => count($m['received']), 'total_chunks' => $m['total'], 'complete' => count($m['received']) === $m['total']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** يرجّع الملف المجمّع. لازم كل الأجزاء تكون وصلت. المجلد بيتمسح بعد ما MessagingService ينقل الملف (discard). */
    public function open(int $userId, string $id, ?string $purpose = null): ChunkedUploadedFile
    {
        $m = $this->meta($userId, $id);
        if ($purpose !== null && ($m['purpose'] ?? 'messages') !== $purpose) {
            throw new \RuntimeException('This upload cannot be used here.');
        }
        if (count($m['received']) !== $m['total']) {
            throw new \RuntimeException('The upload of "' . $m['name'] . '" is not finished yet.');
        }
        $path = $this->dir($userId, $id) . '/data.bin';
        if (!is_file($path) || filesize($path) !== $m['size']) {
            throw new \RuntimeException('The uploaded file is damaged. Please upload it again.');
        }
        // الرفع بالأجزاء مبيعديش على UipContentSafetyMiddleware كملف — بنفحصه هنا بعد التجميع (غير لائق).
        app(\App\Services\ContentSafetyService::class)->assertFileSafe($path, (string) $m['name']);

        return new ChunkedUploadedFile($path, $m['name'], $m['mime'] ?: null, $id);
    }

    public function discard(int $userId, string $id): void
    {
        $dir = $this->dir($userId, $id);
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    private function prune(): void
    {
        $ttl = max(1, (int) $this->cfg('ttl_hours')) * 3600;
        foreach (glob($this->base() . '/[0-9]*/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - $ttl && (!is_file($d . '/meta.json') || filemtime($d . '/meta.json') < time() - $ttl)) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }
}
