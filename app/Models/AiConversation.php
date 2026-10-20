<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AiConversation.php القديمة — نفس جدول
 * ai_conversations الموجود بالفعل في قاعدة البيانات (uip_full_install.sql).
 */
class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id', 'portal', 'title', 'is_pinned', 'is_archived',
        'share_token', 'message_count', 'last_message_at',
    ];

    protected $casts = [
        'is_pinned'       => 'boolean',
        'is_archived'     => 'boolean',
        'message_count'   => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }
}
