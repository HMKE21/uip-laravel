<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * الاستوري (Status) — نص/صورة/فيديو بتختفي بعد مدة.
 *
 * الرؤية (visibleScope): صاحبها دايمًا بيشوفها. غيره بيشوفها لو مفيش حظر بينهم (أي اتجاه) والجمهور يسمح:
 *   contacts  = ناس بينك وبينهم محادثة مباشرة
 *   everyone  = أي مستخدم نشط على المنصة
 *   selected  = المختارين بس (story_audience)
 *   except    = جهات الاتصال ماعدا المختارين
 * الرد بيوصل كرسالة عادية في المحادثة المباشرة (MessagingService::sendMessage) فكل قواعد الشات (الحظر/الصلاحيات/الإشعارات) بتتطبق.
 */
class StoryService
{
    public const BACKGROUNDS = ['violet', 'ocean', 'sunset', 'forest', 'night', 'rose', 'gold', 'slate'];
    public const FONTS = ['sans', 'bold', 'serif', 'mono'];
    public const AUDIENCES = ['contacts', 'everyone', 'selected', 'except'];
    public const EXPIRY_HOURS = [6, 12, 24, 48];
    public const REACTIONS = ['❤', '😂', '😮', '😢', '👏', '🔥', '👍', '🙏'];

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const VIDEO_EXT = ['mp4', 'webm', 'mov'];

    public function __construct(
        private FileUploadService $uploads,
        private MessagingService $messaging,
        private ChatSafetyService $safety,
        private MediaProbeService $probe
    ) {
    }

    /** حدود بتروح للفرونت عشان الواجهة تلتزم بنفس الأرقام. */
    public function limits(): array
    {
        return [
            'max_text'          => (int) config('messaging.stories.max_text', 700),
            'max_caption'       => (int) config('messaging.stories.max_caption', 300),
            'max_video_seconds' => (int) config('messaging.stories.max_video_seconds', 90),
            'max_active'        => (int) config('messaging.stories.max_active', 30),
            'max_image_kb'      => (int) config('messaging.stories.max_image_kb', 10240),
            'max_video_kb'      => (int) config('messaging.stories.max_video_kb', 65536),
            'expiry_hours'      => self::EXPIRY_HOURS,
            'backgrounds'       => self::BACKGROUNDS,
            'fonts'             => self::FONTS,
            'audiences'         => self::AUDIENCES,
        ];
    }

    // ------------------------------------------------------------------ Visibility

    private function visibleScope(int $uid)
    {
        $contact = 'EXISTS (SELECT 1 FROM conversations c
                    JOIN conversation_participants pa ON pa.conversation_id = c.id AND pa.user_id = ?
                    JOIN conversation_participants pb ON pb.conversation_id = c.id AND pb.user_id = s.user_id
                    WHERE c.is_group = 0)';
        $inAudience = 'EXISTS (SELECT 1 FROM story_audience sa WHERE sa.story_id = s.id AND sa.user_id = ?)';
        $blocked = 'EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = s.user_id)
                    OR (b.blocker_user_id = s.user_id AND b.blocked_user_id = ?))';

        return DB::table('stories as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereNull('s.deleted_at')
            ->where('s.expires_at', '>', now())
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->where(function ($w) use ($uid, $contact, $inAudience, $blocked) {
                $w->where('s.user_id', $uid)->orWhere(function ($x) use ($uid, $contact, $inAudience, $blocked) {
                    $x->whereRaw("NOT $blocked", [$uid, $uid])->where(function ($a) use ($uid, $contact, $inAudience) {
                        $a->where('s.audience', 'everyone')
                            ->orWhere(fn ($c) => $c->where('s.audience', 'contacts')->whereRaw($contact, [$uid]))
                            ->orWhere(fn ($c) => $c->where('s.audience', 'selected')->whereRaw($inAudience, [$uid]))
                            ->orWhere(fn ($c) => $c->where('s.audience', 'except')->whereRaw($contact, [$uid])->whereRaw("NOT $inAudience", [$uid]));
                    });
                });
            });
    }

    /** استوري واحدة لو المستخدم يقدر يشوفها (وإلا null). */
    private function visibleStory(int $uid, int $storyId): ?object
    {
        return $this->visibleScope($uid)->where('s.id', $storyId)
            ->select('s.*', 'u.full_name', 'u.avatar_path', 'u.email')
            ->first();
    }

    // ------------------------------------------------------------------ Feed

    /** @return array{me:?array,groups:array,reactions:array,limits:array} */
    public function feed(int $uid): array
    {
        $rows = $this->visibleScope($uid)
            ->leftJoin('story_views as v', function ($j) use ($uid) {
                $j->on('v.story_id', '=', 's.id')->where('v.viewer_id', '=', $uid);
            })
            ->select('s.*', 'u.full_name', 'u.avatar_path', 'v.viewed_at as my_viewed_at', 'v.reaction as my_reaction')
            ->orderBy('s.created_at')
            ->get();

        $muted = DB::table('story_mutes')->where('user_id', $uid)->pluck('muted_user_id')->map(fn ($v) => (int) $v)->all();

        $ownIds = $rows->where('user_id', $uid)->pluck('id')->all();
        $counts = [];
        if ($ownIds) {
            foreach (DB::table('story_views')->whereIn('story_id', $ownIds)->selectRaw('story_id, COUNT(*) AS views, SUM(reaction IS NOT NULL) AS reacts')->groupBy('story_id')->get() as $c) {
                $counts[(int) $c->story_id] = ['views' => (int) $c->views, 'reactions' => (int) $c->reacts];
            }
        }

        $groups = [];
        foreach ($rows as $r) {
            $owner = (int) $r->user_id;
            $isMine = $owner === $uid;
            if (!isset($groups[$owner])) {
                $groups[$owner] = [
                    'user_id'     => $owner,
                    'full_name'   => $r->full_name,
                    'avatar_path' => ($isMine || !$this->messaging->isAvatarHidden($owner, $uid)) ? $r->avatar_path : null,
                    'is_mine'     => $isMine,
                    'muted'       => in_array($owner, $muted, true),
                    'stories'     => [],
                ];
            }
            $groups[$owner]['stories'][] = $this->shape($r, $isMine, $counts[(int) $r->id] ?? null);
        }

        foreach ($groups as &$g) {
            $g['has_unseen'] = false;
            $g['first_unseen'] = 0;
            foreach ($g['stories'] as $i => $s) {
                if (!$g['is_mine'] && !$s['seen']) {
                    $g['has_unseen'] = true;
                    $g['first_unseen'] = $i; // أول استوري لسه متشافتش (العارض يبدأ منها)
                    break;
                }
            }
            $g['latest_at'] = $g['stories'][count($g['stories']) - 1]['created_at'];
        }
        unset($g);

        // الترتيب: أنا أولًا، بعدين اللي فيها جديد، بعدين المتشافة، وآخرًا المكتومين (الأحدث أولًا جوّه كل مجموعة).
        $list = array_values($groups);
        usort($list, function ($a, $b) {
            $rank = fn ($g) => $g['is_mine'] ? 0 : ($g['muted'] ? 3 : ($g['has_unseen'] ? 1 : 2));
            return $rank($a) <=> $rank($b) ?: strcmp((string) $b['latest_at'], (string) $a['latest_at']);
        });

        $me = DB::table('users')->where('id', $uid)->first(['id', 'full_name', 'avatar_path']);
        return [
            'me'        => $me ? ['id' => (int) $me->id, 'full_name' => $me->full_name, 'avatar_path' => $me->avatar_path] : null,
            'groups'    => $list,
            'reactions' => self::REACTIONS,
            'limits'    => $this->limits(),
        ];
    }

    private function shape(object $r, bool $isMine, ?array $counts = null): array
    {
        $out = [
            'id'               => (int) $r->id,
            'user_id'          => (int) $r->user_id,
            'type'             => $r->type,
            'body'             => $r->body,
            'caption'          => $r->caption,
            'bg'               => $r->bg,
            'font'             => $r->font,
            'media_path'       => $r->media_path,
            'thumbnail_path'   => $r->thumbnail_path,
            'duration_seconds' => $r->duration_seconds !== null ? (int) $r->duration_seconds : null,
            'allow_replies'    => (bool) $r->allow_replies,
            'created_at'       => $r->created_at,
            'expires_at'       => $r->expires_at,
            'seen'             => $isMine ? true : !empty($r->my_viewed_at),
            'my_reaction'      => $r->my_reaction ?? null,
        ];
        if ($isMine) {
            $out['audience'] = $r->audience;
            $out['views_count'] = $counts['views'] ?? 0;
            $out['reactions_count'] = $counts['reactions'] ?? 0;
        }
        return $out;
    }

    // ------------------------------------------------------------------ Create / delete

    /**
     * @param array{type:string,body?:?string,caption?:?string,bg?:?string,font?:?string,audience?:?string,audience_ids?:array,expires_hours?:int,allow_replies?:mixed} $in
     */
    public function create(int $uid, array $in, ?UploadedFile $media): array
    {
        $lim = $this->limits();
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, ['text', 'image', 'video'], true)) {
            throw new \InvalidArgumentException('Invalid story type.');
        }

        $audience = (string) ($in['audience'] ?? 'contacts');
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new \InvalidArgumentException('Invalid audience.');
        }
        $audienceIds = [];
        if (in_array($audience, ['selected', 'except'], true)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['audience_ids'] ?? [])), fn ($i) => $i > 0 && $i !== $uid)));
            $audienceIds = $ids ? DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
            if ($audience === 'selected' && !$audienceIds) {
                throw new \InvalidArgumentException('Pick at least one person to share with.');
            }
        }

        $hours = (int) ($in['expires_hours'] ?? 24);
        if (!in_array($hours, self::EXPIRY_HOURS, true)) {
            $hours = 24;
        }

        $active = DB::table('stories')->where('user_id', $uid)->whereNull('deleted_at')->where('expires_at', '>', now())->count();
        if ($active >= $lim['max_active']) {
            throw new \RuntimeException('You have reached the maximum number of active stories.');
        }

        $row = [
            'user_id'       => $uid,
            'type'          => $type,
            'audience'      => $audience,
            'allow_replies' => filter_var($in['allow_replies'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'expires_at'    => now()->addHours($hours),
            'created_at'    => now(),
        ];

        $stored = null;
        if ($type === 'text') {
            $body = trim((string) ($in['body'] ?? ''));
            if ($body === '') {
                throw new \InvalidArgumentException('Write something for your story.');
            }
            if (mb_strlen($body) > $lim['max_text']) {
                throw new \InvalidArgumentException("Story text is too long (max {$lim['max_text']} characters).");
            }
            $bg = (string) ($in['bg'] ?? 'violet');
            $font = (string) ($in['font'] ?? 'sans');
            $row['body'] = $body;
            $row['bg'] = in_array($bg, self::BACKGROUNDS, true) ? $bg : 'violet';
            $row['font'] = in_array($font, self::FONTS, true) ? $font : 'sans';
        } else {
            if (!$media) {
                throw new \InvalidArgumentException('Choose a photo or a video.');
            }
            $ext = strtolower((string) $media->getClientOriginalExtension());
            $isVideo = in_array($ext, self::VIDEO_EXT, true);
            $isImage = in_array($ext, self::IMAGE_EXT, true);
            if (!$isVideo && !$isImage) {
                throw new \InvalidArgumentException('Unsupported file. Use JPG, PNG, WEBP, GIF, MP4, WEBM or MOV.');
            }
            $row['type'] = $isVideo ? 'video' : 'image';

            $caption = trim((string) ($in['caption'] ?? ''));
            if (mb_strlen($caption) > $lim['max_caption']) {
                throw new \InvalidArgumentException("Caption is too long (max {$lim['max_caption']} characters).");
            }
            $row['caption'] = $caption !== '' ? $caption : null;

            $stored = $this->uploads->store(
                $media, 'messages', 'stories',
                $isVideo ? $lim['max_video_kb'] : $lim['max_image_kb'],
                $isVideo ? self::VIDEO_EXT : self::IMAGE_EXT
            );
            $row['media_path'] = $stored['stored_path'];

            if ($isVideo) {
                $abs = public_path($stored['stored_path']);
                $dur = null;
                try {
                    $dur = $this->probe->duration($abs);
                    $row['thumbnail_path'] = $this->probe->videoThumbnail($abs, dirname($stored['stored_path']));
                } catch (\Throwable $e) {
                    \Log::warning('Story video probe failed', ['error' => $e->getMessage()]); // ffmpeg اختياري (fail-open)
                }
                if ($dur !== null && $dur > $lim['max_video_seconds']) {
                    $this->uploads->delete($stored['stored_path']);
                    if (!empty($row['thumbnail_path'])) {
                        $this->uploads->delete($row['thumbnail_path']);
                    }
                    throw new \InvalidArgumentException("Video is too long (max {$lim['max_video_seconds']} seconds).");
                }
                $row['duration_seconds'] = $dur;
            }
        }

        try {
            $id = DB::transaction(function () use ($row, $audienceIds) {
                $id = (int) DB::table('stories')->insertGetId($row);
                foreach ($audienceIds as $a) {
                    DB::table('story_audience')->insert(['story_id' => $id, 'user_id' => $a]);
                }
                return $id;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                $this->uploads->delete($stored['stored_path']);
                if (!empty($row['thumbnail_path'])) {
                    $this->uploads->delete($row['thumbnail_path']);
                }
            }
            throw $e;
        }

        $created = DB::table('stories')->where('id', $id)->first();
        return $this->shape($created, true, ['views' => 0, 'reactions' => 0]);
    }

    public function delete(int $uid, int $storyId): void
    {
        $n = DB::table('stories')->where('id', $storyId)->where('user_id', $uid)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        if (!$n) {
            throw new \RuntimeException('Story not found.');
        }
    }

    // ------------------------------------------------------------------ Views / reactions / replies

    public function markViewed(int $uid, int $storyId): void
    {
        $s = $this->visibleStory($uid, $storyId);
        if (!$s) {
            throw new \RuntimeException('Story not found.');
        }
        if ((int) $s->user_id === $uid) {
            return;
        }
        DB::table('story_views')->insertOrIgnore([
            'story_id'  => $storyId,
            'viewer_id' => $uid,
            'viewed_at' => now(),
            'hidden'    => $this->messaging->readReceiptsEnabled($uid) ? 0 : 1,
        ]);
    }

    /** @return array{viewers:array,count:int,hidden_count:int,receipts_off:bool} صاحب الاستوري بس. */
    public function viewers(int $uid, int $storyId): array
    {
        $owns = DB::table('stories')->where('id', $storyId)->where('user_id', $uid)->whereNull('deleted_at')->exists();
        if (!$owns) {
            throw new \RuntimeException('Story not found.');
        }
        $all = DB::table('story_views as v')->join('users as u', 'u.id', '=', 'v.viewer_id')
            ->where('v.story_id', $storyId)->orderByDesc('v.viewed_at')
            ->get(['u.id', 'u.full_name', 'u.avatar_path', 'v.viewed_at', 'v.reaction', 'v.hidden']);

        // زي واتساب: لو إيصالات القراءة مقفولة عندك مش هتشوف مين شاف، بس بتشوف العدد.
        if (!$this->messaging->readReceiptsEnabled($uid)) {
            return ['viewers' => [], 'count' => $all->count(), 'hidden_count' => 0, 'receipts_off' => true];
        }
        $visible = $all->filter(fn ($r) => !$r->hidden)->map(fn ($r) => [
            'id'          => (int) $r->id,
            'full_name'   => $r->full_name,
            'avatar_path' => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
            'viewed_at'   => $r->viewed_at,
            'reaction'    => $r->reaction,
        ])->values()->all();

        return ['viewers' => $visible, 'count' => $all->count(), 'hidden_count' => $all->count() - count($visible), 'receipts_off' => false];
    }

    /** emoji فاضي = شيل التفاعل. */
    public function react(int $uid, int $storyId, ?string $emoji): ?string
    {
        $s = $this->visibleStory($uid, $storyId);
        if (!$s) {
            throw new \RuntimeException('Story not found.');
        }
        if ((int) $s->user_id === $uid) {
            throw new \RuntimeException('You cannot react to your own story.');
        }
        $emoji = $emoji !== null ? str_replace("\u{FE0F}", '', trim($emoji)) : '';
        if ($emoji !== '' && !in_array($emoji, self::REACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported reaction.');
        }
        $hidden = $this->messaging->readReceiptsEnabled($uid) ? 0 : 1;
        DB::table('story_views')->insertOrIgnore(['story_id' => $storyId, 'viewer_id' => $uid, 'viewed_at' => now(), 'hidden' => $hidden]);
        DB::table('story_views')->where('story_id', $storyId)->where('viewer_id', $uid)
            ->update(['reaction' => $emoji !== '' ? $emoji : null, 'reacted_at' => $emoji !== '' ? now() : null]);
        return $emoji !== '' ? $emoji : null;
    }

    /** الرد بيتبعت كرسالة في المحادثة المباشرة مع صاحب الاستوري (مع بطاقة الاستوري كـ metadata). */
    public function reply(int $uid, int $storyId, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Write a reply first.');
        }
        $s = $this->visibleStory($uid, $storyId);
        if (!$s) {
            throw new \RuntimeException('Story not found.');
        }
        if ((int) $s->user_id === $uid) {
            throw new \RuntimeException('You cannot reply to your own story.');
        }
        if (!$s->allow_replies) {
            throw new \RuntimeException('Replies are turned off for this story.');
        }
        if ($this->safety->isBlockedEitherWay($uid, (int) $s->user_id)) {
            throw new \RuntimeException('You cannot send messages to this user.');
        }

        $conversationId = $this->messaging->openOrStartDirectByEmail($uid, (string) $s->email);
        $snippet = $s->type === 'text' ? mb_substr((string) $s->body, 0, 80) : mb_substr((string) ($s->caption ?? ''), 0, 80);
        $message = $this->messaging->sendMessage($conversationId, $uid, $body, [
            'metadata' => ['story_reply' => [
                'story_id' => (int) $s->id,
                'owner_id' => (int) $s->user_id,
                'type'     => $s->type,
                'snippet'  => $snippet,
                'bg'       => $s->bg,
                'thumb'    => $s->type === 'image' ? $s->media_path : $s->thumbnail_path,
            ]],
        ]);
        return ['conversation_id' => $conversationId, 'message' => $message];
    }

    // ------------------------------------------------------------------ Mute / contacts

    public function setMuted(int $uid, int $targetId, bool $muted): void
    {
        if ($uid === $targetId) {
            throw new \InvalidArgumentException('You cannot mute yourself.');
        }
        if ($muted) {
            DB::table('story_mutes')->insertOrIgnore(['user_id' => $uid, 'muted_user_id' => $targetId, 'created_at' => now()]);
        } else {
            DB::table('story_mutes')->where('user_id', $uid)->where('muted_user_id', $targetId)->delete();
        }
    }

    /** ناس تختارهم للجمهور: جهات اتصالي (محادثات مباشرة) أو بحث بالاسم/الإيميل. */
    public function pickablePeople(int $uid, string $q = ''): array
    {
        $q = trim($q);
        $query = DB::table('users as u')->whereNull('u.deleted_at')->where('u.status', 'active')->where('u.id', '!=', $uid)
            ->whereRaw('NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = u.id) OR (b.blocker_user_id = u.id AND b.blocked_user_id = ?))', [$uid, $uid]);
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like));
        } else {
            $query->whereRaw('EXISTS (SELECT 1 FROM conversations c
                JOIN conversation_participants pa ON pa.conversation_id = c.id AND pa.user_id = ?
                JOIN conversation_participants pb ON pb.conversation_id = c.id AND pb.user_id = u.id
                WHERE c.is_group = 0)', [$uid]);
        }
        return $query->orderBy('u.full_name')->limit(60)->get(['u.id', 'u.full_name', 'u.avatar_path'])->map(fn ($r) => [
            'id'          => (int) $r->id,
            'full_name'   => $r->full_name,
            'avatar_path' => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
        ])->all();
    }

    // ------------------------------------------------------------------ Cleanup

    /** بيمسح الاستوريهات اللي خلصت من أكتر من يوم (أو اتحذفت) — ملفات + صفوف. @return int عدد المحذوف */
    public function prune(): int
    {
        $cut = now()->subDay();
        $n = 0;
        DB::table('stories')->where(fn ($w) => $w->where('expires_at', '<', $cut)->orWhere('deleted_at', '<', $cut))
            ->orderBy('id')->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $r) {
                    foreach ([$r->media_path, $r->thumbnail_path] as $p) {
                        if ($p) {
                            $this->uploads->delete($p);
                        }
                    }
                    DB::table('stories')->where('id', $r->id)->delete();
                    $n++;
                }
            });
        return $n;
    }
}
