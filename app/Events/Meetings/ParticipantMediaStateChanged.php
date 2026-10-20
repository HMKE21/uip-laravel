<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 3 (Signaling) — بند 6 و33: "Mute/camera state" فوق الـ
 * WebSocket. ShouldBroadcastNow (مش ShouldBroadcast العادي) عمدًا —
 * حالة المايك/الكاميرا لازم توصل فورًا من غير ما تستنى دورتها في
 * القيو (queue.php هنا database driver، ممكن ياخد ثواني لو الـ worker
 * مشغول)؛ ده نفس سبب استخدام ShouldBroadcastNow في كل event تاني في
 * الملف ده.
 */
class ParticipantMediaStateChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName,
        public bool $micEnabled,
        public bool $cameraEnabled,
        public bool $screenSharing
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'media.state.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'display_name'    => $this->displayName,
            'mic_enabled'     => $this->micEnabled,
            'camera_enabled'  => $this->cameraEnabled,
            'screen_sharing'  => $this->screenSharing,
        ];
    }
}
