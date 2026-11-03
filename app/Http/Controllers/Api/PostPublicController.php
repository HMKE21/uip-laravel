<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FileUploadService;
use App\Services\MessagingService;
use App\Services\PostMediaService;
use App\Services\PostSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * صفحة المنشور العامة (من غير تسجيل دخول) — /api/v1/public/posts/*
 *
 * بترجّع بس المنشورات اللي: visibility=public + published + مش محذوفة/منتهية + صاحبها active + الميزة مفعّلة.
 * أي حاجة تانية => 404 بنفس الرسالة (من غير ما نكشف إن المنشور موجود أصلًا).
 * مفيش أي بيانات خاصة بالمشاهد (reaction / save / follow). الصورة الشخصية بتتعامل كأنها لشخص غريب.
 * مرفقات المنشور بتتسلّم من /api/v1/public/posts/media/{id}/{variant} وبنفس شرط "المنشور عام".
 */
class PostPublicController extends Controller
{
    public function __construct(private PostMediaService $media, private MessagingService $messaging)
    {
    }

    /** استعلام المنشورات العامة المنشورة بس. */
    public static function publicQuery()
    {
        return DB::table('posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereNull('p.deleted_at')
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->where('p.status', 'published')
            ->where('p.visibility', 'public')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM user_network_settings ns WHERE ns.user_id = p.user_id AND ns.profile_locked = 1)') // بروفايل مقفول = مفيش صفحات عامة
            ->where('p.published_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('p.expires_at')->orWhere('p.expires_at', '>', now()));
    }

    private function enabled(): bool
    {
        return (bool) PostSettings::get('enabled') && (bool) PostSettings::get('allow_public');
    }

    private function notFound()
    {
        return $this->apiError('Post not found.', null, 404);
    }

    private function find(int $id): ?object
    {
        if (!$this->enabled()) {
            return null;
        }
        return self::publicQuery()->where('p.id', $id)->first(['p.*']) ?: null;
    }

    /** GET /public/posts/{id} */
    public function show(Request $request, $id)
    {
        $row = $this->find((int) $id);
        if (!$row) {
            return $this->notFound();
        }
        return $this->apiSuccess($this->present($row), 'Post retrieved successfully.');
    }

    /** GET /public/posts/{id}/comments?page= — تعليقات المستوى الأول للقراءة بس (لو التعليقات مش مقفولة). */
    public function comments(Request $request, $id)
    {
        $row = $this->find((int) $id);
        if (!$row) {
            return $this->notFound();
        }
        if ($row->comments_mode === 'none') {
            return $this->apiSuccess([], 'Comments retrieved successfully.', 200, ['has_more' => false]);
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = 10;
        $rows = DB::table('post_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereNull('c.deleted_at')->whereNull('u.deleted_at')
            ->where('c.post_id', (int) $row->id)->whereNull('c.parent_id')
            ->orderByDesc('c.likes_count')->orderByDesc('c.id')
            ->offset(($page - 1) * $per)->limit($per + 1)
            ->get(['c.id', 'c.body', 'c.likes_count', 'c.replies_count', 'c.created_at', 'c.user_id', 'u.full_name', 'u.avatar_path'])->all();
        $more = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $items = array_map(fn ($r) => [
            'id'            => (int) $r->id,
            'body'          => $r->body,
            'likes_count'   => (int) $r->likes_count,
            'replies_count' => (int) $r->replies_count,
            'created_at'    => $r->created_at,
            'author'        => [
                'id'          => (int) $r->user_id,
                'full_name'   => $r->full_name,
                'avatar_path' => $this->messaging->isAvatarHidden((int) $r->user_id, null) ? null : $r->avatar_path,
                'is_author'   => (int) $r->user_id === (int) $row->user_id,
            ],
        ], $rows);
        return $this->apiSuccess($items, 'Comments retrieved successfully.', 200, ['has_more' => $more, 'page' => $page]);
    }

    // ---------------------------------------------------------------- مرفقات

    public function file(Request $request, $id)
    {
        return $this->serve((int) $id, 'file');
    }

    public function thumbnail(Request $request, $id)
    {
        return $this->serve((int) $id, 'thumbnail');
    }

    public function preview(Request $request, $id)
    {
        return $this->serve((int) $id, 'preview');
    }

    /** GET /public/posts/media/{id}/link?variant= — الواجهة بتستخدم نفس العقد بتاع المنشورات الخاصة؛ هنا الرابط مفتوح فبيرجع مسار التسليم نفسه. */
    public function link(Request $request, $id)
    {
        $variant = in_array($request->query('variant'), ['thumbnail', 'preview'], true) ? $request->query('variant') : 'file';
        $m = $this->authorizeMedia((int) $id);
        if (!$m || ($variant === 'thumbnail' && empty($m->thumbnail_path)) || ($variant === 'preview' && empty($m->preview_path))) {
            return $this->apiError('File not found.', null, 404);
        }
        return $this->apiSuccess(['url' => '/api/v1/public/posts/media/' . (int) $id . '/' . $variant, 'expires_in' => 600], 'Link created.');
    }

    /** GET /public/posts/media/{id}/text — نص الكود للمعاينة (نفس شكل المنشورات الخاصة). */
    public function text(Request $request, $id)
    {
        $m = $this->authorizeMedia((int) $id);
        if (!$m || !PostMediaService::isTextual((string) $m->extension)) {
            return $this->apiError('File not found.', null, 404);
        }
        $path = FileUploadService::absolutePath((string) $m->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }
        $limit = (int) config('posts.code_text_bytes', 524288);
        $raw = (string) @file_get_contents($path, false, null, 0, $limit);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8, Windows-1256, Windows-1252, ISO-8859-1');
        }
        $meta = $m->meta ? (array) json_decode((string) $m->meta, true) : [];
        return $this->apiSuccess([
            'text'      => $raw,
            'language'  => $meta['language'] ?? strtolower((string) $m->extension),
            // التشغيل داخل sandbox محتاج حساب؛ الـ html بيشتغل في iframe معزول فبيفضل متاح.
            'runner'    => (($meta['runner'] ?? 'none') === 'html' && (bool) ($m->_post->allow_run ?? true)) ? 'html' : 'none',
            'truncated' => (int) $m->size_bytes > $limit,
        ], 'File text retrieved successfully.');
    }

    /** GET /public/posts/media/{id}/download — بيحترم allow_download. */
    public function download(Request $request, $id)
    {
        $m = $this->authorizeMedia((int) $id);
        if (!$m) {
            return $this->apiError('File not found.', null, 404);
        }
        if (!(bool) $m->_post->allow_download) {
            return $this->apiError('The author disabled downloads for this post.', null, 403);
        }
        $path = FileUploadService::absolutePath((string) $m->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }
        return response()->download($path, (string) $m->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }

    // ----------------------------------------------------------------

    /** المرفق لو مربوط بمنشور عام منشور، وإلا null. اليتيم (من غير منشور) مش متاح للعامة أبدًا. */
    private function authorizeMedia(int $mediaId): ?object
    {
        $m = DB::table('post_media')->where('id', $mediaId)->first();
        if (!$m || $m->post_id === null) {
            return null;
        }
        $post = $this->find((int) $m->post_id);
        if (!$post) {
            return null;
        }
        $m->_post = $post;
        return $m;
    }

    private function serve(int $id, string $variant)
    {
        $m = $this->authorizeMedia($id);
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
        $inline = $mime !== 'application/octet-stream' && !str_starts_with($mime, 'text/');
        $name = $variant === 'thumbnail' ? 'thumbnail.jpg' : ($variant === 'preview' ? 'preview.pdf' : (string) $m->original_name);

        return response()->file($path, [
            'Content-Type'                 => $mime,
            'Content-Disposition'          => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => "sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control'                => 'public, max-age=300',
        ]);
    }

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
        return $map[$ext] ?? 'application/octet-stream';
    }

    /** شكل المنشور العام: نفس شكل الواجهة لكن من غير أي حاجة خاصة بالمشاهد، ومسارات المرفقات عامة. */
    private function present(object $r): array
    {
        $r = (array) $r;
        $id = (int) $r['id'];
        $authorId = (int) $r['user_id'];
        $a = DB::table('users')->where('id', $authorId)->first(['id', 'full_name', 'avatar_path']);
        $role = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $authorId)->orderBy('ur.assigned_at')->value('r.slug');

        $media = array_map(fn ($m) => $this->publicMedia($m), $this->media->forPosts([$id])[$id] ?? []);
        $tags = DB::table('post_hashtags')->where('post_id', $id)->pluck('tag')->all();
        $showCounts = !$r['hide_counts'];
        $breakdown = [];
        if ($showCounts) {
            foreach (DB::table('post_reactions')->where('post_id', $id)->selectRaw('type, COUNT(*) as c')->groupBy('type')->get() as $b) {
                $breakdown[$b->type] = (int) $b->c;
            }
        }

        $repost = null;
        if (!empty($r['repost_of_id'])) {
            $orig = $this->find((int) $r['repost_of_id']);
            $repost = $orig ? $this->presentOriginal($orig) : ['id' => (int) $r['repost_of_id'], 'unavailable' => true];
        }

        return [
            'id'             => $id,
            'kind'           => $r['kind'],
            'title'          => $r['title'],
            'body'           => $r['body'],
            'link_url'       => $r['link_url'],
            'language'       => $r['language'],
            'comments_mode'  => $r['comments_mode'],
            'allow_download' => (bool) $r['allow_download'],
            'allow_run'      => (bool) $r['allow_run'],
            'is_sensitive'   => (bool) $r['is_sensitive'],
            'published_at'   => $r['published_at'],
            'edited_at'      => $r['edited_at'],
            'counts'         => $showCounts ? [
                'reactions' => (int) $r['reactions_count'], 'comments' => (int) $r['comments_count'],
                'reposts' => (int) $r['reposts_count'], 'shares' => (int) $r['shares_count'], 'views' => (int) $r['views_count'],
            ] : null,
            'reaction_breakdown' => $showCounts ? (object) $breakdown : null,
            'author'         => [
                'id'          => $authorId,
                'full_name'   => $a->full_name ?? 'Deleted user',
                'avatar_path' => $a && !$this->messaging->isAvatarHidden($authorId, null) ? $a->avatar_path : null,
                'role'        => $role,
            ],
            'media'          => $media,
            'hashtags'       => $tags,
            'repost_of'      => $repost,
        ];
    }

    private function presentOriginal(object $r): array
    {
        $r = (array) $r;
        $id = (int) $r['id'];
        $a = DB::table('users')->where('id', (int) $r['user_id'])->first(['id', 'full_name', 'avatar_path']);
        return [
            'id'           => $id,
            'title'        => $r['title'],
            'body'         => $r['body'],
            'published_at' => $r['published_at'],
            'author'       => [
                'id'          => (int) $r['user_id'],
                'full_name'   => $a->full_name ?? 'Deleted user',
                'avatar_path' => $a && !$this->messaging->isAvatarHidden((int) $r['user_id'], null) ? $a->avatar_path : null,
            ],
            'media'        => array_map(fn ($m) => $this->publicMedia($m), $this->media->forPosts([$id])[$id] ?? []),
        ];
    }

    /** يحوّل مسارات المرفق الخاصة لمسارات المنشور العام. */
    private function publicMedia(array $m): array
    {
        $base = '/api/v1/public/posts/media/' . (int) $m['id'];
        $m['urls'] = [
            'link'      => $base . '/link',
            'file'      => $base . '/file',
            'thumbnail' => !empty($m['has_thumbnail']) ? $base . '/thumbnail' : null,
            'preview'   => !empty($m['has_preview']) ? $base . '/preview' : null,
            'text'      => !empty($m['is_text']) ? $base . '/text' : null,
            'download'  => $base . '/download',
        ];
        return $m;
    }
}
