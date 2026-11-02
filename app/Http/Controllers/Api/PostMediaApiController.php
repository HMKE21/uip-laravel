<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChunkedUploadService;
use App\Services\FileUploadService;
use App\Services\PostMediaService;
use App\Services\PostVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * /api/v1/posts/media/* — رفع وتسليم مرفقات المنشورات.
 *
 * الملفات خارج public/ (فئة posts خاصة): مفيش رابط مباشر. كل تسليم بيعدّي على authorize() اللي بيستخدم PostVisibility،
 * يعني الملف بيتسلّم بس لمن يقدر يشوف المنشور (أو لصاحبه لو لسه يتيم). للـ <video> / pdf.js / التطبيق الأصلي (من غير Authorization header)
 * فيه رابط موقّع قصير العمر: GET /media/{id}/link => /media/signed/{id}/{variant}?u=..&signature=.. (بره uip.auth، التوقيع + الرؤية بتتفحص جوه).
 */
class PostMediaApiController extends Controller
{
    public function __construct(
        private PostMediaService $media,
        private PostVisibility $vis,
        private ChunkedUploadService $chunks
    ) {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** POST /posts/media — multipart: file | أو upload_id (رفع بالأجزاء عبر /uploads/{id}/chunk). */
    public function upload(Request $request)
    {
        $uid = $this->uid($request);
        try {
            if (!\App\Services\PostSettings::get('enabled')) {
                throw new \RuntimeException('Posts are turned off by the administrator.');
            }
            $uploadId = $request->input('upload_id');
            if (is_string($uploadId) && $uploadId !== '') {
                $file = $this->chunks->open($uid, $uploadId);
                $shape = $this->media->upload($uid, $file);
                $this->chunks->discard($uid, $uploadId);
            } else {
                $file = $request->file('file');
                if (!$file) {
                    throw new \RuntimeException('No file was uploaded.');
                }
                $shape = $this->media->upload($uid, $file);
            }
            return $this->apiSuccess($shape, 'File uploaded successfully.', 201);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 400);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Post media DB error: ' . $e->getMessage());
            return $this->apiError('Server Error', null, 500);
        }
    }

    /** DELETE /posts/media/{id} — مرفق لسه مش مربوط بمنشور (لو مربوط: شيله بتعديل المنشور media_ids). */
    public function destroy(Request $request, $id)
    {
        $uid = $this->uid($request);
        if ($this->media->deleteOrphan($uid, (int) $id)) {
            return $this->apiSuccess(null, 'File removed.');
        }
        $attached = DB::table('post_media')->where('id', (int) $id)->where('user_id', $uid)->exists();
        return $attached
            ? $this->apiError('This file is attached to a post. Edit the post to remove it.', null, 400)
            : $this->apiError('File not found.', null, 404);
    }

    public function file(Request $request, $id)
    {
        return $this->serve($request, (int) $id, 'file', $this->uid($request));
    }

    public function thumbnail(Request $request, $id)
    {
        return $this->serve($request, (int) $id, 'thumbnail', $this->uid($request));
    }

    public function preview(Request $request, $id)
    {
        return $this->serve($request, (int) $id, 'preview', $this->uid($request));
    }

    /** GET /posts/media/{id}/download — attachment. بيحترم allow_download (صاحب المنشور دايمًا يقدر). */
    public function download(Request $request, $id)
    {
        $uid = $this->uid($request);
        $m = $this->authorize($uid, (int) $id);
        if (!$m) {
            return $this->apiError('File not found.', null, 404);
        }
        if (!$this->canDownload($m, $uid)) {
            return $this->apiError('The author disabled downloads for this post.', null, 403);
        }
        $path = FileUploadService::absolutePath((string) $m->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }
        return response()->download($path, (string) $m->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** GET /posts/media/{id}/text — نص الكود/الملف النصي للمعاينة (highlight / تشغيل). JSON: { text, language, runner, truncated }. */
    public function text(Request $request, $id)
    {
        $m = $this->authorize($this->uid($request), (int) $id);
        if (!$m || !PostMediaService::isTextual((string) $m->extension)) {
            return $this->apiError('File not found.', null, 404);
        }
        $path = FileUploadService::absolutePath((string) $m->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }
        $limit = (int) config('posts.code_text_bytes', 524288);
        $raw = (string) @file_get_contents($path, false, null, 0, $limit + 4);
        if (strlen($raw) > $limit) {
            // قصّ على حدّ حرف كامل (مش في نص حرف UTF-8) عشان الـ JSON ما يفشلش على ملفات أكبر من الحد.
            $raw = mb_strcut($raw, 0, $limit, 'UTF-8');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8, Windows-1256, Windows-1252, ISO-8859-1');
        }
        $raw = mb_scrub($raw, 'UTF-8'); // أي بايتات تالفة متتحوّلش لخطأ 500 في json_encode
        $meta = $m->meta ? (array) json_decode((string) $m->meta, true) : [];
        return $this->apiSuccess([
            'text'      => $raw,
            'language'  => $meta['language'] ?? strtolower((string) $m->extension),
            'runner'    => $this->canRun($m, $this->uid($request)) ? ($meta['runner'] ?? 'none') : 'none',
            'truncated' => (int) $m->size_bytes > $limit,
        ], 'File text retrieved successfully.');
    }

    /** GET /posts/media/{id}/link?variant=file|thumbnail|preview — رابط موقّع قصير العمر. */
    public function link(Request $request, $id)
    {
        $variant = in_array($request->query('variant'), ['thumbnail', 'preview'], true) ? $request->query('variant') : 'file';
        $uid = $this->uid($request);
        $m = $this->authorize($uid, (int) $id);
        if (!$m || ($variant === 'thumbnail' && empty($m->thumbnail_path)) || ($variant === 'preview' && empty($m->preview_path))) {
            return $this->apiError('File not found.', null, 404);
        }
        $ttl = max(60, (int) config('upload.signed_media_ttl', 600));
        $url = URL::temporarySignedRoute('posts.media.signed', now()->addSeconds($ttl), ['id' => (int) $id, 'variant' => $variant, 'u' => $uid], absolute: false);
        return $this->apiSuccess(['url' => $url, 'expires_in' => $ttl], 'Link created.');
    }

    /** GET /posts/media/signed/{id}/{variant}?u=..&expires=..&signature=.. — بعد middleware signed:relative. */
    public function signed(Request $request, $id, $variant)
    {
        $uid = (int) $request->query('u');
        if ($uid <= 0 || !in_array($variant, ['file', 'thumbnail', 'preview'], true)) {
            return $this->apiError('File not found.', null, 404);
        }
        return $this->serve($request, (int) $id, (string) $variant, $uid);
    }

    /** POST /posts/media/{id}/run-token — توكن بوابة التشغيل (uip-RUN) لكود مرفق بمنشور. الواجهة بتجيب النص من /text وتبعته للـ sandbox. */
    public function runToken(Request $request, $id)
    {
        $uid = $this->uid($request);
        $m = $this->authorize($uid, (int) $id);
        $meta = $m && $m->meta ? (array) json_decode((string) $m->meta, true) : [];
        if (!$m || $m->kind !== 'code' || ($meta['runner'] ?? 'none') !== 'sandbox') {
            return $this->apiError('This file cannot be run.', null, 400);
        }
        if (!$this->canRun($m, $uid)) {
            return $this->apiError('The author did not allow running this code.', null, 403);
        }
        $url = rtrim((string) config('services.sandbox.url'), '/');
        $secret = (string) config('services.sandbox.secret');
        if ($url === '' || strlen($secret) < 32) {
            return $this->apiError('The sandbox is not configured on this server.', null, 503);
        }
        $ttl = max(30, (int) config('services.sandbox.token_ttl', 120));
        $now = time();
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode([
            'iss' => 'uip-api', 'aud' => 'uip-sandbox', 'sub' => (string) $uid, 'pid' => 'post-' . (int) $m->id,
            'iat' => $now, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(8)),
        ]));
        $sig = $b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
        return $this->apiSuccess(['url' => $url, 'token' => "{$head}.{$body}.{$sig}", 'expires_in' => $ttl], 'Sandbox token issued.');
    }

    // ----------------------------------------------------------------

    /** المرفق لو المستخدم يقدر يشوفه (+ _post لو مربوط)، وإلا null. اليتيم لصاحبه بس. */
    private function authorize(int $uid, int $mediaId): ?object
    {
        $m = DB::table('post_media')->where('id', $mediaId)->first();
        if (!$m) {
            return null;
        }
        if ($m->post_id === null) {
            return (int) $m->user_id === $uid ? $m : null;
        }
        $post = $this->vis->find($this->vis->viewer($uid), (int) $m->post_id);
        if (!$post) {
            return null;
        }
        $m->_post = $post;
        return $m;
    }

    /** التشغيل: صاحب المنشور (أو الملف لو لسه يتيم) دايمًا، وغيره بس لو الكاتب فعّل allow_run. */
    private function canRun(object $m, int $uid): bool
    {
        if (!isset($m->_post)) {
            return (int) $m->user_id === $uid;
        }
        return (int) $m->_post->user_id === $uid || (bool) ($m->_post->allow_run ?? true);
    }

    private function canDownload(object $m, int $uid): bool
    {
        if (!isset($m->_post)) {
            return (int) $m->user_id === $uid;
        }
        return (int) $m->_post->user_id === $uid || (bool) $m->_post->allow_download;
    }

    private function serve(Request $request, int $id, string $variant, int $uid)
    {
        $m = $this->authorize($uid, $id);
        if (!$m) {
            return $this->apiError('File not found.', null, 404);
        }
        $rel = match ($variant) {
            'thumbnail' => (string) ($m->thumbnail_path ?? ''),
            'preview'   => (string) ($m->preview_path ?? ''),
            default     => (string) $m->stored_path,
        };
        if ($rel === '') {
            return $this->apiError('File not found.', null, 404);
        }
        $path = FileUploadService::absolutePath($rel);
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $variant === 'thumbnail' ? 'image/jpeg' : ($variant === 'preview' ? 'application/pdf' : $this->safeMime($ext, (string) $m->kind));
        // inline بس للأنواع الآمنة للعرض؛ أي حاجة تانية بتتنزّل (مفيش HTML/JS بيتنفّذ من نفس الأوريجن أبدًا).
        $inline = $mime !== 'application/octet-stream' && !str_starts_with($mime, 'text/');
        // "file" الأصلي لمرفق allow_download=false: بيتعرض (وسائط/PDF) بس مش بيتنزّل كـ attachment.
        $name = $variant === 'thumbnail' ? 'thumbnail.jpg' : ($variant === 'preview' ? 'preview.pdf' : (string) $m->original_name);

        return response()->file($path, [
            'Content-Type'                 => $mime,
            'Content-Disposition'          => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => "sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control'                => 'private, max-age=300',
        ]);
    }

    /** MIME من الامتداد الفعلي المتخزّن (مش من اللي بعته العميل). أي حاجة مش في القائمة => octet-stream. */
    private function safeMime(string $ext, string $kind): string
    {
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'opus' => 'audio/ogg', 'weba' => 'audio/webm',
            'pdf' => 'application/pdf',
        ];
        if ($ext === 'webm') {
            return $kind === 'audio' ? 'audio/webm' : 'video/webm';
        }
        return $map[$ext] ?? 'application/octet-stream'; // svg عمدًا مش inline (XSS) => بيتنزّل
    }
}
