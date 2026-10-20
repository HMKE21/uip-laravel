<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageHashtag extends Model
{
    use HasFactory;

    protected $table = 'message_hashtags';
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'tag',
    ];

    // Relationships

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
