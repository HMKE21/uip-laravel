<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DesktopVirtualModeApiController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "الوضع الافتراضي" (UIP OS): GET/PUT /api/v1/desktop/virtual-mode.
 * ⚠ كُتب من غير تشغيل (مفيش vendor/DB في بيئة الكتابة) — شغّله عندك: php artisan test --filter=DesktopVirtualModeTest
 */
class DesktopVirtualModeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Vm', 'email' => 'vm_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function call(string $method, int $uid, string $role, array $body = []): array
    {
        $req = Request::create('/api/v1/desktop/virtual-mode', $method, $body);
        $req->attributes->set('uip_user_id', $uid);
        $req->attributes->set('uip_role', $role);
        $ctl = app(DesktopVirtualModeApiController::class);
        $res = $method === 'GET' ? $ctl->show($req) : $ctl->update($req);

        return [$res->getStatusCode(), $res->getData(true)];
    }

    #[Test]
    public function it_is_pinned_on_for_students_whenever_my_pc_is_open(): void
    {
        $uid = $this->makeUser();
        [$code, $body] = $this->call('GET', $uid, 'student');
        $this->assertSame(200, $code);
        if ($body['data']['available'] === false) {
            $this->markTestSkipped('My PC is closed in this environment: ' . ($body['data']['reason'] ?? ''));
        }
        $this->assertTrue($body['data']['enabled']);
        $this->assertTrue($body['data']['pinned']);
    }

    #[Test]
    public function it_cannot_be_switched_off_by_the_client(): void
    {
        $uid = $this->makeUser();
        [$code, $body] = $this->call('PUT', $uid, 'student', ['enabled' => false]);
        $this->assertSame(200, $code);
        $this->assertSame($body['data']['available'], $body['data']['enabled']);
    }

    #[Test]
    public function other_roles_do_not_get_it(): void
    {
        $uid = $this->makeUser();
        [$code, $body] = $this->call('GET', $uid, 'university');
        $this->assertFalse($body['data']['available']);
        $this->assertFalse($body['data']['enabled']);
    }

    #[Test]
    public function the_global_switch_turns_it_off_for_everyone(): void
    {
        config(['virtual_mode.enabled' => false]);
        $uid = $this->makeUser();
        $body = $this->call('GET', $uid, 'student')[1];
        $this->assertFalse($body['data']['available']);
        $this->assertFalse($body['data']['enabled']);
    }
}
