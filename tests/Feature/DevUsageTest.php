<?php

namespace Tests\Feature;

use App\Services\Developer\DevUsageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Rate Limit Dashboard — اختبارات بدون DB: منطق الحد الفعلي والـ ranges. */
class DevUsageTest extends TestCase
{
    #[Test]
    public function effective_limit_uses_app_limit_then_default_and_is_capped_by_policy(): void
    {
        $default = (int) config('developer.limits.rate_per_min', 300);
        $this->assertSame($default, DevUsageService::effectiveLimit(null, null));
        $this->assertSame(50, DevUsageService::effectiveLimit(50, null));
        $this->assertSame(100, DevUsageService::effectiveLimit(500, 100));
        $this->assertSame(100, DevUsageService::effectiveLimit(null, 100) <= 100 ? 100 : 0);
        $this->assertSame(1, DevUsageService::effectiveLimit(0, 0) >= 1 ? 1 : 0);
    }

    #[Test]
    public function ranges_are_the_three_supported_windows(): void
    {
        $this->assertSame(['1h', '24h', '7d'], DevUsageService::ranges());
    }
}
