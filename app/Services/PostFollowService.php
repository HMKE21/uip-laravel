<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** متابعة المستخدمين (post_follows) + بروفايل المنشورات + اقتراحات ناس. المتابعة من طرف واحد (زي تويتر/لينكدإن Follow). */
class PostFollowService
{
    public function __construct(
        private PostVisibility $vis,
        private PostNotifier $notifier,
        private ChatSafetyService $safety,
        private MessagingService $messaging,
        private RoleService $roles
    ) {
    }

    public function follow(int $uid, int $targetId): array
    {
        if ($uid === $targetId) {
            throw new \RuntimeException('You cannot follow yourself.');
        }
        $this->assertReachable($uid, $targetId);
        $new = DB::table('post_follows')->insertOrIgnore(['follower_id' => $uid, 'followee_id' => $targetId, 'created_at' => now()]);
        if ($new) {
            $this->notifier->notify($targetId, $uid, 'post_follow', 'started following you');
        }
        return $this->counts($uid, $targetId);
    }

    public function unfollow(int $uid, int $targetId): array
    {
        DB::table('post_follows')->where('follower_id', $uid)->where('followee_id', $targetId)->delete();
        return $this->counts($uid, $targetId);
    }

    /** @return array{followers:array,has_more:bool} */
    public function followers(int $uid, int $userId, int $page, int $per): array
    {
        return $this->people($uid, DB::table('post_follows as f')->join('users as u', 'u.id', '=', 'f.follower_id')->where('f.followee_id', $userId), $page, $per);
    }

    public function following(int $uid, int $userId, int $page, int $per): array
    {
        return $this->people($uid, DB::table('post_follows as f')->join('users as u', 'u.id', '=', 'f.followee_id')->where('f.follower_id', $userId), $page, $per);
    }

    public function profile(int $uid, int $userId): array
    {
        $this->assertReachable($uid, $userId);
        $u = DB::table('users')->where('id', $userId)->first(['id', 'full_name', 'avatar_path']);
        $aff = $this->safety->affiliationFor($userId);
        $viewer = $this->vis->viewer($uid);
        $visiblePosts = $this->vis->visibleQuery($viewer)->where('p.user_id', $userId)->count();

        return array_merge([
            'id'            => (int) $u->id,
            'full_name'     => $u->full_name,
            'avatar_path'   => $this->messaging->isAvatarHidden($userId, $uid) ? null : $u->avatar_path,
            'role'          => $this->roles->primaryRoleFor($userId),
            'university_id' => $aff['university_id'],
            'faculty_id'    => $aff['faculty_id'],
            'posts_count'   => (int) $visiblePosts,
            'is_me'         => $uid === $userId,
            'follows_me'    => DB::table('post_follows')->where('follower_id', $userId)->where('followee_id', $uid)->exists(),
            'is_muted'      => DB::table('post_author_mutes')->where('user_id', $uid)->where('muted_user_id', $userId)->exists(),
        ], $this->counts($uid, $userId));
    }

    /** ناس ممكن تتابعهم: اللي بينشروا حاجات تقدر تشوفها، ومتابعهمش، مرتّبين بنفس جامعتك أولًا ثم النشاط والتفاعل. */
    public function suggestions(int $uid, int $limit = 10): array
    {
        $viewer = $this->vis->viewer($uid);
        $q = $this->vis->visibleQuery($viewer)
            ->where('p.user_id', '!=', $uid)
            ->where('p.published_at', '>=', now()->subDays(30))
            ->whereRaw('NOT EXISTS (SELECT 1 FROM post_follows f WHERE f.follower_id = ? AND f.followee_id = p.user_id)', [$uid])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM post_author_mutes m WHERE m.user_id = ? AND m.muted_user_id = p.user_id)', [$uid])
            ->groupBy('p.user_id');
        if ($viewer['university_id']) {
            $q->orderByRaw('MAX(p.university_id = ?) DESC', [$viewer['university_id']]);
        }
        $ids = $q->orderByRaw('COUNT(*) DESC')->orderByRaw('SUM(p.reactions_count) DESC')->limit(max(1, min(30, $limit)))->pluck('p.user_id')->map(fn ($v) => (int) $v)->all();
        if (!$ids) {
            return [];
        }
        $users = DB::table('users')->whereIn('id', $ids)->get(['id', 'full_name', 'avatar_path'])->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            if (isset($users[$id])) {
                $out[] = [
                    'id'          => $id,
                    'full_name'   => $users[$id]->full_name,
                    'avatar_path' => $this->messaging->isAvatarHidden($id, $uid) ? null : $users[$id]->avatar_path,
                    'role'        => $this->roles->primaryRoleFor($id),
                ];
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------

    /** @return array{followers_count:int,following_count:int,is_following:bool} */
    public function counts(int $uid, int $userId): array
    {
        return [
            'followers_count' => (int) DB::table('post_follows')->where('followee_id', $userId)->count(),
            'following_count' => (int) DB::table('post_follows')->where('follower_id', $userId)->count(),
            'is_following'    => DB::table('post_follows')->where('follower_id', $uid)->where('followee_id', $userId)->exists(),
        ];
    }

    private function assertReachable(int $uid, int $targetId): void
    {
        $exists = DB::table('users')->where('id', $targetId)->whereNull('deleted_at')->where('status', 'active')->exists();
        if (!$exists || ($uid !== $targetId && $this->notifier->blocked($uid, $targetId))) {
            throw new \RuntimeException('User not found.');
        }
    }

    private function people(int $uid, $query, int $page, int $per): array
    {
        $per = max(1, min(50, $per));
        $rows = $query->whereNull('u.deleted_at')->where('u.status', 'active')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = u.id) OR (b.blocker_user_id = u.id AND b.blocked_user_id = ?))', [$uid, $uid])
            ->orderByDesc('f.created_at')->offset(($page - 1) * $per)->limit($per + 1)->get(['u.id', 'u.full_name', 'u.avatar_path'])->all();
        $hasMore = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $mine = $ids ? array_flip(DB::table('post_follows')->where('follower_id', $uid)->whereIn('followee_id', $ids)->pluck('followee_id')->all()) : [];
        return [
            'items' => array_map(fn ($r) => [
                'id'           => (int) $r->id,
                'full_name'    => $r->full_name,
                'avatar_path'  => $this->messaging->isAvatarHidden((int) $r->id, $uid) ? null : $r->avatar_path,
                'is_following' => isset($mine[(int) $r->id]),
                'is_me'        => (int) $r->id === $uid,
            ], $rows),
            'has_more' => $hasMore,
        ];
    }
}
