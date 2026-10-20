<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 11: "Thumbs Up, Applause, Laugh,
 * Heart, Celebrate — and other appropriate reactions." ephemeral
 * بالكامل ومقصود (بعكس ParticipantHandRaised) — مفيش عمود مخزّن ليها
 * في meeting_participants/meeting_join_requests ولا صف في أي جدول؛
 * دي "طاير إيموجي على الشاشة لثانيتين" بس، مفيش قيمة لتخزينها أو
 * استرجاعها بعد ما تختفي (بعكس رفعة الإيد اللي لازم تفضل معروفة لحد
 * ما تتخفّض، أو رسالة شات لازم تفضل في التاريخ).
 */
class ParticipantReactionSent implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName,
        public string $type,
        public ?string $emoji
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'reaction.sent';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'display_name'    => $this->displayName,
            'type'            => $this->type,
            'emoji'           => $this->emoji,
        ];
    }
}
