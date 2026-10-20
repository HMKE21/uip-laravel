<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 9: "The host should be able to
 * Allow screen sharing / Disable screen sharing." تبديل القفل العام
 * بتاع الاجتماع كله (meetings.screen_sharing_locked) — راجع docblock
 * migration 2026_08_31_050000 وMeetingSignalingService::canShareScreen().
 */
class ScreenSharePolicyChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public bool $locked
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'screen_share.policy.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return ['locked' => $this->locked];
    }
}
