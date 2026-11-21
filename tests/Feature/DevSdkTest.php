<?php

namespace Tests\Feature;

use App\Services\Developer\DevOpenApi;
use App\Services\Developer\Sdk\SdkGenerator;
use App\Services\Developer\WebhookSignature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SDKs — اختبارات بدون DB: المولّد لازم يطلّع نفس الخرج، من غير placeholders ناقصة، وبكل operation في الـ 3 لغات. */
class DevSdkTest extends TestCase
{
    private function spec(): array
    {
        return DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
    }

    #[Test]
    public function every_language_gets_its_files_and_no_placeholder_is_left(): void
    {
        $files = SdkGenerator::generate($this->spec());
        foreach (['openapi.json', 'python/uipe/operations.py', 'python/uipe/webhooks.py', 'js/src/operations.js', 'js/index.d.ts', 'php/src/Operations.php', 'php/src/Webhooks.php', 'php/tests/run.php'] as $f) {
            $this->assertArrayHasKey($f, $files, $f);
        }
        foreach ($files as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/\{\{[A-Z_]+\}\}/', $content, $path);
            $this->assertStringNotContainsString('.tpl', $path);
        }
        $this->assertStringContainsString('https://uipe.test/api/dev/v1', $files['python/uipe/client.py']);
    }

    #[Test]
    public function every_operation_is_generated_in_all_three_languages(): void
    {
        $spec = $this->spec();
        $files = SdkGenerator::generate($spec);
        $ops = SdkGenerator::operations($spec);
        $this->assertNotEmpty($ops);
        foreach ($ops as $op) {
            $this->assertStringContainsString('def ' . SdkGenerator::snake($op['id']) . '(', $files['python/uipe/operations.py'], $op['id']);
            $this->assertStringContainsString(lcfirst($op['id']) . '(', $files['js/src/operations.js'], $op['id']);
            $this->assertStringContainsString(lcfirst($op['id']) . '(', $files['js/index.d.ts'], $op['id']);
            $this->assertStringContainsString('function ' . lcfirst($op['id']) . '(', $files['php/src/Operations.php'], $op['id']);
        }
    }

    #[Test]
    public function oauth_endpoints_are_not_part_of_the_sdk(): void
    {
        foreach (SdkGenerator::operations($this->spec()) as $op) {
            $this->assertStringStartsNotWith('/oauth/', $op['path']);
        }
    }

    #[Test]
    public function the_output_is_deterministic(): void
    {
        $spec = $this->spec();
        $this->assertSame(SdkGenerator::generate($spec), SdkGenerator::generate($spec));
    }

    #[Test]
    public function the_webhook_vector_in_every_sdk_matches_the_backend(): void
    {
        $v = WebhookSignature::describe()['test_vector'];
        $files = SdkGenerator::generate($this->spec());
        foreach (['python/tests/test_webhooks.py', 'js/test/webhooks.test.js', 'php/tests/run.php'] as $f) {
            $this->assertStringContainsString($v['signature'], $files[$f], $f);
            $this->assertStringContainsString((string) $v['timestamp'], $files[$f], $f);
        }
        $this->assertStringContainsString('DEFAULT_TOLERANCE = ' . WebhookSignature::DEFAULT_TOLERANCE, $files['python/uipe/webhooks.py']);
        $this->assertStringContainsString('DEFAULT_TOLERANCE = ' . WebhookSignature::DEFAULT_TOLERANCE, $files['js/src/webhooks.js']);
        $this->assertStringContainsString('DEFAULT_TOLERANCE = ' . WebhookSignature::DEFAULT_TOLERANCE, $files['php/src/Webhooks.php']);
    }

    #[Test]
    public function a_spec_without_operations_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        SdkGenerator::generate(['paths' => [], 'info' => [], 'servers' => []]);
    }

    #[Test]
    public function an_unknown_language_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SdkGenerator::generate($this->spec(), ['ruby']);
    }
}
