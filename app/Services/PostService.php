<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * المنشورات: إنشاء / تعديل / دورة الحياة (draft → scheduled/pending → published → archived) / حذف + تحويل الصفوف
 * لشكل الواجهة (present). التفاعل (reactions/comments/saves/...) في PostInteractionService، والفيد في PostFeedService.
 */
class PostService
{
    public function __construct(
        private PostVisibility $vis,
        private PostMediaService $media,
        private PostNotifier $notifier,
        private RoleService $roles,
        private MessagingService $messaging
    ) {
    }

    // ---------------------------------------------------------------- config للواجهة

    public function config(int $uid): array
    {
        $viewer = $this->vis->viewer($uid);
        $visibilities = array_values(array_filter((array) config('posts.visibilities'), function ($v) use ($viewer) {
            return match ($v) {
                'public'     => (bool) PostSettings::get('allow_public'),
                'university' => (bool) $viewer['university_id'],
                'faculty'    => (bool) $viewer['faculty_id'],
                default      => true,
            };
        }));
        return [
            'enabled'          => (bool) PostSettings::get('enabled'),
            'visibilities'     => $visibilities,
            'comments_modes'   => config('posts.comments_modes'),
            'reactions'        => config('posts.reactions'),
            'report_reasons'   => config('posts.report_reasons'),
            'hide_reasons'     => config('posts.hide_reasons'),
            'limits'           => [
                'max_body'           => (int) config('posts.max_body'),
                'max_title'          => (int) config('posts.max_title'),
                'max_comment'        => (int) config('posts.max_comment'),
                'max_hashtags'       => (int) config('posts.max_hashtags'),
                'max_media_per_post' => (int) PostSettings::get('max_media_per_post'),
                'max_file_mb'        => (int) PostSettings::get('max_file_mb'),
                'daily_post_limit'   => (int) PostSettings::get('daily_post_limit'),
                'chunked_max_bytes'  => (int) config('messaging.chunked.max_bytes'),
            ],
            'allow_scheduling' => (bool) PostSettings::get('allow_scheduling'),
            'allow_reposts'    => (bool) PostSettings::get('allow_reposts'),
            'code_runners'     => ['sandbox' => trim((string) config('services.sandbox.url')) !== ''],
            'affiliation'      => ['university_id' => $viewer['university_id'], 'faculty_id' => $viewer['faculty_id']],
        ];
    }

    // ---------------------------------------------------------------- إنشاء

    /** @throws \RuntimeException رسالة آمنة للعرض */
    public function create(int $uid, array $in): array
    {
        if (!PostSettings::get('enabled')) {
            throw new \RuntimeException('Posts are turned off by the administrator.');
        }
        $viewer = $this->vis->viewer($uid);
        $f = $this->validateContent($in, true);
        $mediaIds = $this->intList($in['media_ids'] ?? []);
        if ($f['title'] === null && $f['body'] === null && $f['link_url'] === null && !$mediaIds) {
            throw new \RuntimeException('Write something or attach a file first.');
        }
        $this->assertVisibility($viewer, $f['visibility']);
        $audience = $f['visibility'] === 'selected' ? $this->validAudience($uid, $in['audience_ids'] ?? []) : [];
        // منشور "غيّر صورته الشخصية / الغلاف": لازم يكون فيه مرفق (الصورة) وإلا بيتعامل كمنشور عادي.
        $subtype = in_array($in['subtype'] ?? null, ['profile_photo', 'cover_photo'], true) && $mediaIds ? (string) $in['subtype'] : null;

        [$status, $scheduledAt, $publishedAt] = $this->resolveStatus($uid, $in);

        if ($status !== 'draft') {
            $today = DB::table('posts')->where('user_id', $uid)->whereNull('deleted_at')
                ->where('status', '!=', 'draft')->where('created_at', '>=', now()->startOfDay())->count();
            if ($today >= (int) PostSettings::get('daily_post_limit')) {
                throw new \RuntimeException('You reached the daily posting limit. Try again tomorrow.');
            }
        }

        $tags = $this->collectTags($f['title'] . ' ' . $f['body'], $in['tags'] ?? []);
        $mentions = $this->validMentions($uid, $in['mentioned_user_ids'] ?? []);
        $expires = !empty($in['expires_in_hours']) ? now()->addHours(max(1, min(8760, (int) $in['expires_in_hours']))) : null;

        $postId = DB::transaction(function () use ($uid, $viewer, $f, $in, $subtype, $status, $scheduledAt, $publishedAt, $tags, $mentions, $audience, $mediaIds, $expires) {
            $id = (int) DB::table('posts')->insertGetId([
                'user_id'        => $uid,
                'university_id'  => $viewer['university_id'],
                'faculty_id'     => $viewer['faculty_id'],
                'kind'           => 'text',
                'subtype'        => $subtype,
                'title'          => $f['title'],
                'body'           => $f['body'],
                'link_url'       => $f['link_url'],
                'language'       => $this->detectLanguage((string) $f['body']),
                'visibility'     => $f['visibility'],
                'status'         => $status,
                'comments_mode'  => $f['comments_mode'],
                'allow_repost'   => $f['allow_repost'],
                'allow_download' => $f['allow_download'],
                'allow_run'      => $f['allow_run'],
                'hide_counts'    => $f['hide_counts'],
                'is_sensitive'   => $f['is_sensitive'],
                'scheduled_at'   => $scheduledAt,
                'published_at'   => $publishedAt,
                'expires_at'     => $expires,
                'created_at'     => now(),
            ]);
            $this->syncTags($id, $tags);
            $this->syncMentions($id, $mentions);
            $this->syncAudience($id, $audience);
            if ($mediaIds) {
                $this->media->attach($id, $uid, $mediaIds, $this->detailsMap($in['media_details'] ?? []));
            }
            DB::table('posts')->where('id', $id)->update(['kind' => $this->deriveKind($id, $f['link_url'])]);
            return $id;
        });

        if ($status === 'published') {
            $this->afterPublish($postId, $uid);
        }
        return $this->one($uid, $postId) ?? [];
    }

    // ---------------------------------------------------------------- تعديل

    public function update(int $uid, int $postId, array $in): array
    {
        $post = DB::table('posts')->where('id', $postId)->whereNull('deleted_at')->first();
        if (!$post || (int) $post->user_id !== $uid || $post->status === 'removed') {
            throw new \RuntimeException('Post not found.');
        }
        $viewer = $this->vis->viewer($uid);
        $upd = [];
        $contentChanged = false;

        if (array_key_exists('title', $in) || array_key_exists('body', $in) || array_key_exists('link_url', $in)) {
            $f = $this->validateContent(array_merge(['title' => $post->title, 'body' => $post->body, 'link_url' => $post->link_url], $in), false);
            $upd['title'] = $f['title'];
            $upd['body'] = $f['body'];
            $upd['link_url'] = $f['link_url'];
            $upd['language'] = $this->detectLanguage((string) $f['body']);
            $contentChanged = ($f['title'] !== $post->title) || ($f['body'] !== $post->body);
        }
        if (array_key_exists('visibility', $in)) {
            $vis = (string) $in['visibility'];
            if (!in_array($vis, (array) config('posts.visibilities'), true)) {
                throw new \RuntimeException('Invalid audience.');
            }
            $this->assertVisibility($viewer, $vis);
            $upd['visibility'] = $vis;
        }
        if (array_key_exists('comments_mode', $in)) {
            $m = (string) $in['comments_mode'];
            if (!in_array($m, (array) config('posts.comments_modes'), true)) {
                throw new \RuntimeException('Invalid comments setting.');
            }
            $upd['comments_mode'] = $m;
        }
        foreach (['allow_repost', 'allow_download', 'allow_run', 'hide_counts', 'is_sensitive'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $upd[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (array_key_exists('expires_in_hours', $in)) {
            $upd['expires_at'] = !empty($in['expires_in_hours']) ? now()->addHours(max(1, min(8760, (int) $in['expires_in_hours']))) : null;
        }
        if (in_array($post->status, ['draft', 'scheduled'], true) && array_key_exists('scheduled_at', $in)) {
            [$st, $sched] = $this->parseSchedule($in['scheduled_at']);
            $upd['scheduled_at'] = $sched;
            $upd['status'] = $st ?? 'draft';
        }
        if ($contentChanged && $post->status === 'published') {
            $upd['edited_at'] = now();
        }

        DB::transaction(function () use ($uid, $postId, $post, $in, $upd, $contentChanged) {
            if ($upd) {
                DB::table('posts')->where('id', $postId)->update($upd);
            }
            $finalVis = $upd['visibility'] ?? $post->visibility;
            if (array_key_exists('audience_ids', $in) || array_key_exists('visibility', $in)) {
                $this->syncAudience($postId, $finalVis === 'selected' ? $this->validAudience($uid, $in['audience_ids'] ?? $this->currentAudience($postId)) : []);
            }
            if (array_key_exists('media_ids', $in)) {
                $ids = $this->intList($in['media_ids']);
                $this->media->detachMissing($postId, $ids);
                $this->media->attach($postId, $uid, $ids, $this->detailsMap($in['media_details'] ?? []));
            } elseif (array_key_exists('media_details', $in)) {
                $this->media->attach($postId, $uid, DB::table('post_media')->where('post_id', $postId)->orderBy('sort_order')->pluck('id')->all(), $this->detailsMap($in['media_details']));
            }
            if ($contentChanged || array_key_exists('tags', $in)) {
                $fresh = DB::table('posts')->where('id', $postId)->first(['title', 'body']);
                $this->syncTags($postId, $this->collectTags(($fresh->title ?? '') . ' ' . ($fresh->body ?? ''), $in['tags'] ?? []));
            }
            if (array_key_exists('mentioned_user_ids', $in)) {
                $this->syncMentions($postId, $this->validMentions($uid, $in['mentioned_user_ids']));
            }
            $row = DB::table('posts')->where('id', $postId)->first(['title', 'body', 'link_url', 'status']);
            $remaining = DB::table('post_media')->where('post_id', $postId)->count();
            if ($row->title === null && $row->body === null && $row->link_url === null && !$remaining && $row->status !== 'draft') {
                throw new \RuntimeException('A post cannot be empty.');
            }
            DB::table('posts')->where('id', $postId)->update(['kind' => $this->deriveKind($postId, $row->link_url)]);
        });

        return $this->one($uid, $postId) ?? [];
    }

    // ---------------------------------------------------------------- دورة الحياة

    /** draft/scheduled => published (أو pending لو الموافقة المسبقة مفعّلة للطلاب). */
    public function publish(int $uid, int $postId): array
    {
        $post = $this->owned($uid, $postId);
        if (!in_array($post->status, ['draft', 'scheduled'], true)) {
            throw new \RuntimeException('Only drafts can be published.');
        }
        $hasContent = $post->title !== null || $post->body !== null || $post->link_url !== null || DB::table('post_media')->where('post_id', $postId)->exists();
        if (!$hasContent) {
            throw new \RuntimeException('Write something or attach a file first.');
        }
        $needsApproval = PostSettings::get('require_approval') && $this->roles->primaryRoleFor($uid) === 'student';
        DB::table('posts')->where('id', $postId)->update([
            'status'       => $needsApproval ? 'pending' : 'published',
            'published_at' => $needsApproval ? null : now(),
            'scheduled_at' => null,
        ]);
        if (!$needsApproval) {
            $this->afterPublish($postId, $uid);
        }
        return $this->one($uid, $postId) ?? [];
    }

    public function archive(int $uid, int $postId): array
    {
        $post = $this->owned($uid, $postId);
        if ($post->status !== 'published') {
            throw new \RuntimeException('Only published posts can be archived.');
        }
        DB::table('posts')->where('id', $postId)->update(['status' => 'archived', 'pinned_at' => null]);
        return $this->one($uid, $postId) ?? [];
    }

    public function unarchive(int $uid, int $postId): array
    {
        $post = $this->owned($uid, $postId);
        if ($post->status !== 'archived') {
            throw new \RuntimeException('This post is not archived.');
        }
        DB::table('posts')->where('id', $postId)->update(['status' => 'published']);
        return $this->one($uid, $postId) ?? [];
    }

    public function pin(int $uid, int $postId, bool $pin): array
    {
        $post = $this->owned($uid, $postId);
        if ($pin && $post->status !== 'published') {
            throw new \RuntimeException('Only published posts can be pinned.');
        }
        DB::transaction(function () use ($uid, $postId, $pin) {
            if ($pin) {
                DB::table('posts')->where('user_id', $uid)->whereNotNull('pinned_at')->update(['pinned_at' => null]); // تثبيت واحد بس
            }
            DB::table('posts')->where('id', $postId)->update(['pinned_at' => $pin ? now() : null]);
        });
        return $this->one($uid, $postId) ?? [];
    }

    /** حذف ناعم. صاحب المنشور أو مودريتور (بسبب). */
    public function delete(int $uid, int $postId, ?string $moderationReason = null): void
    {
        $post = DB::table('posts')->where('id', $postId)->whereNull('deleted_at')->first();
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        $isOwner = (int) $post->user_id === $uid;
        if (!$isOwner && !$this->vis->viewer($uid)['is_moderator']) {
            throw new \RuntimeException('Post not found.');
        }
        DB::transaction(function () use ($post, $isOwner, $moderationReason) {
            DB::table('posts')->where('id', $post->id)->update([
                'deleted_at'     => now(),
                'status'         => $isOwner ? $post->status : 'removed',
                'removed_reason' => $isOwner ? null : mb_substr((string) $moderationReason, 0, 255),
                'pinned_at'      => null,
            ]);
            if ($post->repost_of_id) {
                DB::table('posts')->where('id', $post->repost_of_id)->where('reposts_count', '>', 0)->decrement('reposts_count');
            }
        });
        if (!$isOwner) {
            $this->notifier->notify((int) $post->user_id, 0, 'post_removed', 'Your post was removed by a moderator.', null, $moderationReason);
        }
    }

    /** المنشورات المجدولة اللي ميعادها جه => published (أو pending لو الموافقة المسبقة مفعّلة لطلاب). بيتنده من posts:publish-due. */
    public function publishDue(int $limit = 200): int
    {
        $rows = DB::table('posts')->where('status', 'scheduled')->whereNull('deleted_at')
            ->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit($limit)->get(['id', 'user_id']);
        $n = 0;
        foreach ($rows as $r) {
            $needsApproval = PostSettings::get('require_approval') && $this->roles->primaryRoleFor((int) $r->user_id) === 'student';
            $updated = DB::table('posts')->where('id', $r->id)->where('status', 'scheduled')->update([
                'status'       => $needsApproval ? 'pending' : 'published',
                'published_at' => $needsApproval ? null : now(),
                'scheduled_at' => null,
            ]);
            if ($updated && !$needsApproval) {
                $this->afterPublish((int) $r->id, (int) $r->user_id);
            }
            $n += $updated ? 1 : 0;
        }
        return $n;
    }

    /** بعد النشر الفعلي (مباشر / جدولة / موافقة): إشعار المذكورين. */
    public function afterPublish(int $postId, int $authorId): void
    {
        $snippet = (string) DB::table('posts')->where('id', $postId)->value('body');
        foreach (DB::table('post_mentions')->where('post_id', $postId)->pluck('user_id') as $mid) {
            if ($this->canSee((int) $mid, $postId)) {
                $this->notifier->notify((int) $mid, $authorId, 'post_mention', 'mentioned you in a post', $postId, $snippet);
            }
        }
    }

    // ---------------------------------------------------------------- قراءة

    public function one(int $uid, int $postId): ?array
    {
        $viewer = $this->vis->viewer($uid);
        $row = $this->vis->find($viewer, $postId);
        if (!$row) {
            return null;
        }
        $items = $this->present([$row], $uid);
        return $items[0] ?? null;
    }

    private function canSee(int $uid, int $postId): bool
    {
        return $this->vis->visibleQuery($this->vis->viewer($uid))->where('p.id', $postId)->exists();
    }

    /**
     * يحوّل صفوف posts لشكل الواجهة (batch: عدد ثابت من الاستعلامات مهما كان عدد المنشورات).
     * @param array<int,object|array> $rows
     * @return array<int,array>
     */
    public function present(array $rows, int $uid, bool $nested = true): array
    {
        $rows = array_map(fn ($r) => (array) $r, $rows);
        if (!$rows) {
            return [];
        }
        $viewer = $this->vis->viewer($uid);
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $authorIds = array_values(array_unique(array_map(fn ($r) => (int) $r['user_id'], $rows)));

        $authors = DB::table('users')->whereIn('id', $authorIds)->get(['id', 'full_name', 'avatar_path'])->keyBy('id');
        $roleBy = [];
        foreach (DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereIn('ur.user_id', $authorIds)->orderBy('ur.assigned_at')->get(['ur.user_id', 'r.slug']) as $r) {
            $roleBy[(int) $r->user_id] ??= $r->slug;
        }
        $media = $this->media->forPosts($ids);
        $tagsBy = [];
        foreach (DB::table('post_hashtags')->whereIn('post_id', $ids)->get() as $t) {
            $tagsBy[(int) $t->post_id][] = $t->tag;
        }
        $myReaction = DB::table('post_reactions')->where('user_id', $uid)->whereIn('post_id', $ids)->pluck('type', 'post_id')->all();
        $savedRows = DB::table('post_saves')->where('user_id', $uid)->whereIn('post_id', $ids)->pluck('collection_id', 'post_id')->all();
        $saved = $savedRows;
        $following = array_flip(DB::table('post_follows')->where('follower_id', $uid)->whereIn('followee_id', $authorIds)->pluck('followee_id')->all());
        $contacts = [];
        if (array_filter($rows, fn ($r) => $r['comments_mode'] === 'contacts')) {
            $contacts = array_flip($this->contactSet($uid, $authorIds));
        }
        $breakdown = [];
        foreach (DB::table('post_reactions')->whereIn('post_id', $ids)->selectRaw('post_id, type, COUNT(*) as c')->groupBy('post_id', 'type')->get() as $b) {
            $breakdown[(int) $b->post_id][$b->type] = (int) $b->c;
        }
        $audienceCount = DB::table('post_audience')->whereIn('post_id', $ids)->selectRaw('post_id, COUNT(*) as c')->groupBy('post_id')->pluck('c', 'post_id')->all();

        $originals = [];
        if ($nested) {
            $origIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['repost_of_id'] ? (int) $r['repost_of_id'] : null, $rows))));
            if ($origIds) {
                $origRows = $this->vis->visibleQuery($viewer)->whereIn('p.id', $origIds)->get(['p.*'])->all();
                foreach ($this->present($origRows, $uid, false) as $o) {
                    $originals[$o['id']] = $o;
                }
            }
        }

        $allowReposts = (bool) PostSettings::get('allow_reposts');
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $authorId = (int) $r['user_id'];
            $isOwner = $authorId === $uid;
            $a = $authors[$authorId] ?? null;
            $showCounts = $isOwner || $viewer['is_moderator'] || !$r['hide_counts'];
            $mode = $r['comments_mode'];
            $canComment = $r['status'] === 'published' && ($isOwner || match ($mode) {
                'everyone'  => true,
                'followers' => isset($following[$authorId]),
                'contacts'  => isset($contacts[$authorId]),
                default     => false,
            });
            $out[] = [
                'id'             => $id,
                'kind'           => $r['kind'],
                'subtype'        => $r['subtype'] ?? null,
                'title'          => $r['title'],
                'body'           => $r['body'],
                'link_url'       => $r['link_url'],
                'language'       => $r['language'],
                'visibility'     => $r['visibility'],
                'status'         => $r['status'],
                'comments_mode'  => $mode,
                'allow_repost'   => (bool) $r['allow_repost'],
                'allow_download' => (bool) $r['allow_download'],
                'allow_run'      => (bool) $r['allow_run'],
                'hide_counts'    => (bool) $r['hide_counts'],
                'is_sensitive'   => (bool) $r['is_sensitive'],
                'is_pinned'      => !empty($r['pinned_at']),
                'scheduled_at'   => $r['scheduled_at'],
                'published_at'   => $r['published_at'],
                'created_at'     => $r['created_at'],
                'edited_at'      => $r['edited_at'],
                'expires_at'     => $r['expires_at'],
                'counts'         => $showCounts ? [
                    'reactions' => (int) $r['reactions_count'], 'comments' => (int) $r['comments_count'], 'reposts' => (int) $r['reposts_count'],
                    'saves' => (int) $r['saves_count'], 'shares' => (int) $r['shares_count'], 'views' => (int) $r['views_count'],
                ] : null,
                'reaction_breakdown' => $showCounts ? ($breakdown[$id] ?? (object) []) : null,
                'author'         => [
                    'id'           => $authorId,
                    'full_name'    => $a->full_name ?? 'Deleted user',
                    'avatar_path'  => $a && !$this->messaging->isAvatarHidden($authorId, $uid) ? $a->avatar_path : null,
                    'role'         => $roleBy[$authorId] ?? null,
                    'is_me'        => $isOwner,
                    'is_following' => isset($following[$authorId]),
                ],
                'media'          => $media[$id] ?? [],
                'hashtags'       => $tagsBy[$id] ?? [],
                'my_reaction'    => $myReaction[$id] ?? null,
                'saved'          => array_key_exists($id, $saved),
                'saved_collection_id' => isset($saved[$id]) ? (int) $saved[$id] : null,
                'audience_count' => $isOwner && $r['visibility'] === 'selected' ? (int) ($audienceCount[$id] ?? 0) : null,
                'repost_of'      => $r['repost_of_id'] ? ($originals[(int) $r['repost_of_id']] ?? ['id' => (int) $r['repost_of_id'], 'unavailable' => true]) : null,
                'can'            => [
                    'edit'     => $isOwner && $r['status'] !== 'removed',
                    'delete'   => $isOwner || $viewer['is_moderator'],
                    'comment'  => $canComment,
                    'repost'   => $allowReposts && $r['status'] === 'published' && $r['visibility'] === 'public' && (bool) $r['allow_repost'],
                    'download' => $isOwner || (bool) $r['allow_download'],
                    'run'      => $isOwner || (bool) $r['allow_run'],
                    'report'   => !$isOwner,
                    'pin'      => $isOwner && $r['status'] === 'published',
                ],
            ];
        }
        return $out;
    }

    /** مين من $authorIds بينه وبين $uid محادثة مباشرة. */
    public function contactSet(int $uid, array $authorIds): array
    {
        if (!$authorIds) {
            return [];
        }
        return DB::table('conversation_participants as pa')
            ->join('conversations as c', 'c.id', '=', 'pa.conversation_id')
            ->join('conversation_participants as pb', 'pb.conversation_id', '=', 'c.id')
            ->where('c.is_group', 0)
            ->where('pa.user_id', $uid)
            ->whereIn('pb.user_id', $authorIds)
            ->pluck('pb.user_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    // ---------------------------------------------------------------- helpers

    private function owned(int $uid, int $postId): object
    {
        $post = DB::table('posts')->where('id', $postId)->where('user_id', $uid)->whereNull('deleted_at')->first();
        if (!$post || $post->status === 'removed') {
            throw new \RuntimeException('Post not found.');
        }
        return $post;
    }

    /** @return array{title:?string,body:?string,link_url:?string,visibility:string,comments_mode:string,allow_repost:int,allow_download:int,hide_counts:int,is_sensitive:int} */
    private function validateContent(array $in, bool $withSettings): array
    {
        $title = isset($in['title']) ? trim((string) $in['title']) : '';
        $body = isset($in['body']) ? trim((string) $in['body']) : '';
        $link = isset($in['link_url']) ? trim((string) $in['link_url']) : '';
        if (mb_strlen($title) > (int) config('posts.max_title')) {
            throw new \RuntimeException('The title is too long.');
        }
        if (mb_strlen($body) > (int) config('posts.max_body')) {
            throw new \RuntimeException('The post text is too long.');
        }
        if ($link !== '') {
            $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
            if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || mb_strlen($link) > 500) {
                throw new \RuntimeException('The link is not valid.');
            }
        }
        $visibility = (string) ($in['visibility'] ?? 'public');
        if (!in_array($visibility, (array) config('posts.visibilities'), true)) {
            throw new \RuntimeException('Invalid audience.');
        }
        $mode = (string) ($in['comments_mode'] ?? 'everyone');
        if (!in_array($mode, (array) config('posts.comments_modes'), true)) {
            throw new \RuntimeException('Invalid comments setting.');
        }
        $flag = fn ($k, $d) => array_key_exists($k, $in) ? (filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : $d;
        return [
            'title'          => $title !== '' ? $title : null,
            'body'           => $body !== '' ? $body : null,
            'link_url'       => $link !== '' ? $link : null,
            'visibility'     => $visibility,
            'comments_mode'  => $mode,
            'allow_repost'   => $flag('allow_repost', 1),
            'allow_download' => $flag('allow_download', 1),
            'allow_run'      => $flag('allow_run', 0),
            'hide_counts'    => $flag('hide_counts', 0),
            'is_sensitive'   => $flag('is_sensitive', 0),
        ];
    }

    private function assertVisibility(array $viewer, string $visibility): void
    {
        if ($visibility === 'public' && !PostSettings::get('allow_public')) {
            throw new \RuntimeException('Public posts are turned off by the administrator.');
        }
        if ($visibility === 'university' && !$viewer['university_id']) {
            throw new \RuntimeException('Your account is not linked to a university.');
        }
        if ($visibility === 'faculty' && !$viewer['faculty_id']) {
            throw new \RuntimeException('Your account is not linked to a faculty.');
        }
    }

    /** @return array{0:string,1:?\DateTimeInterface,2:?\DateTimeInterface} [status, scheduled_at, published_at] */
    private function resolveStatus(int $uid, array $in): array
    {
        if (filter_var($in['draft'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($in['status'] ?? '') === 'draft') {
            return ['draft', null, null];
        }
        if (!empty($in['scheduled_at'])) {
            [$st, $sched] = $this->parseSchedule($in['scheduled_at']);
            if ($st === 'scheduled') {
                return ['scheduled', $sched, null];
            }
        }
        if (PostSettings::get('require_approval') && $this->roles->primaryRoleFor($uid) === 'student') {
            return ['pending', null, null];
        }
        return ['published', null, now()];
    }

    /** @return array{0:?string,1:?\DateTimeInterface} */
    private function parseSchedule($value): array
    {
        if (empty($value)) {
            return [null, null];
        }
        if (!PostSettings::get('allow_scheduling')) {
            throw new \RuntimeException('Scheduling is turned off by the administrator.');
        }
        try {
            $when = \Illuminate\Support\Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The scheduled time is not valid.');
        }
        if ($when->lte(now()->addMinute())) {
            throw new \RuntimeException('Choose a time in the future.');
        }
        if ($when->gt(now()->addDays(180))) {
            throw new \RuntimeException('You can schedule up to 180 days ahead.');
        }
        return ['scheduled', $when];
    }

    private function deriveKind(int $postId, ?string $link): string
    {
        $kinds = DB::table('post_media')->where('post_id', $postId)->pluck('kind')->unique()->values()->all();
        if (!$kinds) {
            return $link ? 'link' : 'text';
        }
        if (count($kinds) === 1) {
            return match ($kinds[0]) {
                'image', 'video' => 'media',
                'audio'          => 'audio',
                'pdf', 'document' => 'document',
                'code'           => 'code',
                default          => 'mixed',
            };
        }
        return array_diff($kinds, ['image', 'video']) === [] ? 'media' : 'mixed';
    }

    private function detectLanguage(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        $ar = preg_match_all('/\p{Arabic}/u', $text);
        $la = preg_match_all('/[A-Za-z]/', $text);
        if ($ar === 0 && $la === 0) {
            return null;
        }
        return $ar >= $la ? 'ar' : 'en';
    }

    /** @return string[] */
    public static function extractTags(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]{2,40})/u', $text, $m);
        return array_values(array_unique(array_map(fn ($t) => mb_strtolower($t), $m[1] ?? [])));
    }

    private function collectTags(string $text, $explicit): array
    {
        $tags = self::extractTags($text);
        foreach ((array) $explicit as $t) {
            $t = mb_strtolower(ltrim(trim((string) $t), '#'));
            if (preg_match('/^[\p{L}\p{N}_]{2,40}$/u', $t)) {
                $tags[] = $t;
            }
        }
        return array_slice(array_values(array_unique($tags)), 0, (int) config('posts.max_hashtags'));
    }

    private function syncTags(int $postId, array $tags): void
    {
        DB::table('post_hashtags')->where('post_id', $postId)->delete();
        if ($tags) {
            DB::table('post_hashtags')->insert(array_map(fn ($t) => ['post_id' => $postId, 'tag' => $t], $tags));
        }
    }

    private function syncMentions(int $postId, array $ids): void
    {
        DB::table('post_mentions')->where('post_id', $postId)->delete();
        if ($ids) {
            DB::table('post_mentions')->insert(array_map(fn ($u) => ['post_id' => $postId, 'user_id' => $u], $ids));
        }
    }

    private function syncAudience(int $postId, array $ids): void
    {
        DB::table('post_audience')->where('post_id', $postId)->delete();
        if ($ids) {
            DB::table('post_audience')->insert(array_map(fn ($u) => ['post_id' => $postId, 'user_id' => $u], $ids));
        }
    }

    private function currentAudience(int $postId): array
    {
        return DB::table('post_audience')->where('post_id', $postId)->pluck('user_id')->all();
    }

    /** @return int[] */
    private function validAudience(int $uid, $ids): array
    {
        $ids = array_values(array_diff($this->intList($ids), [$uid]));
        if (!$ids) {
            throw new \RuntimeException('Choose at least one person for this audience.');
        }
        if (count($ids) > (int) config('posts.max_audience')) {
            throw new \RuntimeException('Too many people selected.');
        }
        $valid = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->where('status', 'active')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (!$valid) {
            throw new \RuntimeException('Choose at least one person for this audience.');
        }
        return $valid;
    }

    /** @return int[] */
    private function validMentions(int $uid, $ids): array
    {
        $ids = array_slice(array_values(array_diff($this->intList($ids), [$uid])), 0, (int) config('posts.max_mentions'));
        if (!$ids) {
            return [];
        }
        return DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->where('status', 'active')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int[] */
    private function intList($v): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $v), fn ($n) => $n > 0)));
    }

    /** @return array<int,array{alt_text?:string,caption?:string}> */
    private function detailsMap($raw): array
    {
        $out = [];
        foreach ((array) $raw as $k => $d) {
            if (is_array($d) && (int) $k > 0) {
                $out[(int) $k] = ['alt_text' => $d['alt_text'] ?? null, 'caption' => $d['caption'] ?? null] + (array_key_exists('output', $d) ? ['output' => $d['output']] : []);
            }
        }
        return $out;
    }
}
