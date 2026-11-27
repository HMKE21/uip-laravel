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
            $hash = sha1(trim($text));
            if ($this->isAllowed('text', $hash)) { // الأدمن راجع النص ده بالظبط وسمح بيه
                return;
            }
            $this->record($kind, $hit['category'], $hit['term'], true, ['sample' => $this->excerpt($text, (string) $hit['term']), 'content_hash' => $hash]);
            throw new ContentBlockedException($this->message($hit['category']), $hit['category'], $kind);
        }
    }

    // ───────────── مراجعة الأدمن: مسموح + حجر الملفات ─────────────

    /** @return string[] "type:hash" */
    private function allowedSet(): array
    {
        try {
            return Cache::remember('csafe:allowlist', 60, fn () => DB::table('content_safety_allowlist')->get(['type', 'hash'])->map(fn ($r) => $r->type . ':' . $r->hash)->all());
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isAllowed(string $type, string $hash): bool
    {
        return $hash !== '' && in_array($type . ':' . $hash, $this->allowedSet(), true);
    }

    public function forgetAllowlist(): void
    {
        Cache::forget('csafe:allowlist');
    }

    private function excerpt(string $text, string $term): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (strlen($text) > 200 && preg_match('#^[A-Za-z0-9+/=]+$#', substr($text, 0, 400))) {
            return '[base64]';
        }
        $pos = $term !== '' ? mb_stripos($text, $term) : false;
        if ($pos === false) {
            return mb_substr($text, 0, 300);
        }
        $start = max(0, $pos - 120);

        return ($start > 0 ? '…' : '') . mb_substr($text, $start, 300);
    }

    private function quarantineDir(): string
    {
        return storage_path('app/private/csafe-quarantine');
    }

    private function quarantine(string $path, string $sha): bool
    {
        if ($sha === '' || !config('content_safety.quarantine_enabled', true)) {
            return false;
        }
        $size = @filesize($path);
        if ($size === false || $size > max(1, (int) config('content_safety.quarantine_max_mb', 100)) * 1048576) {
            return false;
        }
        $dir = $this->quarantineDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $dst = $dir . '/' . $sha . '.bin';

        return is_file($dst) || @copy($path, $dst);
    }

    public function quarantinePath(string $sha): ?string
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
            return null;
        }
        $f = $this->quarantineDir() . '/' . $sha . '.bin';

        return is_file($f) ? $f : null;
    }

    public function dropQuarantine(string $sha): void
    {
        if ($f = $this->quarantinePath($sha)) {
            @unlink($f);
        }
    }

    public function purgeQuarantine(): int
    {
        $n = 0;
        $cut = time() - max(1, (int) config('content_safety.quarantine_days', 30)) * 86400;
        foreach (glob($this->quarantineDir() . '/*.bin') ?: [] as $f) {
            if (@filemtime($f) !== false && filemtime($f) < $cut && @unlink($f)) {
                $n++;
                try {
                    DB::table('content_safety_events')->where('content_hash', basename($f, '.bin'))->update(['has_file' => false]);
                } catch (\Throwable $e) {
                }
            }
        }

        return $n;
    }

    /** @throws ContentBlockedException */
    private function blockFile(string $kind, string $category, string $detail, string $path, string $displayName, ?string $sha): void
    {
        $sha = $sha ?: (string) @sha1_file($path);
        $kept = $this->quarantine($path, $sha);
        $this->record($kind, $category, $detail, true, [
            'content_hash' => $sha ?: null, 'file_name' => $displayName, 'file_mime' => (string) (@mime_content_type($path) ?: ''),
            'file_size' => (int) @filesize($path), 'has_file' => $kept,
        ]);
        throw new ContentBlockedException($this->message($category), $category, $kind);
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
            'blocked_link' => $ar ? 'رابط أو موقع محظور' : 'a blocked link or website',
            'scanner_unavailable' => $ar ? 'تعذّر فحص المحتوى حاليًا' : 'the content could not be checked right now',
            default => $ar ? 'محتوى غير لائق' : 'inappropriate or adult content',
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
            ? 'تم منع الإرسال — السبب: ' . $reason . '. المحتوى غير اللائق أو الحسّاس ممنوع تمامًا في المنصة.'
            : 'Blocked — reason: ' . $reason . '. Inappropriate or sensitive content is strictly prohibited on the platform.';
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
        $sha = null;
        if ($this->allowedSet()) { // ملف الأدمن سمح بيه (بالهاش) بيعدّي من كل الفحوصات
            $sha = @sha1_file($path) ?: null;
            if ($sha && $this->isAllowed('file', $sha)) {
                return;
            }
        }
        $base = pathinfo($displayName, PATHINFO_FILENAME);
        if ($hit = $this->checkText(str_replace(['_', '-', '.'], ' ', $base), false)) {
            $this->blockFile('filename', $hit['category'], $hit['term'], $path, $displayName, $sha);
        }

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
        $sha = $sha ?: (@sha1_file($path) ?: null);
        $key = 'csafe:file:' . $this->provider() . ':' . $sha;
        $cached = Cache::get($key);
        if ($cached === 'ok') {
            return;
        }
        if (is_array($cached)) {
            $this->blockFile($kind, $cached['category'], $cached['reason'] . ' (cached)', $path, $displayName, $sha);
        }
        $verdict = $this->moderateMedia($path, $kind);
        if ($verdict === null) {
            Cache::put($key, 'ok', now()->addDays(7));

            return;
        }
        Cache::put($key, $verdict, now()->addDays(7));
        $this->blockFile($kind, $verdict['category'], $verdict['reason'], $path, $displayName, $sha);
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
                \App\Support\SafePath::delete($tmp);
            }
        }
    }

    /** @var array<int,array<string,mixed>>|null درجات الفاحص لكل إطار — بتتملي بس أثناء analyzeFile (صفحة الأدمن). */
    private ?array $trace = null;

    /** آخر سبب فشل تقني (بيتعرض للأدمن في صفحة الاختبار عشان نعرف المشكلة الحقيقية). */
    private ?string $lastFailure = null;

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
        $this->lastFailure = null;
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
            $out['error_detail'] = $this->lastFailure; // السبب التقني الحقيقي (ffmpeg / HTTP من المزوّد…)
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
            'ffmpeg_path' => $this->ffmpeg(),
            'ffmpeg_hevc' => $this->anyFfmpegHasHevc(), // false = بيلف ffmpeg مش بيفك H.265 (فيديوهات الآيفون) — ركّب نسخة static (scripts/install-ffmpeg.sh)
            'video_api_fallback' => $this->videoApiFallbackEnabled(),
            'gd' => function_exists('imagecreatefromstring'),
            'fail_open' => (bool) config('content_safety.fail_open', false),
            'require_scanner' => (bool) config('content_safety.require_image_scanner', false),
            // Lexicon Hub (lexicon:sync): النسخة المحمّلة + آخر محاولة. loaded=false => القاموس المدمج بس.
            'lexicon' => ['configured' => !empty(config('content_safety.remote.urls'))] + \App\Support\LexiconStore::status(),
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
                    // ffmpeg مش قادر يفك الفيديو (غالبًا H.265/HEVC على build ناقص) → نبعت الفيديو نفسه لفاحص الفيديو عند Sightengine.
                    if ($provider === 'sightengine' && $this->videoApiFallbackEnabled() && $size > 0 && $size <= $this->videoApiMaxBytes()) {
                        $v = $this->scanSightengineVideo($path);
                        if ($v === false) {
                            return $this->failed('could not read video frames');
                        }

                        return $v;
                    }
                    if ($this->decoderMissing) {
                        $this->lastFailure = ($this->lastFailure ? $this->lastFailure . ' | ' : '')
                            . 'HINT: this ffmpeg build lacks the needed video decoder (HEVC/H.265). Install a full static ffmpeg: bash scripts/install-ffmpeg.sh then set FFMPEG_PATH=/usr/local/bin/ffmpeg';
                    }

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
                    \App\Support\SafePath::delete($f);
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
        $this->lastFailure = $this->lastFailure ? ($why . ' — ' . $this->lastFailure) : $why;
        Log::warning('Content safety scan failed: ' . $this->lastFailure);
        SecurityLog::write('Content safety scan failed: ' . $why, ['reason' => $this->lastFailure]);
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

    /** true لو آخر محاولة فك فيديو فشلت بسبب decoder ناقص في ffmpeg. */
    private bool $decoderMissing = false;

    /**
     * كل نسخ ffmpeg المتاحة بالترتيب: FFMPEG_PATH (من config مش env() عشان config:cache) ← FFMPEG_FALLBACK_PATH
     * ← أماكن الـ static builds الشائعة ← اللي في الـ PATH. أول واحدة بتفك الفيديو بتتستخدم.
     *
     * @return string[]
     */
    private function ffmpegCandidates(): array
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $cands = [
            trim((string) config('content_safety.ffmpeg_path', '')),
            trim((string) config('content_safety.ffmpeg_fallback_path', '')),
            '/usr/local/bin/ffmpeg',
            '/opt/ffmpeg/ffmpeg',
            '/usr/local/ffmpeg/ffmpeg',
            '/usr/bin/ffmpeg',
        ];
        $inPath = \App\Support\Cmd::find('ffmpeg');
        if ($inPath !== null) {
            $cands[] = $inPath;
        }
        $list = [];
        foreach ($cands as $c) {
            if ($c !== '' && is_file($c) && is_executable($c) && !in_array($c, $list, true)) {
                $list[] = $c;
            }
        }

        return $list;
    }

    private function ffmpeg(): ?string
    {
        return $this->ffmpegCandidates()[0] ?? null;
    }

    /** هل أي نسخة ffmpeg عندنا فيها HEVC decoder؟ */
    private function anyFfmpegHasHevc(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $has = false;
        foreach ($this->ffmpegCandidates() as $bin) {
            $res = \App\Support\Cmd::run([$bin, '-hide_banner', '-decoders'], 20, true);
            foreach ($res['lines'] as $line) {
                if (preg_match('/\bhevc\b/i', $line) && preg_match('/^\s*V/', $line)) {
                    return $has = true;
                }
            }
        }

        return $has;
    }

    private function videoApiFallbackEnabled(): bool
    {
        return (bool) config('content_safety.video_api_fallback', true);
    }

    private function videoApiMaxBytes(): int
    {
        return max(1, (int) config('content_safety.video_api_max_mb', 50)) * 1048576;
    }

    private function ffprobe(): ?string
    {
        $cfg = trim((string) config('content_safety.ffprobe_path', ''));
        if ($cfg !== '' && is_file($cfg) && is_executable($cfg)) {
            return $cfg;
        }
        $ff = $this->ffmpeg();
        if ($ff === null) {
            return null;
        }
        $sibling = dirname($ff) . '/ffprobe';
        if (is_file($sibling) && is_executable($sibling)) {
            return $sibling;
        }
        return \App\Support\Cmd::find('ffprobe');
    }

    /**
     * إطار واحد من الفيديو في الوقت $t. بيجرّب أكتر من طريقة (بعض الفيديوهات/إصدارات ffmpeg بتفشل مع seek سريع،
     * وffmpeg 7+ بيرفض JPEG من فيديو yuv420p العادي من غير format=yuvj420p).
     */
    private function grabFrame(string $ff, string $path, float $t, string $dst, int $timeout): bool
    {
        $vf = "scale='min(768,iw)':-2,format=yuvj420p";
        $ss = number_format($t, 2, '.', '');
        $tail = ['-an', '-sn', '-dn', '-frames:v', '1', '-vf', $vf, '-strict', 'unofficial', '-q:v', '4', '-y', $dst];
        $variants = [
            // seek سريع قبل الإدخال
            array_merge([$ff, '-nostdin', '-v', 'error', '-ss', $ss, '-i', $path], $tail),
            // seek بعد الإدخال (أبطأ بس أدق مع ملفات moov-at-end / seek مكسور)
            array_merge([$ff, '-nostdin', '-v', 'error', '-i', $path, '-ss', $ss], $tail),
            // أول إطار من غير أي seek
            array_merge([$ff, '-nostdin', '-v', 'error', '-i', $path], $tail),
        ];
        foreach ($variants as $argv) {
            \App\Support\SafePath::delete($dst);
            $res = \App\Support\Cmd::run($argv, $timeout, true);
            $log = $res['lines'];
            $code = $res['code'];
            if (is_file($dst) && filesize($dst) > 200) {
                return true;
            }
            $msg = trim(implode(' | ', array_slice($log, -2)));
            if ($msg !== '' || $code !== 0) {
                $this->lastFailure = 'ffmpeg exit ' . $code . ($msg !== '' ? ': ' . mb_substr($msg, 0, 220) : '');
                if (preg_match('/decoder|does not include|unknown codec|not compiled|Unsupported codec/i', $msg)) {
                    $this->decoderMissing = true;
                    break; // نفس الـ build هيفشل بنفس السبب في باقي المحاولات — جرّب نسخة تانية
                }
            }
        }

        return false;
    }

    /** @return string[] مسارات إطارات jpg */
    private function extractFrames(string $path, string $dir, int $count): array
    {
        $cands = $this->ffmpegCandidates();
        if (!$cands) {
            $this->lastFailure = 'ffmpeg not found (set FFMPEG_PATH in .env)';

            return [];
        }
        $this->decoderMissing = false;
        $dur = 0.0;
        $probe = $this->ffprobe();
        if ($probe !== null) {
            $res = \App\Support\Cmd::run([$probe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path], 20);
            $first = $res['lines'][0] ?? '';
            if ($first !== '' && is_numeric($first)) {
                $dur = (float) $first;
            }
        }
        $timeout = max(8, (int) config('content_safety.timeout', 12) * 2);
        foreach ($cands as $ff) {
            $frames = [];
            for ($i = 0; $i < $count; $i++) {
                $t = $dur > 1 ? ($dur * (($i + 0.5) / $count)) : $i * 0.5;
                $dst = $dir . '/f' . $i . '.jpg';
                if ($this->grabFrame($ff, $path, $t, $dst, $timeout)) {
                    $frames[] = $dst;
                } elseif (!$frames && $this->decoderMissing) {
                    break; // النسخة دي مش بتفك الكودك — جرّب اللي بعدها بدل ما نضيّع وقت
                }
            }
            if ($frames) {
                $this->lastFailure = null; // لقينا إطارات — أي خطأ ffmpeg على إطارات تانية مش مهم
                $this->decoderMissing = false;

                return $frames;
            }
        }

        return [];
    }

    /**
     * فيديو كامل (≤ دقيقة) لفاحص الفيديو عند Sightengine — بيتستخدم لما ffmpeg مش قادر يفك الكودك (HEVC مثلًا).
     *
     * @return array{category:string,reason:string}|null|false  null = نضيف، false = خطأ
     */
    private function scanSightengineVideo(string $path)
    {
        $user = (string) config('content_safety.sightengine.user');
        $secret = (string) config('content_safety.sightengine.secret');
        $timeout = max(60, (int) config('content_safety.timeout', 12) * 5);
        $r = null;
        foreach (['nudity-2.1,gore-2.0', 'nudity-2.1'] as $models) {
            try {
                $fh = fopen($path, 'rb');
                if ($fh === false) {
                    $this->lastFailure = 'could not open video file';

                    return false;
                }
                $r = Http::timeout($timeout)
                    ->attach('media', $fh, 'media.' . (strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp4'))
                    ->post('https://api.sightengine.com/1.0/video/check-sync.json', [
                        'models' => $models,
                        'api_user' => $user,
                        'api_secret' => $secret,
                    ]);
            } catch (\Throwable $e) {
                $this->lastFailure = 'sightengine video request failed: ' . mb_substr($e->getMessage(), 0, 200);
                Log::warning('Content safety (sightengine video) request failed: ' . $e->getMessage());

                return false;
            }
            if ($r->ok() && $r->json('status') === 'success') {
                break;
            }
        }
        if ($r === null || !$r->ok() || $r->json('status') !== 'success') {
            $msg = $r ? (string) ($r->json('error.message') ?? $r->json('error.type') ?? '') : '';
            $this->lastFailure = 'sightengine video HTTP ' . ($r ? $r->status() : '?') . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '');
            Log::warning('Content safety (sightengine video) bad response: ' . $this->lastFailure);

            return false;
        }
        $this->lastFailure = null;
        $frames = (array) ($r->json('data.frames') ?? []);
        if (!$frames) {
            $this->lastFailure = 'sightengine video returned no frames';

            return false;
        }
        $worst = null;
        foreach ($frames as $fr) {
            $v = $this->sightengineVerdict((array) $fr);
            if ($v !== null && $worst === null) {
                $worst = $v;
                if ($this->trace === null) {
                    break; // مش في وضع الاختبار — أول مخالفة تكفي
                }
            }
        }

        return $worst;
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
                register_shutdown_function(fn () => \App\Support\SafePath::delete($tmp));

                return $tmp;
            }
        }
        if ($this->ffmpeg() !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'csi') . '.jpg';
            \App\Support\Cmd::run([$this->ffmpeg(), '-v', 'error', '-i', $path, '-frames:v', '1', '-vf', "scale='min(1024,iw)':-2,format=yuvj420p", '-strict', 'unofficial', '-q:v', '4', '-y', $tmp], 20, true);
            if (is_file($tmp) && filesize($tmp) > 200) {
                register_shutdown_function(fn () => \App\Support\SafePath::delete($tmp));

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
            $this->lastFailure = 'openai request failed: ' . mb_substr($e->getMessage(), 0, 200);
            Log::warning('Content safety (openai) request failed: ' . $e->getMessage());

            return false;
        }
        if (!$r->ok() || !is_array($r->json('results.0'))) {
            $msg = (string) ($r->json('error.message') ?? '');
            $this->lastFailure = 'openai HTTP ' . $r->status() . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '');
            Log::warning('Content safety (openai) bad response: ' . $this->lastFailure);

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
        $bin = @file_get_contents($file);
        if ($bin === false || $bin === '') {
            $this->lastFailure = 'could not read frame file';

            return false;
        }
        $r = null;
        for ($try = 0; $try < 2; $try++) { // محاولة تانية لو الشبكة/المزوّد هبّت لحظيًا
            try {
                $r = Http::timeout((int) config('content_safety.timeout', 12))
                    ->attach('media', $bin, 'media.jpg')
                    ->post('https://api.sightengine.com/1.0/check.json', [
                        'models' => 'nudity-2.1,gore-2.0',
                        'api_user' => (string) config('content_safety.sightengine.user'),
                        'api_secret' => (string) config('content_safety.sightengine.secret'),
                    ]);
            } catch (\Throwable $e) {
                $this->lastFailure = 'sightengine request failed: ' . mb_substr($e->getMessage(), 0, 200);
                Log::warning('Content safety (sightengine) request failed: ' . $e->getMessage());
                $r = null;
                continue;
            }
            if ($r->ok() && $r->json('status') === 'success') {
                $this->lastFailure = null;

                return $this->sightengineVerdict((array) $r->json());
            }
            if ($r->status() < 500 && $r->status() !== 429) {
                break; // خطأ دايم (مفتاح غلط / رصيد خلص / طلب غلط) — مفيش فايدة من إعادة المحاولة
            }
        }
        if ($r !== null) {
            $msg = (string) ($r->json('error.message') ?? $r->json('error.type') ?? '');
            $this->lastFailure = 'sightengine HTTP ' . $r->status() . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '');
            Log::warning('Content safety (sightengine) bad response: ' . $this->lastFailure);
        }

        return false;
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
        // صور إيحائية (إيحائية…) بتتصنّف "suggestive" — قبل كده كانت بتعدّي.
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
    // تصنيف Giphy (g/pg) بيحطّه اللي رفع الـ GIF، فكتير GIFs غير لائقة بتتصنّف "g" غلط. عشان كده بنفحص الصورة نفسها (لقطة ثابتة صغيرة)
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
    public function record(string $kind, string $category, string $detail = '', bool $strike = true, array $extra = []): void
    {
        try {
            $uid = $this->currentUserId();
            $req = request();
            $route = $req ? mb_substr('/' . ltrim($req->path(), '/'), 0, 190) : null;
            $ip = $req ? mb_substr((string) $req->ip(), 0, 60) : null;
            SecurityLog::write('Content safety block: ' . $kind . '/' . $category, ['user_id' => $uid, 'route' => $route, 'detail' => mb_substr($detail, 0, 80)]);
            $row = [
                'user_id' => $uid, 'kind' => mb_substr($kind, 0, 30), 'category' => mb_substr($category, 0, 30), 'detail' => mb_substr($detail, 0, 190),
                'route' => $route, 'ip' => $ip, 'created_at' => now(),
            ];
            if ($extra && $this->hasReviewColumns()) {
                foreach (['sample' => 600, 'content_hash' => 40, 'file_name' => 190, 'file_mime' => 100] as $k => $max) {
                    if (isset($extra[$k]) && $extra[$k] !== '') {
                        $row[$k] = mb_substr((string) $extra[$k], 0, $max);
                    }
                }
                if (isset($extra['file_size'])) {
                    $row['file_size'] = (int) $extra['file_size'];
                }
                $row['has_file'] = !empty($extra['has_file']);
            }
            DB::table('content_safety_events')->insert($row);
            if ($uid && $strike) {
                $k = 'csafe:strikes:' . $uid;
                Cache::add($k, 0, now()->addDay());
                $n = (int) Cache::increment($k);
                $limit = max(1, (int) config('content_safety.strike_alert', 5));
                if ($n === $limit) {
                    app(AuditLogService::class)->record($uid, 'content_safety.repeat_offender', 'User', $uid, null, ['violations_24h' => $n, 'last' => $kind . '/' . $category], $ip);
                    SecurityLog::write('Content safety: repeated violations by user', ['user_id' => $uid, 'violations_24h' => $n]);
                }
                // تحذير ودّي بسيط للمستخدم (سبب الحجب + لينك الاعتراض) — مرة كل 10 دقايق كحد أقصى.
                app(ContentAppealService::class)->warnUser((int) $uid, $category, $n);
            }
        } catch (\Throwable $e) {
            // التسجيل مايكسرش الحجب
        }
    }

    private function hasReviewColumns(): bool
    {
        static $has = null;
        try {
            return $has ??= \Illuminate\Support\Facades\Schema::hasColumn('content_safety_events', 'sample');
        } catch (\Throwable $e) {
            return false;
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
