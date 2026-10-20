<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 7 (Meeting Chat)، بند 8 (Private
 * Chat). ShouldBroadcastNow زي كل event تاني في الموديول ده (بند 7 —
 * "Chat should update in real time without page refresh").
 *
 * القناة اللي بيتبعت عليها بتتحدد وقت الإنشاء (مش ثابتة زي باقي
 * events الموديول) — لو الرسالة عامة بتتبعت على presence-meeting.{uuid}
 * (كل الحاضرين)، لو خاصة بتتبعت على القناة الشخصية بتاعة المستقبِل
 * بس (private-meeting.{uuid}.inbox.{key}، راجع docblock
 * MeetingSignalingService::personalChannelName()) — عشان رسالة خاصة
 * "متبعتش" أصلًا لسوكيت أي حد تاني، مش بس تتفلتر في الفرونت (بند 8:
 * "Private messages must not appear in the public meeting chat").
 * المُرسِل نفسه بياخد نسخته من رد REST المباشر (مش محتاج broadcast ليه).
 */
class ChatMessageSent implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $targetChannelName,
        public array $message
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
        return 'chat.message.sent';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}
