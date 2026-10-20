<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** صوت واحد في استفتاء (poll) — أسئلة/خيارات الاستفتاء نفسها في messages.metadata. بند 18. */
class MessagePollVote extends Model
{
    protected $table = 'message_poll_votes';
    public $timestamps = false;

    protected $fillable = ['message_id', 'user_id', 'option_index', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
