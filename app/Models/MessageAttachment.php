<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $table = 'message_attachments';
    const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'uploader_id',
        'kind',
        'stored_path',
        'thumbnail_path',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'width',
        'height',
        'duration_seconds',
        'waveform_json',
        'is_encrypted',
    ];

    protected $casts = [
        'waveform_json' => 'array',
        'is_encrypted' => 'boolean',
    ];

    // Relationships

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
