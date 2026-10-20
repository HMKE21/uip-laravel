<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_action_items` (Round 7 — migration 2026_08_31_080000). */
class MeetingActionItem extends Model
{
    protected $table = 'meeting_action_items';

    protected $fillable = [
        'meeting_id', 'type', 'title', 'assignee_display_name', 'due_at', 'status',
        'created_by_key', 'created_by_display_name',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}
