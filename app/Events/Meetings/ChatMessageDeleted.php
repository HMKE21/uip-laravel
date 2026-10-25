<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A chat message was deleted (by its sender, or by a host/co-host moderating the room). Goes out on exactly the channel the
 * message itself travelled on — the presence channel for a public message, the recipient's personal inbox for a private one —
 * so the message disappears from every screen that could have shown it, and from nobody else's socket.
 */
class ChatMessageDeleted implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $targetChannelName,
        public int $messageId,
        public string $deletedByKey,
        public string $deletedByDisplayName
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        if (str_starts_with($this->targetChannelName, 'presence-')) {
            return [new PresenceChannel(substr($this->targetChannelName, strlen('presence-')))];
        }

        return [new PrivateChannel(substr($this->targetChannelName, strlen('private-')))];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'message_id'              => $this->messageId,
            'deleted_by_key'          => $this->deletedByKey,
            'deleted_by_display_name' => $this->deletedByDisplayName,
        ];
    }
}
