<?php

namespace App\Services;

use App\Support\Cmd;
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

        $res = Cmd::run(
            [$this->ffprobeBinary(), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $absolutePath],
            max(1, $this->timeout()),
            true
        );
        $output = $res['lines'];
        $exitCode = $res['code'];
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
        $res = Cmd::run(
            [$this->ffmpegBinary(), '-v', 'error', '-y', '-i', $absolutePath, '-vn', '-c:a', 'copy', '-f', $format, $tmp],
            max(1, $this->timeout()),
            true
        );
        $output = $res['lines'];
        $exitCode = $res['code'];

        if ($exitCode === 0 && is_file($tmp) && filesize($tmp) > 0 && $this->duration($tmp) !== null) {
            @rename($tmp, $absolutePath);
            clearstatcache(true, $absolutePath);
        } else {
            \App\Support\SafePath::delete($tmp);
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

        $targetDir = FileUploadService::diskDir(trim($relativeDir, '/') . '/thumbnails');
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
                $rel = trim($relativeDir, '/') . '/thumbnails/' . $thumbName;
                \App\Support\UploadMirror::push($rel, $targetPath); // الصورة المصغّرة تتنسخ على S3 كمان
                return $rel;
            }
        }

        Log::warning('ffmpeg could not generate a video thumbnail', ['file' => basename($absoluteVideoPath)]);
        return null;
    }

    /** كل صيغ الفيديو المقبولة للرفع (بتتحوّل لـ mp4/H.264 على السيرفر بعد الرفع). */
    public const VIDEO_EXTENSIONS = [
        'mp4', 'm4v', 'mov', 'qt', 'webm', 'mkv', 'avi', '3gp', '3g2',
        'wmv', 'asf', 'flv', 'mpg', 'mpeg', 'mts', 'm2ts', 'ogv',
    ];

    /**
     * يخلّي أي فيديو مرفوع قابل للتشغيل في كل المتصفحات: mp4 (H.264 + AAC) و yuv420p و faststart.
     *  - لو الترميز أصلاً مناسب: بيعمل remux بدون إعادة ترميز (سريع، من غير فقد جودة) عشان الحاوية/الـ moov تبقى سليمة.
     *  - لو الفيديو (HEVC / VP9 / AV1 / MPEG-4 / WMV / 10-bit ...) أو الصوت (AC3 / Opus / PCM ...) مش مناسب: بيحوّله.
     * Fail-open: لو ffmpeg مش موجود أو فشل أي شيء، الملف الأصلي بيفضل زي ما هو والدالة بترجع null.
     *
     * @param  string $storedPath مسار نسبي (نفس stored_path)
     * @return array{stored_path:string,extension:string,mime_type:string,size_bytes:int}|null  null = ماتغيّرش حاجة
     */
    public function makeWebPlayable(string $storedPath): ?array
    {
        if (!config('messaging.media_probe.transcode_enabled', true)) {
            return null;
        }
        $abs = FileUploadService::absolutePath($storedPath);
        if (!is_file($abs) || !$this->available()) {
            return null;
        }

        $info = $this->probeStreams($abs);
        if ($info === null || $info['video'] === null) {
            return null; // مش فيديو (أو ffprobe مش قادر يقراه)
        }

        $videoOk = $info['video']['codec'] === 'h264'
            && in_array($info['video']['pix_fmt'], ['yuv420p', 'yuvj420p', ''], true);
        $audioOk = $info['audio'] === null || in_array($info['audio'], ['aac', 'mp3'], true);

        $dir = dirname($storedPath);
        $targetRel = ($dir === '.' ? '' : $dir . '/') . bin2hex(random_bytes(16)) . '.mp4';
        $target = FileUploadService::diskDir($dir) . '/' . basename($targetRel); // diskDir: من غير ما نحاول نستعيد ملف لسه مش موجود من S3

        @set_time_limit(0);
        $timeout = max(60, (int) config('messaging.media_probe.transcode_timeout', 1800));

        // المحاولة الأولى: ننسخ اللي مناسب وبنحوّل الباقي فقط. لو فشلت (ملف غريب) نحوّل كل حاجة.
        $attempts = [[$videoOk, $audioOk]];
        if ($videoOk || $audioOk) {
            $attempts[] = [false, false];
        }

        $done = false;
        foreach ($attempts as [$copyVideo, $copyAudio]) {
            \App\Support\SafePath::delete($target);
            $argv = [
                $this->ffmpegBinary(), '-nostdin', '-v', 'error', '-y', '-i', $abs,
                '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-map_metadata', '0',
            ];
            if ($copyVideo) {
                array_push($argv, '-c:v', 'copy');
            } else {
                array_push(
                    $argv,
                    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                    '-pix_fmt', 'yuv420p', '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2'
                );
            }
            if ($copyAudio) {
                array_push($argv, '-c:a', 'copy');
            } else {
                array_push($argv, '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
            }
            array_push($argv, '-movflags', '+faststart', '-max_muxing_queue_size', '2048', '-f', 'mp4', $target);

            $res = Cmd::run($argv, $timeout, true);
            if ($res['code'] === 0 && is_file($target) && filesize($target) > 0) {
                $check = $this->probeStreams($target);
                if ($check !== null && $check['video'] !== null) {
                    $done = true;
                    break;
                }
            }
            Log::warning('ffmpeg video normalize attempt failed', [
                'file' => basename($abs), 'copy_video' => $copyVideo, 'copy_audio' => $copyAudio,
                'timeout' => $res['timeout'], 'output' => mb_substr(implode(' | ', $res['lines']), -600),
            ]);
        }

        if (!$done) {
            \App\Support\SafePath::delete($target);
            return null;
        }

        clearstatcache(true, $target);
        $size = (int) (@filesize($target) ?: 0);

        // النسخة الجديدة تتحفظ على S3 والأصلية تتشال (محلي + S3).
        \App\Support\UploadMirror::push($targetRel, $target);
        \App\Support\SafePath::delete($abs);
        \App\Support\UploadMirror::forget($storedPath);

        return [
            'stored_path' => $targetRel,
            'extension'   => 'mp4',
            'mime_type'   => 'video/mp4',
            'size_bytes'  => $size,
        ];
    }

    /** يطبّق نتيجة makeWebPlayable على مصفوفة store() (المسار/الامتداد/النوع/الحجم/اسم الملف الأصلي). */
    public static function applyConversion(array $stored, array $conv): array
    {
        $stored['stored_path'] = $conv['stored_path'];
        $stored['extension'] = $conv['extension'];
        $stored['mime_type'] = $conv['mime_type'];
        $stored['size_bytes'] = $conv['size_bytes'];
        if (!empty($stored['original_name'])) {
            $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', (string) $stored['original_name']);
            $stored['original_name'] = ($base !== '' ? $base : 'video') . '.mp4';
        }

        return $stored;
    }

    /** @return array{video:?array{codec:string,pix_fmt:string},audio:?string}|null */
    private function probeStreams(string $abs): ?array
    {
        $res = Cmd::run(
            [$this->ffprobeBinary(), '-v', 'error', '-print_format', 'json', '-show_streams', $abs],
            max(15, $this->timeout())
        );
        if ($res['code'] !== 0) {
            return null;
        }
        $json = json_decode($res['out'], true);
        if (!is_array($json)) {
            return null;
        }
        $video = null;
        $audio = null;
        foreach ((array) ($json['streams'] ?? []) as $st) {
            $type = (string) ($st['codec_type'] ?? '');
            if ($type === 'video' && $video === null) {
                if (!empty($st['disposition']['attached_pic'])) {
                    continue; // غلاف صوت مش فيديو حقيقي
                }
                $video = [
                    'codec'   => strtolower((string) ($st['codec_name'] ?? '')),
                    'pix_fmt' => strtolower((string) ($st['pix_fmt'] ?? '')),
                ];
            } elseif ($type === 'audio' && $audio === null) {
                $audio = strtolower((string) ($st['codec_name'] ?? ''));
            }
        }

        return ['video' => $video, 'audio' => $audio];
    }

    private function extractFrame(string $absoluteVideoPath, string $targetPath, string $seekTo): bool
    {
        \App\Support\SafePath::delete($targetPath);

        $maxWidth = $this->thumbnailMaxWidth();
        $res = Cmd::run(
            [$this->ffmpegBinary(), '-y', '-ss', $seekTo, '-i', $absoluteVideoPath, '-frames:v', '1', '-vf', "scale='min({$maxWidth},iw)':-2", '-q:v', '4', $targetPath],
            max(1, $this->timeout()),
            true
        );
        $exitCode = $res['code'];

        if ($exitCode !== 0 || !is_file($targetPath) || filesize($targetPath) === 0) {
            \App\Support\SafePath::delete($targetPath);
            return false;
        }

        return true;
    }

    private function commandExists(string $binary): bool
    {
        return Cmd::exists($binary);
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
