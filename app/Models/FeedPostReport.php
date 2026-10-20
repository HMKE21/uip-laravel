<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedPostReport extends Model
{
    use HasFactory;

    protected $table = 'feed_post_reports';
    const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'reporter_user_id',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // Relationships

    public function post()
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }

    public function reporterUser()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
