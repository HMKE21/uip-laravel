<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `notifications` القديم بالظبط (الأساسي + priority/category/
 * is_pinned/is_important/is_archived/archived_at/is_deleted/deleted_at
 * + occurrence_count). موديول Notifications الكامل (بند 19) هيوسّع الميثودز
 * فوق ده (markRead/counts/search/mute...) — هنا بس اللي بند 4 محتاجه
 * (forUser/unreadCount) عشان داشبورد الطالب.
 */
class Notification extends Model
{
    protected $table = 'notifications';
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'type', 'priority', 'category', 'occurrence_count',
        'title', 'body', 'link_url', 'portal',
        'is_read', 'read_at', 'is_pinned', 'is_important',
        'is_archived', 'archived_at', 'is_deleted', 'deleted_at',
    ];

    protected $casts = [
        'is_read'     => 'boolean',
        'is_pinned'   => 'boolean',
        'is_important' => 'boolean',
        'is_archived' => 'boolean',
        'is_deleted'  => 'boolean',
        'read_at'     => 'datetime',
        'archived_at' => 'datetime',
        'deleted_at'  => 'datetime',
    ];
}
