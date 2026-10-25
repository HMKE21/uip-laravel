<?php

namespace App\Support;

use App\Events\Realtime\RealtimePing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One entry point for "push this to those people right now".
 * Never throws: if Reverb is down or BROADCAST_CONNECTION is log/null, the
 * app keeps working and the browser's fallback polling still catches up.
 */
class Realtime
{
    public static function enabled(): bool
    {
        return in_array((string) config('broadcasting.default'), ['reverb', 'pusher', 'ably'], true);
    }

    /** @param array<int,int|string> $userIds */
    public static function toUsers(array $userIds, string $topic, array $data = []): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids || !self::enabled()) {
            return;
        }
        try {
            // up to 100 channels per publish (Reverb/Pusher limit)
            foreach (array_chunk($ids, 100) as $chunk) {
                event(new RealtimePing($chunk, $topic, $data));
            }
        } catch (\Throwable $e) {
            Log::debug('realtime push failed: ' . $e->getMessage());
        }
    }

    /** @return int[] everybody in the conversation (sender included: their other devices need it too) */
    public static function conversationMembers(int $conversationId): array
    {
        return DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
