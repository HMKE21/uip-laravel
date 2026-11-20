<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MailWebhookController;
use App\Http\Middleware\UipMailIpMiddleware;
use App\Models\Mail\MailAccount;
use App\Services\Mail\MailAuditService;
use App\Services\Mail\MailBounceService;
use App\Services\Mail\MailboxService;
use App\Services\Mail\MailQueueService;
use App\Services\Mail\MailRetentionService;
use App\Services\Mail\MailSecurityService;
use App\Services\Mail\MailTenantService;
use App\Support\IpMatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** UIPE Mail المرحلة 7: IP rules، حدود الإرسال، suppression/bounces، retention، legal hold، audit، queue، webhook. */
class MailSecurityTest extends TestCase
{
    private MailSecurityService $sec;
    private MailboxService $mail;
    private MailTenantService $tenants;
    private int $uni;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('full_name'); $t->string('email')->nullable(); $t->string('status')->default('active');
            $t->dateTime('deleted_at')->nullable(); // User يستخدم SoftDeletes
        });
        Schema::create('universities', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('official_name_ar'); $t->string('official_name_en');
            $t->string('email_domain')->nullable(); $t->string('email_pattern')->default('number');
        });
        foreach (['2027_11_19_200000_create_mail_system_tables', '2027_11_20_100000_create_mail_advanced_tables', '2027_11_21_100000_create_mail_tenants',
            '2027_11_22_100000_create_identity_provisioning', '2027_11_24_100000_create_mail_security_admin'] as $m) {
            (include base_path("database/migrations/{$m}.php"))->up();
        }
        Cache::flush();
        $this->sec = app(MailSecurityService::class);
        $this->mail = app(MailboxService::class);
        $this->tenants = app(MailTenantService::class);
        $owner = $this->user('Uni Owner');
        $this->uni = DB::table('universities')->insertGetId(['user_id' => $owner, 'official_name_ar' => 'جامعة', 'official_name_en' => 'Test University']);
    }

    private function user(string $name): int
    {
        return DB::table('users')->insertGetId(['full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@gmail.com']);
    }

    private function personal(string $name): MailAccount
    {
        return $this->mail->accountFor($this->user($name));
    }

    /** صندوق مؤسسي تابع لـ tenant الجامعة (من غير دومين: للاختبارات اللي مش بتبعت). */
    private function institutional(string $name): MailAccount
    {
        $t = $this->tenants->tenantByUniversity($this->uni);
        $u = $this->user($name);
        return MailAccount::create(['user_id' => $u, 'address' => strtolower(str_replace(' ', '.', $name)) . '@test.edu.eg', 'kind' => 'institutional',
            'university_id' => $this->uni, 'tenant_id' => $t->id, 'display_name' => $name, 'is_default' => true, 'status' => 'active']);
    }

    private function noUndo(MailAccount ...$accs): void
    {
        foreach ($accs as $a) {
            DB::table('mail_settings')->updateOrInsert(['account_id' => $a->id], ['undo_seconds' => 0]);
        }
    }

    private function fails(callable $fn, ?int $code = null): string
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            if ($code) {
                $this->assertSame($code, $e->getCode());
            }
            return $e->getMessage();
        }
        $this->fail('Expected DomainException');
    }

    /** رسالة + صندوق (item) جاهزين في فولدر معيّن بتاريخ قديم. */
    private function item(MailAccount $a, string $folder, int $daysOld): int
    {
        $tid = DB::table('mail_threads')->insertGetId(['subject_norm' => 's', 'created_at' => now(), 'updated_at' => now()]);
        $mid = DB::table('mail_messages')->insertGetId(['thread_id' => $tid, 'sender_account_id' => $a->id, 'subject' => 's', 'sent_at' => now()->subDays($daysOld), 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('mail_items')->insertGetId(['account_id' => $a->id, 'message_id' => $mid, 'folder' => $folder, 'sort_at' => now()->subDays($daysOld),
            'trashed_at' => $folder === 'trash' ? now()->subDays($daysOld) : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function message(MailAccount $a): int
    {
        $tid = DB::table('mail_threads')->insertGetId(['subject_norm' => 's', 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('mail_messages')->insertGetId(['thread_id' => $tid, 'sender_account_id' => $a->id, 'subject' => 's', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ IpMatcher */

    #[Test]
    public function ip_matcher_handles_v4_v6_and_cidr(): void
    {
        $this->assertTrue(IpMatcher::matches('10.1.2.3', '10.1.2.3'));
        $this->assertTrue(IpMatcher::matches('10.1.2.200', '10.1.2.0/24'));
        $this->assertFalse(IpMatcher::matches('10.1.3.1', '10.1.2.0/24'));
        $this->assertTrue(IpMatcher::matches('192.168.5.9', '192.168.4.0/22'));
        $this->assertTrue(IpMatcher::matches('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(IpMatcher::matches('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue(IpMatcher::matches('::ffff:10.0.0.1', '10.0.0.1'));   // IPv4-mapped
        $this->assertFalse(IpMatcher::matches('10.0.0.1', '2001:db8::/32'));     // عائلات مختلفة
        $this->assertFalse(IpMatcher::valid('10.0.0.0/33'));
        $this->assertFalse(IpMatcher::valid('999.1.1.1'));
        $this->assertFalse(IpMatcher::valid('10.0.0.0/abc'));
        $this->assertSame('10.0.0.0/8', IpMatcher::canonical('10.0.0.0/8'));
        $this->assertSame('2001:db8::1', IpMatcher::canonical('2001:0db8:0000:0000:0000:0000:0000:0001'));
    }

    /* ------------------------------------------------------------------ IP rules + middleware */

    #[Test]
    public function platform_block_applies_everywhere_and_university_allow_list_only_to_its_mailboxes(): void
    {
        $this->sec->addRule(null, '203.0.113.0/24', 'block', 'abuse', null, 1, '198.51.100.1');
        $this->assertSame('blocked', $this->sec->ipBlockReason('203.0.113.9', null));
        $this->assertSame('blocked', $this->sec->ipBlockReason('203.0.113.9', $this->uni));
        $this->assertNull($this->sec->ipBlockReason('198.51.100.7', $this->uni));

        $this->sec->addRule($this->uni, '198.51.100.0/24', 'allow', 'campus', null, 1, '198.51.100.1');
        $this->assertNull($this->sec->ipBlockReason('198.51.100.7', $this->uni));
        $this->assertSame('not_allowed', $this->sec->ipBlockReason('8.8.8.8', $this->uni));
        $this->assertNull($this->sec->ipBlockReason('8.8.8.8', null)); // الصندوق الشخصي مش متأثر بـ allow الجامعة
        $this->assertNull($this->sec->ipBlockReason('8.8.8.8', 9999)); // جامعة تانية

        $rules = $this->sec->rules($this->uni);
        $this->sec->removeRule($this->uni, $rules[0]['id']);
        $this->assertNull($this->sec->ipBlockReason('8.8.8.8', $this->uni)); // آخر allow اتشال = رجوع للوضع المفتوح
    }

    #[Test]
    public function admins_cannot_lock_themselves_out_and_rules_are_validated(): void
    {
        $this->fails(fn () => $this->sec->addRule($this->uni, '198.51.100.0/24', 'block', null, null, 1, '198.51.100.9'));            // يحظر IP الأدمن نفسه
        $this->fails(fn () => $this->sec->addRule($this->uni, '203.0.113.0/24', 'allow', null, null, 1, '198.51.100.9'));            // أول allow من غير IP الأدمن
        $this->fails(fn () => $this->sec->addRule(null, '203.0.113.0/24', 'allow', null, null, 1, null));                              // المنصة block بس
        $this->fails(fn () => $this->sec->addRule($this->uni, 'not-an-ip', 'block', null, null, 1, null));
        $this->fails(fn () => $this->sec->addRule($this->uni, '203.0.113.5', 'block', null, now()->subDay()->toDateTimeString(), 1, null)); // انتهاء في الماضي
        $this->sec->addRule($this->uni, '203.0.113.5', 'block', null, null, 1, '198.51.100.9');
        $this->fails(fn () => $this->sec->addRule($this->uni, '203.0.113.5', 'block', null, null, 1, '198.51.100.9'), 409);              // مكررة
    }

    #[Test]
    public function expired_rules_are_ignored_and_pruned(): void
    {
        DB::table('mail_ip_rules')->insert(['university_id' => 0, 'cidr' => '203.0.113.5', 'action' => 'block', 'expires_at' => now()->subMinute(), 'created_at' => now()]);
        $this->assertNull($this->sec->ipBlockReason('203.0.113.5', null));
        $this->assertSame(1, $this->sec->pruneExpiredRules());
        $this->assertFalse($this->sec->hasRules());
    }

    #[Test]
    public function middleware_blocks_by_the_mailbox_in_use_and_records_one_event(): void
    {
        $inst = $this->institutional('Student One');
        $this->sec->addRule($this->uni, '198.51.100.0/24', 'allow', null, null, 1, '198.51.100.1');
        $mw = app(UipMailIpMiddleware::class);
        $call = function (string $ip, MailAccount $acc, ?int $header = null) use ($mw) {
            $req = Request::create('/api/v1/mail/account', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
            $req->attributes->set('uip_user_id', $acc->user_id);
            if ($header) {
                $req->headers->set('X-Mail-Account', (string) $header);
            }
            return $mw->handle($req, fn () => response('ok'));
        };
        $this->assertSame(200, $call('198.51.100.20', $inst)->getStatusCode());
        $blocked = $call('8.8.8.8', $inst);
        $this->assertSame(403, $blocked->getStatusCode());
        $this->assertSame('mail_ip_blocked', json_decode($blocked->getContent(), true)['errors']['code']);
        $call('8.8.8.8', $inst);
        $this->assertSame(1, DB::table('mail_security_events')->where('type', 'ip_blocked')->count()); // بيتدمج (dedupe)

        // نفس المستخدم بصندوقه الشخصي من نفس الـ IP: مسموح (قواعد الجامعة مش بتتطبّق)
        $personal = MailAccount::create(['user_id' => $inst->user_id, 'address' => 'p@uipe.local', 'kind' => 'personal', 'is_default' => false, 'status' => 'active']);
        $this->assertSame(200, $call('8.8.8.8', $inst, (int) $personal->id)->getStatusCode());
    }

    /* ------------------------------------------------------------------ send limits */

    #[Test]
    public function hourly_send_limit_returns_429_and_records_an_event(): void
    {
        config(['mailbox.security.max_per_hour' => 2]);
        $a = $this->personal('Sender One');
        $b = $this->personal('Receiver One');
        $this->noUndo($a);
        $this->mail->send($a, ['to' => [$b->address], 'subject' => '1', 'body' => 'x']);
        $this->mail->send($a, ['to' => [$b->address], 'subject' => '2', 'body' => 'x']);
        $this->fails(fn () => $this->mail->send($a, ['to' => [$b->address], 'subject' => '3', 'body' => 'x']), 429);
        $ev = DB::table('mail_security_events')->where('type', 'send_limit')->first();
        $this->assertNotNull($ev);
        $this->assertSame($a->id, (int) $ev->account_id);
        $this->assertSame('open', $ev->status);
    }

    #[Test]
    public function repeated_violations_auto_suspend_only_when_the_university_enabled_it(): void
    {
        config(['mailbox.security.max_per_hour' => 1, 'mailbox.security.suspend_after_violations' => 3]);
        $a = $this->institutional('Heavy Sender');
        $t = $this->tenants->tenantByUniversity($this->uni);
        // سباق: نسجّل رسالة "اتبعتت" فعلًا عشان الحد يتعدّى
        $mid = $this->message($a);
        DB::table('mail_messages')->where('id', $mid)->update(['is_draft' => 0, 'sent_at' => now()]);

        for ($i = 0; $i < 4; $i++) {
            $this->fails(fn () => $this->sec->assertMaySend($a, 1, 0), 429);
        }
        $this->assertSame('active', DB::table('mail_accounts')->where('id', $a->id)->value('status')); // مش مفعّل

        $this->sec->updatePolicy((int) $t->id, ['sec_auto_suspend' => true]);
        $this->fails(fn () => $this->sec->assertMaySend($a, 1, 0), 429); // المخالفة 5 ≥ 3
        $row = DB::table('mail_accounts')->where('id', $a->id)->first();
        $this->assertSame('suspended', $row->status);
        $this->assertSame('security', $row->suspended_by);
        $this->assertSame(1, DB::table('mail_security_events')->where('type', 'auto_suspended')->count());
        $this->assertSame(1, DB::table('mail_audit_logs')->where('action', 'mail.account.auto_suspended')->count());

        $this->fails(fn () => $this->sec->reinstate($this->uni + 1, $a->id), 404);   // جامعة تانية
        $this->sec->reinstate($this->uni, $a->id);
        $this->assertSame('active', DB::table('mail_accounts')->where('id', $a->id)->value('status'));
        $this->fails(fn () => $this->sec->reinstate($this->uni, $a->id));              // مش موقوف بالأمان
    }

    #[Test]
    public function university_limits_override_the_defaults(): void
    {
        $t = $this->tenants->tenantByUniversity($this->uni);
        $this->assertSame(200, $this->sec->limitsFor($t)['per_hour']);
        $this->sec->updatePolicy((int) $t->id, ['sec_max_per_hour' => 20]);
        $this->assertSame(20, $this->sec->limitsFor(DB::table('mail_tenants')->where('id', $t->id)->first())['per_hour']);
    }

    /* ------------------------------------------------------------------ policy validation */

    #[Test]
    public function policy_updates_are_validated_and_report_changes(): void
    {
        $t = $this->tenants->tenantByUniversity($this->uni);
        [$p, $changes] = $this->sec->updatePolicy((int) $t->id, ['retention_spam_days' => 30, 'retention_all_days' => 365, 'sec_auto_suspend' => true]);
        $this->assertSame(30, $p['retention_spam_days']);
        $this->assertTrue($p['sec_auto_suspend']);
        $this->assertEqualsCanonicalizing(['retention_spam_days', 'retention_all_days', 'sec_auto_suspend'], array_keys($changes));
        $this->fails(fn () => $this->sec->updatePolicy((int) $t->id, ['retention_all_days' => 5]));      // أقل من 30 يوم = خطر
        $this->fails(fn () => $this->sec->updatePolicy((int) $t->id, ['sec_max_per_hour' => 0]));
        [$p2, $none] = $this->sec->updatePolicy((int) $t->id, ['retention_spam_days' => 30]);
        $this->assertSame([], $none);                                                                    // نفس القيمة = مفيش تغيير
        [$p3] = $this->sec->updatePolicy((int) $t->id, ['retention_spam_days' => null]);
        $this->assertNull($p3['retention_spam_days']);
        $this->fails(fn () => $this->sec->updatePolicy(999999, ['retention_spam_days' => 10]), 404);
    }

    /* ------------------------------------------------------------------ bounces / suppression / webhook */

    #[Test]
    public function hard_bounce_is_recorded_deduped_and_suppresses_the_address(): void
    {
        $a = $this->institutional('Bounce Sender');
        $mid = $this->message($a);
        DB::table('mail_external_deliveries')->insert(['message_id' => $mid, 'sender_account_id' => $a->id, 'university_id' => $this->uni, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $svc = app(MailBounceService::class);

        $payload = ['event' => 'hard_bounce', 'email' => 'Gone@Example.com', 'id' => 'evt-1', 'tags' => ['uipe-m' . $mid], 'reason' => 'mailbox does not exist'];
        $this->assertSame('recorded', $svc->handle($payload));
        $this->assertSame('duplicate', $svc->handle($payload));
        $this->assertSame('ignored', $svc->handle(['event' => 'hard_bounce', 'email' => 'x@example.com', 'tags' => ['other-tag']]));   // مش من عندنا
        $this->assertSame('ignored', $svc->handle(['event' => 'opened', 'email' => 'x@example.com', 'tags' => ['uipe-m' . $mid]]));

        $this->assertSame(1, DB::table('mail_bounces')->count());
        $this->assertSame('hard', DB::table('mail_bounces')->value('type'));
        $this->assertSame('bounced', DB::table('mail_external_deliveries')->where('message_id', $mid)->value('status'));
        $this->assertSame(['gone@example.com'], $this->sec->suppressedAmong($a, ['GONE@example.com', 'ok@example.com']));
        $this->assertSame(1, DB::table('mail_audit_logs')->where('action', 'mail.bounce.hard')->count());
    }

    #[Test]
    public function brevo_tag_can_arrive_as_a_json_string_and_delivered_updates_status(): void
    {
        $a = $this->personal('Tag Sender');
        $mid = $this->message($a);
        DB::table('mail_external_deliveries')->insert(['message_id' => $mid, 'sender_account_id' => $a->id, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($mid, MailBounceService::messageIdFromTags('["uipe-m' . $mid . '"]'));
        $this->assertNull(MailBounceService::messageIdFromTags(['xuipe-m5', 'uipe-m5x']));
        app(MailBounceService::class)->handle(['event' => 'delivered', 'email' => 'a@example.com', 'tag' => '["uipe-m' . $mid . '"]']);
        $this->assertSame('delivered', DB::table('mail_external_deliveries')->where('message_id', $mid)->value('status'));
    }

    #[Test]
    public function soft_bounce_is_recorded_without_suppression(): void
    {
        $a = $this->personal('Soft Sender');
        $mid = $this->message($a);
        app(MailBounceService::class)->handle(['event' => 'soft_bounce', 'email' => 'full@example.com', 'id' => 's1', 'tags' => ['uipe-m' . $mid]]);
        $this->assertSame(1, DB::table('mail_bounces')->where('type', 'soft')->count());
        $this->assertSame(0, DB::table('mail_suppressions')->count());
    }

    #[Test]
    public function webhook_requires_the_secret_and_accepts_batches(): void
    {
        $a = $this->personal('Hook Sender');
        $mid = $this->message($a);
        $ctl = app(MailWebhookController::class);
        $svc = app(MailBounceService::class);
        $req = fn (array $body, array $server = [], string $qs = '') => Request::create('/api/v1/mail/webhooks/brevo' . $qs, 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        $events = [['event' => 'hard_bounce', 'email' => 'a@example.com', 'id' => 'h1', 'tags' => ['uipe-m' . $mid]], ['event' => 'blocked', 'email' => 'b@example.com', 'id' => 'h2', 'tags' => ['uipe-m' . $mid]]];

        config(['mailbox.webhook_secret' => '']);
        $this->assertSame(404, $ctl->brevo($req($events), $svc)->getStatusCode());          // مقفول لو مفيش secret
        config(['mailbox.webhook_secret' => 's3cret-token']);
        $this->assertSame(401, $ctl->brevo($req($events), $svc)->getStatusCode());
        $this->assertSame(401, $ctl->brevo($req($events, ['HTTP_X_WEBHOOK_TOKEN' => 'wrong']), $svc)->getStatusCode());
        $this->assertSame(0, DB::table('mail_bounces')->count());

        $ok = $ctl->brevo($req($events, ['HTTP_X_WEBHOOK_TOKEN' => 's3cret-token']), $svc);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(2, json_decode($ok->getContent(), true)['data']['recorded']);
        $this->assertSame(200, $ctl->brevo($req($events[0], [], '?token=s3cret-token'), $svc)->getStatusCode());                   // ?token= + حدث واحد (duplicate)
        $this->assertSame(200, $ctl->brevo($req($events[0], ['HTTP_AUTHORIZATION' => 'Bearer s3cret-token']), $svc)->getStatusCode()); // Bearer
        $this->assertSame(2, DB::table('mail_bounces')->count());
    }

    #[Test]
    public function suppression_list_is_scoped_per_university(): void
    {
        $this->sec->addSuppression($this->uni, 'Block.Me@Example.com', 'manual', 'note', 1);
        $this->sec->addSuppression(null, 'platform@example.com');
        $this->fails(fn () => $this->sec->addSuppression($this->uni, 'not-an-email'));
        $inst = $this->institutional('Supp Sender');
        $pers = $this->personal('Personal Sender');
        $this->assertEqualsCanonicalizing(['block.me@example.com', 'platform@example.com'], $this->sec->suppressedAmong($inst, ['block.me@example.com', 'platform@example.com']));
        $this->assertSame(['platform@example.com'], $this->sec->suppressedAmong($pers, ['block.me@example.com', 'platform@example.com']));
        $list = $this->sec->suppressions($this->uni, null);
        $this->assertSame(1, $list['total']);
        $this->sec->removeSuppression($this->uni, $list['items'][0]['id']);
        $this->fails(fn () => $this->sec->removeSuppression($this->uni, $list['items'][0]['id']), 404);
    }

    /* ------------------------------------------------------------------ legal hold + retention */

    #[Test]
    public function legal_hold_blocks_permanent_deletion_until_lifted(): void
    {
        $a = $this->personal('Held User');
        $this->item($a, 'trash', 1);
        $this->sec->setLegalHold(null, $a->id, true, 'Case 123');
        $held = MailAccount::find($a->id);
        $this->fails(fn () => $this->mail->emptyTrash($held), 423);
        $this->assertSame(1, DB::table('mail_items')->where('account_id', $a->id)->count());
        $this->assertCount(1, $this->sec->flaggedAccounts(null));
        $this->sec->setLegalHold(null, $a->id, false, null);
        $this->assertSame(1, $this->mail->emptyTrash(MailAccount::find($a->id)));
        $this->assertSame(1, DB::table('mail_audit_logs')->where('action', 'mail.empty_trash')->count());
    }

    #[Test]
    public function retention_policy_purges_old_mail_but_never_drafts_or_held_mailboxes(): void
    {
        $t = $this->tenants->tenantByUniversity($this->uni);
        $a = $this->institutional('Retained One');
        $held = $this->institutional('Held Two');
        $this->sec->setLegalHold($this->uni, $held->id, true, 'Investigation');
        $this->sec->updatePolicy((int) $t->id, ['retention_spam_days' => 10, 'retention_all_days' => 100]);

        $oldSpam = $this->item($a, 'spam', 20);
        $newSpam = $this->item($a, 'spam', 2);
        $oldInbox = $this->item($a, 'inbox', 200);
        $newInbox = $this->item($a, 'inbox', 50);
        $oldDraft = $this->item($a, 'drafts', 400);
        $heldSpam = $this->item($held, 'spam', 20);
        $heldInbox = $this->item($held, 'inbox', 400);

        $ret = app(MailRetentionService::class);
        $dry = $ret->run(true);
        $this->assertSame(1, $dry['spam']);
        $this->assertSame(7, DB::table('mail_items')->count()); // dry = ما مسحش حاجة

        $r = $ret->run();
        $this->assertSame(['spam' => 1, 'sent' => 0, 'all' => 1], $r);
        $left = DB::table('mail_items')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$newSpam, $newInbox, $oldDraft, $heldSpam, $heldInbox], $left);
        $this->assertSame(1, DB::table('mail_audit_logs')->where('action', 'mail.retention.purged')->count());
    }

    #[Test]
    public function trash_uses_the_university_retention_days(): void
    {
        $t = $this->tenants->tenantByUniversity($this->uni);
        $inst = $this->institutional('Trash Inst');
        $pers = $this->personal('Trash Pers');
        $this->sec->updatePolicy((int) $t->id, ['retention_trash_days' => 5]);
        $i1 = $this->item($inst, 'trash', 6);   // أقدم من 5 → يتمسح
        $i2 = $this->item($pers, 'trash', 6);   // شخصي: الافتراضي 30 → يفضل
        $i3 = $this->item($pers, 'trash', 31);  // يتمسح
        $this->assertSame(2, $this->mail->purgeOldTrash());
        $this->assertSame([$i2], DB::table('mail_items')->pluck('id')->all());
    }

    #[Test]
    public function operational_logs_are_pruned(): void
    {
        $a = $this->personal('Ops User');
        DB::table('mail_security_events')->insert([
            ['type' => 'send_limit', 'status' => 'resolved', 'created_at' => now()->subDays(200)],
            ['type' => 'send_limit', 'status' => 'open', 'created_at' => now()->subDays(200)],
        ]);
        DB::table('mail_bounces')->insert(['university_id' => 0, 'recipient' => 'x@example.com', 'type' => 'soft', 'provider' => 'brevo', 'provider_event_id' => 'old', 'created_at' => now()->subDays(400)]);
        $r = app(MailRetentionService::class)->pruneOperational();
        $this->assertSame(['events' => 1, 'deliveries' => 0, 'bounces' => 1], $r);
        $this->assertSame(1, DB::table('mail_security_events')->where('status', 'open')->count()); // المفتوح مبيتمسحش
    }

    /* ------------------------------------------------------------------ audit */

    #[Test]
    public function audit_log_filters_and_scopes_by_university(): void
    {
        $audit = app(MailAuditService::class);
        $a = $this->institutional('Audited User');
        $audit->record('mail.domain.added', 'Domain added', ['university_id' => $this->uni, 'actor_user_id' => 5, 'actor_role' => 'university', 'ip' => '10.0.0.1']);
        $audit->record('mail.policy.updated', 'Policy', ['university_id' => $this->uni, 'account_id' => $a->id]);
        $audit->record('mail.domain.added', 'Other uni', ['university_id' => $this->uni + 1]);

        $this->assertSame(2, $audit->list($this->uni, [])['total']);
        $this->assertSame(3, $audit->list(null, [])['total']);
        $this->assertSame(1, $audit->list($this->uni, ['action' => 'mail.domain'])['total']);
        $this->assertSame(1, $audit->list($this->uni, ['account_id' => $a->id])['total']);
        $this->assertSame(1, $audit->list(null, ['q' => 'Other'])['total']);
        $this->assertSame(0, $audit->list($this->uni, ['from' => now()->addDay()->toDateString()])['total']);
        $first = $audit->list($this->uni, [])['items'][0];
        $this->assertSame('mail.policy.updated', $first['action']);                  // الأحدث الأول
        $this->assertSame($a->address, $first['account']['address']);

        DB::table('mail_audit_logs')->update(['created_at' => now()->subDays(400)]);
        $this->assertSame(3, $audit->prune());
    }

    /* ------------------------------------------------------------------ queue + security scan */

    #[Test]
    public function queue_stats_and_requeue_work_per_university(): void
    {
        $a = $this->institutional('Queue User');
        $mid = $this->message($a);
        $id = DB::table('mail_external_deliveries')->insertGetId(['message_id' => $mid, 'sender_account_id' => $a->id, 'university_id' => $this->uni, 'status' => 'failed',
            'attempts' => 5, 'next_retry_at' => null, 'last_error' => 'boom', 'created_at' => now(), 'updated_at' => now()]);
        $q = app(MailQueueService::class);
        $this->assertFalse($q->stats($this->uni)['worker']['healthy']);              // مفيش heartbeat
        $q->heartbeat(['sent' => 1]);
        $this->assertTrue($q->stats($this->uni)['worker']['healthy']);
        $this->assertSame(1, $q->stats($this->uni)['external']['gave_up']);
        $this->assertSame(0, $q->stats($this->uni + 1)['external']['gave_up']);      // عزل الجامعات
        $this->fails(fn () => $q->requeue($this->uni + 1, $id), 404);
        $q->requeue($this->uni, $id);
        $row = DB::table('mail_external_deliveries')->where('id', $id)->first();
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNotNull($row->next_retry_at);
        $this->assertSame(1, $q->deliveries($this->uni, ['status' => 'failed'])['total']);
    }

    #[Test]
    public function scan_flags_bruteforce_ips_and_bounce_spikes_once(): void
    {
        config(['mailbox.security.bruteforce_failures' => 5, 'mailbox.security.bounce_spike_24h' => 3]);
        for ($i = 0; $i < 6; $i++) {
            DB::table('login_events')->insert(['identifier' => "user{$i}@x.com", 'success' => 0, 'ip_address' => '203.0.113.50', 'created_at' => now()]);
        }
        DB::table('login_events')->insert(['identifier' => 'ok@x.com', 'success' => 1, 'ip_address' => '203.0.113.60', 'created_at' => now()]);
        $a = $this->institutional('Spiky Sender');
        $mid = $this->message($a);
        for ($i = 0; $i < 3; $i++) {
            app(MailBounceService::class)->handle(['event' => 'hard_bounce', 'email' => "b{$i}@example.com", 'id' => "sp{$i}", 'tags' => ['uipe-m' . $mid]]);
        }
        $this->assertSame(['bruteforce' => 1, 'bounce_spike' => 1], $this->sec->scan());
        $this->assertSame(['bruteforce' => 0, 'bounce_spike' => 0], $this->sec->scan());   // dedupe: حدث مفتوح موجود
        $this->assertSame(2, DB::table('mail_security_events')->where('status', 'open')->count());

        $ev = $this->sec->events($this->uni, ['type' => 'bounce_spike']);
        $this->assertSame(1, $ev['total']);
        $this->assertSame(0, $this->sec->events($this->uni, ['type' => 'login_bruteforce'])['total']); // الـ brute-force مش تابع لجامعة
        $this->assertSame(1, $this->sec->events(null, ['type' => 'login_bruteforce'])['total']);

        $this->sec->resolveEvent(null, $ev['items'][0]['id'], 'resolved', 'checked', 1);
        $this->assertSame(0, $this->sec->overview($this->uni)['open_events']['high']);
        $this->fails(fn () => $this->sec->resolveEvent($this->uni + 1, $ev['items'][0]['id'], 'ignored', null, 1), 404);
        $this->fails(fn () => $this->sec->resolveEvent(null, $ev['items'][0]['id'], 'open', null, 1));
    }
}
