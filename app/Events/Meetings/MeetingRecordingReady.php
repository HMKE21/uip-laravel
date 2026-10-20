<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 9 (Recording) — بند 18 ("Recording processing" + "Use
 * asynchronous processing where appropriate"). بيتبعت من
 * ProcessMeetingRecordingJob لما status تتحول من processing لـ
 * completed (أو failed) — الفرونت بيستخدمه عشان يفعّل زرار "Play/
 * Download" من غير ما يعمل polling.
 */
class MeetingRecordingReady implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public int $recordingId,
        public string $status
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.recording_ready';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'recording_id' => $this->recordingId,
            'status'       => $this->status,
        ];
    }
}
