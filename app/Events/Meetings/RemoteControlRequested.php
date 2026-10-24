<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * التحكم عن بُعد (زي Zoom): حد طلب يتحكم في شاشة مشارك بيشاركها. الحدث ده بيوصل للكل بس الفرونت بيعرضه للـ target_key بس.
 */
class RemoteControlRequested implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $controllerKey,
        public string $controllerName,
        public string $targetKey
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'remote_control.requested';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'controller_key' => $this->controllerKey,
            'controller_name' => $this->controllerName,
            'target_key' => $this->targetKey,
        ];
    }
}
