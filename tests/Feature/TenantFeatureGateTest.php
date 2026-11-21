<?php

namespace Tests\Feature;

use App\Http\Middleware\TenantFeatureGate;
use App\Http\Middleware\UipTenantScopeMiddleware;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** الخطوة 4 — ميزات العملاء: التصنيف، دورة الطلب/القرار، والـ gate. على sqlite. */
class TenantFeatureGateTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::resetForTests();
        TenantFeatures::resetCaches();
        config([
            'tenancy.isolation.template' => 'sqlite',
            'cache.default' => 'file',
            'cache.stores.file.path' => sys_get_temp_dir() . '/uip-feat-cache-' . uniqid(),
            'tenant_features.unmapped' => 'deny',
        ]);
        foreach (['tenant_feature_grants', 'tenant_domains', 'tenants'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('tenants', function ($t) {
            $t->id(); $t->string('public_id'); $t->string('name'); $t->string('slug')->nullable(); $t->string('status')->default('active');
            $t->string('mode')->default('shared'); $t->string('db_name')->nullable(); $t->string('db_status')->default('none');
        });
        Schema::create('tenant_feature_grants', function ($t) {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->string('feature', 40); $t->string('status', 10)->default('pending');
            $t->unsignedBigInteger('requested_by')->nullable(); $t->string('request_note')->nullable(); $t->dateTime('requested_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable(); $t->string('decision_note')->nullable(); $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'feature']);
        });
    }

    protected function tearDown(): void
    {
        TenantContext::deactivate();
        TenantContext::resetForTests();
        TenantFeatures::resetCaches();
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tenant(int $id = 1): object
    {
        $file = sys_get_temp_dir() . "/uip-feat-{$id}-" . uniqid() . '.sqlite';
        touch($file);
        $this->files[] = $file;
        DB::table('tenants')->insert(['id' => $id, 'public_id' => "tnt_{$id}", 'name' => "T{$id}", 'slug' => "t{$id}", 'mode' => 'isolated', 'db_name' => $file, 'db_status' => 'ready']);
        return DB::table('tenants')->where('id', $id)->first();
    }

    /** يشغّل الـ gate لمسار معيّن جوه العميل ويرجّع [status, json|null]. */
    private function hit(object $tenant, string $path, string $method = 'GET'): array
    {
        $res = TenantContext::run($tenant, fn () => (new TenantFeatureGate())->handle(Request::create($path, $method), fn () => response('ok', 200)));
        return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
    }

    #[Test]
    public function paths_are_normalized_and_classified_longest_prefix_wins(): void
    {
        $this->assertNull(TenantFeatures::normalizePath('/up'));
        $this->assertSame('meetings/x', TenantFeatures::normalizePath('/api/v1/meetings/x'));
        $this->assertSame('dev/v1/openapi.json', TenantFeatures::normalizePath('/api/dev/v1/openapi.json'));
        $this->assertNull(TenantFeatures::normalizePath('/apixyz'));

        $this->assertSame('core', TenantFeatures::classify('auth/login'));
        $this->assertSame('core', TenantFeatures::classify('admin/users'));
        $this->assertSame('meetings', TenantFeatures::classify('admin/meetings/4'));
        $this->assertSame('messaging', TenantFeatures::classify('admin/messaging'));
        $this->assertSame('core', TenantFeatures::classify('dev/v1/tenant/branding'));
        $this->assertSame('developer', TenantFeatures::classify('dev/v1/openapi.json'));
        $this->assertSame('forms', TenantFeatures::classify('f/abc123'));
        $this->assertNull(TenantFeatures::classify('feedback'));      // 'f' مش بتطابق 'feedback'
        $this->assertNull(TenantFeatures::classify('something-new'));
    }

    #[Test]
    public function every_prefix_belongs_to_exactly_one_owner(): void
    {
        $seen = [];
        $all = array_map(fn ($p) => ['core', $p], (array) config('tenant_features.core'));
        foreach (TenantFeatures::catalog() as $key => $f) {
            foreach ($f['prefixes'] as $p) {
                $all[] = [$key, $p];
            }
        }
        foreach ($all as [$owner, $p]) {
            $this->assertArrayNotHasKey($p, $seen, "prefix '{$p}' مكرر بين '{$owner}' و '" . ($seen[$p] ?? '') . "'");
            $seen[$p] = $owner;
        }
    }

    #[Test]
    public function new_tenant_has_everything_closed_except_core(): void
    {
        $t = $this->tenant();
        [$s, $j] = $this->hit($t, '/api/v1/meetings');
        $this->assertSame(403, $s);
        $this->assertSame('feature_not_enabled', $j['errors']['code']);
        $this->assertSame('meetings', $j['errors']['feature']);

        $this->assertSame(200, $this->hit($t, '/api/v1/auth/login', 'POST')[0]);
        $this->assertSame(200, $this->hit($t, '/api/v1/tenant/features')[0]);
        $this->assertSame(200, $this->hit($t, '/up')[0]);              // مش تحت /api
        $this->assertSame(200, $this->hit($t, '/api/v1/meetings', 'OPTIONS')[0]); // preflight
    }

    #[Test]
    public function request_then_approve_opens_it_and_disable_closes_it_again(): void
    {
        $t = $this->tenant();
        $r = TenantFeatures::request(1, ['meetings', 'meetings', 'mail'], 7, 'محتاجينها');
        $this->assertSame(['meetings', 'mail'], $r['requested']);
        $this->assertSame(403, $this->hit($t, '/api/v1/meetings')[0], 'pending مش مفتوحة');

        TenantFeatures::decide(1, 'meetings', 'enabled', 99, 'تمام');
        $this->assertSame(200, $this->hit($t, '/api/v1/meetings/abc/chat')[0]);
        $this->assertSame(200, $this->hit($t, '/api/v1/admin/meetings')[0]);
        $this->assertSame(403, $this->hit($t, '/api/v1/mail/inbox')[0], 'mail لسه pending');

        TenantFeatures::decide(1, 'mail', 'rejected', 99, 'مش في الباقة');
        $this->assertSame(403, $this->hit($t, '/api/v1/mail/inbox')[0]);

        TenantFeatures::decide(1, 'meetings', 'disabled', 99);
        $this->assertSame(403, $this->hit($t, '/api/v1/meetings')[0]);
    }

    #[Test]
    public function requests_are_idempotent_and_rejected_can_be_requested_again(): void
    {
        $this->tenant();
        $this->assertSame(['meetings'], TenantFeatures::request(1, ['meetings'], 7)['requested']);
        $again = TenantFeatures::request(1, ['meetings'], 7);
        $this->assertSame([], $again['requested']);
        $this->assertSame(['meetings'], $again['skipped']);

        TenantFeatures::decide(1, 'meetings', 'rejected', 99, 'لا');
        $this->assertSame(['meetings'], TenantFeatures::request(1, ['meetings'], 7)['requested']);
        $row = DB::table('tenant_feature_grants')->where('tenant_id', 1)->where('feature', 'meetings')->first();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->decision_note, 'القرار القديم اتمسح');

        $this->assertTrue(TenantFeatures::cancelRequest(1, 'meetings'));
        $this->assertFalse(TenantFeatures::cancelRequest(1, 'meetings'));
    }

    #[Test]
    public function unknown_feature_is_rejected_and_nothing_is_written(): void
    {
        $this->tenant();
        try {
            TenantFeatures::request(1, ['meetings', 'nope'], 7);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, DB::table('tenant_feature_grants')->count());
        }
    }

    #[Test]
    public function features_do_not_leak_between_tenants(): void
    {
        $a = $this->tenant(1);
        $b = $this->tenant(2);
        TenantFeatures::decide(1, 'meetings', 'enabled', 99);
        $this->assertSame(200, $this->hit($a, '/api/v1/meetings')[0]);
        $this->assertSame(403, $this->hit($b, '/api/v1/meetings')[0]);
    }

    #[Test]
    public function unmapped_paths_are_denied_unless_configured_to_allow(): void
    {
        $t = $this->tenant();
        [$s, $j] = $this->hit($t, '/api/v1/brand-new-module');
        $this->assertSame(403, $s);
        $this->assertSame('feature_not_available', $j['errors']['code']);

        config(['tenant_features.unmapped' => 'allow']);
        $this->assertSame(200, $this->hit($t, '/api/v1/brand-new-module')[0]);
    }

    #[Test]
    public function missing_grants_table_fails_closed(): void
    {
        $t = $this->tenant();
        Schema::drop('tenant_feature_grants');
        TenantFeatures::resetCaches();
        $this->assertSame(403, $this->hit($t, '/api/v1/meetings')[0]);
        $this->assertSame(200, $this->hit($t, '/api/v1/auth/login', 'POST')[0], 'core يفضل شغال');
    }

    #[Test]
    public function gate_does_nothing_on_the_platform(): void
    {
        $res = (new TenantFeatureGate())->handle(Request::create('/api/v1/meetings'), fn () => response('ok'));
        $this->assertSame(200, $res->getStatusCode());
    }

    #[Test]
    public function scope_middleware_separates_platform_and_tenant_routes(): void
    {
        $t = $this->tenant();
        $mw = new UipTenantScopeMiddleware();
        $ok = fn () => response('ok');

        $this->assertSame(200, $mw->handle(Request::create('/x'), $ok, 'platform')->getStatusCode());
        $this->assertSame(404, $mw->handle(Request::create('/x'), $ok, 'tenant')->getStatusCode());
        TenantContext::run($t, function () use ($mw, $ok) {
            $this->assertSame(404, $mw->handle(Request::create('/x'), $ok, 'platform')->getStatusCode());
            $this->assertSame(200, $mw->handle(Request::create('/x'), $ok, 'tenant')->getStatusCode());
        });
    }

    #[Test]
    public function state_and_request_inbox_reflect_current_status(): void
    {
        $this->tenant();
        TenantFeatures::request(1, ['ai'], 7, 'please');
        TenantFeatures::decide(1, 'drive', 'enabled', 99);
        $state = collect(TenantFeatures::stateFor(1))->keyBy('feature');
        $this->assertSame('pending', $state['ai']['status']);
        $this->assertSame('enabled', $state['drive']['status']);
        $this->assertSame('none', $state['mail']['status']);

        $inbox = TenantFeatures::listRequests('pending');
        $this->assertCount(1, $inbox);
        $this->assertSame('ai', $inbox[0]['feature']);
        $this->assertSame('t1', $inbox[0]['tenant_slug']);
    }
}
