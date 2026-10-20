<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/** Round 7 (Collaboration Extras) — بند 21: "Participants can vote in real time. Display results live." بيتبعت بعد كل صوت. */
class MeetingPollResultsUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /** @param array<string,mixed> $results شكل MeetingPollService::results() بالظبط. */
    public function __construct(
        public string $meetingUuid,
        public int $pollId,
        public array $results
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.poll_results_updated';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'poll_id' => $this->pollId,
            'results' => $this->results,
        ];
    }
}
