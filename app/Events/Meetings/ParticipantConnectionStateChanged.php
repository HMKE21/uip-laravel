<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/** Round 3 (Signaling) — بند 6/33/34: connecting/connected/reconnecting/disconnected. */
class ParticipantConnectionStateChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $connectionState
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'connection.state.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key'  => $this->participantKey,
            'connection_state' => $this->connectionState,
        ];
    }
}
