<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `project_discussion_messages` القديم بالظبط (migration 090)
 * — بند 11 مرحلة 4 (Discussion). Thread مسطّح (مش threaded) لكل مشروع؛
 * أي عضو فريق ACCEPTED (مش بس المالك) يقدر يبعت فيه — التعاون هو أصلًا
 * الهدف من التاب ده. مفيش updated_at في الجدول القديم (created_at بس).
 */
class ProjectDiscussionMessage extends Model
{
    const UPDATED_AT = null;

    protected $table = 'project_discussion_messages';

    protected $fillable = [
        'project_id', 'user_id', 'message',
    ];
}
