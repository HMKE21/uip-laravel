<?php

namespace Tests\Feature;

use App\Services\PermissionService;
use App\Services\UipJwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الصلاحيات اللي الأدمن بيظبطها من /admin/roles لازم تتنفّذ فعليًا:
 * سحب صلاحية من دور => أول ريكوست بعدها بيتقفل (نفس التوكن، من غير logout)،
 * ومنحها => بيتفتح تاني.
 */
class PermissionEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    private int $roleId;
    private int $userId;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-permission-enforcement');
            $_ENV['JWT_SECRET'] = 'test-secret-for-permission-enforcement';
        }

        // دور مخصص معزول (مفيش أي صلاحية)، عشان الاختبار ميتأثرش ببيانات الـ seed.
        $this->roleId = DB::table('roles')->insertGetId([
            'slug' => 'perm_test_' . Str::lower(Str::random(8)),
            'name_ar' => 'اختبار', 'name_en' => 'Perm test', 'is_system' => 0,
        ]);
        $this->userId = $this->makeUser($this->roleId);
        // الـ role claim في التوكن = 'student' عشان نعدّي أي فحص دور جوّه الكنترولرز؛
        // الصلاحيات نفسها بتتقري من user_roles (الدور المخصص ده).
        $this->token = UipJwtService::encode(['sub' => $this->userId, 'role' => 'student'], 600);
    }

    private function makeUser(int $roleId): int
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Perm Tester',
            'email' => 'perm_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        return $userId;
    }

    private function permId(string $slug): int
    {
        DB::table('permissions')->insertOrIgnore(['slug' => $slug, 'module' => explode('.', $slug)[0]]);
        return (int) DB::table('permissions')->where('slug', $slug)->value('id');
    }

    private function grant(string $slug): void
    {
        DB::table('role_permission')->insertOrIgnore(['role_id' => $this->roleId, 'permission_id' => $this->permId($slug)]);
    }

    private function revoke(string $slug): void
    {
        DB::table('role_permission')->where('role_id', $this->roleId)->where('permission_id', $this->permId($slug))->delete();
    }

    private function call(string $method, string $uri, ?string $token = null)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])->json($method, $uri);
    }

    private function assertDenied($response, string $permission): void
    {
        $response->assertStatus(403);
        $this->assertSame('permission_denied', $response->json('errors.code'));
        $this->assertSame($permission, $response->json('errors.permission'));
    }

    private function assertNotDenied($response): void
    {
        $this->assertNotSame('permission_denied', $response->json('errors.code'), 'Request was blocked by the permission layer.');
    }

    #[Test]
    public function revoking_a_permission_closes_the_route_immediately_and_granting_reopens_it(): void
    {
        $uri = '/api/v1/projects/999999/ai-analysis';

        $this->assertDenied($this->call('GET', $uri), 'ai.request_analysis');

        $this->grant('ai.request_analysis');
        $this->assertNotDenied($this->call('GET', $uri)); // نفس التوكن — اتفتح

        $this->revoke('ai.request_analysis');
        $this->assertDenied($this->call('GET', $uri), 'ai.request_analysis'); // واتقفل تاني فورًا
    }

    #[Test]
    public function ai_assistant_is_gated_but_its_status_probe_stays_open(): void
    {
        $this->assertDenied($this->call('GET', '/api/v1/ai-assistant/conversations'), 'ai.assistant_use');
        $this->assertDenied($this->call('POST', '/api/v1/ai-assistant/conversations/1/messages'), 'ai.assistant_use');
        $this->assertNotDenied($this->call('GET', '/api/v1/ai-assistant/status'));

        $this->grant('ai.assistant_use');
        $this->assertNotDenied($this->call('GET', '/api/v1/ai-assistant/conversations'));
    }

    #[Test]
    public function write_prefixed_rule_only_blocks_mutating_requests(): void
    {
        // notifications.manage بيقفل التعديل بس؛ قراءة الإشعارات فضلت مفتوحة.
        $this->assertNotDenied($this->call('GET', '/api/v1/notifications'));
        $this->assertDenied($this->call('POST', '/api/v1/notifications/read-all'), 'notifications.manage');

        $this->grant('notifications.manage');
        $this->assertNotDenied($this->call('POST', '/api/v1/notifications/read-all'));
    }

    #[Test]
    public function project_create_and_edit_have_their_own_permissions(): void
    {
        $this->assertDenied($this->call('POST', '/api/v1/projects'), 'project.create');
        $this->assertDenied($this->call('PATCH', '/api/v1/projects/1'), 'project.edit_own');

        $this->grant('project.create');
        $this->assertNotDenied($this->call('POST', '/api/v1/projects'));
        $this->assertDenied($this->call('PATCH', '/api/v1/projects/1'), 'project.edit_own');
    }

    #[Test]
    public function each_data_analysis_and_security_section_checks_its_own_permission(): void
    {
        // entry permission مقفولة => البورتال كله مقفول.
        $this->assertDenied($this->call('GET', '/api/v1/data-analysis/dashboard'), 'data_analysis.dashboard.view');
        $this->assertDenied($this->call('GET', '/api/v1/data-analysis/exports'), 'data_analysis.dashboard.view');

        // ومعاها الـ entry، كل قسم لسه محتاج صلاحيته.
        $this->grant('data_analysis.dashboard.view');
        $this->assertDenied($this->call('GET', '/api/v1/data-analysis/exports'), 'data_analysis.export');
        $this->assertDenied($this->call('GET', '/api/v1/data-analysis/segments'), 'data_analysis.segments.manage');
        $this->assertDenied($this->call('GET', '/api/v1/data-analysis/kpis'), 'data_analysis.trends.view');
        $this->assertDenied($this->call('GET', '/api/v1/data-analysis/reports'), 'data_analysis.reports.manage');
        // saved dashboards: القراءة مفتوحة، التعديل محتاج customize.
        $this->assertNotDenied($this->call('GET', '/api/v1/data-analysis/dashboards'));
        $this->assertDenied($this->call('POST', '/api/v1/data-analysis/dashboards'), 'data_analysis.dashboards.customize');

        $this->assertDenied($this->call('GET', '/api/v1/security/incidents'), 'security.incidents.manage');
        $this->assertDenied($this->call('GET', '/api/v1/security/sessions'), 'security.sessions.manage');
        $this->assertDenied($this->call('POST', '/api/v1/security/logs/blocked-ips/1/unblock'), 'logs.view_security');
        $this->grant('logs.view_security');
        $this->assertDenied($this->call('POST', '/api/v1/security/logs/blocked-ips/1/unblock'), 'security.ip.manage');
    }

    #[Test]
    public function messaging_and_analytics_are_gated(): void
    {
        $this->assertDenied($this->call('GET', '/api/v1/messaging/inbox'), 'messaging.use');
        $this->assertDenied($this->call('GET', '/api/v1/analytics/overview'), 'analytics.view');
        $this->grant('messaging.use');
        $this->assertNotDenied($this->call('GET', '/api/v1/messaging/inbox'));
    }

    #[Test]
    public function per_user_revoke_override_beats_the_role_grant_and_grant_override_adds_one(): void
    {
        $this->grant('messaging.use');
        $svc = app(PermissionService::class);
        $this->assertTrue($svc->userHasPermission($this->userId, 'messaging.use'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $this->userId, 'permission_id' => $this->permId('messaging.use'), 'effect' => 'revoke',
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $this->userId, 'permission_id' => $this->permId('analytics.view'), 'effect' => 'grant',
        ]);

        $this->assertFalse($svc->userHasPermission($this->userId, 'messaging.use'));
        $this->assertTrue($svc->userHasPermission($this->userId, 'analytics.view'));
        $this->assertDenied($this->call('GET', '/api/v1/messaging/inbox'), 'messaging.use');
    }

    #[Test]
    public function admin_always_passes_and_my_permissions_endpoint_reports_the_effective_set(): void
    {
        $adminToken = UipJwtService::encode(['sub' => $this->userId, 'role' => 'admin'], 600);
        $this->assertNotDenied($this->call('GET', '/api/v1/ai-assistant/conversations', $adminToken));
        $this->assertNotDenied($this->call('GET', '/api/v1/messaging/inbox', $adminToken));

        $mine = $this->call('GET', '/api/v1/auth/permissions');
        $mine->assertOk();
        $this->assertFalse($mine->json('data.is_admin'));
        $this->assertSame([], $mine->json('data.permissions'));

        $this->grant('ai.assistant_use');
        $this->assertSame(['ai.assistant_use'], $this->call('GET', '/api/v1/auth/permissions')->json('data.permissions'));

        $asAdmin = $this->call('GET', '/api/v1/auth/permissions', $adminToken);
        $this->assertTrue($asAdmin->json('data.is_admin'));
        $this->assertContains('ai.assistant_use', $asAdmin->json('data.permissions'));
    }
}
