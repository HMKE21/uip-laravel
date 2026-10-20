<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `meeting_chat_messages` (Round 5 — migration 2026_08_31_060000). */
class MeetingChatMessage extends Model
{
    protected $table = 'meeting_chat_messages';

    protected $fillable = [
        'meeting_id', 'sender_key', 'sender_type', 'sender_display_name',
        'recipient_key', 'type', 'body', 'reply_to_message_id', 'mentioned_keys',
    ];

    protected $casts = [
        'mentioned_keys' => 'array',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function reactions()
    {
        return $this->hasMany(MeetingChatMessageReaction::class, 'message_id');
    }

    public function isPrivate(): bool
    {
        return $this->recipient_key !== null;
    }
}
