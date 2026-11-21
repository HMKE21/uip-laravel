<?php

namespace Tests\Feature;

use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** فحص عضوية المساحة: الحالات اللي مش محتاجة DB (المنصة نفسها بتفضل من غير أي تغيير). */
class WorkspaceAccessTest extends TestCase
{
    #[Test]
    public function the_platform_itself_is_never_restricted(): void
    {
        $this->assertNull(WorkspaceAccess::check(Request::create('/api/v1/me', 'GET'), 5, 'student'));
        $r = Request::create('/api/v1/me', 'GET');
        $r->headers->set('X-Tenant-Domain', 'localhost');
        $this->assertNull(WorkspaceAccess::check($r, 5, 'student'));
    }

    #[Test]
    public function a_malformed_workspace_header_is_ignored_here_because_resolve_tenant_rejects_it(): void
    {
        $r = Request::create('/api/v1/me', 'GET');
        $r->headers->set('X-Tenant-Slug', '../etc');
        $this->assertNull(WorkspaceAccess::check($r, 5, 'student'));
        $this->assertNull(WorkspaceAccess::check(Request::create('/api/v1/me', 'GET'), 0, 'student'));
    }
}
