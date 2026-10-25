<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * نسخة طبق الأصل من app/Services/MediaProbeService.php القديمة — نفس
 * الـ fail-open pattern بتاع FileUploadService::scanForMalware(): لو
 * ffmpeg/ffprobe مش متثبتين على السيرفر، الفيديو لسه بيترفع عادي، بس
 * thumbnail/duration بيفضلوا null (بالظبط سلوك القديم) — أداة CLI
 * اختيارية ناقصة عمرها ما توقف رفع ملف. الفرق الوحيد عن القديمة:
 * base_path('public/'...) بقت public_path(...) (زي FileUploadService).
 */
class MediaProbeService
{
    /** true بس لو ffprobe و ffmpeg الاتنين موجودين على السيرفر ده. */
    public function available(): bool
    {
        return $this->commandExists($this->ffprobeBinary()) && $this->commandExists($this->ffmpegBinary());
    }

    /** المدة بالثانية الكاملة لفيديو مرفوع، أو null لو ffprobe مش متاح أو الملف مش وسائط مقروءة. عمرها ما بترمي — المدة metadata، مش بوابة رفع. */
    public function duration(string $absolutePath): ?int
    {
        if (!is_file($absolutePath) || !$this->commandExists($this->ffprobeBinary())) {
            return null;
        }

        $command = sprintf(
            'timeout %d %s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            max(1, $this->timeout()),
            escapeshellcmd($this->ffprobeBinary()),
            escapeshellarg($absolutePath)
        );

        exec($command, $output, $exitCode);
        $raw = trim(implode('', $output));

        if ($exitCode !== 0 || $raw === '' || !is_numeric($raw)) {
            Log::warning('ffprobe could not read duration for attachment', [
                'file' => basename($absolutePath), 'exit_code' => $exitCode, 'output' => implode(' | ', $output),
            ]);
            return null;
        }

        $seconds = (float) $raw;
        return $seconds > 0 ? (int) round($seconds) : null;
    }

    /**
     * تسجيلات الصوت من MediaRecorder (webm/ogg) بتتخزن من غير مدة ولا فهرس seek: ffprobe بيرجّع N/A والمتصفح عند المستلم
     * بيضطر ينزّل الملف كله ويعمل seek وهمي عشان يعرف طوله، فالتشغيل بيتقطع/بيتنطط. هنا بنعيد تغليف الصوت بدون أي
     * إعادة ترميز (-c:a copy، ثواني وبدون فقد جودة) فيطلع الملف بمدة وفهرس صحيحين، وبنرجّع المدة بالثواني.
     * Fail-open: لو ffmpeg مش موجود أو فشل، الملف الأصلي بيفضل زي ما هو وبنرجّع null.
     */
    public function normalizeAudio(string $absolutePath): ?int
    {
        if (!is_file($absolutePath) || !$this->available()) {
            return null;
        }
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $format = ['webm' => 'webm', 'weba' => 'webm', 'ogg' => 'ogg', 'opus' => 'ogg'][$ext] ?? null;
        if ($format === null) {
            return $this->duration($absolutePath);
        }

        $tmp = $absolutePath . '.fix.' . $ext;
        $command = sprintf(
            'timeout %d %s -v error -y -i %s -vn -c:a copy -f %s %s 2>&1',
            max(1, $this->timeout()),
            escapeshellcmd($this->ffmpegBinary()),
            escapeshellarg($absolutePath),
            $format,
            escapeshellarg($tmp)
        );
        exec($command, $output, $exitCode);

        if ($exitCode === 0 && is_file($tmp) && filesize($tmp) > 0 && $this->duration($tmp) !== null) {
            @rename($tmp, $absolutePath);
            clearstatcache(true, $absolutePath);
        } else {
            @unlink($tmp);
            Log::warning('ffmpeg audio remux failed for attachment', ['file' => basename($absolutePath), 'output' => implode(' | ', $output)]);
        }

        return $this->duration($absolutePath);
    }

    /**
     * بيسحب فريم JPEG واحد من فيديو عشان يستخدم كصورة مصغّرة له في
     * المعرض. بيرجع مسار الصورة نسبي لـ public/ (نفس اتفاق
     * FileUploadService::store()'s stored_path)، أو null على أي فشل.
     */
    public function videoThumbnail(string $absoluteVideoPath, string $relativeDir): ?string
    {
        if (!is_file($absoluteVideoPath) || !$this->commandExists($this->ffmpegBinary())) {
            return null;
        }

        $targetDir = public_path(trim($relativeDir, '/') . '/thumbnails');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            Log::warning('Could not prepare video thumbnail directory', ['dir' => $targetDir]);
            return null;
        }

        $thumbName = bin2hex(random_bytes(16)) . '.jpg';
        $targetPath = $targetDir . '/' . $thumbName;

        // بيجرب ثانية 0.5 الأول (بتتخطى فريم افتتاحي أسود في أغلب المقاطع)؛
        // مقاطع قصيرة جدًا ممكن الـ seek ده يفشل معاها، فبيرجع لأول فريم
        // قبل ما يستسلم.
        foreach (['00:00:00.5', '00:00:00.0'] as $seekTo) {
            if ($this->extractFrame($absoluteVideoPath, $targetPath, $seekTo)) {
                return trim($relativeDir, '/') . '/thumbnails/' . $thumbName;
            }
        }

        Log::warning('ffmpeg could not generate a video thumbnail', ['file' => basename($absoluteVideoPath)]);
        return null;
    }

    private function extractFrame(string $absoluteVideoPath, string $targetPath, string $seekTo): bool
    {
        @unlink($targetPath);

        $maxWidth = $this->thumbnailMaxWidth();
        $command = sprintf(
            'timeout %d %s -y -ss %s -i %s -frames:v 1 -vf %s -q:v 4 %s 2>&1',
            max(1, $this->timeout()),
            escapeshellcmd($this->ffmpegBinary()),
            escapeshellarg($seekTo),
            escapeshellarg($absoluteVideoPath),
            escapeshellarg("scale='min({$maxWidth},iw)':-2"),
            escapeshellarg($targetPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($targetPath) || filesize($targetPath) === 0) {
            @unlink($targetPath);
            return false;
        }

        return true;
    }

    private function commandExists(string $binary): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $checkCmd = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        @exec($checkCmd, $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    private function ffmpegBinary(): string
    {
        return (string) config('messaging.media_probe.ffmpeg_binary', 'ffmpeg');
    }

    private function ffprobeBinary(): string
    {
        return (string) config('messaging.media_probe.ffprobe_binary', 'ffprobe');
    }

    private function timeout(): int
    {
        return (int) config('messaging.media_probe.timeout', 15);
    }

    private function thumbnailMaxWidth(): int
    {
        return (int) config('messaging.media_probe.thumbnail_max_width', 480);
    }
}
