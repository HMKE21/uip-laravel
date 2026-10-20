<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_invitations` (Round 1 — migration 2026_08_31_000000). */
class MeetingInvitation extends Model
{
    protected $table = 'meeting_invitations';

    protected $hidden = ['token'];

    protected $fillable = [
        'meeting_id', 'invited_by_user_id', 'invited_user_id',
        'token', 'status', 'message', 'expires_at', 'responded_at',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function invitedUser()
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
