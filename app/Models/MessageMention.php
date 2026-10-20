<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageMention extends Model
{
    use HasFactory;

    protected $table = 'message_mentions';
    const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'mentioned_user_id',
    ];

    // Relationships

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function mentionedUser()
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
