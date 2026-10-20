<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 9: "Stop another participant's
 * sharing session." بيتبعت **بالإضافة** لـ ParticipantMediaStateChanged
 * العادي (Round 3 — اللي بيحدّث screen_sharing=false في الـ grid زي أي
 * تغيير حالة تاني)، مش بدالها — ده event تكميلي بس عشان الفرونت يقدر
 * يفرّق "أنا وقفت المشاركة بنفسي" عن "الهوست وقفها ليّا" ويوري toast
 * مناسب للشخص اللي اتوقفله (مفيش تغيير حالة تلقائي إضافي هنا، راجع
 * نفس فلسفة ParticipantMediaFailure).
 */
class ParticipantScreenShareForceStopped implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $stoppedByKey,
        public string $stoppedByDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'screen_share.force_stopped';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key'         => $this->participantKey,
            'stopped_by_key'          => $this->stoppedByKey,
            'stopped_by_display_name' => $this->stoppedByDisplayName,
        ];
    }
}
