<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationParticipant extends Model
{
    use HasFactory;

    protected $table = 'conversation_participants';
    public $incrementing = false;
    // Composite primary key (conversation_id, user_id) - Eloquent has no native support;
    // query via where() clauses, e.g. static::where('col1', $a)->where('col2', $b).
    public $timestamps = false;

    protected $fillable = [
        'joined_at',
        'last_read_at',
        'role',
        'is_favorite',
        'is_pinned',
        'is_muted',
        'is_archived',
        'category',
        'last_typing_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_read_at' => 'datetime',
        'is_favorite' => 'boolean',
        'is_pinned' => 'boolean',
        'is_muted' => 'boolean',
        'is_archived' => 'boolean',
        'last_typing_at' => 'datetime',
    ];

    // Relationships

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
