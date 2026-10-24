<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalGuideSection extends Model
{
    protected $table = 'portal_guide_sections';

    protected $fillable = [
        'guide_id', 'key', 'kind', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'icon',
        'audience_roles', 'sort_order', 'is_hidden', 'is_custom', 'updated_by',
    ];

    protected $casts = [
        'audience_roles' => 'array',
        'sort_order'     => 'integer',
        'is_hidden'      => 'boolean',
        'is_custom'      => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PortalGuideItem::class, 'section_id')->orderBy('sort_order')->orderBy('id');
    }
}
