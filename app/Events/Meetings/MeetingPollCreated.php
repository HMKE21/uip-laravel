<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/** Round 7 (Collaboration Extras) — بند 21: هوست/co-host فتح استفتاء جديد. */
class MeetingPollCreated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /** @param array<string,mixed> $poll شكل MeetingPollService::present() بالظبط. */
    public function __construct(
        public string $meetingUuid,
        public array $poll
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.poll_created';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return ['poll' => $this->poll];
    }
}
