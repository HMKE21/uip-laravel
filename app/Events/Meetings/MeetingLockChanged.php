<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 6 (Host Controls) — بند 5: "Lock meeting." مختلف عن
 * ScreenSharePolicyChanged (Round 5 — قفل مشاركة الشاشة بس) — ده قفل
 * الاجتماع كله ضد أي انضمام جديد (راجع docblock migration
 * 2026_08_31_070000 وMeetingLobbyService::isJoinable()).
 */
class MeetingLockChanged implements ShouldBroadcastNow
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
        return 'meeting.lock_changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return ['locked' => $this->locked];
    }
}
