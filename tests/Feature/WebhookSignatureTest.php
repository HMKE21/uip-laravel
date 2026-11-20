<?php

namespace Tests\Feature;

use App\Services\Developer\DevOpenApi;
use App\Services\Developer\WebhookReplayGuard;
use App\Services\Developer\WebhookSamples;
use App\Services\Developer\WebhookService;
use App\Services\Developer\WebhookSignature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Replay Protection — اختبارات بدون DB: توقيع، نافذة الـ 5 دقايق، rotation، حارس إعادة الحدث، والتوثيق. */
class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_unit_test_secret';
    private const BODY = '{"id":"evt_1","type":"mail.message.received","data":{"subject":"مرحبا"}}';
    private const NOW = 1_800_000_000;

    private function header(int $t, string $body = self::BODY, string $secret = self::SECRET): string
    {
        return WebhookSignature::header($secret, $body, $t);
    }

    #[Test]
    public function a_fresh_valid_signature_passes(): void
    {
        $h = $this->header(self::NOW - 10);
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, $h, self::SECRET, 300, self::NOW));
        $r = WebhookSignature::inspect(self::BODY, $h, self::SECRET, 300, self::NOW);
        $this->assertNull($r['reason']);
        $this->assertSame(10, $r['age_seconds']);
    }

    #[Test]
    public function the_five_minute_window_is_inclusive_and_symmetric(): void
    {
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, $this->header(self::NOW - 300), self::SECRET, 300, self::NOW));
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, $this->header(self::NOW + 300), self::SECRET, 300, self::NOW));

        $old = WebhookSignature::inspect(self::BODY, $this->header(self::NOW - 301), self::SECRET, 300, self::NOW);
        $this->assertFalse($old['valid']);
        $this->assertSame('timestamp_too_old', $old['reason']);

        $future = WebhookSignature::inspect(self::BODY, $this->header(self::NOW + 301), self::SECRET, 300, self::NOW);
        $this->assertFalse($future['valid']);
        $this->assertSame('timestamp_in_future', $future['reason']);
    }

    #[Test]
    public function the_default_window_comes_from_config(): void
    {
        $this->assertSame(300, WebhookSignature::DEFAULT_TOLERANCE);
        config(['developer.webhooks.tolerance' => 60]);
        $this->assertSame(60, WebhookSignature::tolerance());
        $this->assertFalse(WebhookSignature::verifySignature(self::BODY, $this->header(self::NOW - 90), self::SECRET, null, self::NOW));
        config(['developer.webhooks.tolerance' => 0]);
        $this->assertSame(300, WebhookSignature::tolerance());
    }

    #[Test]
    public function a_re_signed_old_body_with_a_fresh_timestamp_is_the_only_way_in(): void
    {
        // المهاجم يغيّر t بس: التوقيع بيتغطي t فيفشل
        $p = WebhookSignature::parse($this->header(self::NOW - 1000));
        $forged = 't=' . self::NOW . ',v1=' . $p['v1'][0];
        $r = WebhookSignature::inspect(self::BODY, $forged, self::SECRET, 300, self::NOW);
        $this->assertSame('signature_mismatch', $r['reason']);
    }

    #[Test]
    public function tampering_wrong_secret_and_bad_headers_are_rejected(): void
    {
        $h = $this->header(self::NOW);
        $this->assertSame('signature_mismatch', WebhookSignature::inspect(self::BODY . ' ', $h, self::SECRET, 300, self::NOW)['reason']);
        $this->assertSame('signature_mismatch', WebhookSignature::inspect(self::BODY, $h, 'whsec_other', 300, self::NOW)['reason']);
        $this->assertSame('missing_header', WebhookSignature::inspect(self::BODY, null, self::SECRET, 300, self::NOW)['reason']);
        $this->assertSame('missing_header', WebhookSignature::inspect(self::BODY, '   ', self::SECRET, 300, self::NOW)['reason']);
        $this->assertSame('missing_secret', WebhookSignature::inspect(self::BODY, $h, '', 300, self::NOW)['reason']);
        foreach (['garbage', 't=abc,v1=' . str_repeat('a', 64), 't=1', 'v1=' . str_repeat('a', 64), 't=1,v1=short', 't=1,t=2,v1=' . str_repeat('a', 64), 't=-5,v1=' . str_repeat('a', 64), '=1', str_repeat('x', 2000)] as $bad) {
            $this->assertSame('malformed_header', WebhookSignature::inspect(self::BODY, $bad, self::SECRET, 300, self::NOW)['reason'], $bad);
        }
    }

    #[Test]
    public function signature_is_checked_before_time_so_a_bad_signature_never_reports_a_stale_timestamp(): void
    {
        $bad = 't=' . (self::NOW - 5000) . ',v1=' . str_repeat('0', 64);
        $this->assertSame('signature_mismatch', WebhookSignature::inspect(self::BODY, $bad, self::SECRET, 300, self::NOW)['reason']);
    }

    #[Test]
    public function rotation_accepts_old_or_new_secret_and_multiple_v1_values(): void
    {
        $t = self::NOW;
        $new = WebhookSignature::sign('whsec_new', $t, self::BODY);
        $old = WebhookSignature::sign('whsec_old', $t, self::BODY);

        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, "t={$t},v1={$old}", ['whsec_new', 'whsec_old'], 300, $t));
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, "t={$t},v1={$new}", ['whsec_new', 'whsec_old'], 300, $t));
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, "t={$t},v1={$old},v1={$new}", 'whsec_new', 300, $t));
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, "t={$t},v1=" . strtoupper($new), 'whsec_new', 300, $t), 'hex is case-insensitive');
        $this->assertFalse(WebhookSignature::verifySignature(self::BODY, "t={$t},v1={$old}", 'whsec_new', 300, $t));
        $this->assertTrue(WebhookSignature::verifySignature(self::BODY, "t={$t},v0=zzz,v1={$new}", 'whsec_new', 300, $t), 'unknown schemes are ignored');
    }

    #[Test]
    public function the_service_signs_with_the_same_algorithm_and_header_format(): void
    {
        $this->assertSame(hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_x'), app(WebhookService::class)->sign('whsec_x', 1700000000, '{"a":1}'));
        $this->assertMatchesRegularExpression('/^t=\d{10},v1=[0-9a-f]{64}$/', WebhookSignature::header('whsec_x', '{}'));
        $this->assertNotNull(WebhookSignature::parse('t=1700000000,v1=' . str_repeat('a', 64)));
    }

    #[Test]
    public function the_published_test_vector_is_stable(): void
    {
        $v = WebhookSignature::describe()['test_vector'];
        $this->assertSame('whsec_test_secret', $v['secret']);
        $this->assertSame(hash_hmac('sha256', '1700000000.' . $v['body'], $v['secret']), $v['signature']);
        $this->assertSame('t=1700000000,v1=' . $v['signature'], $v['header']);
        $this->assertTrue(WebhookSignature::verifySignature($v['body'], $v['header'], $v['secret'], 300, 1700000100));
        $this->assertSame(WebhookSignature::tolerance() * 2, WebhookSignature::describe()['dedupe_ttl_seconds']);
    }

    #[Test]
    public function the_replay_guard_accepts_an_event_id_once_per_scope(): void
    {
        $id = 'evt_' . bin2hex(random_bytes(6));
        $this->assertTrue(WebhookReplayGuard::firstSeen('verify:1', $id));
        $this->assertFalse(WebhookReplayGuard::firstSeen('verify:1', $id));
        $this->assertTrue(WebhookReplayGuard::firstSeen('verify:2', $id), 'another webhook has its own memory');
        $this->assertTrue(WebhookReplayGuard::firstSeen('verify:1', $id . 'x'));
    }

    #[Test]
    public function every_webhook_operation_carries_receiver_samples_and_the_window(): void
    {
        $spec = DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
        $this->assertNotEmpty($spec['webhooks']);
        foreach ($spec['webhooks'] as $name => $item) {
            $op = $item['post'];
            $langs = array_column($op['x-codeSamples'], 'lang');
            $this->assertEqualsCanonicalizing(['PHP', 'JavaScript', 'Python', 'Go'], $langs, $name);
            $this->assertStringContainsString('300 seconds', $op['description'], $name);
            $this->assertStringContainsString('X-UIPE-Event-Id', $op['description'], $name);
        }
        foreach (WebhookSamples::all() as $lang => $src) {
            $this->assertNotSame('', trim($src), $lang);
            $this->assertMatchesRegularExpression('/hash_equals|timingSafeEqual|compare_digest|hmac\.Equal/', $src, $lang . ' must compare in constant time');
            $this->assertMatchesRegularExpression('/300|5 \* time\.Minute/', $src, $lang . ' must enforce the window');
        }
    }
}
