<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageHiddenForUser extends Model
{
    use HasFactory;

    protected $table = 'message_hidden_for_user';
    public $incrementing = false;
    // Composite primary key (message_id, user_id) - Eloquent has no native support;
    // query via where() clauses, e.g. static::where('col1', $a)->where('col2', $b).
    public $timestamps = false;

    protected $fillable = [
        'hidden_at',
    ];

    protected $casts = [
        'hidden_at' => 'datetime',
    ];

    // Relationships

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
