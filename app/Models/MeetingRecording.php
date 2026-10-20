<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** يطابق جدول `meeting_recordings` (Round 9 — migration 2026_08_31_100000). */
class MeetingRecording extends Model
{
    use SoftDeletes;

    protected $table = 'meeting_recordings';

    protected $fillable = [
        'meeting_id', 'kind', 'started_by_key', 'started_by_display_name',
        'status', 'started_at', 'stopped_at', 'duration_seconds',
        'original_name', 'stored_path', 'mime_type', 'size_bytes',
        'failure_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'recording';
    }
}
