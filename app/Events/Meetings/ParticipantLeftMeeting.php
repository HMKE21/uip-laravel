<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 3 (Signaling) — بند 6 و7 (مثال بند 7: "Ahmed joined the
 * meeting"/leave — الرسالة نفسها في الـ chat هي Round 5، هنا بس الحدث
 * الخام اللي شاشة الاجتماع وأي مستمع تاني (زي chat لاحقًا) يقدر يبني
 * فوقه). ده event صريح لخروج متعمّد (endpoint /signaling/leave)، مختلف
 * عن حدث "leaving" التلقائي بتاع presence channel نفسه (اللي بيتفعّل
 * برضو مجرد ما الـ socket يقفل — ممكن يكون قطع اتصال مؤقت مش خروج
 * فعلي). الفرونت بيسمع للاتنين، لكن ده هو اللي بيأكد "خرج فعلًا" (بعد
 * ما status اتسجل left في الداتابيز).
 */
class ParticipantLeftMeeting implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'participant.left';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'display_name'    => $this->displayName,
        ];
    }
}
