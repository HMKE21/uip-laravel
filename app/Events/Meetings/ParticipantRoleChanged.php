<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Round 6 (Host Controls) — بند 5: "Promote moderator" / "Remove
 * moderator" (bند 10: Promote/Demote). $newRole دايمًا 'co_host' أو
 * 'participant' هنا — تحويل الهوست الأساسي نفسه (بند 5 — "Make
 * participant host") ليه event منفصل (MeetingHostTransferred) لأنه
 * بيغيّر meetings.host_user_id نفسه مش بس دور صف واحد.
 */
class ParticipantRoleChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $participantKey,
        public string $displayName,
        public string $newRole,
        public string $changedByKey
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'participant.role_changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'participant_key' => $this->participantKey,
            'display_name'    => $this->displayName,
            'new_role'        => $this->newRole,
            'changed_by_key'  => $this->changedByKey,
        ];
    }
}
