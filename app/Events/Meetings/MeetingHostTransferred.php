<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/** Round 6 (Host Controls) — بند 5: "Make participant host." meetings.host_user_id بيتغيّر فعليًا (راجع docblock MeetingHostControlService::transferHost()). */
class MeetingHostTransferred implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $previousHostKey,
        public string $newHostKey,
        public string $newHostDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.host_transferred';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'previous_host_key'     => $this->previousHostKey,
            'new_host_key'          => $this->newHostKey,
            'new_host_display_name' => $this->newHostDisplayName,
        ];
    }
}
