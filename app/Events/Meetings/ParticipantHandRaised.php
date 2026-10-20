<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 11: "Raise Hand / Lower Hand."
 * حالة مُخزّنة (hand_raised/hand_raised_at، راجع docblock migration
 * 2026_08_31_050000) — بعكس ParticipantReactionSent اللي ephemeral
 * بالكامل. بيتبعت في الحالتين (رفع وخفض) عشان الـ grid يحدّث الأيقونة
 * فورًا عند أي حد تاني بيشوفها.
 */
class ParticipantHandRaised implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName,
        public bool $raised,
        public ?string $handRaisedAt
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'hand.raised';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'display_name'    => $this->displayName,
            'raised'          => $this->raised,
            'hand_raised_at'  => $this->handRaisedAt,
        ];
    }
}
