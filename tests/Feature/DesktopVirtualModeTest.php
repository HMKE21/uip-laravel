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
    public function defaults_to_off_and_is_available_for_students(): void
    {
        $uid = $this->makeUser();
        [$code, $body] = $this->call('GET', $uid, 'student');
        $this->assertSame(200, $code);
        $this->assertFalse($body['data']['enabled']);
    }

    #[Test]
    public function student_can_turn_it_on_and_off_and_it_persists(): void
    {
        $uid = $this->makeUser();
        [$code, $body] = $this->call('PUT', $uid, 'student', ['enabled' => true]);
        if ($body['data']['available'] === false) {
            $this->markTestSkipped('My PC is closed in this environment: ' . ($body['data']['reason'] ?? ''));
        }
        $this->assertTrue($body['data']['enabled']);
        $this->assertTrue($this->call('GET', $uid, 'student')[1]['data']['enabled']);
        $this->assertTrue((bool) DB::table('desktop_settings')->where('user_id', $uid)->value('virtual_mode'));

        $this->assertFalse($this->call('PUT', $uid, 'student', ['enabled' => false])[1]['data']['enabled']);
    }

    #[Test]
    public function other_roles_cannot_enable_it(): void
    {
        $uid = $this->makeUser();
        [$code, $body] = $this->call('PUT', $uid, 'university', ['enabled' => true]);
        $this->assertSame(403, $code);
        $this->assertFalse($this->call('GET', $uid, 'university')[1]['data']['available']);
    }

    #[Test]
    public function the_global_switch_turns_it_off_for_everyone(): void
    {
        config(['virtual_mode.enabled' => false]);
        $uid = $this->makeUser();
        $this->assertFalse($this->call('GET', $uid, 'student')[1]['data']['available']);
        $this->assertSame(403, $this->call('PUT', $uid, 'student', ['enabled' => true])[0]);
    }
}
