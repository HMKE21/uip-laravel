<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_poll_options` (Round 7 — migration 2026_08_31_080000). */
class MeetingPollOption extends Model
{
    protected $table = 'meeting_poll_options';

    protected $fillable = ['poll_id', 'option_text', 'position'];

    public function poll()
    {
        return $this->belongsTo(MeetingPoll::class, 'poll_id');
    }

    public function votes()
    {
        return $this->hasMany(MeetingPollVote::class, 'option_id');
    }
}
