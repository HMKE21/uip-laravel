<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 6 (Host Controls) — بند 5/10: "Remove participant." مختلف عن
 * ParticipantLeftMeeting (خروج طوعي بزرار "Leave meeting") — ده طرد
 * إجباري من الهوست/co-host. الفرونت بيسمع للحدث ده تحديدًا عشان يقفل
 * اتصال WebRTC بتاع الشخص المطرود فورًا ويوريله شاشة "تم إخراجك من
 * الاجتماع من قِبل المضيف" بدل شاشة "leave" العادية.
 */
class ParticipantRemovedFromMeeting implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName,
        public string $removedByKey,
        public string $removedByDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'participant.removed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key'          => $this->participantKey,
            'display_name'             => $this->displayName,
            'removed_by_key'           => $this->removedByKey,
            'removed_by_display_name'  => $this->removedByDisplayName,
        ];
    }
}
