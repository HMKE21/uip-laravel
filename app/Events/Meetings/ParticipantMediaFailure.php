<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 4 (WebRTC Core) — بند 34: "Handle network disconnection,
 * reconnection, camera failure, microphone failure, WebRTC failure.
 * Users should receive clear feedback instead of silently failing."
 *
 * ده event **إعلامي بس** — مفيش تغيير حالة تلقائي في الداتابيز مرتبط
 * بيه (مثلًا فشل الكاميرا مايطفيش camera_enabled تلقائيًا، الفرونت هو
 * اللي بيقرر يبعت updateMediaState بعدها لو فعلًا هيقفلها). الهدف هنا
 * إشعار باقي المشاركين (وتحديدًا الشخص نفسه على أي تاب/جهاز تاني مفتوح
 * له) إن حاجة فشلت، بدل ما يفضل صامت من غير أي مؤشر UI — مش تسجيل
 * حالة رسمية.
 */
class ParticipantMediaFailure implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName,
        public string $failureType,
        public ?string $message = null
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'participant.media.failure';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'display_name'    => $this->displayName,
            'failure_type'    => $this->failureType,
            'message'         => $this->message,
        ];
    }
}
