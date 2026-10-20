<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AiMessage.php القديمة — جدول ai_messages
 * الموجود بالفعل (أعمدة answer_source/faq_intent_id مضافة بميجريشن
 * 2_faq_smart_answer_layer.sql، إضافية بالكامل ومتوافقة رجعيًا).
 */
class AiMessage extends Model
{
    protected $table = 'ai_messages';

    public $timestamps = false; // created_at بس (لا updated_at) — زي الجدول الأصلي بالظبط

    protected $fillable = [
        'conversation_id', 'role', 'content', 'status', 'model',
        'answer_source', 'faq_intent_id',
        'prompt_tokens', 'completion_tokens', 'is_bookmarked', 'reactions_json',
        'parent_message_id', 'context_snapshot', 'error_message', 'is_deleted', 'edited_at',
    ];

    protected $casts = [
        'is_bookmarked' => 'boolean',
        'is_deleted'    => 'boolean',
        'created_at'    => 'datetime',
        'edited_at'     => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function attachments()
    {
        return $this->hasMany(AiMessageAttachment::class, 'message_id');
    }
}
