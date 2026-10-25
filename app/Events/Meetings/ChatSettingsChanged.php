<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/** Live chat policy: on/off, history for late joiners, and who is muted in chat. Payload = MeetingChatService::chatSettings(). */
class ChatSettingsChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public array $settings
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'chat.settings.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return $this->settings;
    }
}
