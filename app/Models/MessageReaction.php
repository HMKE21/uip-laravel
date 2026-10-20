<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ريأكشن (إيموجي) على رسالة — صف واحد لكل (message, user, emoji). بند 18. */
class MessageReaction extends Model
{
    protected $table = 'message_reactions';
    public $timestamps = false;

    protected $fillable = ['message_id', 'user_id', 'emoji', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
