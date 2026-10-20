<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 6 (Host Controls) — بند 5/10: "Mute participant." بيتبعت
 * **بالإضافة** لـ ParticipantMediaStateChanged العادي (Round 3 — اللي
 * بيحدّث mic_enabled=false في الـ grid زي أي تغيير حالة تاني)، مش
 * بدالها — نفس فلسفة ParticipantScreenShareForceStopped بالظبط: event
 * تكميلي بس عشان الفرونت يفرّق "أنا كتمت نفسي" عن "الهوست كتمني" ويوري
 * toast مناسب للشخص اللي اتكتمله.
 */
class ParticipantMutedByHost implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $mutedByKey,
        public string $mutedByDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'participant.muted_by_host';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key'       => $this->participantKey,
            'muted_by_key'          => $this->mutedByKey,
            'muted_by_display_name' => $this->mutedByDisplayName,
        ];
    }
}
