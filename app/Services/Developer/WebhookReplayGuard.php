<?php

namespace App\Services\Developer;

use Illuminate\Support\Facades\Cache;

/**
 * حماية من إعادة نفس الحدث: بنحفظ (scope + event id) في الكاش لمدة 2 × نافذة التوقيع.
 * ليه الضعف؟ توقيع صالح ممكن يتقبل لحد ±نافذة من t، فالحدث الواحد ممكن يتقبل على مدى ضعف النافذة.
 * firstSeen() ذرّية (Cache::add): بترجّع true لأول مرة بس.
 */
final class WebhookReplayGuard
{
    public static function firstSeen(string $scope, string $eventId, ?int $ttl = null): bool
    {
        $ttl = $ttl && $ttl > 0 ? $ttl : WebhookSignature::tolerance() * 2;
        try {
            return (bool) Cache::add('dev:whreplay:' . hash('sha256', $scope . '|' . $eventId), 1, $ttl);
        } catch (\Throwable $e) {
            return true; // الكاش وقع: مش هنمنع أداة التشخيص
        }
    }
}
