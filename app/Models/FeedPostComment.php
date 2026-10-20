<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedPostComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'feed_post_comments';

    protected $fillable = [
        'post_id',
        'user_id',
        'body',
        'deleted_reason',
    ];

    // Relationships

    public function post()
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
