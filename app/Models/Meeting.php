<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** يطابق جدول `meetings` (Round 1 — migration 2026_08_31_000000). */
class Meeting extends Model
{
    use SoftDeletes;

    protected $table = 'meetings';

    protected $fillable = [
        'uuid', 'host_user_id', 'title', 'description', 'type', 'status',
        'scheduled_start_at', 'duration_minutes',
        'meeting_code', 'join_token', 'password_hash',
        'waiting_room_enabled', 'allow_guests', 'max_participants', 'settings',
        'started_at', 'ended_at', 'cancelled_at',
        // Round 3 (Signaling, بند 12 — reminder dedup).
        'starting_soon_reminder_sent_at',
        // Round 5 (Live Collaboration، بند 9 — Screen Sharing host policy).
        'screen_sharing_locked',
        // Round 6 (Host Controls، بند 5 — "Lock meeting").
        'locked',
        // Round 8 (Invitations & Calendar، بند 26 — Project Integration).
        'attachable_type', 'attachable_id',
    ];

    protected $hidden = [
        'password_hash', 'join_token',
    ];

    protected $casts = [
        'scheduled_start_at'   => 'datetime',
        'started_at'           => 'datetime',
        'ended_at'             => 'datetime',
        'cancelled_at'         => 'datetime',
        'waiting_room_enabled'            => 'boolean',
        'allow_guests'                    => 'boolean',
        'settings'                        => 'array',
        'starting_soon_reminder_sent_at'  => 'datetime',
        'screen_sharing_locked'           => 'boolean',
        'locked'                          => 'boolean',
    ];

    public function host()
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function participants()
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function invitations()
    {
        return $this->hasMany(MeetingInvitation::class);
    }

    public function chatMessages()
    {
        return $this->hasMany(MeetingChatMessage::class);
    }

    public function hasPassword(): bool
    {
        return !empty($this->password_hash);
    }
}
