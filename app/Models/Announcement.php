<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `announcements` القديم بالظبط (migration 113 + 118 + 122). */
class Announcement extends Model
{
    protected $table = 'announcements';

    protected $fillable = [
        'university_id', 'author_user_id', 'category',
        'target_faculty_id', 'target_department_id', 'target_academic_year',
        'title', 'body', 'publish_at', 'expires_at', 'published_at', 'notified_at',
        'deleted_at', 'deleted_reason',
    ];
}
