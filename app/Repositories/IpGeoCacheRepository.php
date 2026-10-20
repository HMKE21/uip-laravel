<?php

namespace App\Repositories;

use App\Models\IpGeoCache;

/**
 * منقولة من app/Repositories/IpGeoCacheRepository.php القديمة بالكامل —
 * بند 25 batch 3 (Country Restrictions). GeoIpService وحده بيستخدمها،
 * هو اللي بيقرر الـ freshness (cache_days) ويرجعلها هنا يقرأ/يكتب صف.
 */
class IpGeoCacheRepository
{
    public function find(string $ip): ?IpGeoCache
    {
        return IpGeoCache::find($ip);
    }

    public function put(string $ip, ?string $countryCode, ?string $countryName, string $status): IpGeoCache
    {
        $existing = $this->find($ip);
        $attributes = [
            'ip_address'    => $ip,
            'country_code'  => $countryCode,
            'country_name'  => $countryName,
            'lookup_status' => $status,
            'resolved_at'   => now(),
        ];

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();
            return $existing;
        }

        return IpGeoCache::create($attributes);
    }
}
