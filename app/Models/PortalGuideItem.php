<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalGuideItem extends Model
{
    protected $table = 'portal_guide_items';

    protected $fillable = [
        'guide_id', 'section_id', 'key', 'title_ar', 'title_en', 'summary_ar', 'summary_en',
        'body_ar', 'body_en', 'route', 'permission', 'icon', 'steps', 'tips', 'audience_roles',
        'sort_order', 'is_hidden', 'is_custom', 'updated_by',
    ];

    protected $casts = [
        'steps'          => 'array',
        'tips'           => 'array',
        'audience_roles' => 'array',
        'sort_order'     => 'integer',
        'is_hidden'      => 'boolean',
        'is_custom'      => 'boolean',
    ];
}
