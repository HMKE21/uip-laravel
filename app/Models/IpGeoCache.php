<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `ip_geo_cache` القديم بالظبط (migration 068) — بند 25
 * batch 3 (Country Restrictions). مفتاحه الأساسي `ip_address` نفسه (مش
 * `id` تصاعدي)، وملوش أعمدة created_at/updated_at — بس `resolved_at`
 * بنديره يدويًا في IpGeoCacheRepository::put(). GeoIpService وحده
 * بيستخدم الموديل ده (عبر IpGeoCacheRepository).
 */
class IpGeoCache extends Model
{
    protected $table = 'ip_geo_cache';

    protected $primaryKey = 'ip_address';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'ip_address', 'country_code', 'country_name', 'lookup_status', 'resolved_at',
    ];
}
