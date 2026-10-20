<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_participants` (Round 1 — migration 2026_08_31_000000). */
class MeetingParticipant extends Model
{
    protected $table = 'meeting_participants';

    protected $fillable = [
        'meeting_id', 'user_id', 'role', 'status',
        'invited_by_user_id', 'joined_at', 'left_at',
        // Round 3 (Signaling) — راجع docblock migration 2026_08_31_030000.
        'mic_enabled', 'camera_enabled', 'screen_sharing', 'connection_state', 'last_seen_at',
        // Round 4 (WebRTC Core، بند 34) — راجع docblock migration 2026_08_31_040000.
        'connection_quality',
        // Round 5 (Live Collaboration، بند 11/9) — راجع docblock migration 2026_08_31_050000.
        'hand_raised', 'hand_raised_at', 'screen_share_allowed',
        // Round 6 (Host Controls، بند 10) — راجع docblock migration 2026_08_31_070000.
        'removed_by_user_id', 'removed_at',
    ];

    protected $casts = [
        'joined_at'             => 'datetime',
        'left_at'               => 'datetime',
        'mic_enabled'           => 'boolean',
        'camera_enabled'        => 'boolean',
        'screen_sharing'        => 'boolean',
        'last_seen_at'          => 'datetime',
        'hand_raised'           => 'boolean',
        'hand_raised_at'        => 'datetime',
        'screen_share_allowed'  => 'boolean',
        'removed_at'            => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** Round 6 — الهوست/co-host اللي شال العنصر ده (لو status=removed). */
    public function removedBy()
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }
}
