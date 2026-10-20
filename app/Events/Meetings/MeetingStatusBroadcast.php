<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 3 (Signaling) — بند 33 ("Meeting events" فوق الـ WebSocket).
 * بيتبعت من MeetingService::start()/end()/cancel() (Round 1، عدّلناها
 * تنادي broadcastStatus() الجديدة في MeetingSignalingService) عشان أي
 * حد فاتح شاشة الاجتماع فعلًا (مش بس اللي بيعمل الـ REST call نفسه)
 * يعرف على طول من غير ما يحتاج يعمل polling على GET /meetings/{uuid}.
 */
class MeetingStatusBroadcast implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $status
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.status.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return ['status' => $this->status];
    }
}
