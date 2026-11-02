<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** مراجعة المنشورات للأدمن: البلاغات، المنشورات المعلّقة (الموافقة المسبقة / كتر بلاغات)، وإحصائيات سريعة. */
class PostModerationService
{
    public function __construct(
        private PostService $posts,
        private PostInteractionService $interactions,
        private PostNotifier $notifier,
        private PostVisibility $vis
    ) {
    }

    public function stats(): array
    {
        $byStatus = DB::table('posts')->whereNull('deleted_at')->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all();
        return [
            'posts_by_status'   => $byStatus,
            'posts_last_7_days' => (int) DB::table('posts')->whereNull('deleted_at')->where('status', 'published')->where('published_at', '>=', now()->subDays(7))->count(),
            'comments_last_7d'  => (int) DB::table('post_comments')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(7))->count(),
            'pending_reports'   => (int) DB::table('post_reports')->where('status', 'pending')->count(),
            'pending_posts'     => (int) ($byStatus['pending'] ?? 0),
            'active_authors_7d' => (int) DB::table('posts')->whereNull('deleted_at')->where('published_at', '>=', now()->subDays(7))->distinct()->count('user_id'),
        ];
    }

    public function reports(string $status, int $page, int $per): array
    {
        $per = max(1, min(50, $per));
        $q = DB::table('post_reports as r')
            ->join('posts as p', 'p.id', '=', 'r.post_id')
            ->join('users as rep', 'rep.id', '=', 'r.reporter_id')
            ->join('users as au', 'au.id', '=', 'p.user_id')
            ->leftJoin('post_comments as c', 'c.id', '=', 'r.comment_id');
        if (in_array($status, ['pending', 'resolved', 'dismissed'], true)) {
            $q->where('r.status', $status);
        }
        $rows = $q->orderByDesc('r.id')->offset(($page - 1) * $per)->limit($per + 1)->get([
            'r.id', 'r.post_id', 'r.comment_id', 'r.reason', 'r.details', 'r.status', 'r.resolution', 'r.created_at',
            'rep.full_name as reporter_name', 'au.id as author_id', 'au.full_name as author_name',
            'p.title as post_title', 'p.body as post_body', 'p.status as post_status', 'c.body as comment_body',
        ])->all();
        $hasMore = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $counts = [];
        if ($rows) {
            $counts = DB::table('post_reports')->whereIn('post_id', array_unique(array_map(fn ($r) => (int) $r->post_id, $rows)))
                ->where('status', 'pending')->selectRaw('post_id, COUNT(*) c')->groupBy('post_id')->pluck('c', 'post_id')->all();
        }
        return [
            'items' => array_map(fn ($r) => [
                'id'               => (int) $r->id,
                'post_id'          => (int) $r->post_id,
                'comment_id'       => $r->comment_id ? (int) $r->comment_id : null,
                'reason'           => $r->reason,
                'details'          => $r->details,
                'status'           => $r->status,
                'resolution'       => $r->resolution,
                'created_at'       => $r->created_at,
                'reporter_name'    => $r->reporter_name,
                'author_id'        => (int) $r->author_id,
                'author_name'      => $r->author_name,
                'post_title'       => $r->post_title,
                'post_snippet'     => mb_substr((string) $r->post_body, 0, 240),
                'post_status'      => $r->post_status,
                'comment_snippet'  => $r->comment_body !== null ? mb_substr((string) $r->comment_body, 0, 240) : null,
                'pending_for_post' => (int) ($counts[(int) $r->post_id] ?? 0),
            ], $rows),
            'has_more' => $hasMore,
        ];
    }

    /** action: dismiss | remove_post | remove_comment — بيقفل كل البلاغات المعلّقة على نفس الهدف. */
    public function resolve(int $adminId, int $reportId, string $action): void
    {
        $r = DB::table('post_reports')->where('id', $reportId)->first();
        if (!$r) {
            throw new \RuntimeException('Report not found.');
        }
        if (!in_array($action, ['dismiss', 'remove_post', 'remove_comment'], true)) {
            throw new \RuntimeException('Invalid action.');
        }
        if ($action === 'remove_comment' && !$r->comment_id) {
            throw new \RuntimeException('This report is not about a comment.');
        }
        DB::transaction(function () use ($adminId, $r, $action) {
            if ($action === 'remove_post') {
                $this->posts->delete($adminId, (int) $r->post_id, 'Removed after a report (' . $r->reason . ')');
            } elseif ($action === 'remove_comment') {
                $this->interactions->deleteComment($adminId, (int) $r->comment_id);
            }
            $same = DB::table('post_reports')->where('post_id', $r->post_id)->where('status', 'pending');
            $r->comment_id ? $same->where('comment_id', $r->comment_id) : $same->whereNull('comment_id');
            $same->update([
                'status'      => $action === 'dismiss' ? 'dismissed' : 'resolved',
                'resolution'  => $action === 'dismiss' ? 'dismissed' : ($action === 'remove_post' ? 'post_removed' : 'comment_removed'),
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);
            if ($action === 'dismiss') { // المنشور اتعلّق أوتوماتيك بسبب البلاغات؟ يرجع.
                DB::table('posts')->where('id', $r->post_id)->where('status', 'pending')->whereNotNull('published_at')->update(['status' => 'published']);
            }
        });
    }

    public function pending(int $adminId, int $page, int $per): array
    {
        $per = max(1, min(50, $per));
        $rows = DB::table('posts as p')->where('p.status', 'pending')->whereNull('p.deleted_at')
            ->orderBy('p.created_at')->offset(($page - 1) * $per)->limit($per + 1)->get(['p.*'])->all();
        $hasMore = count($rows) > $per;
        return ['items' => $this->posts->present(array_slice($rows, 0, $per), $adminId), 'has_more' => $hasMore];
    }

    public function approve(int $adminId, int $postId): void
    {
        $post = DB::table('posts')->where('id', $postId)->whereNull('deleted_at')->where('status', 'pending')->first();
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        DB::table('posts')->where('id', $postId)->update(['status' => 'published', 'published_at' => $post->published_at ?: now()]);
        DB::table('post_reports')->where('post_id', $postId)->where('status', 'pending')->update([
            'status' => 'dismissed', 'resolution' => 'dismissed', 'resolved_by' => $adminId, 'resolved_at' => now(),
        ]);
        $this->posts->afterPublish($postId, (int) $post->user_id);
        $this->notifier->notify((int) $post->user_id, $adminId, 'post_approved', 'approved your post', $postId);
    }

    public function reject(int $adminId, int $postId, ?string $reason): void
    {
        $post = DB::table('posts')->where('id', $postId)->whereNull('deleted_at')->where('status', 'pending')->first();
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        $this->posts->delete($adminId, $postId, $reason ?: 'Rejected by a moderator');
    }
}
