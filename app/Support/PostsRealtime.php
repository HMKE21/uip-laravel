<?php

namespace App\Support;

use App\Events\Realtime\PostsPing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** بث تغييرات المنشورات لحظيًا (شوف UipRealtimeMiddleware::posts). مبيرميش استثناء أبدًا. */
class PostsRealtime
{
    /** أقصى عدد ناس نبعتلهم مباشرة لمنشور غير عام (الباقي بيلحق بالـ resync/polling). */
    private const MAX_DIRECT = 400;

    /** @param object|null $post صف المنشور (null = اتحذف) */
    public static function publish(int $postId, string $kind, array $extra = [], ?object $post = null): void
    {
        try {
            if (!Realtime::enabled()) {
                return;
            }
            $data = array_filter(['post_id' => $postId, 'kind' => $kind] + $extra, fn ($v) => $v !== null);
            $public = $post && $post->visibility === 'public' && $post->status === 'published';
            // مسودة/مجدول: صاحبه بس. اتحذف: القناة المشتركة (id بس) عشان اللي شايفينه يشيلوه من قدامهم.
            if (!$post || $public) {
                event(new PostsPing($data));
                return;
            }
            $ids = [(int) $post->user_id];
            if ($post->status === 'published') {
                $ids = array_merge(
                    $ids,
                    DB::table('post_comments')->where('post_id', $postId)->whereNull('deleted_at')->limit(self::MAX_DIRECT)->pluck('user_id')->all(),
                    DB::table('post_reactions')->where('post_id', $postId)->limit(self::MAX_DIRECT)->pluck('user_id')->all(),
                    DB::table('post_audience')->where('post_id', $postId)->limit(self::MAX_DIRECT)->pluck('user_id')->all()
                );
            }
            Realtime::toUsers(array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_DIRECT), 'posts', $data);
        } catch (\Throwable $e) {
            Log::debug('posts realtime push failed: ' . $e->getMessage());
        }
    }
}
