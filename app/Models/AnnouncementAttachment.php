<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnouncementAttachment extends Model
{
    use HasFactory;

    protected $table = 'announcement_attachments';
    const UPDATED_AT = null;

    protected $fillable = [
        'announcement_id',
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

    public function announcement()
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }
}
