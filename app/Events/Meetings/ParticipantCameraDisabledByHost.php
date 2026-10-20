<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 6 (Host Controls) — بند 5/10: "Disable participant camera."
 * نفس فلسفة ParticipantMutedByHost بالظبط — تكميلي لـ
 * ParticipantMediaStateChanged العادي، مش بدالها.
 */
class ParticipantCameraDisabledByHost implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $disabledByKey,
        public string $disabledByDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'participant.camera_disabled_by_host';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key'          => $this->participantKey,
            'disabled_by_key'          => $this->disabledByKey,
            'disabled_by_display_name' => $this->disabledByDisplayName,
        ];
    }
}
