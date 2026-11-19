<?php

namespace Tests\Feature;

use App\Services\Identity\LoginHistoryService;
use App\Services\Identity\TokenRevocationService;
use App\Services\Mail\MailProvisioningService;
use App\Services\Mail\MailTenantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** UIPE Mail المرحلة 5 (دفعة 1): سجل الدخول، إلغاء التوكنز، الـ Provisioning التلقائي. */
class IdentityBatch1Test extends TestCase
{
    private int $uni;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('full_name'); $t->string('email')->nullable(); $t->string('status')->default('active');
        });
        Schema::create('universities', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('official_name_ar'); $t->string('official_name_en');
            $t->string('email_domain')->nullable(); $t->string('email_pattern')->default('number');
        });
        Schema::create('faculties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('university_id'); $t->string('name_ar'); $t->string('name_en'); $t->string('slug'); $t->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('university_id')->nullable(); $t->string('student_number')->nullable();
            $t->unsignedBigInteger('faculty_id')->nullable(); $t->string('university_email')->nullable();
        });
        Schema::create('academic_staff', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('university_id'); $t->unsignedBigInteger('faculty_id')->nullable();
            $t->string('staff_number')->nullable(); $t->string('status')->default('active');
        });
        Schema::create('user_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('session_token', 128); $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable(); $t->string('device_label', 120)->nullable(); $t->string('location_label', 120)->nullable();
            $t->boolean('is_active')->default(1); $t->unsignedBigInteger('revoked_by')->nullable(); $t->dateTime('revoked_at')->nullable();
            $t->dateTime('last_activity_at')->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('expires_at')->nullable(); $t->string('revoke_reason', 40)->nullable();
        });
        Schema::create('refresh_tokens', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->char('token_hash', 64); $t->string('device_label', 150)->nullable(); $t->string('ip_address', 45)->nullable();
            $t->dateTime('expires_at'); $t->dateTime('revoked_at')->nullable(); $t->unsignedBigInteger('replaced_by_id')->nullable(); $t->dateTime('created_at')->nullable();
        });
        foreach (['2027_11_19_200000_create_mail_system_tables', '2027_11_20_100000_create_mail_advanced_tables', '2027_11_21_100000_create_mail_tenants', '2027_11_22_100000_create_identity_provisioning'] as $m) {
            (include base_path("database/migrations/{$m}.php"))->up();
        }
        $this->uni = DB::table('universities')->insertGetId(['user_id' => $this->user('Uni Owner'), 'official_name_ar' => 'جامعة', 'official_name_en' => 'Test University']);
    }

    private function user(string $name): int
    {
        return DB::table('users')->insertGetId(['full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@gmail.com']);
    }

    private function req(string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0', string $path = 'api/v1/auth/login'): Request
    {
        return Request::create('/' . $path, 'POST', [], [], [], ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '127.0.0.1']);
    }

    #[Test]
    public function login_events_record_success_failure_and_flag_new_device(): void
    {
        $h = app(LoginHistoryService::class);
        $u = $this->user('Sara Ali');
        $h->record($this->req(), $u, true);                                   // أول دخول: مش جهاز جديد
        $h->record($this->req(), $u, true);                                   // نفس الجهاز
        $h->record($this->req('Mozilla/5.0 (Android) Chrome/120.0'), $u, true); // جهاز تاني → جديد
        $h->record($this->req(), $u, false, null, 'bad_credentials', 'Sara@Gmail.com');

        $rows = DB::table('login_events')->orderBy('id')->get();
        $this->assertCount(4, $rows);
        $this->assertSame([0, 0, 1, 0], $rows->pluck('is_new_device')->map(fn ($x) => (int) $x)->all());
        $this->assertSame('bad_credentials', $rows[3]->failure_reason);
        $this->assertSame('sara@gmail.com', $rows[3]->identifier);
        $this->assertNull($rows[3]->location_label);

        $mine = $h->forUser($u, 1, 2);
        $this->assertCount(2, $mine['items']);
        $this->assertSame(4, $mine['meta']['total']);
    }

    #[Test]
    public function method_is_detected_from_the_route(): void
    {
        $this->assertSame('google', LoginHistoryService::methodFromRequest($this->req('x', 'api/v1/auth/google')));
        $this->assertSame('passkey', LoginHistoryService::methodFromRequest($this->req('x', 'api/v1/auth/passkeys/login')));
        $this->assertSame('2fa', LoginHistoryService::methodFromRequest($this->req('x', 'api/v1/auth/two-factor/verify')));
        $this->assertSame('password', LoginHistoryService::methodFromRequest($this->req()));
    }

    #[Test]
    public function university_search_only_sees_its_own_members(): void
    {
        $h = app(LoginHistoryService::class);
        $mine = $this->user('Mine Student');
        $other = $this->user('Other Person');
        DB::table('students')->insert(['user_id' => $mine, 'university_id' => $this->uni]);
        $h->record($this->req(), $mine, true);
        $h->record($this->req(), $other, true);

        $this->assertSame(1, $h->search([], $this->uni)['meta']['total']);
        $this->assertSame(2, $h->search([], null)['meta']['total']);
        $this->assertSame(0, $h->search(['success' => '0'], null)['meta']['total']);
    }

    #[Test]
    public function revoke_others_keeps_the_current_session_and_revoke_all_cuts_everything(): void
    {
        $u = $this->user('Omar Hassan');
        $ids = [];
        foreach (['a', 'b', 'c'] as $k) {
            $ids[$k] = DB::table('user_sessions')->insertGetId(['user_id' => $u, 'session_token' => hash('sha256', $k), 'is_active' => 1, 'created_at' => now(), 'last_activity_at' => now()]);
            DB::table('refresh_tokens')->insert(['user_id' => $u, 'token_hash' => hash('sha256', $k), 'expires_at' => now()->addDay(), 'created_at' => now()]);
        }
        $svc = app(TokenRevocationService::class);

        $res = $svc->revokeOthers($u, $ids['a']);
        $this->assertSame(2, $res['sessions']);
        $this->assertSame(1, DB::table('user_sessions')->where('user_id', $u)->where('is_active', 1)->count());
        $this->assertSame(1, DB::table('refresh_tokens')->where('user_id', $u)->whereNull('revoked_at')->count());

        $svc->revokeAll($u);
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $u)->where('is_active', 1)->count());
        $this->assertSame(0, DB::table('refresh_tokens')->where('user_id', $u)->whereNull('revoked_at')->count());
        $this->assertNotNull(DB::table('users')->where('id', $u)->value('tokens_valid_after'));
    }

    private function activeDomain(): void
    {
        $t = app(MailTenantService::class);
        $row = $t->addDomain($this->uni, 'test.edu.eg');
        $t->setDomainStatus($row['id'], 'active');
        $t->setPrimary($this->uni, $row['id']);
    }

    #[Test]
    public function auto_provision_creates_one_mailbox_for_the_new_member_only(): void
    {
        $this->activeDomain();
        $a = $this->user('Ahmed Samir');
        $b = $this->user('Bassem Adel');
        DB::table('students')->insert(['user_id' => $a, 'university_id' => $this->uni, 'student_number' => '2024001']);
        DB::table('students')->insert(['user_id' => $b, 'university_id' => $this->uni, 'student_number' => '2024002']);

        MailProvisioningService::forUser($a);
        $this->assertSame(1, DB::table('mail_accounts')->where('kind', 'institutional')->count());
        $this->assertSame('2024001@test.edu.eg', DB::table('mail_accounts')->where('user_id', $a)->value('address'));
        $this->assertSame('2024001@test.edu.eg', DB::table('students')->where('user_id', $a)->value('university_email'));

        MailProvisioningService::forUser($a);                                 // idempotent
        $this->assertSame(1, DB::table('mail_accounts')->where('kind', 'institutional')->count());

        $r = app(MailProvisioningService::class)->syncAll();                  // المجدول يكمّل الباقي
        $this->assertGreaterThanOrEqual(1, $r['created']);                    // + صندوق مكتب الجامعة نفسها
        $this->assertSame(2, DB::table('mail_accounts')->where('member_type', 'student')->count());
    }

    #[Test]
    public function auto_provision_respects_the_tenant_switch_and_missing_domain(): void
    {
        $a = $this->user('Nour Khaled');
        DB::table('students')->insert(['user_id' => $a, 'university_id' => $this->uni, 'student_number' => '1']);
        MailProvisioningService::forUser($a);                                 // مفيش نطاق مفعّل → مفيش صندوق ومفيش خطأ
        $this->assertSame(0, DB::table('mail_accounts')->count());

        $this->activeDomain();
        app(MailTenantService::class)->updatePolicies($this->uni, ['auto_provision' => false]);
        MailProvisioningService::forUser($a);
        $this->assertSame(0, DB::table('mail_accounts')->count());

        app(MailTenantService::class)->updatePolicies($this->uni, ['auto_provision' => true]);
        MailProvisioningService::forUser($a);
        $this->assertSame(1, DB::table('mail_accounts')->count());
    }
}
