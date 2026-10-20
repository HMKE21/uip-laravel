<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPresence extends Model
{
    use HasFactory;

    protected $table = 'user_presence';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'last_seen_at',
        'is_typing_in',
        'typing_started_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'typing_started_at' => 'datetime',
    ];

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isTypingIn()
    {
        return $this->belongsTo(Conversation::class, 'is_typing_in');
    }
}
