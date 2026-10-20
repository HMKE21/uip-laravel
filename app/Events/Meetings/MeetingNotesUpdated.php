<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 7 (Collaboration Extras) — بند 20: "Collaborative meeting
 * notes." بيتبعت بالـ body الكامل الجديد (مش diff) — المستند مستوى
 * واحد بسيط (مفيش OT/CRDT حقيقي هنا)، آخر save بيكسب (last-write-wins)
 * زي أي حقل عادي، والفرونت بيستبدل النص المحلي بالكامل عند الاستلام.
 */
class MeetingNotesUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $body,
        public string $editedByKey,
        public string $editedByDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.notes_updated';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'body'                   => $this->body,
            'edited_by_key'          => $this->editedByKey,
            'edited_by_display_name' => $this->editedByDisplayName,
        ];
    }
}
