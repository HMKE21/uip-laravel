<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `meeting_poll_votes` (Round 7 — migration
 * 2026_08_31_080000). voter_display_name بيتخزن دايمًا حتى لو
 * poll.is_anonymous=true — راجع docblock migration الملف — الإخفاء
 * بيحصل في MeetingPollService::results() بس، مش هنا.
 */
class MeetingPollVote extends Model
{
    protected $table = 'meeting_poll_votes';

    protected $fillable = ['poll_id', 'option_id', 'voter_key', 'voter_display_name'];

    public function poll()
    {
        return $this->belongsTo(MeetingPoll::class, 'poll_id');
    }

    public function option()
    {
        return $this->belongsTo(MeetingPollOption::class, 'option_id');
    }
}
