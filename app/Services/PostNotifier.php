<?php

namespace App\Services;

use App\Repositories\NotificationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * إشعارات المنشورات. أنواعها: post_comment | post_reply | post_reaction | post_mention | post_repost | post_follow.
 * (NotificationService بيصنّف النوع حسب الكلمة: comment/reply/mention => messages، والباقي => university.)
 * الرابط /{portal}/posts/{id} — الـ portal بيتحدد من دور المستلم. أي فشل بيتبلع: الإشعار مش لازم يكسر التفاعل نفسه.
 */
class PostNotifier
{
    public function __construct(private NotificationService $notifications, private RoleService $roles)
    {
    }

    public function notify(int $toUserId, int $fromUserId, string $type, string $verb, ?int $postId = null, ?string $snippet = null): void
    {
        if ($toUserId <= 0 || $toUserId === $fromUserId) {
            return;
        }
        try {
            if ($this->blocked($toUserId, $fromUserId)) {
                return;
            }
            $name = (string) DB::table('users')->where('id', $fromUserId)->value('full_name');
            $portal = NotificationRepository::portalsForRole($this->roles->primaryRoleFor($toUserId))[0] ?? 'student';
            $link = $postId ? "/{$portal}/posts/{$postId}" : "/{$portal}/posts";
            $this->notifications->notify(
                $toUserId,
                $type,
                trim($name . ' ' . $verb),
                $snippet !== null ? mb_substr(trim($snippet), 0, 140) : null,
                $link
            );
        } catch (\Throwable $e) {
            Log::debug('post notification failed: ' . $e->getMessage());
        }
    }

    public function blocked(int $a, int $b): bool
    {
        return DB::table('user_blocks')->where(function ($w) use ($a, $b) {
            $w->where(fn ($x) => $x->where('blocker_user_id', $a)->where('blocked_user_id', $b))
                ->orWhere(fn ($x) => $x->where('blocker_user_id', $b)->where('blocked_user_id', $a));
        })->exists();
    }
}
