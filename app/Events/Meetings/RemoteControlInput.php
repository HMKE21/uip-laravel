<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * دفعة أحداث ماوس/كيبورد من المتحكم لصاحب الشاشة. السيرفر بس هو اللي بيبثها بعد ما يتأكد إن المرسل هو المتحكم المصرّح له حاليًا، فمفيش مشارك يقدر ينتحل صفة المتحكم.
 */
class RemoteControlInput implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $controllerKey,
        public string $targetKey,
        public array $events
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'remote_control.input';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'controller_key' => $this->controllerKey,
            'target_key' => $this->targetKey,
            'events' => $this->events,
        ];
    }
}
