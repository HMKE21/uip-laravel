<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ريأكشن (إيموجي) على رسالة شات اجتماع — صف واحد لكل (message, actor, emoji). Round 5، بند 7. */
class MeetingChatMessageReaction extends Model
{
    protected $table = 'meeting_chat_message_reactions';
    public $timestamps = false;

    protected $fillable = ['message_id', 'actor_key', 'actor_display_name', 'emoji', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function message()
    {
        return $this->belongsTo(MeetingChatMessage::class, 'message_id');
    }
}
