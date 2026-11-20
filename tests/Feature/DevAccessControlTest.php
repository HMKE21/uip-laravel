<?php

namespace Tests\Feature;

use App\Services\Developer\DevAccessService;
use App\Services\Developer\DevScopes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** تحكم المطورين — اختبارات بدون DB: مطابقة القواعد وقرار الوصول (DevAccessService::evaluate). */
class DevAccessControlTest extends TestCase
{
    private function pol(array $over = []): array
    {
        return array_replace(['university_id' => 1, 'level' => 'university', 'configured' => true], DevAccessService::defaults(), $over);
    }

    private function ctx(array $over = []): array
    {
        return array_replace([
            'enabled' => true, 'emails' => ['ali@cs.uni.edu.eg'], 'roles' => ['student'], 'university_id' => 1,
            'platform' => $this->pol(['university_id' => null, 'level' => 'platform']), 'uni' => $this->pol(), 'row' => null, 'rules' => [],
        ], $over);
    }

    private function rule(string $effect, string $kind, string $pattern, string $level = 'university', ?array $scopes = null, ?int $maxApps = null, int $id = 1): array
    {
        return ['id' => $id, 'kind' => $kind, 'pattern' => $pattern, 'effect' => $effect, 'scopes' => $scopes, 'max_apps' => $maxApps, 'level' => $level];
    }

    #[Test]
    public function rule_matching_for_emails_domains_and_roles(): void
    {
        $m = fn ($k, $p, $e = ['ali@cs.uni.edu.eg'], $r = ['student']) => DevAccessService::ruleMatches($k, $p, $e, $r);
        $this->assertTrue($m('email', 'ali@cs.uni.edu.eg'));
        $this->assertTrue($m('email', 'ALI@cs.uni.edu.eg'));
        $this->assertFalse($m('email', 'ali@uni.edu.eg'));
        $this->assertTrue($m('email', 'a*@cs.uni.edu.eg'));
        $this->assertFalse($m('email', 'b*@cs.uni.edu.eg'));
        $this->assertTrue($m('domain', 'cs.uni.edu.eg'));
        $this->assertFalse($m('domain', 'uni.edu.eg'));
        $this->assertTrue($m('domain', '*.uni.edu.eg'));
        $this->assertFalse($m('domain', '*.cs.uni.edu.eg'));      // النمط مش بيطابق الدومين نفسه
        $this->assertFalse($m('domain', '*.edu.eg', ['x@edu.eg']));
        $this->assertTrue($m('role', 'student'));
        $this->assertFalse($m('role', 'doctor'));
        $this->assertTrue($m('email', 'x@y.com', ['personal@gmail.com', 'x@y.com']));   // أي إيميل من إيميلات المستخدم
    }

    #[Test]
    public function open_policy_allows_everything_by_default(): void
    {
        $r = DevAccessService::evaluate($this->ctx());
        $this->assertTrue($r['allowed']);
        $this->assertSame('policy', $r['via']);
        $this->assertSame(DevScopes::names(), $r['scopes']);
        $this->assertTrue($r['webhooks_enabled']);
        $this->assertFalse($r['sandbox_only']);
    }

    #[Test]
    public function disabled_levels_deny_with_reason(): void
    {
        $this->assertSame('disabled', DevAccessService::evaluate($this->ctx(['enabled' => false]))['reason']);
        $this->assertSame('platform_disabled', DevAccessService::evaluate($this->ctx(['platform' => $this->pol(['apps_enabled' => false])]))['reason']);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $this->pol(['apps_enabled' => false])]));
        $this->assertFalse($r['allowed']);
        $this->assertSame('tenant_disabled', $r['reason']);
        $this->assertSame([], $r['scopes']);
    }

    #[Test]
    public function allowed_roles_gate_and_manual_allow_bypass(): void
    {
        $uni = $this->pol(['allowed_roles' => ['doctor', 'academic_staff']]);
        $this->assertSame('role_not_allowed', DevAccessService::evaluate($this->ctx(['uni' => $uni]))['reason']);
        $this->assertTrue(DevAccessService::evaluate($this->ctx(['uni' => $uni, 'roles' => ['doctor']]))['allowed']);
        $row = ['status' => 'allowed', 'set_by' => 'university', 'scopes' => null];
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'row' => $row]));
        $this->assertTrue($r['allowed']);
        $this->assertSame('override', $r['via']);
        // دور ممنوع على مستوى المنصة: سماح الجامعة مبيتخطاهوش، سماح أدمن المنصة بيتخطاه
        $plat = $this->pol(['university_id' => null, 'level' => 'platform', 'allowed_roles' => ['doctor']]);
        $this->assertSame('role_not_allowed', DevAccessService::evaluate($this->ctx(['platform' => $plat, 'row' => $row]))['reason']);
        $this->assertTrue(DevAccessService::evaluate($this->ctx(['platform' => $plat, 'row' => ['set_by' => 'admin'] + $row]))['allowed']);
    }

    #[Test]
    public function deny_rules_and_manual_block(): void
    {
        $deny = $this->rule('deny', 'domain', '*.uni.edu.eg');
        $this->assertSame('rule_denied', DevAccessService::evaluate($this->ctx(['rules' => [$deny]]))['reason']);
        // قاعدة منع الجامعة بيتخطاها السماح اليدوي، لكن قاعدة منع المنصة لأ
        $row = ['status' => 'allowed', 'set_by' => 'university', 'scopes' => null];
        $this->assertTrue(DevAccessService::evaluate($this->ctx(['rules' => [$deny], 'row' => $row]))['allowed']);
        $platDeny = $this->rule('deny', 'role', 'student', 'platform');
        $this->assertSame('rule_denied', DevAccessService::evaluate($this->ctx(['rules' => [$platDeny], 'row' => $row]))['reason']);
        $this->assertSame('user_blocked', DevAccessService::evaluate($this->ctx(['row' => ['status' => 'blocked', 'set_by' => 'university']]))['reason']);
    }

    #[Test]
    public function closed_mode_needs_an_allow_rule_and_rules_can_set_scopes(): void
    {
        $uni = $this->pol(['default_access' => 'closed', 'allowed_scopes' => ['mail.read']]);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni]));
        $this->assertSame('not_allowlisted', $r['reason']);
        $this->assertFalse($r['can_request']);

        $allow = $this->rule('allow', 'domain', 'cs.uni.edu.eg', 'university', ['mail.read', 'mail.send'], 3);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'rules' => [$allow]]));
        $this->assertTrue($r['allowed']);
        $this->assertSame('rule', $r['via']);
        $this->assertSame(['mail.read', 'mail.send'], $r['scopes']);   // قاعدة السماح بتتفوّق على scopes السياسة
        $this->assertSame(3, $r['limits']['apps']);

        // سقف المنصة بيقصّ الـ scopes مهما كانت القاعدة
        $plat = $this->pol(['university_id' => null, 'level' => 'platform', 'allowed_scopes' => ['mail.read']]);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'rules' => [$allow], 'platform' => $plat]));
        $this->assertSame(['mail.read'], $r['scopes']);

        // استثناء المطوّر نفسه بيتفوّق على القاعدة (وبرضه تحت سقف المنصة)
        $row = ['status' => 'allowed', 'set_by' => 'university', 'scopes' => json_encode(['mail.send', 'mail.read'])];
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'rules' => [$allow], 'row' => $row]));
        $this->assertSame(['mail.read', 'mail.send'], $r['scopes']);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'rules' => [$allow], 'row' => $row, 'platform' => $plat]));
        $this->assertSame(['mail.read'], $r['scopes']);
    }

    #[Test]
    public function approval_flow_statuses(): void
    {
        $uni = $this->pol(['require_approval' => true]);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni]));
        $this->assertSame('approval_required', $r['reason']);
        $this->assertTrue($r['can_request']);
        $pending = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'row' => ['status' => 'pending', 'set_by' => 'user']]));
        $this->assertSame('pending_approval', $pending['reason']);
        $this->assertFalse($pending['can_request']);
        $rej = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'row' => ['status' => 'rejected', 'set_by' => 'university']]));
        $this->assertSame('request_rejected', $rej['reason']);
        $this->assertTrue($rej['can_request']);
        $ok = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'row' => ['status' => 'allowed', 'set_by' => 'university', 'scopes' => null]]));
        $this->assertTrue($ok['allowed']);
        // قاعدة سماح بتغني عن الموافقة
        $this->assertTrue(DevAccessService::evaluate($this->ctx(['uni' => $uni, 'rules' => [$this->rule('allow', 'role', 'student')]]))['allowed']);
        // لو الموافقة اتلغت، طلب قديم pending مبيمنعش حد
        $this->assertTrue(DevAccessService::evaluate($this->ctx(['row' => ['status' => 'pending', 'set_by' => 'user']]))['allowed']);
    }

    #[Test]
    public function limits_flags_and_platform_caps(): void
    {
        $uni = $this->pol(['max_apps_per_user' => 5, 'max_keys_per_user' => 8, 'rate_limit_per_min' => 120, 'webhooks_enabled' => true, 'sandbox_only' => false]);
        $plat = $this->pol(['university_id' => null, 'level' => 'platform', 'max_apps_per_user' => 4, 'rate_limit_per_min' => 100]);
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'platform' => $plat]));
        $this->assertSame(4, $r['limits']['apps']);            // السقف أقل
        $this->assertSame(8, $r['limits']['keys']);
        $this->assertSame(100, $r['limits']['rate_per_min']);

        $row = ['status' => 'allowed', 'set_by' => 'university', 'scopes' => null, 'max_apps' => 2, 'webhooks_enabled' => 0, 'sandbox_only' => 1, 'rate_limit_per_min' => 50];
        $r = DevAccessService::evaluate($this->ctx(['uni' => $uni, 'row' => $row]));
        $this->assertSame(2, $r['limits']['apps']);
        $this->assertFalse($r['webhooks_enabled']);
        $this->assertTrue($r['sandbox_only']);
        $this->assertSame(50, $r['limits']['rate_per_min']);

        $r = DevAccessService::evaluate($this->ctx(['uni' => $this->pol(['allowed_events' => ['mail.message.received', 'mail.message.sent']]),
            'platform' => $this->pol(['university_id' => null, 'level' => 'platform', 'allowed_events' => ['mail.message.received']])]));
        $this->assertSame(['mail.message.received'], $r['events']);
        $this->assertTrue(DevAccessService::evaluate($this->ctx(['platform' => $this->pol(['university_id' => null, 'level' => 'platform', 'sandbox_only' => true])]))['sandbox_only']);
    }

    #[Test]
    public function user_without_a_university_only_follows_the_platform_policy(): void
    {
        $plat = $this->pol(['university_id' => null, 'level' => 'platform', 'allowed_scopes' => ['profile.read']]);
        $r = DevAccessService::evaluate($this->ctx(['uni' => null, 'university_id' => null, 'platform' => $plat]));
        $this->assertTrue($r['allowed']);
        $this->assertSame(['profile.read'], $r['scopes']);
    }
}
