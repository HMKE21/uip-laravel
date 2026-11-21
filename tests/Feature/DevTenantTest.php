<?php

namespace Tests\Feature;

use App\Services\Developer\DevOpenApi;
use App\Services\Tenancy\TenantBranding;
use App\Services\Tenancy\TenantDomainService;
use App\Services\Tenancy\TenantService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Tenants — اختبارات بدون DB: تطبيع الدومين، مصفوفة الصلاحيات، الـ features، وإن النقاط العامة مش في الـ OpenAPI. */
class DevTenantTest extends TestCase
{
    #[Test]
    public function domains_are_normalised_to_plain_lowercase_hostnames(): void
    {
        $this->assertSame('meet.example.com', TenantDomainService::normalize('  Meet.Example.COM. '));
        $this->assertSame('a-b.example.co.uk', TenantDomainService::normalize('a-b.example.co.uk'));
        $this->assertSame('code-uipe.duckdns.org', TenantDomainService::normalize('Code-UIPE.duckdns.org'));   // دومينات DuckDNS مسموحة
    }

    #[Test]
    public function unsafe_or_malformed_domains_are_rejected(): void
    {
        foreach (['https://meet.example.com', 'meet.example.com/path', 'meet.example.com:8443', '*.example.com', 'localhost', 'example', '127.0.0.1', '10.0.0.1',
            'a_b.example.com', '-bad.example.com', 'bad-.example.com', 'user@example.com', '', 'exa mple.com', str_repeat('a', 64) . '.example.com'] as $bad) {
            try {
                TenantDomainService::normalize($bad);
                $this->fail("'{$bad}' should have been rejected");
            } catch (\DomainException $e) {
                $this->assertGreaterThanOrEqual(400, $e->getCode(), $bad);
            }
        }
    }

    #[Test]
    public function the_platform_own_host_and_reserved_domains_cannot_be_claimed(): void
    {
        config(['app.url' => 'https://uipe.example.org', 'tenancy.domains.reserved' => ['platform.test']]);
        foreach (['uipe.example.org', 'meet.uipe.example.org', 'platform.test', 'x.platform.test'] as $d) {
            try {
                TenantDomainService::normalize($d);
                $this->fail("'{$d}' should be reserved");
            } catch (\DomainException $e) {
                $this->assertSame(403, $e->getCode(), $d);
            }
        }
        $this->assertSame('notplatform.test', TenantDomainService::normalize('notplatform.test'));
    }

    #[Test]
    public function the_dns_challenge_name_uses_the_configured_prefix(): void
    {
        $this->assertSame('_uipe-challenge.meet.example.com', TenantDomainService::txtName('meet.example.com'));
        config(['tenancy.domains.txt_prefix' => '_x.']);
        $this->assertSame('_x.meet.example.com', TenantDomainService::txtName('meet.example.com'));
    }

    #[Test]
    public function a_viewer_can_read_but_never_write_and_only_owners_delete(): void
    {
        $can = TenantService::ABILITIES;
        $this->assertContains('viewer', $can['view']);
        foreach (['resources', 'branding', 'members', 'domains', 'delete'] as $a) {
            $this->assertNotContains('viewer', $can[$a], $a);
        }
        $this->assertSame(['owner'], $can['delete']);
        $this->assertNotContains('developer', $can['members']);
        $this->assertNotContains('developer', $can['branding']);
        $this->assertContains('developer', $can['resources']);
    }

    #[Test]
    public function white_label_is_a_platform_granted_feature(): void
    {
        $this->assertTrue(TenantBranding::hasFeature((object) ['features' => '["white_label"]'], 'white_label'));
        $this->assertFalse(TenantBranding::hasFeature((object) ['features' => '[]'], 'white_label'));
        $this->assertFalse(TenantBranding::hasFeature((object) ['features' => null], 'white_label'));
        $this->assertSame(['white_label', 'custom_domain'], config('tenancy.features'));
    }

    #[Test]
    public function the_public_tenant_routes_exist_but_are_not_documented_in_the_openapi_spec(): void
    {
        $uris = array_map(fn ($r) => $r->uri(), \Illuminate\Support\Facades\Route::getRoutes()->getRoutes());
        foreach (['api/dev/v1/tenant/branding', 'api/dev/v1/tenant/domain-check', 'api/v1/developer/tenants', 'api/v1/developer/tenants/{id}/domains', 'api/v1/developer/tenants/{id}/usage', 'api/v1/developer/tenants/{id}/logs'] as $u) {
            $this->assertContains($u, $uris, $u);
        }
        $spec = DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
        foreach (array_keys($spec['paths']) as $p) {
            $this->assertStringNotContainsString('/tenant', $p);
        }
    }
}
