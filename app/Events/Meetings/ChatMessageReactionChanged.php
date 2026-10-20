<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 7: "Message reactions." بيتبعت
 * دايمًا على presence-meeting.{uuid} العامة (مش على القناة الشخصية)
 * حتى لو الرسالة الأصلية خاصة — لأن المتفاعلين مع رسالة خاصة هما بس
 * طرفيها (المرسِل والمستقبِل)، وهما الاتنين أصلًا أعضاء في الـ presence
 * channel العام (بعكس محتوى الرسالة الخاصة نفسه اللي محتاج يتعزل تمامًا
 * — راجع docblock ChatMessageSent). الفرونت بيفلتر العرض حسب هل هو طرف
 * في الرسالة دي ولا لأ، بنفس المنطق اللي شايف بيه الرسالة الأصلية.
 */
class ChatMessageReactionChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public int $messageId,
        public string $actorKey,
        public string $emoji,
        public bool $added
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.reaction_changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'actor_key'  => $this->actorKey,
            'emoji'      => $this->emoji,
            'added'      => $this->added,
        ];
    }
}
