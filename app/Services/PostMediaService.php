<?php

namespace App\Services;

use App\Support\ChunkedUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * مرفقات المنشورات. الرفع بيتم لوحده (POST /posts/media) قبل ما المنشور يتعمل — كل ملف بياخد id وبيتربط بالمنشور وقت
 * الإنشاء/التعديل (attach). ده بيخلي الواجهة ترفع الملفات الكبيرة بالتوازي وبالأجزاء (ChunkedUploadService) ومع progress لكل ملف.
 *
 * التخزين عبر FileUploadService (فئة 'posts' = خاصة، خارج public/): نفس فحوصات الأمان (content sniffing + ClamAV +
 * PDF active content + تحييد php/html => .txt). التسليم دايمًا من PostMediaApiController بعد فحص الرؤية (PostVisibility).
 */
class PostMediaService
{
    private const KIND_MAP = [
        'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
        'video'    => ['mp4', 'webm', 'mov', 'mkv'],
        'audio'    => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus', 'weba'],
        'pdf'      => ['pdf'],
        'archive'  => ['zip', 'rar', '7z'],
        'code'     => ['php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb', 'html', 'css', 'sql', 'sh', 'yml', 'yaml', 'json', 'xml', 'md'],
        'document' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odp', 'odt', 'rtf', 'csv', 'txt', 'log'],
    ];

    /** امتدادات بنقدر نبعتها كنص للمعاينة (كود + نصوص). */
    private const TEXT_EXT = ['php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb', 'html', 'css', 'sql', 'sh', 'yml', 'yaml', 'json', 'xml', 'md', 'txt', 'csv', 'log'];

    public function __construct(
        private FileUploadService $uploads,
        private MediaProbeService $probe
    ) {
    }

    public static function classifyKind(string $extension, ?string $originalName = null, ?string $mime = null): string
    {
        $extension = strtolower($extension);
        // تسجيل صوتي (webm صوت فقط) بيتصنّف صوت مش فيديو.
        if (($originalName !== null && preg_match('/^(voice-message|recording)-/i', $originalName)) || ($mime !== null && str_starts_with(strtolower($mime), 'audio/'))) {
            return 'audio';
        }
        foreach (self::KIND_MAP as $kind => $exts) {
            if (in_array($extension, $exts, true)) {
                return $kind;
            }
        }
        return 'file';
    }

    public static function isTextual(string $extension): bool
    {
        return in_array(strtolower($extension), self::TEXT_EXT, true);
    }

    /**
     * يرفع ملف واحد ويسجّله يتيم (post_id = NULL) لحد ما يتربط بمنشور.
     * @throws \RuntimeException رسالتها آمنة للعرض للمستخدم
     */
    public function upload(int $uid, UploadedFile $file): array
    {
        $limitKb = (int) PostSettings::get('max_file_mb') * 1024;
        $absoluteMaxKb = null;
        if ($file instanceof ChunkedUploadedFile) {
            $absoluteMaxKb = min($limitKb, (int) floor((int) config('messaging.chunked.max_bytes') / 1024));
        }
        $stored = $this->uploads->store($file, 'posts', 'u_' . $uid, $limitKb, null, $absoluteMaxKb);

        $ext = (string) $stored['extension'];
        $kind = self::classifyKind($ext, $file->getClientOriginalName(), $file->getClientMimeType());
        $relDir = dirname($stored['stored_path']);
        $abs = FileUploadService::absolutePath($stored['stored_path']);

        $width = $height = $duration = $pages = null;
        $thumb = null;
        $meta = [];
        $size = (int) $stored['size_bytes'];

        try {
            if ($kind === 'image') {
                [$width, $height] = $this->imageDimensions($abs);
                $thumb = $this->imageThumbnail($abs, $relDir, $ext, $width, $height);
            } elseif ($kind === 'video') {
                $thumb = $this->probe->videoThumbnail($abs, $relDir);
                $duration = $this->probe->duration($abs);
            } elseif ($kind === 'audio') {
                $duration = $this->probe->normalizeAudio($abs); // remux بدون إعادة ترميز: مدة + seek
                clearstatcache(true, $abs);
                $size = @filesize($abs) ?: $size;
                \App\Support\UploadMirror::push($stored['stored_path'], $abs);
            } elseif ($kind === 'pdf') {
                [$pages, $thumb] = $this->probePdf($abs, $relDir);
            } elseif ($kind === 'code') {
                $meta = $this->codeMeta($abs, $ext);
            } elseif (self::isTextual($ext)) {
                $meta = $this->codeMeta($abs, $ext); // txt/csv/log: نفس معاينة النص
            }
        } catch (\Throwable $e) {
            Log::warning('Post media probe failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }

        $id = (int) DB::table('post_media')->insertGetId([
            'post_id'          => null,
            'user_id'          => $uid,
            'kind'             => $kind,
            'stored_path'      => $stored['stored_path'],
            'thumbnail_path'   => $thumb,
            'original_name'    => mb_substr($stored['original_name'], 0, 255),
            'mime_type'        => $stored['mime_type'],
            'extension'        => $ext,
            'size_bytes'       => $size,
            'width'            => $width,
            'height'           => $height,
            'duration_seconds' => $duration,
            'page_count'       => $pages,
            'meta'             => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at'       => now(),
        ]);

        if (in_array($ext, (array) config('posts.convertible_to_pdf', []), true) && PostSettings::get('convert_office_to_pdf')) {
            $this->convertToPdfAfterResponse($id, $abs, $relDir);
        }

        return $this->shape((array) DB::table('post_media')->where('id', $id)->first());
    }

    /**
     * يربط ملفات مرفوعة بمنشور بالترتيب المطلوب. بيرفض أي id مش بتاع المستخدم أو مربوط بمنشور تاني.
     * @param int[]  $mediaIds
     * @param array<int,array{alt_text?:?string,caption?:?string}> $details  id => تفاصيل
     */
    public function attach(int $postId, int $uid, array $mediaIds, array $details = []): void
    {
        $mediaIds = array_values(array_unique(array_map('intval', $mediaIds)));
        if (!$mediaIds) {
            return;
        }
        $max = (int) PostSettings::get('max_media_per_post');
        if (count($mediaIds) > $max) {
            throw new \RuntimeException("A post can have at most {$max} attachments.");
        }
        $rows = DB::table('post_media')->whereIn('id', $mediaIds)->get()->keyBy('id');
        foreach ($mediaIds as $order => $mid) {
            $row = $rows[$mid] ?? null;
            if (!$row || (int) $row->user_id !== $uid || ($row->post_id !== null && (int) $row->post_id !== $postId)) {
                throw new \RuntimeException('One of the attachments is not available.');
            }
            $d = $details[$mid] ?? [];
            $upd = [
                'post_id'    => $postId,
                'sort_order' => $order,
                'alt_text'   => isset($d['alt_text']) ? mb_substr(trim((string) $d['alt_text']), 0, 300) ?: null : $row->alt_text,
                'caption'    => isset($d['caption']) ? mb_substr(trim((string) $d['caption']), 0, 300) ?: null : $row->caption,
            ];
            // ناتج تشغيل نشره الكاتب مع الكود (نص بس، بحد أقصى) — بيتعرض للقراء من غير ما يشغّلوا حاجة.
            if ($row->kind === 'code' && array_key_exists('output', $d)) {
                $meta = $row->meta ? (array) json_decode((string) $row->meta, true) : [];
                $out = self::cleanOutput((string) ($d['output'] ?? ''));
                if ($out === '') {
                    unset($meta['output']);
                } else {
                    $meta['output'] = ['text' => $out, 'at' => now()->toDateTimeString()];
                }
                $upd['meta'] = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
            }
            DB::table('post_media')->where('id', $mid)->update($upd);
        }
    }

    public const OUTPUT_MAX = 8000;

    /** نص ناتج آمن للتخزين: من غير رموز ANSI / تحكّم، وبحد أقصى (آخر جزء لو طويل). */
    public static function cleanOutput(string $raw): string
    {
        $s = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]|\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)|\x1B[@-Z\\\\-_]/', '', $raw) ?? '';
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
        $s = trim($s);
        if (mb_strlen($s) > self::OUTPUT_MAX) {
            $s = '… ' . mb_substr($s, -self::OUTPUT_MAX);
        }
        return $s;
    }

    /** يشيل (صفوف + ملفات) مرفقات منشور مش موجودة في $keepIds — للتعديل. */
    public function detachMissing(int $postId, array $keepIds): void
    {
        $q = DB::table('post_media')->where('post_id', $postId);
        if ($keepIds) {
            $q->whereNotIn('id', array_map('intval', $keepIds));
        }
        foreach ($q->get() as $row) {
            $this->destroyRow($row);
        }
    }

    /** حذف مرفق يتيم (لسه مش مربوط) بتاع المستخدم — لما يلغي الرفع أو يشيله قبل النشر. */
    public function deleteOrphan(int $uid, int $mediaId): bool
    {
        $row = DB::table('post_media')->where('id', $mediaId)->where('user_id', $uid)->whereNull('post_id')->first();
        if (!$row) {
            return false;
        }
        $this->destroyRow($row);
        return true;
    }

    public function destroyRow(object $row): void
    {
        foreach ([$row->stored_path ?? null, $row->thumbnail_path ?? null, $row->preview_path ?? null] as $rel) {
            if ($rel) {
                try {
                    $this->uploads->delete((string) $rel);
                } catch (\Throwable $e) {
                    Log::warning('Post media file delete failed', ['path' => $rel, 'error' => $e->getMessage()]);
                }
            }
        }
        DB::table('post_media')->where('id', $row->id)->delete();
    }

    /** مرفقات مجموعة منشورات: post_id => [shape,...] */
    public function forPosts(array $postIds): array
    {
        if (!$postIds) {
            return [];
        }
        $rows = DB::table('post_media')->whereIn('post_id', $postIds)->orderBy('sort_order')->orderBy('id')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->post_id][] = $this->shape((array) $row);
        }
        return $out;
    }

    /** الشكل اللي بيروح للواجهة. المسارات الفعلية بتتسلّم من PostMediaApiController بعد فحص الرؤية. */
    public function shape(array $r): array
    {
        $id = (int) $r['id'];
        $base = '/api/v1/posts/media/' . $id;
        $meta = [];
        if (!empty($r['meta'])) {
            $decoded = is_array($r['meta']) ? $r['meta'] : json_decode((string) $r['meta'], true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $textual = self::isTextual((string) ($r['extension'] ?? ''));
        return [
            'id'               => $id,
            'post_id'          => isset($r['post_id']) ? (int) $r['post_id'] : null,
            'kind'             => $r['kind'],
            'original_name'    => $r['original_name'],
            'mime_type'        => $r['mime_type'] ?? null,
            'extension'        => $r['extension'] ?? null,
            'size_bytes'       => (int) ($r['size_bytes'] ?? 0),
            'width'            => isset($r['width']) ? (int) $r['width'] : null,
            'height'           => isset($r['height']) ? (int) $r['height'] : null,
            'duration_seconds' => isset($r['duration_seconds']) ? (int) $r['duration_seconds'] : null,
            'page_count'       => isset($r['page_count']) ? (int) $r['page_count'] : null,
            'alt_text'         => $r['alt_text'] ?? null,
            'caption'          => $r['caption'] ?? null,
            'sort_order'       => (int) ($r['sort_order'] ?? 0),
            'has_thumbnail'    => !empty($r['thumbnail_path']),
            'has_preview'      => !empty($r['preview_path']),     // نسخة PDF متحوّلة (عرض تقديمي / مستند)
            'is_text'          => $textual,
            'meta'             => $meta,
            'urls'             => [
                'link'      => $base . '/link',
                'file'      => $base . '/file',
                'thumbnail' => !empty($r['thumbnail_path']) ? $base . '/thumbnail' : null,
                'preview'   => !empty($r['preview_path']) ? $base . '/preview' : null,
                'text'      => $textual ? $base . '/text' : null,
                'download'  => $base . '/download',
            ],
        ];
    }

    // ---------------------------------------------------------------- probes (كلها fail-open)

    private function imageDimensions(string $abs): array
    {
        $info = @getimagesize($abs);
        return $info ? [(int) $info[0], (int) $info[1]] : [null, null];
    }

    /** نسخة JPEG مصغّرة (720px) للصور الكبيرة عشان الفيد يتحمّل بسرعة. */
    private function imageThumbnail(string $abs, string $relDir, string $ext, ?int $w, ?int $h): ?string
    {
        if (!$w || !$h || $w <= 1280 || !function_exists('imagecreatefromstring') || !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }
        if ($w * $h > 40_000_000) { // حماية الذاكرة
            return null;
        }
        $src = @imagecreatefromstring((string) @file_get_contents($abs));
        if (!$src) {
            return null;
        }
        $tw = 720;
        $th = (int) round($h * ($tw / $w));
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $rel = $relDir . '/th_' . bin2hex(random_bytes(8)) . '.jpg';
        $target = FileUploadService::absolutePath($rel);
        @mkdir(dirname($target), 0775, true);
        $ok = @imagejpeg($dst, $target, 82);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            return null;
        }
        \App\Support\UploadMirror::push($rel, $target);
        return $rel;
    }

    /** @return array{0:?int,1:?string} [عدد الصفحات, مسار صورة الصفحة الأولى] */
    private function probePdf(string $abs, string $relDir): array
    {
        $pages = null;
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo')) {
            $p = new Process(['pdfinfo', $abs]);
            $p->setTimeout(15);
            $p->run();
            if ($p->isSuccessful() && preg_match('/^Pages:\s+(\d+)/m', $p->getOutput(), $m)) {
                $pages = (int) $m[1];
            }
        }
        if ($pages === null && @filesize($abs) <= 80 * 1024 * 1024) {
            $c = (string) @file_get_contents($abs);
            $n = preg_match_all('/\/Type\s*\/Page(?![a-zA-Z])/', $c);
            $pages = $n ? (int) $n : null;
        }

        $thumb = null;
        if ($finder->find('pdftoppm')) {
            $prefix = sys_get_temp_dir() . '/pdfthumb_' . bin2hex(random_bytes(6));
            $p = new Process(['pdftoppm', '-jpeg', '-f', '1', '-l', '1', '-scale-to', '720', '-singlefile', $abs, $prefix]);
            $p->setTimeout(30);
            $p->run();
            if ($p->isSuccessful() && is_file($prefix . '.jpg')) {
                $rel = $relDir . '/th_' . bin2hex(random_bytes(8)) . '.jpg';
                $target = FileUploadService::absolutePath($rel);
                @mkdir(dirname($target), 0775, true);
                if (@copy($prefix . '.jpg', $target)) {
                    \App\Support\UploadMirror::push($rel, $target);
                    $thumb = $rel;
                }
            }
            @unlink($prefix . '.jpg');
        }
        return [$pages, $thumb];
    }

    private function codeMeta(string $abs, string $ext): array
    {
        $map = (array) config('posts.code_languages', []);
        [$lang, $runner] = $map[strtolower($ext)] ?? [strtolower($ext), 'none'];
        if ($runner === 'sandbox' && trim((string) config('services.sandbox.url')) === '') {
            $runner = 'none'; // بوابة التشغيل مش متظبطة على السيرفر
        }
        $limit = (int) config('posts.code_text_bytes', 524288);
        $size = (int) @filesize($abs);
        $head = (string) @file_get_contents($abs, false, null, 0, $limit);
        return [
            'language'  => $lang,
            'runner'    => $runner,          // html (iframe sandbox بالمتصفح) | sandbox (uip-RUN) | none
            'lines'     => $head === '' ? 0 : substr_count($head, "\n") + 1,
            'truncated' => $size > $limit,
        ];
    }

    /**
     * عرض تقديمي/مستند => PDF (LibreOffice) بعد ما الرد يتبعت، عشان يتعرض carousel زي PDF. اختياري (إعداد convert_office_to_pdf)
     * وfail-open: لو soffice مش موجود أو فشل، المرفق يفضل ملف عادي قابل للتحميل.
     */
    private function convertToPdfAfterResponse(int $mediaId, string $abs, string $relDir): void
    {
        app()->terminating(function () use ($mediaId, $abs, $relDir) {
            try {
                $bin = (new ExecutableFinder())->find('soffice') ?: (new ExecutableFinder())->find('libreoffice');
                if (!$bin || !is_file($abs)) {
                    return;
                }
                $tmp = sys_get_temp_dir() . '/postconv_' . bin2hex(random_bytes(6));
                @mkdir($tmp, 0775, true);
                $p = new Process([$bin, '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $tmp, $abs], null, ['HOME' => $tmp]);
                $p->setTimeout(120);
                $p->run();
                $pdf = $tmp . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.pdf';
                if ($p->isSuccessful() && is_file($pdf)) {
                    $rel = $relDir . '/pv_' . bin2hex(random_bytes(8)) . '.pdf';
                    $target = FileUploadService::absolutePath($rel);
                    @mkdir(dirname($target), 0775, true);
                    if (@copy($pdf, $target)) {
                        \App\Support\UploadMirror::push($rel, $target);
                        [$pages, $thumb] = $this->probePdf($target, $relDir);
                        DB::table('post_media')->where('id', $mediaId)->update([
                            'preview_path'   => $rel,
                            'page_count'     => $pages,
                            'thumbnail_path' => $thumb,
                        ]);
                    }
                }
                foreach (glob($tmp . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($tmp);
            } catch (\Throwable $e) {
                Log::warning('Post office->pdf conversion failed', ['media' => $mediaId, 'error' => $e->getMessage()]);
            }
        });
    }
}
