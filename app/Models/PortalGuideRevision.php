<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalGuideRevision extends Model
{
    protected $table = 'portal_guide_revisions';
    public $timestamps = false;

    protected $fillable = [
        'guide_id', 'entity', 'entity_id', 'action', 'label', 'before', 'after', 'user_id', 'restored_from',
    ];

    protected $casts = [
        'before' => 'array',
        'after'  => 'array',
    ];
}
