<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 5 (Live Collaboration) — بند 9: تفويض/منع شخص بعينه من مشاركة
 * الشاشة (screen_share_allowed tri-state، راجع docblock migration
 * 2026_08_31_050000)، بغض النظر عن قفل الاجتماع العام. مختلف عن
 * ScreenSharePolicyChanged (اللي بيغطي القفل العام بتاع الاجتماع كله).
 */
class ScreenShareParticipantPolicyChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public ?bool $allowed
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'screen_share.participant_policy.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'allowed'         => $this->allowed,
        ];
    }
}
