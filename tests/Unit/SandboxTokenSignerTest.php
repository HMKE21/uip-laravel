<?php

namespace Tests\Unit;

use App\Support\SandboxTokenSigner;
use Tests\TestCase;

class SandboxTokenSignerTest extends TestCase
{
    private function cfg(string $url = 'https://sbx.example.com', int $ttl = 90): void
    {
        config(['services.sandbox.url' => $url, 'services.sandbox.secret' => str_repeat('k', 40), 'services.sandbox.token_ttl' => $ttl]);
    }

    private function claims(string $token): array
    {
        return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    public function test_not_configured_returns_null(): void
    {
        config(['services.sandbox.url' => '', 'services.sandbox.secret' => '']);
        $this->assertNull(SandboxTokenSigner::issue(1, 'terminal'));
    }

    public function test_plain_http_to_a_public_host_is_refused(): void
    {
        $this->cfg('http://sbx.example.com');
        $this->assertNull(SandboxTokenSigner::issue(1, 'terminal'));
        $this->cfg('http://localhost:8787');
        $this->assertNotNull(SandboxTokenSigner::issue(1, 'terminal'));
    }

    public function test_ttl_is_clamped_and_claims_are_correct(): void
    {
        $this->cfg('https://sbx.example.com', 3600);
        $t = SandboxTokenSigner::issue(7, 'drive-12');
        $this->assertSame(SandboxTokenSigner::MAX_TTL, $t['expires_in']);
        $c = $this->claims($t['token']);
        $this->assertSame('uip-sandbox', $c['aud']);
        $this->assertSame('7', $c['sub']);
        $this->assertSame('drive-12', $c['pid']);
        $this->assertLessThanOrEqual(time() + SandboxTokenSigner::MAX_TTL, $c['exp']);
        $this->assertGreaterThanOrEqual(32, strlen($c['jti']));
    }

    public function test_signature_verifies_and_jti_is_unique(): void
    {
        $this->cfg();
        $a = SandboxTokenSigner::issue(1, 'terminal')['token'];
        $b = SandboxTokenSigner::issue(1, 'terminal')['token'];
        [$h, $p, $s] = explode('.', $a);
        $want = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", str_repeat('k', 40), true)), '+/', '-_'), '=');
        $this->assertSame($want, $s);
        $this->assertNotSame($this->claims($a)['jti'], $this->claims($b)['jti']);
    }

    public function test_odd_session_keys_are_rejected(): void
    {
        $this->cfg();
        $this->expectException(\InvalidArgumentException::class);
        SandboxTokenSigner::issue(1, "../etc/passwd");
    }
}
