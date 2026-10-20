<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * عزل الضوضاء من ملف صوت/فيديو مرفوع (بيتطبّق على سيرفر قبل التخزين).
 *
 *  - لو ffmpeg فيه فلتر arnndn وموديل RNNoise موجود (resources/audio/rnnoise-bd.rnnn)
 *    بيستخدم الشبكة العصبية (جودة عالية للكلام).
 *  - غير كده بيرجع لـ afftdn (فلتر FFT) + high-pass.
 *  - الفيديو: بيتنسخ ستريم الصورة كما هو (-c:v copy) وبيتعالج الصوت بس، فمفيش فقد في الجودة ولا وقت إعادة ترميز.
 *  - Fail-open زي MediaProbeService: أي فشل = الملف الأصلي زي ما هو والـ caller بيعرف من القيمة الراجعة.
 */
class AudioDenoiseService
{
    /** آخر سبب فشل (ffmpeg_missing | exec_disabled | unsupported_format | filter_failed | timeout | ...). */
    private ?string $lastReason = null;

    public function lastReason(): ?string
    {
        return $this->lastReason;
    }

    /** امتداد => [codec الصوت, bitrate]. */
    private const CODECS = [
        'webm' => ['libopus', '64k'], 'weba' => ['libopus', '64k'], 'ogg' => ['libopus', '64k'], 'opus' => ['libopus', '64k'],
        'mkv'  => ['libopus', '64k'],
        'mp4'  => ['aac', '128k'],    'm4a'  => ['aac', '128k'],    'mov'  => ['aac', '128k'], 'aac' => ['aac', '128k'],
        'mp3'  => ['libmp3lame', '128k'], 'avi' => ['libmp3lame', '128k'],
        'wav'  => ['pcm_s16le', null],
    ];

    /** الامتدادات اللي ممكن يبقى فيها فيديو (بنحافظ على ستريم الصورة). */
    private const VIDEO_EXT = ['mp4', 'webm', 'mov', 'mkv', 'avi'];

    public function supports(string $extension): bool
    {
        return isset(self::CODECS[strtolower($extension)]);
    }

    public function available(): bool
    {
        return $this->commandExists($this->ffmpegBinary());
    }

    public function modelPath(): ?string
    {
        $path = (string) config('messaging.denoise.model_path', resource_path('audio/rnnoise-bd.rnnn'));
        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * بيعالج الملف المرفوع "في مكانه" (نفس المسار المؤقت) عشان FileUploadService::store() يخزّن النسخة النضيفة.
     * @return bool true لو اتعالج فعلاً.
     */
    public function denoiseUpload(UploadedFile $file): bool
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $in  = $file->getRealPath();
        $this->lastReason = null;
        if (!$in || !$this->supports($ext)) {
            $this->lastReason = 'unsupported_format';
            return false;
        }
        if (!function_exists('exec')) {
            $this->lastReason = 'exec_disabled';
            return false;
        }
        if (!$this->available()) {
            $this->lastReason = 'ffmpeg_missing';
            return false;
        }

        $out = tempnam(sys_get_temp_dir(), 'dn_');
        if ($out === false) {
            return false;
        }
        @unlink($out);
        $out .= '.' . $ext;

        $ok = $this->run($in, $out, $ext, true);
        if ($ok && $this->modelPath() !== null && $this->lostTooMuch($in, $out)) {
            // موديل RNNoise متدرّب على الكلام: على موسيقى / أصوات مش كلام بيمسحها. لو الصوت وقع أكتر من 6dB
            // نرجع لفلتر الضوضاء الثابتة (afftdn) اللي بيسيب الموسيقى سليمة.
            @unlink($out);
            $ok = $this->run($in, $out, $ext, false);
        } elseif (!$ok && $this->modelPath() !== null) {
            // arnndn ممكن يكون مش متضمّن في build الـ ffmpeg أو الموديل مش شغال → نجرب فلتر afftdn العادي قبل ما نستسلم.
            @unlink($out);
            $ok = $this->run($in, $out, $ext, false);
        }
        if (!$ok && $this->lastReason === null) {
            $this->lastReason = 'filter_failed';
        }
        if ($ok) {
            // copy() مش rename(): مسار الـ tmp ممكن يكون على filesystem تاني.
            $ok = @copy($out, $in);
        }
        @unlink($out);
        return $ok;
    }

    private function run(string $in, string $out, string $ext, bool $useModel = true): bool
    {
        [$codec, $bitrate] = self::CODECS[$ext];
        $model = $useModel ? $this->modelPath() : null;

        // كل فلتر بيأخّر الصوت (arnndn ≈ 10ms، afftdn ≈ 25ms): بنقصّ التأخير ده عشان الصوت يفضل متزامن مع الصورة
        // (الفيديو بيتنسخ -c:v copy). arnndn بيقلّل مستوى الصوت ~2dB فبنرجّعه مع limiter يمنع الكليبينج.
        $filter = $model
            ? 'highpass=f=60,arnndn=m=' . basename($model) . ':mix=1,atrim=start=0.010,asetpts=PTS-STARTPTS,volume=2dB,alimiter=limit=0.97,aresample=async=1'
            : 'highpass=f=60,afftdn=nf=-35:nr=18:tn=1,atrim=start=0.025,asetpts=PTS-STARTPTS,alimiter=limit=0.97,aresample=async=1';

        $cmd = [$this->ffmpegBinary(), '-nostdin', '-y', '-v', 'error', '-i', $in];
        if (in_array($ext, self::VIDEO_EXT, true)) {
            array_push($cmd, '-map', '0:v?', '-c:v', 'copy');
        }
        array_push($cmd, '-map', '0:a:0', '-af', $filter, '-c:a', $codec);
        if ($bitrate) {
            array_push($cmd, '-b:a', $bitrate);
        }
        if ($ext === 'mp4' || $ext === 'm4a' || $ext === 'mov') {
            array_push($cmd, '-movflags', '+faststart');
        }
        $cmd[] = $out;

        $log = tempnam(sys_get_temp_dir(), 'dnlog_');
        $proc = @proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
            $pipes,
            $model ? dirname($model) : null // arnndn بياخد اسم الملف نسبي → من غير مشاكل escape لمسارات Windows (C:\…)
        );
        if (!is_resource($proc)) {
            @unlink($log);
            return false;
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + max(5, $this->timeout());
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($proc, 9);
            proc_close($proc);
            $this->lastReason = 'timeout';
            Log::warning('Noise reduction timed out', ['file' => basename($in)]);
            @unlink($log);
            return false;
        }
        $exit = $status['exitcode'];
        proc_close($proc);

        $good = ($exit === 0 || $exit === -1) && is_file($out) && filesize($out) > 0;
        if (!$good) {
            Log::warning('ffmpeg noise reduction failed', ['file' => basename($in), 'exit' => $exit, 'log' => mb_substr((string) @file_get_contents($log), -600)]);
        }
        @unlink($log);
        return $good && $exit === 0;
    }

    /** هل الصوت بعد المعالجة أقل من الأصلي بأكتر من 6dB؟ (علامة إن الموديل مسح موسيقى / صوت مش كلام). */
    private function lostTooMuch(string $in, string $out): bool
    {
        $a = $this->meanVolume($in);
        $b = $this->meanVolume($out);
        return $a !== null && $b !== null && ($a - $b) > 6.0;
    }

    private function meanVolume(string $path): ?float
    {
        $cmd = escapeshellarg($this->ffmpegBinary()) . ' -nostdin -v info -t 90 -i ' . escapeshellarg($path)
            . ' -vn -map 0:a:0 -af volumedetect -f null - 2>&1';
        @exec($cmd, $lines, $code);
        foreach ((array) $lines as $line) {
            if (preg_match('/mean_volume:\s*(-?[0-9.]+)\s*dB/', (string) $line, $m)) {
                return (float) $m[1];
            }
        }
        return null;
    }

    private function commandExists(string $binary): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $check = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        @exec($check, $output, $code);
        return $code === 0 && !empty($output);
    }

    private function ffmpegBinary(): string
    {
        return (string) config('messaging.media_probe.ffmpeg_binary', 'ffmpeg');
    }

    private function timeout(): int
    {
        // معالجة صوت كامل أطول من thumbnail: ضعف المدة المسموحة للـ probe كحد أدنى 120 ثانية.
        return (int) config('messaging.denoise.timeout', 180);
    }
}
