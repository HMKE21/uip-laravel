<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedPostAttachment extends Model
{
    use HasFactory;

    protected $table = 'feed_post_attachments';
    const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'kind',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'external_url',
        'link_title',
        'sort_order',
    ];

    // Relationships

    public function post()
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }
}
