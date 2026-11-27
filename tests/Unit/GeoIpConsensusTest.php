<?php

namespace Tests\Unit;

use App\Services\GeoIpService;
use App\Support\UserAgentInfo;
use PHPUnit\Framework\TestCase;

/** دمج نتائج مزوّدين IP في موقع واحد + قراءة الـ User-Agent. (pure: من غير DB ولا شبكة.) */
class GeoIpConsensusTest extends TestCase
{
    public function test_agreeing_providers_give_city_level_location_with_high_confidence(): void
    {
        $r = GeoIpService::consensus([
            'ip-api' => ['code' => 'EG', 'country' => 'Egypt', 'city' => 'Cairo', 'region' => 'Cairo Governorate', 'lat' => 30.04, 'lng' => 31.24, 'isp' => 'Telecom Egypt', 'asn' => 'AS8452'],
            'ipwho'  => ['code' => 'EG', 'city' => 'Cairo', 'lat' => 30.05, 'lng' => 31.23],
            'ipapi'  => ['code' => 'EG', 'city' => 'Cairo', 'lat' => 30.06, 'lng' => 31.25],
        ]);
        $this->assertSame('ok', $r['status']);
        $this->assertSame('EG', $r['code']);
        $this->assertSame('Cairo', $r['city']);
        $this->assertEqualsWithDelta(30.05, $r['lat'], 0.05);
        $this->assertLessThanOrEqual(50, $r['accuracy_km']);
        $this->assertGreaterThanOrEqual(70, $r['confidence']);
        $this->assertSame('broadband', $r['connection']);
    }

    public function test_outlier_provider_is_ignored(): void
    {
        $r = GeoIpService::consensus([
            'ip-api' => ['code' => 'DE', 'city' => 'Frankfurt', 'lat' => 50.11, 'lng' => 8.68],
            'ipwho'  => ['code' => 'DE', 'city' => 'Frankfurt', 'lat' => 50.12, 'lng' => 8.69],
            'geojs'  => ['code' => 'DE', 'city' => 'Berlin', 'lat' => 52.52, 'lng' => 13.40],
        ]);
        $this->assertSame('Frankfurt', $r['city']);
        $this->assertEqualsWithDelta(50.11, $r['lat'], 0.1);
    }

    public function test_country_only_answer_has_no_coordinates(): void
    {
        $r = GeoIpService::consensus(['country-url' => ['code' => 'FR']]);
        $this->assertSame('ok', $r['status']);
        $this->assertNull($r['lat']);
        $this->assertSame('France', $r['name']);
    }

    public function test_hosting_flag_sets_datacenter_connection(): void
    {
        $r = GeoIpService::consensus([
            'ip-api' => ['code' => 'US', 'city' => 'Ashburn', 'lat' => 39.04, 'lng' => -77.49, 'isp' => 'Amazon.com', 'hosting' => true],
        ]);
        $this->assertSame('datacenter', $r['connection']);
        $this->assertTrue($r['hosting']);
    }

    public function test_user_agent_detection(): void
    {
        $this->assertSame('mobile', UserAgentInfo::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1')['type']);
        $this->assertSame('desktop', UserAgentInfo::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36')['type']);
        $this->assertSame('tool', UserAgentInfo::parse('sqlmap/1.7')['type']);
        $this->assertSame('unknown', UserAgentInfo::parse('')['type']);
    }
}
