<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    use HasFactory;

    protected $table = 'ai_usage_logs';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'conversation_id',
        'portal',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'was_error',
        'error_message',
    ];

    protected $casts = [
        'was_error' => 'boolean',
    ];

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
