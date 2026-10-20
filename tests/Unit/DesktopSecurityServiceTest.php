<?php

namespace Tests\Unit;

use App\Services\DesktopSecurityService;
use PHPUnit\Framework\TestCase;

class DesktopSecurityServiceTest extends TestCase
{
    public function test_stricter_picks_the_smaller_positive_limit_and_zero_means_unlimited(): void
    {
        $this->assertSame(0, DesktopSecurityService::stricter(0, 0));
        $this->assertSame(5, DesktopSecurityService::stricter(0, 5));
        $this->assertSame(3, DesktopSecurityService::stricter(3, 0));
        $this->assertSame(3, DesktopSecurityService::stricter(3, 5));
        $this->assertSame(2, DesktopSecurityService::stricter(5, 2));
    }

    public function test_normalize_rejects_values_outside_the_allowed_lists(): void
    {
        $s = (new DesktopSecurityService())->normalize([
            'maxDevices' => 999, 'autoLockMinutes' => 7, 'autoLogoutMinutes' => 61,
            'lockOnHide' => 3, 'deviceOverflow' => 'nope', 'alertNewDevice' => 'false',
        ]);
        $this->assertSame(DesktopSecurityService::MAX_DEVICES_CHOICE, $s['maxDevices']);
        $this->assertSame(0, $s['autoLockMinutes']);
        $this->assertSame(0, $s['autoLogoutMinutes']);
        $this->assertSame(-1, $s['lockOnHide']);
        $this->assertSame('replace_oldest', $s['deviceOverflow']);
        $this->assertFalse($s['alertNewDevice']);
    }

    public function test_sleep_never_comes_before_auto_lock(): void
    {
        $s = (new DesktopSecurityService())->normalize(['autoLockMinutes' => 10, 'sleepMinutes' => 5]);
        $this->assertSame(10, $s['sleepMinutes']);
    }

    public function test_partial_update_keeps_the_other_saved_values(): void
    {
        $svc = new DesktopSecurityService();
        $base = $svc->normalize(['maxDevices' => 2, 'autoLockMinutes' => 5]);
        $next = $svc->normalize(['sleepMinutes' => 10], $base);
        $this->assertSame(2, $next['maxDevices']);
        $this->assertSame(5, $next['autoLockMinutes']);
        $this->assertSame(10, $next['sleepMinutes']);
    }
}
