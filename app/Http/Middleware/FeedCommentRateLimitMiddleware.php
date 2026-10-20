<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * منقولة من app/Middleware/FeedCommentRateLimitMiddleware.php القديمة —
 * غطا إضافي فوق UipRateLimitMiddleware العام (اللي على مستوى الـ IP/user
 * لكل الـ API)، مقيّد بس على POST /feed/{id}/comments. زي
 * UipRateLimitMiddleware بالظبط: fixed-window counter عبر Cache::add()/
 * increment() بدل الملفات الخام تحت storage/cache/ اللي القديمة كانت
 * بتستخدمها (LimitsActionRate trait). لازم يتحط بعد uip.auth في المجموعة
 * (محتاج uip_user_id اللي uip.auth بيحطها في $request->attributes) —
 * فبيبني الـ bucket لكل طالب لوحده، مش لكل IP.
 */
class FeedCommentRateLimitMiddleware
{
    /** 20 comments per rolling 60-second window per student. */
    private const LIMIT = 20;
    private const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->attributes->get('uip_user_id');
        $bucketKey = $userId !== null ? 'user_' . $userId : 'ip_' . $request->ip();

        $window = (int) floor(time() / self::WINDOW_SECONDS);
        $key = 'ratelimit_feed_comment_' . preg_replace('/[^a-zA-Z0-9]/', '_', (string) $bucketKey) . '_' . $window;

        $count = (int) Cache::get($key, 0);

        if ($count >= self::LIMIT) {
            return response()->json([
                'success' => false,
                'message' => 'Too many comments — please slow down and try again shortly.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 429);
        }

        Cache::add($key, 0, self::WINDOW_SECONDS + 5);
        Cache::increment($key);

        return $next($request);
    }
}
