<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** يطابق جدول `meeting_files` (Round 7 — migration 2026_08_31_080000). */
class MeetingFile extends Model
{
    use SoftDeletes;

    protected $table = 'meeting_files';

    protected $fillable = [
        'meeting_id', 'uploader_key', 'uploader_display_name',
        'original_name', 'stored_path', 'extension', 'mime_type', 'size_bytes',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}
