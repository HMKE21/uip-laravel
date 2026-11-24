<?php

namespace Tests\Unit;

use App\Services\SfuHealth;
use App\Services\SfuManager;
use PHPUnit\Framework\TestCase;

/** قرار اختيار مزوّد الـ SFU (دالة نقية — مفيش cache ولا إعدادات). */
class SfuManagerChooseTest extends TestCase
{
    public function test_new_room_takes_preferred_when_healthy(): void
    {
        $this->assertSame('selfhosted', SfuManager::choose('selfhosted', null, ['selfhosted' => true, 'cloudflare' => true], true));
    }

    public function test_new_room_falls_over_when_preferred_is_down(): void
    {
        $this->assertSame('cloudflare', SfuManager::choose('selfhosted', null, ['selfhosted' => false, 'cloudflare' => true], true));
    }

    public function test_live_room_is_sticky_while_its_provider_is_healthy(): void
    {
        $this->assertSame('cloudflare', SfuManager::choose('selfhosted', 'cloudflare', ['selfhosted' => true, 'cloudflare' => true], true));
    }

    public function test_live_room_moves_when_its_provider_dies(): void
    {
        $this->assertSame('cloudflare', SfuManager::choose('selfhosted', 'selfhosted', ['selfhosted' => false, 'cloudflare' => true], true));
    }

    public function test_both_down_keeps_current(): void
    {
        $this->assertSame('selfhosted', SfuManager::choose('selfhosted', 'selfhosted', ['selfhosted' => false, 'cloudflare' => false], true));
    }

    public function test_failover_off_never_moves_a_live_room(): void
    {
        $this->assertSame('selfhosted', SfuManager::choose('cloudflare', 'selfhosted', ['selfhosted' => false, 'cloudflare' => true], false));
        $this->assertSame('cloudflare', SfuManager::choose('cloudflare', null, ['selfhosted' => true, 'cloudflare' => true], false));
    }

    public function test_canary_is_off_unless_preferred_is_selfhosted_and_below_100(): void
    {
        $this->assertSame('selfhosted', SfuManager::canaryPreferred('selfhosted', 100, 1));
        $this->assertSame('cloudflare', SfuManager::canaryPreferred('cloudflare', 10, 1));
        $this->assertSame('cloudflare', SfuManager::canaryPreferred('selfhosted', 0, 1));
    }

    public function test_canary_is_sticky_per_meeting_and_monotonic(): void
    {
        for ($id = 1; $id <= 300; $id++) {
            $this->assertSame(SfuManager::canaryPreferred('selfhosted', 20, $id), SfuManager::canaryPreferred('selfhosted', 20, $id));
            // رفع النسبة مايشيلش اجتماع كان على سيرفرنا
            if (SfuManager::canaryPreferred('selfhosted', 20, $id) === 'selfhosted') {
                $this->assertSame('selfhosted', SfuManager::canaryPreferred('selfhosted', 50, $id));
            }
        }
    }

    public function test_canary_split_is_close_to_the_percent(): void
    {
        $own = 0;
        for ($id = 1; $id <= 2000; $id++) {
            $own += SfuManager::canaryPreferred('selfhosted', 25, $id) === 'selfhosted' ? 1 : 0;
        }
        $this->assertGreaterThan(400, $own);   // ~500 متوقع
        $this->assertLessThan(600, $own);
    }

    public function test_canary_target_still_fails_over_when_down(): void
    {
        $pref = SfuManager::canaryPreferred('selfhosted', 100, 7);
        $this->assertSame('cloudflare', SfuManager::choose($pref, null, ['selfhosted' => false, 'cloudflare' => true], true));
    }

    public function test_only_server_side_failures_count(): void
    {
        foreach ([502, 503, 504, 401, 429] as $s) {
            $this->assertTrue(SfuHealth::failureStatus($s), (string) $s);
        }
        foreach ([200, 400, 404, 422, 500] as $s) {
            $this->assertFalse(SfuHealth::failureStatus($s), (string) $s);
        }
    }
}
