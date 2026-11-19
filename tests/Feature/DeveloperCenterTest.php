<?php

namespace Tests\Feature;

use App\Services\Developer\DevScopes;
use App\Services\Developer\DevTokens;
use App\Services\Developer\DeveloperAuthService;
use App\Services\Developer\WebhookService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** المرحلة 6 — اختبارات بدون DB: Scopes، حماية SSRF، توقيع الـ Webhook، IP allowlist، توليد/تجزئة التوكنز. */
class DeveloperCenterTest extends TestCase
{
    #[Test]
    public function scopes_parse_and_validate(): void
    {
        $this->assertSame(['mail.read', 'mail.send'], DevScopes::parse('mail.read  mail.send,mail.read'));
        $this->assertSame(['nope'], DevScopes::unknown(['mail.read', 'nope']));
        $this->assertTrue(DevScopes::has(['mail.read'], 'mail.read'));
        $this->assertFalse(DevScopes::has(['mail.read'], 'mail.send'));
    }

    #[Test]
    public function ssrf_blocks_private_and_special_ranges(): void
    {
        $w = app(WebhookService::class);
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.1', '169.254.169.254', '100.64.0.1', '198.18.0.1', '::1', '::ffff:127.0.0.1', 'fd00::1'] as $ip) {
            $this->assertFalse($w->publicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue($w->publicIp($ip), $ip);
        }
    }

    #[Test]
    public function webhook_signature_is_hmac_of_timestamp_and_body(): void
    {
        $w = app(WebhookService::class);
        $this->assertSame(hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_x'), $w->sign('whsec_x', 1700000000, '{"a":1}'));
    }

    #[Test]
    public function ip_allowlist_matches_ip_and_cidr(): void
    {
        $a = app(DeveloperAuthService::class);
        $this->assertTrue($a->ipAllowed('203.0.113.7', ['203.0.113.0/24']));
        $this->assertFalse($a->ipAllowed('203.0.114.7', ['203.0.113.0/24']));
        $this->assertTrue($a->ipAllowed('198.51.100.9', ['198.51.100.9']));
        $this->assertTrue($a->ipAllowed('2001:db8::5', ['2001:db8::/32']));
        $this->assertFalse($a->ipAllowed('not-an-ip', ['0.0.0.0/0']));
    }

    #[Test]
    public function tokens_have_prefix_and_are_hashed(): void
    {
        $t = DevTokens::make('uipk_', 40);
        $this->assertStringStartsWith('uipk_', $t);
        $this->assertNotSame($t, DevTokens::hash($t));
        $this->assertSame(DevTokens::hash($t), DevTokens::hash($t));
        $this->assertNotSame($t, DevTokens::make('uipk_', 40));
    }
}
