<?php

namespace Tests\Feature;

use App\Services\Developer\DevOpenApi;
use App\Services\Developer\DevScopes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** OpenAPI — اختبارات بدون DB: التوثيق لازم يفضل ماشي مع الـ routes الفعلية. */
class DevOpenApiTest extends TestCase
{
    private function spec(): array
    {
        return DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
    }

    #[Test]
    public function every_developer_route_is_documented_and_nothing_is_stale(): void
    {
        $routes = DevOpenApi::routesFromLaravel();
        $this->assertNotEmpty($routes);
        $this->assertSame([], DevOpenApi::unmapped($routes), 'Add these routes to DevOpenApi::catalog()');
        $this->assertSame([], DevOpenApi::orphans($routes), 'These catalog entries have no route anymore');
    }

    #[Test]
    public function the_docs_route_itself_is_not_listed(): void
    {
        $keys = array_map(fn ($r) => $r['method'] . ' ' . $r['path'], DevOpenApi::routesFromLaravel());
        $this->assertNotContains('GET /openapi.json', $keys);
    }

    #[Test]
    public function operations_are_unique_and_scopes_exist(): void
    {
        $spec = $this->spec();
        $ids = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                $ids[] = $op['operationId'];
                $this->assertNotEmpty($op['summary'], "$method $path");
                if (isset($op['x-required-scope'])) {
                    $this->assertContains($op['x-required-scope'], DevScopes::names(), "$method $path");
                }
                preg_match_all('/\{(\w+)\}/', $path, $m);
                $declared = array_column(array_filter($op['parameters'] ?? [], fn ($p) => $p['in'] === 'path'), 'name');
                sort($m[1]);
                sort($declared);
                $this->assertSame($m[1], $declared, "path params of $method $path");
            }
        }
        $this->assertSame($ids, array_values(array_unique($ids)), 'operationId must be unique');
    }

    #[Test]
    public function webhooks_cover_exactly_the_configured_events_and_oauth_scopes_match(): void
    {
        $spec = $this->spec();
        $events = array_keys((array) config('developer.events'));
        $this->assertEqualsCanonicalizing($events, array_keys($spec['webhooks']));
        $this->assertEqualsCanonicalizing($events, $spec['components']['schemas']['WebhookEvent']['properties']['type']['enum']);
        $this->assertEqualsCanonicalizing(DevScopes::names(), array_keys($spec['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']));
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotFalse(json_encode($spec, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function every_schema_reference_resolves(): void
    {
        $spec = $this->spec();
        $json = json_encode($spec);
        preg_match_all('~#/components/schemas/(\w+)~', $json, $m);
        foreach (array_unique($m[1]) as $name) {
            $this->assertArrayHasKey($name, $spec['components']['schemas'], "missing schema $name");
        }
        preg_match_all('~"(\w+)":\[\]~', json_encode($spec['paths']), $none);
        foreach (['bearerAuth', 'oauth2', 'clientBasic'] as $s) {
            $this->assertArrayHasKey($s, $spec['components']['securitySchemes']);
        }
    }
}
