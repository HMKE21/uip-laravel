<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * موديل Conversation الكامل (بند 18 — Messaging). بيغطي كل أعمدة
 * `conversations` (018 + 053 group chat + 114 student_group_id)، مع
 * الحفاظ الكامل على التوافق مع كل مستهلك موجود (StudentGroupChatService).
 *
 * conversation_participants ليه مفتاح مركّب (conversation_id, user_id)
 * من غير عمود id — مقروء/مكتوب عبر DB::table() في ConversationRepository
 * بدل ما يبقى موديل Eloquent منفصل (Eloquent محتاج primary key بسيط).
 */
class Conversation extends Model
{
    protected $table = 'conversations';

    protected $fillable = [
        'subject', 'related_project_id',
        'is_group', 'group_name', 'student_group_id',
    ];

    protected $casts = [
        'is_group' => 'boolean',
    ];

    public $timestamps = true;

    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }
}
