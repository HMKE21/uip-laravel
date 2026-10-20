<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * موديل Message (بند 18). الأعمدة الكاملة بعد 018 + 083 (reply/forward/
 * pin/soft-delete/edit-history/type+metadata). القراءات المعقدة (joins مع
 * senders/attachments/reactions/mentions) بتحصل في MessageRepository عبر
 * DB facade — الموديل ده بيتستخدم للكتابة المباشرة (create/update) بس.
 */
class Message extends Model
{
    protected $table = 'messages';
    public $timestamps = false; // created_at بس، من غير updated_at

    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'attachment_path',
        'parent_message_id', 'forwarded_from_id', 'message_type', 'metadata',
        'is_edited', 'is_pinned', 'edited_at',
        'is_deleted', 'deleted_at', 'deleted_by', 'deleted_reason',
        'created_at',
    ];

    protected $casts = [
        'is_edited'   => 'boolean',
        'is_pinned'   => 'boolean',
        'is_deleted'  => 'boolean',
        'created_at'  => 'datetime',
        'edited_at'   => 'datetime',
        'deleted_at'  => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function parent()
    {
        return $this->belongsTo(Message::class, 'parent_message_id');
    }
}
