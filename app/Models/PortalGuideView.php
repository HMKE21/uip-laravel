<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalGuideView extends Model
{
    protected $table = 'portal_guide_views';
    public $timestamps = false;

    protected $fillable = ['user_id', 'guide_id', 'seen_version', 'seen_at'];

    protected $casts = ['seen_version' => 'integer'];
}
