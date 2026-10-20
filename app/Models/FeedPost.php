<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedPost extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'feed_posts';

    protected $fillable = [
        'university_id',
        'author_user_id',
        'title',
        'body',
        'is_event',
        'event_starts_at',
        'event_ends_at',
        'event_location',
        'is_pinned',
        'pinned_at',
        'likes_count',
        'comments_count',
        'shares_count',
        'saves_count',
        'deleted_reason',
        'status',
        'is_edited',
        'edited_at',
        'target_faculty_id',
        'target_department_id',
        'target_academic_year',
    ];

    protected $casts = [
        'is_event' => 'boolean',
        'event_starts_at' => 'datetime',
        'event_ends_at' => 'datetime',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        'status' => 'boolean',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
    ];

    // Relationships

    public function university()
    {
        return $this->belongsTo(University::class, 'university_id');
    }

    public function authorUser()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function targetFaculty()
    {
        return $this->belongsTo(Faculty::class, 'target_faculty_id');
    }

    public function targetDepartment()
    {
        return $this->belongsTo(Department::class, 'target_department_id');
    }

    public function feedPostAttachments()
    {
        return $this->hasMany(FeedPostAttachment::class, 'post_id');
    }

    public function feedPostLikes()
    {
        return $this->hasMany(FeedPostLike::class, 'post_id');
    }

    public function feedPostSaves()
    {
        return $this->hasMany(FeedPostSave::class, 'post_id');
    }

    public function feedPostShares()
    {
        return $this->hasMany(FeedPostShare::class, 'post_id');
    }

    public function feedPostComments()
    {
        return $this->hasMany(FeedPostComment::class, 'post_id');
    }

    public function feedPostReports()
    {
        return $this->hasMany(FeedPostReport::class, 'post_id');
    }
}
