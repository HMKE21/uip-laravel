<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** دليل بوابة واحد. `version` بيزيد مع كل تعديل ويتقارن بـ portal_guide_views.seen_version لعلامة "جديد". */
class PortalGuide extends Model
{
    protected $table = 'portal_guides';

    protected $fillable = [
        'portal', 'title_ar', 'title_en', 'subtitle_ar', 'subtitle_en', 'intro_ar', 'intro_en',
        'icon', 'is_published', 'version', 'deleted_keys', 'updated_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'version'      => 'integer',
        'deleted_keys' => 'array',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(PortalGuideSection::class, 'guide_id')->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PortalGuideItem::class, 'guide_id');
    }
}
