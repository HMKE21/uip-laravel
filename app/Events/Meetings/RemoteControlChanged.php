<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * حالة التحكم عن بُعد اتغيرت: granted | denied | stopped. بتتبعت بعد قرار صاحب الشاشة أو لما أي طرف يوقف التحكم.
 */
class RemoteControlChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $status,
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
        return 'remote_control.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'status' => $this->status,
            'controller_key' => $this->controllerKey,
            'controller_name' => $this->controllerName,
            'target_key' => $this->targetKey,
        ];
    }
}
