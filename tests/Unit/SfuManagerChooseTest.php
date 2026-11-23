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
