<?php

namespace Tests\Unit;

use App\Services\MeetingTransportService;
use PHPUnit\Framework\TestCase;

/** قرار mesh/SFU (دالة نقية — مفيش DB ولا cache). */
class MeetingTransportPolicyTest extends TestCase
{
    private function decide(array $over = []): array
    {
        return MeetingTransportService::decide($over + [
            'current' => 'mesh', 'changed_at' => 0, 'now' => 1000, 'count' => 2, 'sfu_available' => true, 'blocked' => false,
            'force' => 'auto', 'min_participants' => 5, 'max_mesh' => 6, 'min_dwell' => 30, 'mesh_poor_ratio' => 0.0, 'sfu_sample_ok' => true,
        ]);
    }

    public function test_small_room_stays_mesh(): void
    {
        $this->assertSame('mesh', $this->decide()['mode']);
    }

    public function test_sfu_disabled_or_blocked_forces_mesh_even_for_big_rooms(): void
    {
        $this->assertSame('mesh', $this->decide(['sfu_available' => false, 'count' => 30])['mode']);
        $this->assertSame('mesh', $this->decide(['blocked' => true, 'count' => 30])['mode']);
    }

    public function test_big_room_or_over_mesh_limit_goes_sfu(): void
    {
        $this->assertSame('sfu', $this->decide(['count' => 5])['mode']);
        $this->assertSame('sfu', $this->decide(['count' => 8, 'min_participants' => 50])['mode']);
    }

    public function test_poor_mesh_quality_moves_a_mid_size_room_to_sfu_only_if_sfu_is_reachable(): void
    {
        $this->assertSame('sfu', $this->decide(['count' => 4, 'mesh_poor_ratio' => 0.5])['mode']);
        $this->assertSame('mesh', $this->decide(['count' => 4, 'mesh_poor_ratio' => 0.5, 'sfu_sample_ok' => false])['mode']);
    }

    public function test_hysteresis_and_dwell(): void
    {
        $this->assertSame('sfu', $this->decide(['current' => 'sfu', 'count' => 4])['mode']);                       // sticky
        $this->assertSame('mesh', $this->decide(['current' => 'sfu', 'count' => 2])['mode']);                      // shrunk to 1:1
        $this->assertSame('sfu', $this->decide(['current' => 'sfu', 'count' => 2, 'changed_at' => 990])['mode']);  // dwell
        $this->assertSame('mesh', $this->decide(['count' => 6, 'changed_at' => 990])['mode']);                     // dwell on the way up
    }

    public function test_force_modes(): void
    {
        $this->assertSame('sfu', $this->decide(['force' => 'sfu', 'count' => 2])['mode']);
        $this->assertSame('mesh', $this->decide(['force' => 'mesh', 'count' => 30])['mode']);
    }
}
