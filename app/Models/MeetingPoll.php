<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_polls` (Round 7 — migration 2026_08_31_080000). */
class MeetingPoll extends Model
{
    protected $table = 'meeting_polls';

    protected $fillable = [
        'meeting_id', 'created_by_key', 'created_by_display_name',
        'question', 'poll_type', 'is_anonymous', 'status', 'closed_at',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'closed_at'    => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function options()
    {
        return $this->hasMany(MeetingPollOption::class, 'poll_id')->orderBy('position');
    }

    public function votes()
    {
        return $this->hasMany(MeetingPollVote::class, 'poll_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
