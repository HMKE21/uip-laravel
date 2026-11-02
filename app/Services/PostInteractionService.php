<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * تفاعل المستخدم مع المنشورات: reactions / save / repost / share / views(dwell) / hide / mute / report / comments.
 * كل ميثود بتبدأ بفحص الرؤية (PostVisibility) فمفيش تفاعل على منشور المستخدم مايقدرش يشوفه.
 * الإشارات (تفاعل، حفظ، تعليق، مكوث) بتغذّي user_post_interests اللي PostFeedService بيستخدمها في الترتيب.
 */
class PostInteractionService
{
    public function __construct(
        private PostVisibility $vis,
        private PostService $posts,
        private PostNotifier $notifier,
        private MessagingService $messaging
    ) {
    }

    // ---------------------------------------------------------------- reactions

    /** @return array{my_reaction:?string,reactions_count:int,breakdown:array} */
    public function react(int $uid, int $postId, ?string $type): array
    {
        $post = $this->visiblePublished($uid, $postId);
        if ($type !== null && !in_array($type, (array) config('posts.reactions'), true)) {
            throw new \RuntimeException('Invalid reaction.');
        }
        $existing = DB::table('post_reactions')->where('post_id', $postId)->where('user_id', $uid)->first();
        $added = false;

        DB::transaction(function () use ($uid, $postId, $type, $existing, &$added) {
            if ($type === null) {
                if ($existing && DB::table('post_reactions')->where('post_id', $postId)->where('user_id', $uid)->delete()) {
                    DB::table('posts')->where('id', $postId)->where('reactions_count', '>', 0)->decrement('reactions_count');
                }
            } elseif (!$existing) {
                DB::table('post_reactions')->insert(['post_id' => $postId, 'user_id' => $uid, 'type' => $type, 'created_at' => now()]);
                DB::table('posts')->where('id', $postId)->increment('reactions_count');
                $added = true;
            } elseif ($existing->type !== $type) {
                DB::table('post_reactions')->where('post_id', $postId)->where('user_id', $uid)->update(['type' => $type]);
            }
        });

        if ($added) {
            $this->bumpInterests($uid, $postId, 1.0);
            // مرة واحدة في اليوم لكل (مستخدم، منشور) — تبديل التفاعل لا يكرر الإشعار.
            if (Cache::add("post_react_notify:{$uid}:{$postId}", 1, 86400)) {
                $this->notifier->notify((int) $post->user_id, $uid, 'post_reaction', 'reacted to your post', $postId, $post->body);
            }
        }
        $fresh = DB::table('posts')->where('id', $postId)->value('reactions_count');
        return [
            'my_reaction'     => $type,
            'reactions_count' => (int) $fresh,
            'breakdown'       => DB::table('post_reactions')->where('post_id', $postId)->selectRaw('type, COUNT(*) c')->groupBy('type')->pluck('c', 'type')->all(),
        ];
    }

    /** مين تفاعل (للمالك أو لو العدّادات مش مخفية). */
    public function reactors(int $uid, int $postId, ?string $type, int $page, int $perPage): array
    {
        $post = $this->visiblePublished($uid, $postId);
        if ($post->hide_counts && (int) $post->user_id !== $uid && empty($this->vis->viewer($uid)['is_moderator'])) {
            throw new \RuntimeException('The author hid the reactions on this post.');
        }
        $q = DB::table('post_reactions as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.post_id', $postId)->whereNull('u.deleted_at')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = u.id) OR (b.blocker_user_id = u.id AND b.blocked_user_id = ?))', [$uid, $uid]);
        if ($type && in_array($type, (array) config('posts.reactions'), true)) {
            $q->where('r.type', $type);
        }
        $rows = $q->orderByDesc('r.created_at')->forPage($page, $perPage)->get(['u.id', 'u.full_name', 'u.avatar_path', 'r.type', 'r.created_at']);
        return $rows->map(fn ($r) => [
            'id'          => (int) $r->id,
            'full_name'   => $r->full_name,
            'avatar_path' => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
            'type'        => $r->type,
            'at'          => $r->created_at,
        ])->all();
    }

    /**
     * مين شارك المنشور: مشاركات (رابط / شات / خارجي) + إعادات النشر (مع تعليقهم).
     * $kind: all | share | repost. نفس قاعدة الإخفاء بتاعة reactors (المالك والمشرف يشوفوا دايمًا).
     *
     * @return array<int,array>
     */
    public function sharers(int $uid, int $postId, string $kind, int $page, int $perPage): array
    {
        $post = $this->visiblePublished($uid, $postId);
        if ($post->hide_counts && (int) $post->user_id !== $uid && empty($this->vis->viewer($uid)['is_moderator'])) {
            throw new \RuntimeException('The author hid the counts on this post.');
        }
        $block = 'NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = u.id) OR (b.blocker_user_id = u.id AND b.blocked_user_id = ?))';
        $take = $page * $perPage;
        $out = [];

        if ($kind === 'all' || $kind === 'share') {
            $rows = DB::table('post_shares as s')->join('users as u', 'u.id', '=', 's.user_id')
                ->where('s.post_id', $postId)->whereNull('u.deleted_at')->whereRaw($block, [$uid, $uid])
                ->orderByDesc('s.created_at')->limit($take)->get(['u.id', 'u.full_name', 'u.avatar_path', 's.channel', 's.created_at']);
            foreach ($rows as $r) {
                $out[] = ['kind' => 'share', 'id' => (int) $r->id, 'full_name' => $r->full_name, 'avatar_path' => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
                    'channel' => $r->channel, 'at' => $r->created_at];
            }
        }
        if ($kind === 'all' || $kind === 'repost') {
            $rows = DB::table('posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.repost_of_id', $postId)->where('p.status', 'published')->whereNull('p.deleted_at')->whereNull('u.deleted_at')->whereRaw($block, [$uid, $uid])
                ->orderByDesc('p.created_at')->limit($take)->get(['u.id', 'u.full_name', 'u.avatar_path', 'p.id as post_id', 'p.body', 'p.created_at']);
            foreach ($rows as $r) {
                $out[] = ['kind' => 'repost', 'id' => (int) $r->id, 'full_name' => $r->full_name, 'avatar_path' => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
                    'post_id' => (int) $r->post_id, 'body' => $r->body, 'at' => $r->created_at];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($out, ($page - 1) * $perPage, $perPage);
    }

    /** مين شاف المنشور (المالك والمشرف بس): عدد المشاهدات، مدة المكوث، هل خلّصه، آخر مشاهدة. */
    public function viewers(int $uid, int $postId, int $page, int $perPage): array
    {
        $post = $this->visiblePublished($uid, $postId);
        if ((int) $post->user_id !== $uid && empty($this->vis->viewer($uid)['is_moderator'])) {
            throw new \RuntimeException('Only the author can see who viewed this post.');
        }
        $rows = DB::table('post_views as v')->join('users as u', 'u.id', '=', 'v.viewer_id')
            ->where('v.post_id', $postId)->whereNull('u.deleted_at')
            ->orderByDesc('v.last_viewed_at')->forPage($page, $perPage)
            ->get(['u.id', 'u.full_name', 'u.avatar_path', 'v.views', 'v.dwell_ms', 'v.completed', 'v.last_viewed_at']);
        return $rows->map(fn ($r) => [
            'id'          => (int) $r->id,
            'full_name'   => $r->full_name,
            'avatar_path' => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
            'views'       => (int) $r->views,
            'dwell_ms'    => (int) $r->dwell_ms,
            'completed'   => (bool) $r->completed,
            'at'          => $r->last_viewed_at,
        ])->all();
    }

    // ---------------------------------------------------------------- save / share / repost

    /**
     * حفظ / إلغاء حفظ / نقل لمجلد.
     * $action: 'save' (يحفظ أو ينقل لمجلد $collectionId — null = بدون مجلد) | 'unsave' | null (toggle قديم).
     */
    public function toggleSave(int $uid, int $postId, ?string $action = null, ?int $collectionId = null): array
    {
        $this->visiblePublished($uid, $postId);
        if ($collectionId !== null && !DB::table('post_collections')->where('id', $collectionId)->where('user_id', $uid)->exists()) {
            throw new \RuntimeException('Collection not found.');
        }
        $row = DB::table('post_saves')->where('post_id', $postId)->where('user_id', $uid)->first();
        $exists = (bool) $row;
        $wantSave = $action === 'save' ? true : ($action === 'unsave' ? false : !$exists);

        if ($exists && !$wantSave) {
            DB::table('post_saves')->where('post_id', $postId)->where('user_id', $uid)->delete();
            DB::table('posts')->where('id', $postId)->where('saves_count', '>', 0)->decrement('saves_count');
        } elseif ($exists && $wantSave) {
            if ($action === 'save') {
                DB::table('post_saves')->where('post_id', $postId)->where('user_id', $uid)->update(['collection_id' => $collectionId]);
            }
        } elseif (!$exists && $wantSave) {
            DB::table('post_saves')->insertOrIgnore(['post_id' => $postId, 'user_id' => $uid, 'collection_id' => $collectionId, 'created_at' => now()]);
            DB::table('posts')->where('id', $postId)->increment('saves_count');
            $this->bumpInterests($uid, $postId, 1.5);
        }
        $saved = $wantSave;
        return [
            'saved'         => $saved,
            'collection_id' => $saved ? $collectionId : null,
            'saves_count'   => (int) DB::table('posts')->where('id', $postId)->value('saves_count'),
        ];
    }

    /** مجلدات المحفوظات + عدد المنشورات في كل مجلد (+ عدد غير المصنّف). */
    public function collections(int $uid): array
    {
        $counts = DB::table('post_saves')->where('user_id', $uid)->selectRaw('collection_id, COUNT(*) c')->groupBy('collection_id')->pluck('c', 'collection_id')->all();
        $items = DB::table('post_collections')->where('user_id', $uid)->orderBy('name')->get()
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'count' => (int) ($counts[$c->id] ?? 0)])->all();
        return ['items' => $items, 'uncategorized' => (int) ($counts[''] ?? $counts[0] ?? 0), 'total' => (int) array_sum($counts)];
    }

    public function createCollection(int $uid, string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new \RuntimeException('Collection name must be 1-60 characters.');
        }
        if (DB::table('post_collections')->where('user_id', $uid)->count() >= 50) {
            throw new \RuntimeException('You reached the maximum number of collections.');
        }
        if (DB::table('post_collections')->where('user_id', $uid)->where('name', $name)->exists()) {
            throw new \RuntimeException('A collection with this name already exists.');
        }
        $id = DB::table('post_collections')->insertGetId(['user_id' => $uid, 'name' => $name, 'created_at' => now()]);
        return ['id' => (int) $id, 'name' => $name, 'count' => 0];
    }

    public function renameCollection(int $uid, int $id, string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new \RuntimeException('Collection name must be 1-60 characters.');
        }
        if (!DB::table('post_collections')->where('id', $id)->where('user_id', $uid)->exists()) {
            throw new \RuntimeException('Collection not found.');
        }
        if (DB::table('post_collections')->where('user_id', $uid)->where('name', $name)->where('id', '!=', $id)->exists()) {
            throw new \RuntimeException('A collection with this name already exists.');
        }
        DB::table('post_collections')->where('id', $id)->update(['name' => $name]);
        return ['id' => $id, 'name' => $name];
    }

    /** حذف المجلد بس — المنشورات المحفوظة فيه بترجع "بدون مجلد" ومبتتمسحش. */
    public function deleteCollection(int $uid, int $id): void
    {
        if (!DB::table('post_collections')->where('id', $id)->where('user_id', $uid)->exists()) {
            throw new \RuntimeException('Collection not found.');
        }
        DB::table('post_saves')->where('user_id', $uid)->where('collection_id', $id)->update(['collection_id' => null]);
        DB::table('post_collections')->where('id', $id)->delete();
    }

    public function share(int $uid, int $postId, string $channel): array
    {
        $this->visiblePublished($uid, $postId);
        if (!in_array($channel, (array) config('posts.share_channels'), true)) {
            $channel = 'link';
        }
        DB::table('post_shares')->insert(['post_id' => $postId, 'user_id' => $uid, 'channel' => $channel, 'created_at' => now()]);
        DB::table('posts')->where('id', $postId)->increment('shares_count');
        $this->bumpInterests($uid, $postId, 0.8);
        return ['shares_count' => (int) DB::table('posts')->where('id', $postId)->value('shares_count')];
    }

    /** إعادة نشر (مع تعليق اختياري). بس للمنشورات العامة، ودايمًا على المنشور الأصلي (مفيش repost لـ repost). */
    public function repost(int $uid, int $postId, ?string $commentary): array
    {
        if (!PostSettings::get('allow_reposts')) {
            throw new \RuntimeException('Reposting is turned off by the administrator.');
        }
        $post = $this->visiblePublished($uid, $postId);
        if ($post->repost_of_id) {
            $post = $this->visiblePublished($uid, (int) $post->repost_of_id);
        }
        if ($post->visibility !== 'public' || !$post->allow_repost) {
            throw new \RuntimeException('This post cannot be reposted.');
        }
        if (DB::table('posts')->where('user_id', $uid)->where('repost_of_id', $post->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('You already reposted this post.');
        }
        $text = trim((string) $commentary);
        if (mb_strlen($text) > (int) config('posts.max_body')) {
            throw new \RuntimeException('The post text is too long.');
        }
        $viewer = $this->vis->viewer($uid);
        $newId = DB::transaction(function () use ($uid, $viewer, $post, $text) {
            $id = (int) DB::table('posts')->insertGetId([
                'user_id' => $uid, 'university_id' => $viewer['university_id'], 'faculty_id' => $viewer['faculty_id'],
                'kind' => 'text', 'body' => $text !== '' ? $text : null, 'visibility' => 'public', 'status' => 'published',
                'comments_mode' => 'everyone', 'repost_of_id' => (int) $post->id, 'published_at' => now(), 'created_at' => now(),
            ]);
            DB::table('posts')->where('id', $post->id)->increment('reposts_count');
            return $id;
        });
        $this->bumpInterests($uid, (int) $post->id, 1.2);
        $this->notifier->notify((int) $post->user_id, $uid, 'post_repost', 'reposted your post', (int) $post->id, $post->body);
        return $this->posts->one($uid, $newId) ?? [];
    }

    // ---------------------------------------------------------------- views / dwell

    /**
     * الواجهة بتبعت المشاهدة لما المنشور يخرج من الشاشة (أو كل فترة للفيديو): dwell_ms مدة المكوث في الجلسة دي،
     * completed لو خلّص الفيديو/كل صفحات الـ PDF. المشاهدة بتتحسب مرة واحدة لكل (مستخدم، منشور) في views_count،
     * ومدة المكوث بتتراكم وبتغذّي الاهتمامات لما تعدّي 4 ثواني (أو الإكمال).
     */
    public function trackView(int $uid, int $postId, int $dwellMs, bool $completed): void
    {
        $post = $this->visiblePublished($uid, $postId);
        if ((int) $post->user_id === $uid) {
            return;
        }
        $dwellMs = max(0, min(600000, $dwellMs));
        $row = DB::table('post_views')->where('post_id', $postId)->where('viewer_id', $uid)->first();
        $prevDwell = $row ? (int) $row->dwell_ms : 0;
        $wasCompleted = $row ? (bool) $row->completed : false;

        if (!$row) {
            DB::table('post_views')->insertOrIgnore([
                'post_id' => $postId, 'viewer_id' => $uid, 'views' => 1, 'dwell_ms' => $dwellMs,
                'completed' => $completed ? 1 : 0, 'first_viewed_at' => now(), 'last_viewed_at' => now(),
            ]);
            DB::table('posts')->where('id', $postId)->increment('views_count');
        } else {
            DB::table('post_views')->where('post_id', $postId)->where('viewer_id', $uid)->update([
                'views'          => DB::raw('views + 1'),
                'dwell_ms'       => min(3600000, $prevDwell + $dwellMs),
                'completed'      => ($wasCompleted || $completed) ? 1 : 0,
                'last_viewed_at' => now(),
            ]);
        }
        if (($prevDwell < 4000 && $prevDwell + $dwellMs >= 4000)) {
            $this->bumpInterests($uid, $postId, 0.4);
        }
        if ($completed && !$wasCompleted) {
            $this->bumpInterests($uid, $postId, 0.8);
        }
    }

    // ---------------------------------------------------------------- hide / mute / report

    public function hide(int $uid, int $postId, ?string $reason): void
    {
        $this->visiblePublished($uid, $postId);
        $reason = in_array($reason, (array) config('posts.hide_reasons'), true) ? $reason : 'not_interested';
        DB::table('post_hides')->insertOrIgnore(['user_id' => $uid, 'post_id' => $postId, 'reason' => $reason, 'created_at' => now()]);
        $this->bumpInterests($uid, $postId, -1.5);
    }

    public function unhide(int $uid, int $postId): void
    {
        DB::table('post_hides')->where('user_id', $uid)->where('post_id', $postId)->delete();
    }

    public function muteAuthor(int $uid, int $authorId, bool $mute): void
    {
        if ($authorId === $uid) {
            throw new \RuntimeException('You cannot mute yourself.');
        }
        if ($mute) {
            if (!DB::table('users')->where('id', $authorId)->whereNull('deleted_at')->exists()) {
                throw new \RuntimeException('User not found.');
            }
            DB::table('post_author_mutes')->insertOrIgnore(['user_id' => $uid, 'muted_user_id' => $authorId, 'created_at' => now()]);
        } else {
            DB::table('post_author_mutes')->where('user_id', $uid)->where('muted_user_id', $authorId)->delete();
        }
    }

    public function report(int $uid, int $postId, string $reason, ?string $details, ?int $commentId): void
    {
        $post = $this->visiblePublished($uid, $postId);
        if ((int) $post->user_id === $uid && !$commentId) {
            throw new \RuntimeException('You cannot report your own post.');
        }
        if (!in_array($reason, (array) config('posts.report_reasons'), true)) {
            throw new \RuntimeException('Invalid report reason.');
        }
        if ($commentId && !DB::table('post_comments')->where('id', $commentId)->where('post_id', $postId)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Comment not found.');
        }
        // الـ unique index مبيمنعش التكرار لما comment_id = NULL (MySQL بيعتبر كل NULL مختلف)، فبنفحص يدويًا.
        $already = DB::table('post_reports')->where('post_id', $postId)->where('reporter_id', $uid)
            ->when($commentId, fn ($q) => $q->where('comment_id', $commentId), fn ($q) => $q->whereNull('comment_id'))
            ->exists();
        if (!$already) {
            DB::table('post_reports')->insertOrIgnore([
                'post_id' => $postId, 'comment_id' => $commentId, 'reporter_id' => $uid, 'reason' => $reason,
                'details' => $details !== null ? mb_substr(trim($details), 0, 1000) : null, 'status' => 'pending', 'created_at' => now(),
            ]);
        }
        // كتير بلاغات مختلفة => المنشور يتعلّق لحد مراجعة الأدمن.
        $threshold = (int) PostSettings::get('auto_remove_reports');
        if ($threshold > 0 && !$commentId) {
            $distinct = DB::table('post_reports')->where('post_id', $postId)->whereNull('comment_id')->where('status', 'pending')->distinct()->count('reporter_id');
            if ($distinct >= $threshold) {
                DB::table('posts')->where('id', $postId)->where('status', 'published')->update(['status' => 'pending']);
            }
        }
    }

    // ---------------------------------------------------------------- comments

    /** تعليقات المستوى الأول (مع أول ردّين لكل واحد) — sort: new | top. */
    public function comments(int $uid, int $postId, string $sort, int $page, int $perPage): array
    {
        $this->visiblePublished($uid, $postId);
        $q = $this->commentQuery($uid)->where('c.post_id', $postId)->whereNull('c.parent_id');
        $sort === 'top' ? $q->orderByDesc('c.likes_count')->orderByDesc('c.id') : $q->orderByDesc('c.id');
        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage + 1)->get(['c.*', 'u.full_name', 'u.avatar_path'])->all();
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $replies = [];
        $parentIds = array_map(fn ($r) => (int) $r->id, $rows);
        if ($parentIds) {
            $rr = $this->commentQuery($uid)->whereIn('c.parent_id', $parentIds)->orderBy('c.id')->limit(300)->get(['c.*', 'u.full_name', 'u.avatar_path']);
            foreach ($rr as $r) {
                if (count($replies[(int) $r->parent_id] ?? []) < 2) {
                    $replies[(int) $r->parent_id][] = $r;
                }
            }
        }
        $all = $rows;
        foreach ($replies as $list) {
            $all = array_merge($all, $list);
        }
        $ctx = $this->commentContext($uid, $postId, $all);
        $items = [];
        foreach ($rows as $r) {
            $shape = $this->shapeComment($r, $uid, $ctx);
            $shape['replies'] = array_map(fn ($x) => $this->shapeComment($x, $uid, $ctx), $replies[(int) $r->id] ?? []);
            $items[] = $shape;
        }
        return ['items' => $items, 'has_more' => $hasMore];
    }

    public function replies(int $uid, int $commentId, int $page, int $perPage): array
    {
        $parent = DB::table('post_comments')->where('id', $commentId)->whereNull('deleted_at')->first();
        if (!$parent) {
            throw new \RuntimeException('Comment not found.');
        }
        $this->visiblePublished($uid, (int) $parent->post_id);
        $rows = $this->commentQuery($uid)->where('c.parent_id', $commentId)->orderBy('c.id')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get(['c.*', 'u.full_name', 'u.avatar_path'])->all();
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $ctx = $this->commentContext($uid, (int) $parent->post_id, $rows);
        return ['items' => array_map(fn ($r) => $this->shapeComment($r, $uid, $ctx), $rows), 'has_more' => $hasMore];
    }

    public function addComment(int $uid, int $postId, string $body, ?int $parentId): array
    {
        $post = $this->visiblePublished($uid, $postId);
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }
        if (mb_strlen($body) > (int) config('posts.max_comment')) {
            throw new \RuntimeException('The comment is too long.');
        }
        $isOwner = (int) $post->user_id === $uid;
        if (!$isOwner) {
            $allowed = match ($post->comments_mode) {
                'everyone'  => true,
                'followers' => DB::table('post_follows')->where('follower_id', $uid)->where('followee_id', $post->user_id)->exists(),
                'contacts'  => in_array((int) $post->user_id, $this->posts->contactSet($uid, [(int) $post->user_id]), true),
                default     => false,
            };
            if (!$allowed) {
                throw new \RuntimeException('Comments are limited on this post.');
            }
        }
        $parent = null;
        if ($parentId) {
            $parent = DB::table('post_comments')->where('id', $parentId)->where('post_id', $postId)->whereNull('deleted_at')->first();
            if (!$parent) {
                throw new \RuntimeException('The comment you are replying to is not available.');
            }
            if ($parent->parent_id) { // مستويين بس: الرد على رد بيتحط تحت نفس الأب
                $parent = DB::table('post_comments')->where('id', $parent->parent_id)->first() ?: $parent;
            }
        }
        $id = DB::transaction(function () use ($uid, $postId, $body, $parent) {
            $cid = (int) DB::table('post_comments')->insertGetId([
                'post_id' => $postId, 'user_id' => $uid, 'parent_id' => $parent ? (int) $parent->id : null, 'body' => $body, 'created_at' => now(),
            ]);
            DB::table('posts')->where('id', $postId)->increment('comments_count');
            if ($parent) {
                DB::table('post_comments')->where('id', $parent->id)->increment('replies_count');
            }
            return $cid;
        });
        $this->bumpInterests($uid, $postId, 1.2);
        $this->notifier->notify((int) $post->user_id, $uid, 'post_comment', 'commented on your post', $postId, $body);
        if ($parent && (int) $parent->user_id !== (int) $post->user_id) {
            $this->notifier->notify((int) $parent->user_id, $uid, 'post_reply', 'replied to your comment', $postId, $body);
        }
        $row = $this->commentQuery($uid)->where('c.id', $id)->first(['c.*', 'u.full_name', 'u.avatar_path']);
        return $this->shapeComment($row, $uid, $this->commentContext($uid, $postId, [$row]));
    }

    public function editComment(int $uid, int $commentId, string $body): array
    {
        $c = DB::table('post_comments')->where('id', $commentId)->whereNull('deleted_at')->first();
        if (!$c || (int) $c->user_id !== $uid) {
            throw new \RuntimeException('Comment not found.');
        }
        $this->visiblePublished($uid, (int) $c->post_id);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > (int) config('posts.max_comment')) {
            throw new \RuntimeException('Comment cannot be empty.');
        }
        DB::table('post_comments')->where('id', $commentId)->update(['body' => $body, 'edited_at' => now()]);
        $row = $this->commentQuery($uid)->where('c.id', $commentId)->first(['c.*', 'u.full_name', 'u.avatar_path']);
        return $this->shapeComment($row, $uid, $this->commentContext($uid, (int) $c->post_id, [$row]));
    }

    /** صاحب التعليق، أو صاحب المنشور، أو مودريتور. */
    public function deleteComment(int $uid, int $commentId): void
    {
        $c = DB::table('post_comments')->where('id', $commentId)->whereNull('deleted_at')->first();
        if (!$c) {
            throw new \RuntimeException('Comment not found.');
        }
        $post = DB::table('posts')->where('id', $c->post_id)->first();
        $mod = $this->vis->viewer($uid)['is_moderator'];
        if ((int) $c->user_id !== $uid && (!$post || (int) $post->user_id !== $uid) && !$mod) {
            throw new \RuntimeException('Comment not found.');
        }
        DB::transaction(function () use ($c) {
            $removed = 1;
            if (!$c->parent_id) {
                $removed += DB::table('post_comments')->where('parent_id', $c->id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            } else {
                DB::table('post_comments')->where('id', $c->parent_id)->where('replies_count', '>', 0)->decrement('replies_count');
            }
            DB::table('post_comments')->where('id', $c->id)->update(['deleted_at' => now()]);
            DB::table('posts')->where('id', $c->post_id)->update(['comments_count' => DB::raw('GREATEST(comments_count - ' . (int) $removed . ', 0)')]);
        });
    }

    /** إعجاب سريع (توافق مع القديم): بيعمل like لو مفيش تفاعل، وبيشيله لو فيه. */
    public function toggleCommentLike(int $uid, int $commentId): array
    {
        $cur = DB::table('post_comment_likes')->where('comment_id', $commentId)->where('user_id', $uid)->exists();
        return $this->reactComment($uid, $commentId, $cur ? null : 'like');
    }

    /**
     * تفاعل على تعليق بنفس أنواع تفاعلات المنشور (like/love/…): تفاعل واحد لكل مستخدم، تبديل النوع، أو إزالته (type = null).
     * @return array{my_reaction:?string,liked:bool,likes_count:int,reactions:array}
     */
    public function reactComment(int $uid, int $commentId, ?string $type): array
    {
        $c = DB::table('post_comments')->where('id', $commentId)->whereNull('deleted_at')->first();
        if (!$c) {
            throw new \RuntimeException('Comment not found.');
        }
        $this->visiblePublished($uid, (int) $c->post_id);
        if ($type !== null && !in_array($type, (array) config('posts.reactions'), true)) {
            throw new \RuntimeException('Invalid reaction.');
        }
        DB::transaction(function () use ($uid, $commentId, $type) {
            $q = DB::table('post_comment_likes')->where('comment_id', $commentId)->where('user_id', $uid);
            if ($type === null) {
                $q->delete();
            } elseif (!(clone $q)->exists()) {
                DB::table('post_comment_likes')->insertOrIgnore(['comment_id' => $commentId, 'user_id' => $uid, 'type' => $type, 'created_at' => now()]);
            } else {
                $q->update(['type' => $type]);
            }
            // العدّاد بيتحسب من الجدول نفسه (مش increment) عشان ما يتفكش مع الضغط المتزامن.
            DB::table('post_comments')->where('id', $commentId)->update(['likes_count' => DB::table('post_comment_likes')->where('comment_id', $commentId)->count()]);
        });
        return [
            'my_reaction' => $type,
            'liked'       => $type !== null,
            'likes_count' => (int) DB::table('post_comments')->where('id', $commentId)->value('likes_count'),
            'reactions'   => DB::table('post_comment_likes')->where('comment_id', $commentId)->selectRaw('type, COUNT(*) c')->groupBy('type')->pluck('c', 'type')->all(),
        ];
    }

    // ---------------------------------------------------------------- interests (وقود الترتيب)

    /**
     * يزوّد/ينقّص وزن وسوم المنشور في اهتمامات المستخدم. الوزن بيتآكل شوية مع كل تحديث (×0.98) عشان الاهتمامات القديمة تبهت
     * ويتحدّد سقف 50 عشان تفاعل مكثف على وسم واحد مايطغاش على الباقي. بيتبلع أي فشل — الاهتمامات مش حرجة.
     */
    public function bumpInterests(int $uid, int $postId, float $delta): void
    {
        try {
            $tags = DB::table('post_hashtags')->where('post_id', $postId)->limit(8)->pluck('tag')->all();
            foreach ($tags as $tag) {
                $row = DB::table('user_post_interests')->where('user_id', $uid)->where('tag', $tag)->first();
                $w = max(0.0, min(50.0, ($row ? (float) $row->weight * 0.98 : 0.0) + $delta));
                if ($row) {
                    DB::table('user_post_interests')->where('user_id', $uid)->where('tag', $tag)->update(['weight' => $w, 'updated_at' => now()]);
                } elseif ($w > 0) {
                    DB::table('user_post_interests')->insertOrIgnore(['user_id' => $uid, 'tag' => $tag, 'weight' => $w, 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            // تجاهل عمدًا
        }
    }

    // ---------------------------------------------------------------- helpers

    /** المنشور لو منشور (published) ومرئي للمستخدم، وإلا استثناء "Post not found." */
    private function visiblePublished(int $uid, int $postId): object
    {
        $row = $this->vis->find($this->vis->viewer($uid), $postId);
        if (!$row || $row->status !== 'published') {
            throw new \RuntimeException('Post not found.');
        }
        return $row;
    }

    private function commentQuery(int $uid)
    {
        return DB::table('post_comments as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereNull('c.deleted_at')->whereNull('u.deleted_at')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = c.user_id) OR (b.blocker_user_id = c.user_id AND b.blocked_user_id = ?))', [$uid, $uid]);
    }

    /** @return array{liked:array,post_owner:int,mod:bool} */
    private function commentContext(int $uid, int $postId, array $rows): array
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        return [
            'liked'      => $ids ? array_flip(DB::table('post_comment_likes')->where('user_id', $uid)->whereIn('comment_id', $ids)->pluck('comment_id')->all()) : [],
            'mine'       => $ids ? DB::table('post_comment_likes')->where('user_id', $uid)->whereIn('comment_id', $ids)->pluck('type', 'comment_id')->all() : [],
            'breakdown'  => $this->commentBreakdown($ids),
            'post_owner' => (int) DB::table('posts')->where('id', $postId)->value('user_id'),
            'mod'        => $this->vis->viewer($uid)['is_moderator'],
        ];
    }

    /** @return array<int,array<string,int>> comment_id => [type => count] */
    private function commentBreakdown(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (DB::table('post_comment_likes')->whereIn('comment_id', $ids)->selectRaw('comment_id, type, COUNT(*) c')->groupBy('comment_id', 'type')->get() as $r) {
            $out[(int) $r->comment_id][(string) $r->type] = (int) $r->c;
        }
        return $out;
    }

    private function shapeComment(object $r, int $uid, array $ctx): array
    {
        $mine = (int) $r->user_id === $uid;
        return [
            'id'            => (int) $r->id,
            'post_id'       => (int) $r->post_id,
            'parent_id'     => $r->parent_id ? (int) $r->parent_id : null,
            'body'          => $r->body,
            'likes_count'   => (int) $r->likes_count,
            'replies_count' => (int) $r->replies_count,
            'created_at'    => $r->created_at,
            'edited_at'     => $r->edited_at,
            'liked_by_me'   => isset($ctx['liked'][(int) $r->id]),
            'my_reaction'   => isset($ctx['liked'][(int) $r->id]) ? (($ctx['mine'][(int) $r->id] ?? null) ?: 'like') : null,
            'reactions'     => $ctx['breakdown'][(int) $r->id] ?? (object) [],
            'author'        => [
                'id'          => (int) $r->user_id,
                'full_name'   => $r->full_name,
                'avatar_path' => $this->messaging->isAvatarHidden((int) $r->user_id, $uid) ? null : $r->avatar_path,
                'is_author'   => (int) $r->user_id === $ctx['post_owner'],
                'is_me'       => $mine,
            ],
            'can' => [
                'edit'   => $mine,
                'delete' => $mine || $uid === $ctx['post_owner'] || $ctx['mod'],
                'report' => !$mine,
            ],
        ];
    }
}
