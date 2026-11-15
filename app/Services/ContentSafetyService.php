<?php

namespace App\Services;

use App\Support\ContentLexicon;
use App\Support\SecurityLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * فحص المحتوى المركزي: نصوص + صور + فيديو + GIF. شوف config/content_safety.php.
 *
 * كل الدوال "assert*" بترمي ContentBlockedException (رسالتها آمنة تتعرض للمستخدم)،
 * والدوال "check*" بترجّع النتيجة من غير ما ترمي.
 */
class ContentSafetyService
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'avif'];
    private const VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi', '3gp'];

    public function enabled(): bool
    {
        return (bool) config('content_safety.enabled', true);
    }

    // ───────────────────────── النصوص ─────────────────────────

    /** @return string[] */
    private function csv(string $v): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\n,;،]+/u', $v) ?: []), fn ($w) => $w !== ''));
    }

    /** @return array{tier:int,category:string,term:string}|null */
    public function checkText(string $text, bool $strict = false, array $extra = []): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $extraWords = array_merge($this->csv((string) config('content_safety.extra_block', '')), $extra);

        return ContentLexicon::check($text, $strict, $extraWords, $this->csv((string) config('content_safety.allow', '')));
    }

    /** @throws ContentBlockedException */
    public function assertText(string $text, bool $strict = false, string $kind = 'text', array $extra = []): void
    {
        $hit = $this->checkText($text, $strict, $extra);
        if ($hit) {
            $this->record($kind, $hit['category'], $hit['term']);
            throw new ContentBlockedException($this->message($hit['category']), $hit['category'], $kind);
        }
    }

    private function isArabic(): bool
    {
        return request()?->header('X-Locale', 'en') === 'ar';
    }

    /** سبب الحظر بجملة قصيرة (بتتعرض للمستخدم جوه رسالة الحظر وفي errors.reason). */
    public function reasonLabel(string $category = 'sexual'): string
    {
        $ar = $this->isArabic();

        return match ($category) {
            'gore' => $ar ? 'محتوى عنيف أو مشاهد مؤذية' : 'graphic violence or harmful imagery',
            'child_safety' => $ar ? 'محتوى يمسّ سلامة الأطفال' : 'content that endangers children',
            'profanity' => $ar ? 'ألفاظ نابية أو شتائم' : 'profanity or abusive language',
            'custom' => $ar ? 'كلمات محظورة من إدارة المنصة' : 'words blocked by the platform administrators',
            'scanner_unavailable' => $ar ? 'تعذّر فحص المحتوى حاليًا' : 'the content could not be checked right now',
            default => $ar ? 'محتوى إباحي أو جنسي' : 'pornographic or sexually explicit content',
        };
    }

    /** رسالة الحظر الكاملة (فيها السبب). */
    public function message(string $category = 'sexual'): string
    {
        $ar = $this->isArabic();
        $reason = $this->reasonLabel($category);
        if ($category === 'scanner_unavailable') {
            return $this->unavailableMessage();
        }

        return $ar
            ? 'تم منع الإرسال — السبب: ' . $reason . '. المحتوى الإباحي أو المخلّ أو الحسّاس ممنوع تمامًا في المنصة.'
            : 'Blocked — reason: ' . $reason . '. Pornographic, indecent or sensitive content is strictly prohibited on the platform.';
    }

    // ───────────────────────── GIF ─────────────────────────

    public function maxGifRating(): string
    {
        $r = strtolower((string) config('content_safety.gif_max_rating', 'pg'));

        return in_array($r, ['g', 'pg', 'pg-13'], true) ? $r : 'pg';
    }

    /** @return string[] التصنيفات المسموحة لحد الأعلى. */
    public function allowedGifRatings(?string $cap = null): array
    {
        $order = ['g', 'pg', 'pg-13'];
        $cap = in_array($cap, $order, true) ? $cap : $this->maxGifRating();
        $max = array_search($this->minRating($cap, $this->maxGifRating()), $order, true);

        return array_slice($order, 0, $max + 1);
    }

    /** الأقل تساهلًا بين تصنيفين (مفيش 'r' أبدًا). */
    public function minRating(string $a, string $b): string
    {
        $order = ['g' => 0, 'pg' => 1, 'pg-13' => 2];
        $a = $order[$a] ?? 1;
        $b = $order[$b] ?? 1;

        return array_search(min($a, $b), $order, true) ?: 'pg';
    }

    // ───────────────────────── الملفات ─────────────────────────

    /** @return 'image'|'video'|null */
    public function mediaKind(string $path, string $name = ''): ?string
    {
        $ext = strtolower(pathinfo($name !== '' ? $name : $path, PATHINFO_EXTENSION));
        $mime = '';
        if (is_file($path) && function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = (string) @finfo_file($f, $path);
                @finfo_close($f);
            }
        }
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (in_array($ext, self::IMAGE_EXT, true)) {
            return 'image';
        }
        if (in_array($ext, self::VIDEO_EXT, true)) {
            return 'video';
        }

        return null;
    }

    /**
     * فحص ملف مرفوع كامل: اسم الملف + (لو صورة/فيديو/GIF) المحتوى نفسه + (SVG) النص اللي جواه.
     * @throws ContentBlockedException
     */
    public function assertFileSafe(string $path, string $displayName, bool $scanMedia = true): void
    {
        if (!$this->enabled() || !is_file($path)) {
            return;
        }
        $base = pathinfo($displayName, PATHINFO_FILENAME);
        $this->assertText(str_replace(['_', '-', '.'], ' ', $base), false, 'filename');

        $ext = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        if ($ext === 'svg' && filesize($path) < 1_500_000) {
            $this->assertText((string) @file_get_contents($path), false, 'svg_text');
        }
        if (!$scanMedia) {
            return;
        }
        $kind = $this->mediaKind($path, $displayName);
        if ($kind === null) {
            return;
        }
        $key = 'csafe:file:' . $this->provider() . ':' . @sha1_file($path); // المزوّد في المفتاح: لما الأدمن يفعّل فاحص، النتايج القديمة "نضيف" متتعتبرش
        $cached = Cache::get($key);
        if ($cached === 'ok') {
            return;
        }
        if (is_array($cached)) {
            $this->record($kind, $cached['category'], $cached['reason'] . ' (cached)');
            throw new ContentBlockedException($this->message($cached['category']), $cached['category'], $kind);
        }
        $verdict = $this->moderateMedia($path, $kind);
        if ($verdict === null) {
            Cache::put($key, 'ok', now()->addDays(7));

            return;
        }
        Cache::put($key, $verdict, now()->addDays(7));
        $this->record($kind, $verdict['category'], $verdict['reason']);
        throw new ContentBlockedException($this->message($verdict['category']), $verdict['category'], $kind);
    }

    /** @throws ContentBlockedException */
    public function assertUploadSafe(?UploadedFile $file, bool $scanMedia = true): void
    {
        if ($file && $file->isValid() && $file->getRealPath()) {
            $this->assertFileSafe($file->getRealPath(), $file->getClientOriginalName(), $scanMedia);
        }
    }

    /** data:image/...;base64,... جوه نص (محرر الوورد، الستوري…). @throws ContentBlockedException */
    public function assertDataImagesIn(string $text): void
    {
        if (!$this->enabled() || stripos($text, 'data:image/') === false) {
            return;
        }
        if (!preg_match_all('#data:image/(?:png|jpe?g|gif|webp|bmp);base64,([A-Za-z0-9+/=\r\n]{200,})#i', $text, $m)) {
            return;
        }
        foreach (array_slice($m[1], 0, 6) as $b64) {
            if (strlen($b64) > 16_000_000) {
                continue;
            }
            $bin = base64_decode(str_replace(["\r", "\n"], '', $b64), true);
            if ($bin === false || $bin === '') {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'csd');
            if ($tmp === false) {
                continue;
            }
            try {
                file_put_contents($tmp, $bin);
                $this->assertFileSafe($tmp, 'inline.img', true);
            } finally {
                @unlink($tmp);
            }
        }
    }

    /** @var array<int,array<string,mixed>>|null درجات الفاحص لكل إطار — بتتملي بس أثناء analyzeFile (صفحة الأدمن). */
    private ?array $trace = null;

    /**
     * اختبار ملف من صفحة الأدمن: بيرجّع الحكم + درجات الفاحص لكل إطار، من غير كاش ومن غير تسجيل في سجل المحاولات.
     *
     * @return array{kind:?string,provider:string,scanned:bool,blocked:bool,category:?string,reason:?string,error:?string,filename_hit:?array,frames:array,thresholds:array,note:?string}
     */
    public function analyzeFile(string $path, string $displayName): array
    {
        $out = [
            'kind' => null, 'provider' => $this->provider(), 'scanned' => false, 'blocked' => false, 'category' => null,
            'reason' => null, 'error' => null, 'filename_hit' => null, 'frames' => [], 'thresholds' => (array) config('content_safety.thresholds', []),
            'note' => null,
        ];
        $base = str_replace(['_', '-', '.'], ' ', pathinfo($displayName, PATHINFO_FILENAME));
        $hit = $this->checkText($base, false);
        if ($hit !== null) {
            $out['filename_hit'] = $hit;
            $out['blocked'] = true;
            $out['category'] = $hit['category'] ?? 'sexual';
            $out['reason'] = 'filename: ' . ($hit['term'] ?? '');
        }
        if (strtolower(pathinfo($displayName, PATHINFO_EXTENSION)) === 'svg' && filesize($path) < 1_500_000) {
            $svgHit = $this->checkText((string) @file_get_contents($path), false);
            if ($svgHit !== null && !$out['blocked']) {
                $out['blocked'] = true;
                $out['category'] = $svgHit['category'] ?? 'sexual';
                $out['reason'] = 'svg_text: ' . ($svgHit['term'] ?? '');
            }
        }

        $kind = $this->mediaKind($path, $displayName);
        $out['kind'] = $kind;
        if ($kind === null) {
            $out['note'] = 'unsupported_type'; // مش صورة/فيديو: اسم الملف (ونص SVG) بس اتفحصوا
            return $out;
        }
        if ($out['provider'] === 'none') {
            $out['note'] = 'no_provider';

            return $out;
        }

        $this->trace = [];
        $failOpen = config('content_safety.fail_open', false);
        config(['content_safety.fail_open' => false]); // في الاختبار نعرض أخطاء الفاحص بدل ما نخبّيها
        try {
            $verdict = $this->moderateMedia($path, $kind);
            $out['scanned'] = true;
            if ($verdict !== null && !$out['blocked']) {
                $out['blocked'] = true;
                $out['category'] = $verdict['category'];
                $out['reason'] = $verdict['reason'];
            }
        } catch (ContentBlockedException $e) {
            $out['error'] = $e->getMessage();
        } finally {
            config(['content_safety.fail_open' => $failOpen]);
            $out['frames'] = $this->trace ?? [];
            $this->trace = null;
        }

        return $out;
    }

    // ───────────────────────── مزوّد الصور ─────────────────────────

    public function provider(): string
    {
        $p = strtolower((string) config('content_safety.provider', 'auto'));
        $hasOpenAi = trim((string) config('content_safety.openai.key', '')) !== '';
        $hasSe = trim((string) config('content_safety.sightengine.user', '')) !== '' && trim((string) config('content_safety.sightengine.secret', '')) !== '';
        if ($p === 'openai') {
            return $hasOpenAi ? 'openai' : 'none';
        }
        if ($p === 'sightengine') {
            return $hasSe ? 'sightengine' : 'none';
        }
        if ($p === 'none') {
            return 'none';
        }

        return $hasOpenAi ? 'openai' : ($hasSe ? 'sightengine' : 'none');
    }

    /** @return array{provider:string,ffmpeg:bool,gd:bool,fail_open:bool,require_scanner:bool} */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'provider' => $this->provider(),
            'ffmpeg' => $this->ffmpeg() !== null,
            'gd' => function_exists('imagecreatefromstring'),
            'fail_open' => (bool) config('content_safety.fail_open', false),
            'require_scanner' => (bool) config('content_safety.require_image_scanner', false),
        ];
    }

    /**
     * @return array{category:string,reason:string}|null  null = نضيف
     * @throws ContentBlockedException لو الفحص نفسه فشل والإعداد fail-closed
     */
    private function moderateMedia(string $path, string $kind): ?array
    {
        $provider = $this->provider();
        if ($provider === 'none') {
            if (config('content_safety.require_image_scanner', false)) {
                throw new ContentBlockedException($this->unavailableMessage(), 'scanner_unavailable');
            }

            // مفيش مزوّد صور متظبط => الصورة/الـ GIF/الفيديو المرفوع بيعدّي من غير فحص محتوى (النص واسم الملف بس بيتفحصوا).
            // قبل كده كان ده بيحصل بصمت؛ دلوقتي بيتسجّل تحذير في اللوج (مرة كل ساعة) عشان يبان.
            if (Cache::add('csafe:no_scanner_warned', 1, now()->addHour())) {
                Log::warning('Content safety: NO image scanner configured — uploaded images/videos are NOT being checked. Set CONTENT_SAFETY_OPENAI_KEY (or SIGHTENGINE_API_USER/SECRET) in .env, then php artisan config:clear.');
            }

            return null;
        }
        $maxBytes = max(1, (int) config('content_safety.max_scan_mb', 25)) * 1048576;
        $size = (int) @filesize($path);
        if ($kind === 'image' && $size > $maxBytes && $this->ffmpeg() === null && !function_exists('imagecreatefromstring')) {
            return $this->failed('image too large to scan');
        }

        $frames = [];
        $tmpDir = null;
        try {
            if ($kind === 'video' || $this->isAnimated($path)) {
                $tmpDir = sys_get_temp_dir() . '/csafe_' . bin2hex(random_bytes(6));
                @mkdir($tmpDir, 0700, true);
                $frames = $this->extractFrames($path, $tmpDir, $kind === 'video' ? 4 : 3);
                if (!$frames && $kind === 'video') {
                    return $this->failed('could not read video frames');
                }
            }
            if (!$frames) {
                $one = $this->prepareStill($path, $size > 4_000_000);
                if ($one === null) {
                    return $this->failed('could not prepare image');
                }
                $frames = [$one];
            }
            foreach ($frames as $f) {
                $v = $provider === 'openai' ? $this->scanOpenAi($f) : $this->scanSightengine($f);
                if ($v === false) {
                    return $this->failed('scanner error');
                }
                if ($v !== null) {
                    return $v;
                }
            }

            return null;
        } finally {
            if ($tmpDir) {
                foreach (glob($tmpDir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($tmpDir);
            }
        }
    }

    private function unavailableMessage(): string
    {
        return request()?->header('X-Locale', 'en') === 'ar'
            ? 'تعذّر فحص الملف حاليًا. حاول مرة تانية بعد شوية.'
            : 'The file could not be checked right now. Please try again shortly.';
    }

    /** @throws ContentBlockedException */
    private function failed(string $why): ?array
    {
        Log::warning('Content safety scan failed: ' . $why);
        SecurityLog::write('Content safety scan failed: ' . $why, ['reason' => $why]);
        if (config('content_safety.fail_open', false)) {
            return null;
        }
        throw new ContentBlockedException($this->unavailableMessage(), 'scanner_unavailable');
    }

    private function isAnimated(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 32);
        if (str_starts_with($head, 'GIF8')) {
            return true;
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return stripos((string) @file_get_contents($path, false, null, 0, 65536), 'ANIM') !== false;
        }

        return false;
    }

    private function ffmpeg(): ?string
    {
        static $bin = false;
        if ($bin === false) {
            $bin = null;
            $env = trim((string) env('FFMPEG_PATH', ''));
            if ($env !== '' && is_file($env) && is_executable($env)) {
                return $bin = $env;
            }
            if (function_exists('exec')) {
                $out = [];
                @exec('command -v ffmpeg 2>/dev/null', $out);
                $bin = !empty($out[0]) ? $out[0] : null;
            }
        }

        return $bin;
    }

    /** @return string[] مسارات إطارات jpg */
    private function extractFrames(string $path, string $dir, int $count): array
    {
        $ff = $this->ffmpeg();
        if ($ff === null) {
            return [];
        }
        $dur = 0.0;
        $out = [];
        @exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null', $out);
        if (!empty($out[0]) && is_numeric($out[0])) {
            $dur = (float) $out[0];
        }
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $t = $dur > 1 ? ($dur * (($i + 0.5) / $count)) : $i * 0.5;
            $dst = $dir . '/f' . $i . '.jpg';
            $cmd = sprintf(
                'timeout %d %s -v error -ss %s -i %s -frames:v 1 -vf "scale=\'min(768,iw)\':-2" -q:v 4 -y %s 2>&1',
                max(5, (int) config('content_safety.timeout', 12) * 2),
                escapeshellarg($ff),
                escapeshellarg(number_format($t, 2, '.', '')),
                escapeshellarg($path),
                escapeshellarg($dst)
            );
            @exec($cmd);
            if (is_file($dst) && filesize($dst) > 200) {
                $frames[] = $dst;
            }
        }

        return $frames;
    }

    /** صورة ثابتة جاهزة للإرسال للمزوّد (بتتصغّر لو كبيرة). */
    private function prepareStill(string $path, bool $downscale): ?string
    {
        if (!$downscale && filesize($path) <= 4_000_000) {
            return $path;
        }
        if (function_exists('imagecreatefromstring') && filesize($path) < 40_000_000) {
            $img = @imagecreatefromstring((string) file_get_contents($path));
            if ($img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $scale = min(1, 1024 / max($w, $h, 1));
                $dst = $scale < 1 ? imagescale($img, max(1, (int) ($w * $scale)), max(1, (int) ($h * $scale))) : $img;
                $tmp = tempnam(sys_get_temp_dir(), 'csi') . '.jpg';
                imagejpeg($dst, $tmp, 80);
                imagedestroy($img);
                if ($dst !== $img) {
                    imagedestroy($dst);
                }
                register_shutdown_function(fn () => @unlink($tmp));

                return $tmp;
            }
        }
        if ($this->ffmpeg() !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'csi') . '.jpg';
            @exec(sprintf('timeout 20 %s -v error -i %s -frames:v 1 -vf "scale=\'min(1024,iw)\':-2" -q:v 4 -y %s 2>&1', escapeshellarg($this->ffmpeg()), escapeshellarg($path), escapeshellarg($tmp)));
            if (is_file($tmp) && filesize($tmp) > 200) {
                register_shutdown_function(fn () => @unlink($tmp));

                return $tmp;
            }
        }

        return filesize($path) <= 15_000_000 ? $path : null;
    }

    /** @return array{category:string,reason:string}|null|false  false = خطأ في المزوّد */
    private function scanOpenAi(string $file)
    {
        $bin = @file_get_contents($file);
        if ($bin === false) {
            return false;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: 'image/jpeg';
        if (!str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
        }
        try {
            $r = Http::withToken((string) config('content_safety.openai.key'))
                ->timeout((int) config('content_safety.timeout', 12))
                ->post('https://api.openai.com/v1/moderations', [
                    'model' => (string) config('content_safety.openai.model', 'omni-moderation-latest'),
                    'input' => [['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bin)]]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Content safety (openai) request failed: ' . $e->getMessage());

            return false;
        }
        if (!$r->ok() || !is_array($r->json('results.0'))) {
            Log::warning('Content safety (openai) bad response: HTTP ' . $r->status());

            return false;
        }
        return $this->openAiVerdict((array) $r->json('results.0'));
    }

    /** @return array{category:string,reason:string}|null  نتيجة OpenAI Moderation (results[0]) → حكم */
    private function openAiVerdict(array $res): ?array
    {
        $s = (array) ($res['category_scores'] ?? []);
        $c = (array) ($res['categories'] ?? []);
        $t = (array) config('content_safety.thresholds', []);
        $sexual = (float) ($s['sexual'] ?? 0);
        $minors = (float) ($s['sexual/minors'] ?? 0);
        $gore = (float) ($s['violence/graphic'] ?? 0);
        if ($this->trace !== null) {
            $this->trace[] = ['sexual' => round($sexual, 3), 'sexual_minors' => round($minors, 3), 'violence_graphic' => round($gore, 3)];
        }
        if ($minors >=($t['sexual_minors'] ?? 0.02) || !empty($c['sexual/minors'])) {
            return ['category' => 'child_safety', 'reason' => 'openai sexual/minors=' . round($minors, 3)];
        }
        if ($sexual >= ($t['sexual'] ?? 0.30) || !empty($c['sexual'])) {
            return ['category' => 'sexual', 'reason' => 'openai sexual=' . round($sexual, 3)];
        }
        if ($gore >= ($t['violence_graphic'] ?? 0.55) || !empty($c['violence/graphic'])) {
            return ['category' => 'gore', 'reason' => 'openai violence/graphic=' . round($gore, 3)];
        }

        return null;
    }

    /** @return array{category:string,reason:string}|null|false */
    private function scanSightengine(string $file)
    {
        try {
            $r = Http::timeout((int) config('content_safety.timeout', 12))
                ->attach('media', (string) file_get_contents($file), 'media.jpg')
                ->post('https://api.sightengine.com/1.0/check.json', [
                    'models' => 'nudity-2.1,gore-2.0',
                    'api_user' => (string) config('content_safety.sightengine.user'),
                    'api_secret' => (string) config('content_safety.sightengine.secret'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Content safety (sightengine) request failed: ' . $e->getMessage());

            return false;
        }
        if (!$r->ok() || $r->json('status') !== 'success') {
            Log::warning('Content safety (sightengine) bad response: HTTP ' . $r->status());

            return false;
        }
        return $this->sightengineVerdict((array) $r->json());
    }

    /** @return array{category:string,reason:string}|null  رد Sightengine → حكم */
    private function sightengineVerdict(array $json): ?array
    {
        $n = (array) ($json['nudity'] ?? []);
        $t = (array) config('content_safety.thresholds', []);
        $sexual = max((float) ($n['sexual_activity'] ?? 0), (float) ($n['sexual_display'] ?? 0), (float) ($n['erotica'] ?? 0));
        if ($sexual >= ($t['sexual'] ?? 0.30)) {
            return ['category' => 'sexual', 'reason' => 'sightengine nudity=' . round($sexual, 3)];
        }
        $very = (float) ($n['very_suggestive'] ?? 0);
        $mid = (float) ($n['suggestive'] ?? 0);
        if ($this->trace !== null) {
            $this->trace[] = [
                'sexual' => round($sexual, 3), 'very_suggestive' => round($very, 3), 'suggestive' => round($mid, 3),
                'mildly_suggestive' => round((float) ($n['mildly_suggestive'] ?? 0), 3), 'gore' => round((float) ($json['gore']['prob'] ?? 0), 3),
            ];
        }
        if (config('content_safety.debug', false)) {
            Log::info('Content safety (sightengine) scores', [
                'sexual' => round($sexual, 3), 'very_suggestive' => round($very, 3), 'suggestive' => round($mid, 3),
                'mildly_suggestive' => round((float) ($n['mildly_suggestive'] ?? 0), 3), 'none' => round((float) ($n['none'] ?? 0), 3),
            ]);
        }
        if ($very >= ($t['suggestive'] ?? 0.50)) {
            return ['category' => 'sexual', 'reason' => 'sightengine very_suggestive=' . round($very, 3)];
        }
        // صور إيحائية (قُبَل/ملابس داخلية/بيكيني…) بتتصنّف "suggestive" — قبل كده كانت بتعدّي.
        if ($mid >= ($t['suggestive_mid'] ?? 0.70)) {
            return ['category' => 'sexual', 'reason' => 'sightengine suggestive=' . round($mid, 3)];
        }
        $gore = (float) ($json['gore']['prob'] ?? 0);
        if ($gore >= ($t['violence_graphic'] ?? 0.55)) {
            return ['category' => 'gore', 'reason' => 'sightengine gore=' . round($gore, 3)];
        }

        return null;
    }

    // ───────────────────────── صور الـ GIF (Giphy) ─────────────────────────
    // تصنيف Giphy (g/pg) بيحطّه اللي رفع الـ GIF، فكتير GIFs إباحية بتتصنّف "g" غلط. عشان كده بنفحص الصورة نفسها (لقطة ثابتة صغيرة)
    // بنفس مزوّد فحص الصور (OpenAI/Sightengine) — بالتوازي، والنتيجة بتتكاش 30 يوم، والفشل = رفض (إلا لو CONTENT_SAFETY_FAIL_OPEN).

    private function isGiphyUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($url, 'https://') && ($host === 'giphy.com' || str_ends_with($host, '.giphy.com'));
    }

    /** هل فيه فاحص صور شغّال لنتايج الـ GIF؟ */
    public function canScanGifImages(): bool
    {
        return $this->enabled() && $this->provider() !== 'none';
    }

    /**
     * @param string[] $urls لقطات ثابتة من Giphy
     * @return array<string,true|array{category:string,reason:string}>  url => true (نضيف) | حكم المنع
     */
    public function scanRemoteStills(array $urls): array
    {
        $provider = $this->provider();
        $out = [];
        $todo = [];
        foreach (array_values(array_unique(array_filter($urls, 'is_string'))) as $u) {
            if (!$this->isGiphyUrl($u)) {
                $out[$u] = ['category' => 'sexual', 'reason' => 'untrusted image host'];
                continue;
            }
            $cached = Cache::get('csafe:still:' . $provider . ':' . sha1($u));
            if ($cached === 'ok') {
                $out[$u] = true;
            } elseif (is_array($cached)) {
                $out[$u] = $cached;
            } else {
                $todo[] = $u;
            }
        }
        if (!$todo) {
            return $out;
        }
        if ($provider === 'none') {
            $unavailable = (bool) config('content_safety.require_image_scanner', false);
            foreach ($todo as $u) {
                $out[$u] = $unavailable ? ['category' => 'scanner_unavailable', 'reason' => 'no image scanner configured'] : true;
            }

            return $out;
        }
        $failOpen = (bool) config('content_safety.fail_open', false);
        $timeout = max(3, min(10, (int) config('content_safety.timeout', 12)));
        $err = ['category' => 'scanner_unavailable', 'reason' => 'scanner error'];

        // 1) تنزيل اللقطات بالتوازي (صغيرة: بضع KB)
        $bins = [];
        try {
            $downloads = Http::pool(function ($pool) use ($todo, $timeout) {
                foreach ($todo as $i => $u) {
                    $pool->as('d' . $i)->timeout($timeout)->get($u);
                }
            });
        } catch (\Throwable $e) {
            $downloads = [];
        }
        foreach ($todo as $i => $u) {
            $r = $downloads['d' . $i] ?? null;
            $body = $r instanceof \Illuminate\Http\Client\Response && $r->ok() ? $r->body() : '';
            if ($body !== '' && strlen($body) >= 100 && strlen($body) <= 3_000_000) {
                $bins[$i] = $body;
            } else {
                $out[$u] = $failOpen ? true : $err;
            }
        }
        if (!$bins) {
            return $out;
        }

        // 2) فحص اللقطات بالتوازي
        try {
            $scans = Http::pool(function ($pool) use ($bins, $provider, $timeout) {
                foreach ($bins as $i => $bin) {
                    if ($provider === 'openai') {
                        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: 'image/jpeg';
                        $mime = str_starts_with($mime, 'image/') ? $mime : 'image/jpeg';
                        $pool->as('s' . $i)->withToken((string) config('content_safety.openai.key'))->timeout($timeout)
                            ->post('https://api.openai.com/v1/moderations', [
                                'model' => (string) config('content_safety.openai.model', 'omni-moderation-latest'),
                                'input' => [['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bin)]]],
                            ]);
                    } else {
                        $pool->as('s' . $i)->timeout($timeout)->attach('media', $bin, 'media.jpg')
                            ->post('https://api.sightengine.com/1.0/check.json', [
                                'models' => 'nudity-2.1,gore-2.0',
                                'api_user' => (string) config('content_safety.sightengine.user'),
                                'api_secret' => (string) config('content_safety.sightengine.secret'),
                            ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Content safety (gif stills) pool failed: ' . $e->getMessage());
            $scans = [];
        }
        foreach ($bins as $i => $bin) {
            $u = $todo[$i];
            $r = $scans['s' . $i] ?? null;
            $verdict = false;
            if ($r instanceof \Illuminate\Http\Client\Response && $r->ok()) {
                if ($provider === 'openai' && is_array($r->json('results.0'))) {
                    $verdict = $this->openAiVerdict((array) $r->json('results.0'));
                } elseif ($provider !== 'openai' && $r->json('status') === 'success') {
                    $verdict = $this->sightengineVerdict((array) $r->json());
                }
            }
            $ck = 'csafe:still:' . $provider . ':' . sha1($u);
            if ($verdict === false) {
                Log::warning('Content safety (gif stills) scan failed for one result');
                $out[$u] = $failOpen ? true : $err;
            } elseif ($verdict === null) {
                Cache::put($ck, 'ok', now()->addDays(30));
                $out[$u] = true;
            } else {
                Cache::put($ck, $verdict, now()->addDays(30));
                $this->record('gif_result', $verdict['category'], $verdict['reason'], false); // نتيجة بحث مش ذنب المستخدم => من غير strike
                $out[$u] = $verdict;
            }
        }

        return $out;
    }

    /**
     * يشيل من نتايج بحث الـ GIF أي نتيجة صورتها مش نضيفة. من غير فاحص صور: بترجّع النتايج زي ما هي (والأدمن بيشوف تحذير).
     * @param array<int,array<string,mixed>> $items كل عنصر فيه still أو preview
     * @return array<int,array<string,mixed>>
     */
    public function filterSafeGifItems(array $items): array
    {
        if (!$items || !$this->canScanGifImages()) {
            return $items;
        }
        $key = fn (array $i) => (string) ($i['still'] ?? $i['preview'] ?? '');
        $verdicts = $this->scanRemoteStills(array_map($key, $items));

        return array_values(array_filter($items, fn ($i) => ($verdicts[$key($i)] ?? null) === true));
    }

    /** @throws ContentBlockedException لو لقطة الـ GIF مش نضيفة أو الفحص فشل. */
    public function assertRemoteStillSafe(?string $stillUrl, string $kind = 'gif'): void
    {
        if (!$this->canScanGifImages()) {
            if ($this->enabled() && config('content_safety.require_image_scanner', false)) {
                throw new ContentBlockedException($this->unavailableMessage(), 'scanner_unavailable', $kind);
            }

            return;
        }
        if (!$stillUrl) {
            throw new ContentBlockedException($this->unavailableMessage(), 'scanner_unavailable', $kind);
        }
        $v = $this->scanRemoteStills([$stillUrl])[$stillUrl] ?? ['category' => 'scanner_unavailable', 'reason' => 'no verdict'];
        if ($v === true) {
            return;
        }
        if ($v['category'] !== 'scanner_unavailable') {
            $this->record($kind, $v['category'], $v['reason']);
        }
        throw new ContentBlockedException($this->message($v['category']), $v['category'], $kind);
    }

    // ───────────────────────── تسجيل المخالفات ─────────────────────────

    /** يسجّل مخالفة (مفيش نص المحتوى بيتخزّن — بس الكلمة/السبب). Never throws. */
    public function record(string $kind, string $category, string $detail = '', bool $strike = true): void
    {
        try {
            $uid = $this->currentUserId();
            $req = request();
            $route = $req ? mb_substr('/' . ltrim($req->path(), '/'), 0, 190) : null;
            $ip = $req ? mb_substr((string) $req->ip(), 0, 60) : null;
            SecurityLog::write('Content safety block: ' . $kind . '/' . $category, ['user_id' => $uid, 'route' => $route, 'detail' => mb_substr($detail, 0, 80)]);
            DB::table('content_safety_events')->insert([
                'user_id' => $uid, 'kind' => mb_substr($kind, 0, 30), 'category' => mb_substr($category, 0, 30), 'detail' => mb_substr($detail, 0, 190),
                'route' => $route, 'ip' => $ip, 'created_at' => now(),
            ]);
            if ($uid && $strike) {
                $k = 'csafe:strikes:' . $uid;
                Cache::add($k, 0, now()->addDay());
                $n = (int) Cache::increment($k);
                $limit = max(1, (int) config('content_safety.strike_alert', 5));
                if ($n === $limit) {
                    app(AuditLogService::class)->record($uid, 'content_safety.repeat_offender', 'User', $uid, null, ['violations_24h' => $n, 'last' => $kind . '/' . $category], $ip);
                    SecurityLog::write('Content safety: repeated violations by user', ['user_id' => $uid, 'violations_24h' => $n]);
                }
            }
        } catch (\Throwable $e) {
            // التسجيل مايكسرش الحجب
        }
    }

    private function currentUserId(): ?int
    {
        $req = request();
        if (!$req) {
            return null;
        }
        $id = $req->attributes->get('uip_user_id');
        if ($id) {
            return (int) $id;
        }
        try {
            $h = (string) $req->header('Authorization', '');
            if (str_starts_with($h, 'Bearer ')) {
                $claims = \App\Services\UipJwtService::decodeAccess(substr($h, 7));
                if ($claims && isset($claims['sub'])) {
                    return (int) $claims['sub'];
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }
}
