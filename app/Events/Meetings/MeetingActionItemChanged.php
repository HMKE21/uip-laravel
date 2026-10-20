<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 7 (Collaboration Extras) — بند 20: Decision/Action Item/Task
 * اتضاف/اتعدّل/اتمسح. حدث واحد لكل التلاتة (مش تلات events منفصلة) —
 * $action ('created'|'updated'|'deleted') كافي للفرونت يقرر يضيف/يحدّث/
 * يشيل الصف من القائمة، ونفس الـ payload شكل موحّد للتلاتة.
 */
class MeetingActionItemChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $action,
        public int $itemId,
        public ?array $item = null
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.action_item_changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'action'  => $this->action,
            'item_id' => $this->itemId,
            'item'    => $this->item,
        ];
    }
}
