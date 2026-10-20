<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 4 (WebRTC Core) — بند 34 (Connection Quality: Excellent/Good/
 * Poor/Reconnecting). القيمة محسوبة على الفرونت من WebRTC stats API
 * لكل peer connection (مش لارافيل — راجع docblock migration
 * 2026_08_31_040000)، وبتتباع هنا عشان باقي المشاركين (وشاشة الهوست
 * تحديدًا) يشوفوا مؤشر الجودة جنب كارت المشارك من غير ما يحسبوا هم
 * أنفسهم quality كل peer تاني بتاعهم (كل client بيحسب quality الاتصال
 * بتاعه هو بس، مش كل الشبكة).
 */
class ParticipantConnectionQualityChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $connectionQuality
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'connection.quality.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key'    => $this->participantKey,
            'connection_quality' => $this->connectionQuality,
        ];
    }
}
