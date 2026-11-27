<?php

namespace App\Repositories;

use App\Models\IpGeoCache;
use Illuminate\Support\Facades\Schema;

/**
 * منقولة من app/Repositories/IpGeoCacheRepository.php القديمة بالكامل —
 * بند 25 batch 3 (Country Restrictions). GeoIpService وحده بيستخدمها،
 * هو اللي بيقرر الـ freshness (cache_days) ويرجعلها هنا يقرأ/يكتب صف.
 *
 * Live Threat Monitor: put() بقت بتقبل تفاصيل الموقع (مدينة/إحداثيات/ISP/…). الأعمدة الجديدة
 * بتتكتب بس لو الـ migration اتنفّذت (columns() بتفلتر) — فالكود شغّال قبل وبعد الـ migration.
 */
class IpGeoCacheRepository
{
    public function find(string $ip): ?IpGeoCache
    {
        return IpGeoCache::find($ip);
    }

    /** @param array<string,mixed> $detail مفاتيح اختيارية: region, city, postal, latitude, longitude, accuracy_km, timezone, isp, asn, connection_type, is_proxy, is_hosting, is_mobile, confidence, sources, detail_at */
    public function put(string $ip, ?string $countryCode, ?string $countryName, string $status, array $detail = []): IpGeoCache
    {
        $existing = $this->find($ip);
        $attributes = array_merge([
            'ip_address'    => $ip,
            'country_code'  => $countryCode,
            'country_name'  => $countryName,
            'lookup_status' => $status,
            'resolved_at'   => now(),
        ], $this->onlyExistingColumns($detail));

        // لو الأعمدة الجديدة موجودة وإحنا بنكتب صف من غير تفاصيل، نصفّر القديم عشان مايفضلش موقع قديم لدولة جديدة.
        if (!$detail && $this->hasDetailColumns()) {
            $attributes = array_merge($attributes, [
                'region' => null, 'city' => null, 'postal' => null, 'latitude' => null, 'longitude' => null,
                'accuracy_km' => null, 'timezone' => null, 'isp' => null, 'asn' => null, 'connection_type' => null,
                'is_proxy' => false, 'is_hosting' => false, 'is_mobile' => false, 'confidence' => null,
                'sources' => null, 'detail_at' => null,
            ]);
        }

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();
            return $existing;
        }

        return IpGeoCache::create($attributes);
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private function onlyExistingColumns(array $detail): array
    {
        if (!$detail) {
            return [];
        }
        $cols = array_flip($this->columns());

        return array_intersect_key($detail, $cols);
    }

    private function hasDetailColumns(): bool
    {
        return in_array('detail_at', $this->columns(), true);
    }

    /** @return array<int,string> */
    private function columns(): array
    {
        static $memo = null;
        if ($memo === null) {
            try {
                $memo = Schema::getColumnListing('ip_geo_cache');
            } catch (\Throwable) {
                $memo = [];
            }
        }

        return $memo;
    }
}
