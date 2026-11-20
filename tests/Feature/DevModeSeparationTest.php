<?php

namespace Tests\Feature;

use App\Services\Developer\DevModeGate;
use App\Services\Developer\DevOpenApi;
use App\Services\Developer\DeveloperAppService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** فصل sandbox/live + Rotate لمفتاح الـ API — اختبارات بدون DB. */
class DevModeSeparationTest extends TestCase
{
    #[Test]
    public function every_catalog_endpoint_declares_its_mode_explicitly(): void
    {
        foreach (DevOpenApi::catalog() as $key => $e) {
            if (!empty($e['oauth'])) {
                continue;
            }
            $this->assertContains($e['modes'] ?? null, ['both', 'live', 'sandbox'], "$key must declare modes (both|live|sandbox)");
        }
    }

    #[Test]
    public function every_developer_route_resolves_to_a_known_mode(): void
    {
        $routes = DevOpenApi::routesFromLaravel();
        $this->assertNotEmpty($routes);
        foreach ($routes as $r) {
            if (in_array($r['path'], ['/oauth/token', '/oauth/revoke'], true)) {
                continue;
            }
            $this->assertNotSame('unknown', DevOpenApi::modeOf($r['method'], $r['path']), $r['method'] . ' ' . $r['path']);
        }
    }

    #[Test]
    public function sandbox_endpoints_are_sandbox_only_and_endpoints_that_touch_real_resources_are_live_only(): void
    {
        $this->assertSame('sandbox', DevOpenApi::modeOf('POST', '/sandbox/reset'));
        $this->assertSame('sandbox', DevOpenApi::modeOf('POST', '/sandbox/simulate-incoming'));
        foreach (['GET /mail/labels', 'POST /mail/labels', 'PUT /mail/labels/{id}', 'DELETE /mail/labels/{id}', 'GET /mail/threads/{threadId}', 'PUT /mail/drafts/{id}', 'POST /mail/drafts/{id}/send',
            'POST /mail/messages/{id}/share-chat', 'POST /mail/messages/{id}/meeting', 'POST /mail/messages/{id}/save-drive', 'POST /mail/ai/{action}'] as $k) {
            [$m, $p] = explode(' ', $k, 2);
            $this->assertSame('live', DevOpenApi::modeOf($m, $p), $k);
        }
        foreach (['GET /webhooks', 'POST /webhooks', 'DELETE /webhooks/{id}', 'GET /me', 'GET /mail/messages'] as $k) {
            [$m, $p] = explode(' ', $k, 2);
            $this->assertSame('both', DevOpenApi::modeOf($m, $p), $k);
        }
        $this->assertSame('unknown', DevOpenApi::modeOf('GET', '/not/documented'));
    }

    #[Test]
    public function the_gate_blocks_across_modes_and_fails_closed_for_unknown_endpoints(): void
    {
        $this->assertNull(DevModeGate::check('live', 'live'));
        $this->assertNull(DevModeGate::check('live', 'both'));
        $this->assertNull(DevModeGate::check('sandbox', 'sandbox'));
        $this->assertNull(DevModeGate::check('sandbox', 'both'));

        $this->assertSame('sandbox_unsupported', DevModeGate::check('sandbox', 'live')['code']);
        $this->assertSame('sandbox_unsupported', DevModeGate::check('sandbox', 'unknown')['code']);
        $this->assertSame('sandbox_only', DevModeGate::check('live', 'sandbox')['code']);
        $this->assertNull(DevModeGate::check('live', 'unknown'), 'live credentials are not affected by an undocumented route');
    }

    #[Test]
    public function the_webhook_docs_say_only_same_mode_webhooks_are_visible(): void
    {
        $spec = DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
        $this->assertStringContainsString('own mode', $spec['paths']['/webhooks']['get']['description']);
        $this->assertStringContainsString('own mode', $spec['paths']['/webhooks/{id}']['delete']['description']);
    }

    #[Test]
    public function the_spec_exposes_the_mode_of_every_endpoint(): void
    {
        $spec = DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
        $this->assertSame('both', $spec['paths']['/webhooks']['get']['x-uipe-mode']);
        $this->assertSame('live', $spec['paths']['/mail/labels']['get']['x-uipe-mode']);
        $this->assertSame('sandbox', $spec['paths']['/sandbox/reset']['post']['x-uipe-mode']);
    }

    #[Test]
    public function rotation_without_grace_revokes_immediately(): void
    {
        $this->assertNull(DeveloperAppService::rotationCutoff(null, 0, Carbon::parse('2027-12-01 10:00:00', 'UTC')));
    }

    #[Test]
    public function rotation_grace_ends_after_the_requested_hours(): void
    {
        $now = Carbon::parse('2027-12-01 10:00:00', 'UTC');
        $this->assertSame('2027-12-02 10:00:00', DeveloperAppService::rotationCutoff(null, 24, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2027-12-08 10:00:00', DeveloperAppService::rotationCutoff(null, DeveloperAppService::ROTATE_MAX_GRACE_HOURS, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2027-12-01 10:00:00', $now->format('Y-m-d H:i:s'), 'the given date is not mutated');
    }

    #[Test]
    public function grace_never_extends_the_life_of_a_key_that_expires_sooner(): void
    {
        $now = Carbon::parse('2027-12-01 10:00:00', 'UTC');
        $this->assertSame('2027-12-01 12:00:00', DeveloperAppService::rotationCutoff('2027-12-01 12:00:00', 24, $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2027-12-02 10:00:00', DeveloperAppService::rotationCutoff('2028-06-01 00:00:00', 24, $now)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function rotation_defaults_are_sane(): void
    {
        $this->assertSame(24, DeveloperAppService::ROTATE_DEFAULT_GRACE_HOURS);
        $this->assertSame(168, DeveloperAppService::ROTATE_MAX_GRACE_HOURS);
    }
}
