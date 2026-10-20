<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedPostSave extends Model
{
    use HasFactory;

    protected $table = 'feed_post_saves';
    const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'user_id',
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
