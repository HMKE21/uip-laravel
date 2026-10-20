<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_attendance_sessions` (Round 7 — migration 2026_08_31_080000). */
class MeetingAttendanceSession extends Model
{
    protected $table = 'meeting_attendance_sessions';

    protected $fillable = [
        'meeting_id', 'participant_key', 'display_name', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at'   => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function isOpen(): bool
    {
        return $this->left_at === null;
    }
}
