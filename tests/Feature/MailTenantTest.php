<?php

namespace Tests\Feature;

use App\Models\Mail\MailAccount;
use App\Services\Mail\MailboxService;
use App\Services\Mail\MailTenantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** UIPE Mail المرحلة 4: tenants، نطاقات، provisioning، حصص وسياسات. */
class MailTenantTest extends TestCase
{
    private MailTenantService $svc;
    private MailboxService $mail;
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
        foreach (['2027_11_19_200000_create_mail_system_tables', '2027_11_20_100000_create_mail_advanced_tables', '2027_11_21_100000_create_mail_tenants'] as $m) {
            (include base_path("database/migrations/{$m}.php"))->up();
        }
        $this->svc = app(MailTenantService::class);
        $this->mail = app(MailboxService::class);

        $u = $this->user('Uni Owner');
        $this->uni = DB::table('universities')->insertGetId(['user_id' => $u, 'official_name_ar' => 'جامعة', 'official_name_en' => 'Test University']);
    }

    private function user(string $name): int
    {
        return DB::table('users')->insertGetId(['full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@gmail.com']);
    }

    private function student(string $name, ?string $number, ?int $faculty = null, ?string $email = null): int
    {
        return DB::table('students')->insertGetId(['user_id' => $this->user($name), 'university_id' => $this->uni, 'student_number' => $number, 'faculty_id' => $faculty, 'university_email' => $email]);
    }

    private function activeDomain(string $d = 'test.edu.eg'): int
    {
        $row = $this->svc->addDomain($this->uni, $d);
        $this->svc->setDomainStatus($row['id'], 'active');
        $this->svc->setPrimary($this->uni, $row['id']);
        return $row['id'];
    }

    /** Undo Send الافتراضي 10 ثواني بيحجز الرسالة؛ الاختبار عايز تسليم فوري. */
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

    #[Test]
    public function new_domain_is_pending_and_provisioning_needs_an_active_primary(): void
    {
        $row = $this->svc->addDomain($this->uni, '@Test.Edu.EG');
        $this->assertSame('test.edu.eg', $row['domain']);
        $this->assertSame('pending', $row['status']);
        $this->fails(fn () => $this->svc->setPrimary($this->uni, $row['id']), 409);
        $this->fails(fn () => $this->svc->provisionMembers($this->uni, ['student']), 409);
        $this->fails(fn () => $this->svc->addDomain($this->uni, 'test.edu.eg'), 409);
        $this->fails(fn () => $this->svc->addDomain($this->uni, 'uipe.local'));
        $this->fails(fn () => $this->svc->addDomain($this->uni, 'not a domain'));
    }

    #[Test]
    public function provisioning_creates_institutional_accounts_once(): void
    {
        $this->activeDomain();
        $this->assertSame('test.edu.eg', DB::table('universities')->where('id', $this->uni)->value('email_domain'));
        $fac = DB::table('faculties')->insertGetId(['university_id' => $this->uni, 'name_ar' => 'هندسة', 'name_en' => 'Engineering', 'slug' => 'engineering', 'user_id' => $this->user('Dean Eng')]);
        $s1 = $this->student('Ahmed Ali', '2024001', $fac);
        $s2 = $this->student('Mona Said', '2024002', $fac, 'mona.said@test.edu.eg'); // عنوان مولَّد قبل كده
        DB::table('academic_staff')->insert(['user_id' => $this->user('Hany Samir'), 'university_id' => $this->uni, 'faculty_id' => $fac, 'staff_number' => 'T9']);
        DB::table('academic_staff')->insert(['user_id' => $this->user('Inactive Doc'), 'university_id' => $this->uni, 'status' => 'inactive']);

        $r = $this->svc->provisionMembers($this->uni, ['student', 'staff', 'faculty', 'university']);
        $this->assertSame(5, $r['created']); // 2 طلاب + 1 موظف نشط + كلية + جامعة
        $this->assertSame(['student' => 2, 'staff' => 1, 'faculty' => 1, 'university' => 1], $r['by_type']);
        $addr = MailAccount::where('tenant_id', '!=', null)->pluck('address', 'member_type');
        $this->assertSame('hany.samir@test.edu.eg', $addr['staff']);
        $this->assertSame('engineering@test.edu.eg', $addr['faculty']);
        $this->assertSame('office@test.edu.eg', $addr['university']);
        $this->assertSame('2024001@test.edu.eg', DB::table('students')->where('id', $s1)->value('university_email'));
        $this->assertSame('mona.said@test.edu.eg', MailAccount::where('member_type', 'student')->where('member_id', $s2)->value('address'));
        $this->assertSame('institutional', MailAccount::where('member_type', 'staff')->value('kind'));

        $again = $this->svc->provisionMembers($this->uni, ['student', 'staff', 'faculty', 'university']);
        $this->assertSame(0, $again['created']);
        $this->assertSame(5, $again['skipped']);
    }

    #[Test]
    public function duplicate_names_get_unique_addresses_and_pattern_name_works(): void
    {
        $this->activeDomain();
        DB::table('universities')->where('id', $this->uni)->update(['email_pattern' => 'name']);
        $this->student('Ahmed Ali', '1');
        $this->student('Ahmed Ali', '2');
        $this->svc->provisionMembers($this->uni, ['student']);
        $this->assertEqualsCanonicalizing(['ahmed.ali@test.edu.eg', 'ahmed.ali2@test.edu.eg'], MailAccount::pluck('address')->all());
    }

    #[Test]
    public function student_send_scope_and_recipient_limit(): void
    {
        $this->activeDomain();
        $a = $this->student('Stu One', '11');
        $b = $this->student('Stu Two', '12');
        $this->svc->provisionMembers($this->uni, ['student']);
        $sa = MailAccount::where('member_id', $a)->first();
        $sb = MailAccount::where('member_id', $b)->first();
        $outsider = MailAccount::create(['user_id' => $this->user('Outsider'), 'address' => 'out@uipe.local', 'kind' => 'personal']);

        $this->noUndo($sa, $sb, $outsider);
        $this->svc->updatePolicies($this->uni, ['student_send_scope' => 'university', 'max_recipients' => 2]);
        $this->mail->send($sa, ['to' => [$sb->address], 'subject' => 'hi', 'body' => 'x']);
        $this->assertSame(1, DB::table('mail_items')->where('account_id', $sb->id)->where('folder', 'inbox')->count());
        $this->fails(fn () => $this->mail->send($sa, ['to' => [$outsider->address], 'subject' => 'hi', 'body' => 'x']), 403);
        $this->fails(fn () => $this->mail->send($sa, ['to' => [$sb->address, 'a@test.edu.eg', 'b@test.edu.eg'], 'subject' => 'hi', 'body' => 'x']));

        $this->svc->updatePolicies($this->uni, ['student_send_scope' => 'platform']);
        $this->mail->send($sa, ['to' => [$outsider->address], 'subject' => 'hi2', 'body' => 'x']);
        $this->assertSame(1, DB::table('mail_items')->where('account_id', $outsider->id)->where('folder', 'inbox')->count());

        $this->svc->updatePolicies($this->uni, ['allow_external' => false]);
        $this->fails(fn () => $this->mail->send($sa, ['to' => [$outsider->address . '.x@gmail.com'], 'subject' => 's', 'body' => 'x']));
    }

    #[Test]
    public function suspended_tenant_cannot_send_or_receive(): void
    {
        $this->activeDomain();
        $a = $this->student('Stu One', '11');
        $b = $this->student('Stu Two', '12');
        $this->svc->provisionMembers($this->uni, ['student']);
        $sa = MailAccount::where('member_id', $a)->first();
        $sb = MailAccount::where('member_id', $b)->first();
        $personal = MailAccount::create(['user_id' => $this->user('Outsider'), 'address' => 'out@uipe.local', 'kind' => 'personal']);

        $this->noUndo($sa, $sb, $personal);
        $this->svc->setTenantStatus($this->uni, true, 'billing');
        $this->assertNotNull($this->mail->blockReason($sa->fresh()));
        $this->fails(fn () => $this->mail->send($sa->fresh(), ['to' => [$personal->address], 'subject' => 's', 'body' => 'x']), 403);
        $this->fails(fn () => $this->mail->send($personal, ['to' => [$sb->address], 'subject' => 's', 'body' => 'x']));
        $this->fails(fn () => $this->svc->provisionMembers($this->uni, ['student']), 403);

        $this->svc->setTenantStatus($this->uni, false);
        $this->assertNull($this->mail->blockReason($sa->fresh()));
        $this->mail->send($sa->fresh(), ['to' => [$personal->address], 'subject' => 's', 'body' => 'x']);
    }

    #[Test]
    public function quota_blocks_delivery_to_a_full_mailbox_and_reports_usage(): void
    {
        $this->activeDomain();
        $a = $this->student('Stu One', '11');
        $b = $this->student('Stu Two', '12');
        $this->svc->provisionMembers($this->uni, ['student']);
        $sa = MailAccount::where('member_id', $a)->first();
        $sb = MailAccount::where('member_id', $b)->first();

        $this->noUndo($sa, $sb);
        $this->assertSame(1024, $this->svc->quotaPayload($sa)['limit_mb']);
        $this->svc->updateAccount($this->uni, (int) $sb->id, ['quota_mb' => 1]);
        $this->assertSame(1, $this->svc->quotaPayload($sb->fresh())['limit_mb']);

        // نملا صندوق ب بحجم يتعدّى 1MB
        $mid = DB::table('mail_messages')->insertGetId(['thread_id' => DB::table('mail_threads')->insertGetId(['created_at' => now(), 'updated_at' => now()]),
            'sender_account_id' => $sa->id, 'subject' => 'big', 'body_html' => str_repeat('a', 1100000), 'is_draft' => 0, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mail_items')->insert(['account_id' => $sb->id, 'message_id' => $mid, 'folder' => 'inbox', 'sort_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue($this->svc->quotaPayload($sb->fresh())['full']);
        $this->assertStringContainsString('full', $this->fails(fn () => $this->mail->send($sa, ['to' => [$sb->address], 'subject' => 's', 'body' => 'x'])));
        $this->svc->updateAccount($this->uni, (int) $sb->id, ['quota_mb' => null]);
        $this->mail->send($sa, ['to' => [$sb->address], 'subject' => 's', 'body' => 'x']);
    }

    #[Test]
    public function admin_can_suspend_rename_and_list_members(): void
    {
        $this->activeDomain();
        $a = $this->student('Stu One', '11');
        $this->student('Stu Two', '12');
        $this->svc->provisionMembers($this->uni, ['student']);
        $acc = MailAccount::where('member_id', $a)->first();

        $row = $this->svc->updateAccount($this->uni, (int) $acc->id, ['status' => 'suspended', 'suspended_reason' => 'abuse', 'local' => 'stu.one']);
        $this->assertSame('stu.one@test.edu.eg', $row['address']);
        $this->assertSame('suspended', $row['status']);
        $this->assertSame('stu.one@test.edu.eg', DB::table('students')->where('id', $a)->value('university_email'));
        $this->fails(fn () => $this->svc->updateAccount($this->uni, (int) $acc->id, ['local' => '12']), 409); // مستخدم عند الطالب التاني
        $this->fails(fn () => $this->svc->updateAccount($this->uni, (int) $acc->id, ['local' => 'postmaster']), 409);

        $list = $this->svc->members($this->uni, ['status' => 'suspended']);
        $this->assertSame(1, $list['total']);
        $this->assertSame(2, $this->svc->members($this->uni, ['type' => 'student'])['total']);
        $this->assertSame(1, $this->svc->members($this->uni, ['q' => 'two'])['total']);
        $ov = $this->svc->overview($this->uni);
        $this->assertSame(2, $ov['stats']['accounts']);
        $this->assertSame(1, $ov['stats']['suspended']);
    }

    #[Test]
    public function domain_rules(): void
    {
        $id = $this->activeDomain();
        $this->fails(fn () => $this->svc->removeDomain($this->uni, $id), 409);          // primary
        $second = $this->svc->addDomain($this->uni, 'second.edu.eg');
        $this->svc->removeDomain($this->uni, $second['id']);
        $this->assertCount(1, $this->svc->domains($this->svc->tenantByUniversity($this->uni)));
        $this->fails(fn () => $this->svc->setDomainStatus($id, 'disabled'), 409);
        $this->fails(fn () => $this->svc->updatePolicies($this->uni, ['max_recipients' => 9999]));
    }
}
