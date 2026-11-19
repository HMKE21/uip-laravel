<?php

namespace Tests\Feature;

use App\Services\Identity\PasskeyService;
use App\Services\Identity\ProvisioningImportService;
use App\Services\Identity\ScimException;
use App\Services\Identity\ScimService;
use App\Services\Mail\MailMembershipService;
use App\Support\WebAuthn\WebAuthn;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** UIPE Mail المرحلة 5 (دفعة 2): Passkeys، تعليق الصندوق عند انتهاء العضوية، SCIM، استيراد CSV (معاينة). */
class IdentityBatch2Test extends TestCase
{
    private int $uni;
    private int $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.passkeys.origins' => 'https://uipe.test', 'identity.passkeys.rp_id' => 'uipe.test', 'identity.scim.enabled' => true]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('full_name'); $t->string('email')->nullable(); $t->string('status')->default('active');
            $t->string('name_ar')->nullable(); $t->string('name_en')->nullable(); $t->dateTime('deleted_at')->nullable(); $t->dateTime('created_at')->nullable();
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
            $t->unsignedBigInteger('faculty_id')->nullable(); $t->unsignedBigInteger('department_id')->nullable(); $t->string('university_email')->nullable();
        });
        Schema::create('academic_staff', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('university_id'); $t->unsignedBigInteger('faculty_id')->nullable(); $t->unsignedBigInteger('department_id')->nullable();
            $t->string('staff_number')->nullable(); $t->string('status')->default('active');
        });
        Schema::create('graduation_records', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('student_id'); $t->string('status')->default('graduated'); $t->date('graduation_date');
        });
        foreach (['2027_11_19_200000_create_mail_system_tables', '2027_11_20_100000_create_mail_advanced_tables', '2027_11_21_100000_create_mail_tenants', '2027_11_22_100000_create_identity_provisioning'] as $m) {
            (include base_path("database/migrations/{$m}.php"))->up();
        }
        $this->uni = DB::table('universities')->insertGetId(['user_id' => $this->user('Uni Owner'), 'official_name_ar' => 'جامعة', 'official_name_en' => 'Test University']);
        $this->tenant = (int) DB::table('mail_tenants')->insertGetId(['university_id' => $this->uni, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $name, string $status = 'active'): int
    {
        return DB::table('users')->insertGetId(['full_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@gmail.com', 'status' => $status, 'name_en' => $name, 'created_at' => now()]);
    }

    /** طالب + صندوق مؤسسي. @return array{0:int,1:int,2:int} [userId, studentId, mailboxId] */
    private function studentWithMailbox(string $name, array $box = []): array
    {
        $u = $this->user($name);
        $s = DB::table('students')->insertGetId(['user_id' => $u, 'university_id' => $this->uni, 'student_number' => (string) random_int(1000, 9999)]);
        $m = DB::table('mail_accounts')->insertGetId($box + ['user_id' => $u, 'address' => strtolower(str_replace(' ', '.', $name)) . '@test.edu.eg', 'kind' => 'institutional', 'university_id' => $this->uni,
            'tenant_id' => $this->tenant, 'member_type' => 'student', 'member_id' => $s, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return [$u, $s, (int) $m];
    }

    /* ------------------------------------------------------------------ Passkeys (مصادق برمجي) */

    private function cEnc($v): string
    {
        $head = function (int $major, int $n): string {
            if ($n < 24) {
                return chr(($major << 5) | $n);
            }
            return $n < 256 ? chr(($major << 5) | 24) . chr($n) : chr(($major << 5) | 25) . pack('n', $n);
        };
        if (is_int($v)) {
            return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        }
        if (is_string($v)) {
            return $head(2, strlen($v)) . $v;
        }
        if (is_array($v) && array_is_list($v)) {
            return $head(4, count($v)) . implode('', array_map(fn ($x) => $this->cEnc($x), $v));
        }
        $o = $head(5, count($v));
        foreach ($v as $k => $x) {
            $o .= (is_string($k) ? $head(3, strlen($k)) . $k : $this->cEnc($k)) . $this->cEnc($x);
        }
        return $o;
    }

    private function authData(int $flags, int $counter, ?array $cose = null, string $credId = ''): string
    {
        $ad = hash('sha256', 'uipe.test', true) . chr($flags) . pack('N', $counter);
        return $cose === null ? $ad : $ad . str_repeat("\x01", 16) . pack('n', strlen($credId)) . $credId . $this->cEnc($cose);
    }

    private function cd(string $type, string $ch): string
    {
        return json_encode(['type' => $type, 'challenge' => $ch, 'origin' => 'https://uipe.test']);
    }

    /** @return array{0:\OpenSSLAsymmetricKey,1:string,2:array} [priv, credIdRaw, credentialJson] */
    private function registerPasskey(PasskeyService $svc, int $userId): array
    {
        $opts = $svc->registrationOptions($userId)['publicKey'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key)['ec'];
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
        $credId = random_bytes(32);
        $att = $this->cEnc(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x45, 0, $cose, $credId)]);
        $svc->finishRegistration($userId, ['response' => [
            'clientDataJSON' => WebAuthn::b64uEncode($this->cd('webauthn.create', $opts['challenge'])), 'attestationObject' => WebAuthn::b64uEncode($att), 'transports' => ['internal'],
        ]], 'My laptop');
        return [$key, $credId, []];
    }

    private function assertionFor(PasskeyService $svc, $key, string $credId, int $userId, int $counter, ?array &$ceremony = null): array
    {
        $ceremony = $svc->loginOptions();
        $cd = $this->cd('webauthn.get', $ceremony['publicKey']['challenge']);
        $ad = $this->authData(0x05, $counter);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $key, OPENSSL_ALGO_SHA256);
        return ['id' => WebAuthn::b64uEncode($credId), 'response' => [
            'clientDataJSON' => WebAuthn::b64uEncode($cd), 'authenticatorData' => WebAuthn::b64uEncode($ad), 'signature' => WebAuthn::b64uEncode($sig),
            'userHandle' => WebAuthn::b64uEncode($svc->userHandle($userId)),
        ]];
    }

    #[Test]
    public function passkey_register_then_login_then_replay_and_revoked_are_rejected(): void
    {
        $svc = app(PasskeyService::class);
        $this->assertTrue($svc->enabled());
        $u = $this->user('Passkey User');
        [$key, $credId] = $this->registerPasskey($svc, $u);

        $list = $svc->list($u);
        $this->assertCount(1, $list);
        $this->assertSame('My laptop', $list[0]['name']);

        $cred = $this->assertionFor($svc, $key, $credId, $u, 3, $c);
        $this->assertSame($u, $svc->finishLogin($c['ceremony_id'], $cred));
        $this->assertSame(3, (int) DB::table('passkeys')->where('user_id', $u)->value('sign_count'));

        try {   // نفس الـ ceremony تاني = challenge اتستهلك
            $svc->finishLogin($c['ceremony_id'], $cred);
            $this->fail('replay accepted');
        } catch (\DomainException $e) {
            $this->assertSame(410, $e->getCode());
        }

        $cred2 = $this->assertionFor($svc, $key, $credId, $u, 3, $c2);   // عدّاد مش زايد = جهاز منسوخ
        try {
            $svc->finishLogin($c2['ceremony_id'], $cred2);
            $this->fail('stale counter accepted');
        } catch (\DomainException $e) {
            $this->assertSame(422, $e->getCode());
        }

        $svc->revoke($u, (int) $list[0]['id']);
        $cred3 = $this->assertionFor($svc, $key, $credId, $u, 9, $c3);
        $this->expectException(\DomainException::class);
        $svc->finishLogin($c3['ceremony_id'], $cred3);
    }

    #[Test]
    public function passkeys_report_503_when_not_configured_and_cannot_be_used_across_users(): void
    {
        $svc = app(PasskeyService::class);
        $a = $this->user('User A');
        $b = $this->user('User B');
        [$key, $credId] = $this->registerPasskey($svc, $a);
        $cred = $this->assertionFor($svc, $key, $credId, $a, 1, $c);
        $cred['response']['userHandle'] = WebAuthn::b64uEncode($svc->userHandle($b));   // user handle بتاع مستخدم تاني
        try {
            $svc->finishLogin($c['ceremony_id'], $cred);
            $this->fail('cross-user handle accepted');
        } catch (\DomainException $e) {
            $this->assertSame(422, $e->getCode());
        }
        config(['identity.passkeys.origins' => '']);
        $this->assertFalse($svc->enabled());
        $this->expectException(\DomainException::class);
        $svc->loginOptions();
    }

    /* ------------------------------------------------------------------ تعليق الصندوق عند انتهاء العضوية */

    #[Test]
    public function expired_membership_suspends_and_clearing_it_resumes(): void
    {
        [, , $box] = $this->studentWithMailbox('Expiring Student', ['membership_expires_at' => now()->subDay()]);
        $svc = app(MailMembershipService::class);

        $dry = $svc->sweep($this->uni, true);
        $this->assertSame(1, $dry['suspended']);
        $this->assertSame('active', DB::table('mail_accounts')->where('id', $box)->value('status'));   // المعاينة مبتكتبش

        $svc->sweep($this->uni);
        $row = DB::table('mail_accounts')->where('id', $box)->first();
        $this->assertSame('suspended', $row->status);
        $this->assertSame('membership_expired', $row->suspended_reason);
        $this->assertSame('membership', $row->suspended_by);

        $svc->setExpiry($this->uni, $box, null);
        $res = $svc->sweep($this->uni);
        $this->assertSame(1, $res['resumed']);
        $this->assertSame('active', DB::table('mail_accounts')->where('id', $box)->value('status'));
    }

    #[Test]
    public function manual_admin_suspension_is_never_auto_resumed(): void
    {
        [, , $box] = $this->studentWithMailbox('Manual Hold', ['status' => 'suspended', 'suspended_reason' => 'abuse']);
        $svc = app(MailMembershipService::class);
        $this->assertSame(0, $svc->sweep($this->uni)['resumed']);
        $this->assertSame('suspended', DB::table('mail_accounts')->where('id', $box)->value('status'));
    }

    #[Test]
    public function suspended_platform_account_and_graduation_after_grace_suspend_the_mailbox(): void
    {
        [$u1, , $b1] = $this->studentWithMailbox('Banned Student');
        DB::table('users')->where('id', $u1)->update(['status' => 'suspended']);
        [, $s2, $b2] = $this->studentWithMailbox('Old Graduate');
        DB::table('graduation_records')->insert(['student_id' => $s2, 'status' => 'graduated', 'graduation_date' => now()->subDays(400)->toDateString()]);
        [, $s3, $b3] = $this->studentWithMailbox('Fresh Graduate');
        DB::table('graduation_records')->insert(['student_id' => $s3, 'status' => 'graduated', 'graduation_date' => now()->subDays(10)->toDateString()]);

        app(MailMembershipService::class)->sweep($this->uni);
        $reason = fn ($id) => DB::table('mail_accounts')->where('id', $id)->value('suspended_reason');
        $this->assertSame('account_suspended', $reason($b1));
        $this->assertSame('graduated', $reason($b2));
        $this->assertSame('active', DB::table('mail_accounts')->where('id', $b3)->value('status'));   // جوه مهلة الـ 180 يوم
    }

    #[Test]
    public function policy_off_stops_new_suspensions_and_resumes_auto_suspended(): void
    {
        [, , $box] = $this->studentWithMailbox('Policy Student', ['membership_expires_at' => now()->subDay()]);
        $svc = app(MailMembershipService::class);
        $svc->sweep($this->uni);
        $this->assertSame('suspended', DB::table('mail_accounts')->where('id', $box)->value('status'));

        DB::table('mail_tenants')->where('id', $this->tenant)->update(['suspend_on_expiry' => 0]);
        $svc->sweep($this->uni);
        $this->assertSame('active', DB::table('mail_accounts')->where('id', $box)->value('status'));
    }

    /* ------------------------------------------------------------------ SCIM */

    private function scimCtx(?string &$raw = null, ?int $expiresInDays = null): object
    {
        $scim = app(ScimService::class);
        $raw = $scim->createToken($this->tenant, 'Entra', null, $expiresInDays)['token'];
        return $scim->authenticate($raw);
    }

    #[Test]
    public function scim_token_authentication_rules(): void
    {
        $scim = app(ScimService::class);
        $ctx = $this->scimCtx($raw);
        $this->assertSame($this->uni, $ctx->university_id);
        $this->assertSame(1, (int) DB::table('scim_tokens')->whereNotNull('last_used_at')->count());
        $this->assertNotSame($raw, DB::table('scim_tokens')->value('token_hash'));   // الـ hash بس بيتخزن

        foreach ([null, '', 'scim_wrong'] as $bad) {
            try {
                $scim->authenticate($bad);
                $this->fail('bad token accepted');
            } catch (ScimException $e) {
                $this->assertSame(401, $e->status);
            }
        }

        $tokenId = (int) DB::table('scim_tokens')->value('id');
        $scim->revokeToken($this->tenant, $tokenId);
        try {
            $scim->authenticate($raw);
            $this->fail('revoked token accepted');
        } catch (ScimException $e) {
            $this->assertSame(401, $e->status);
        }

        $raw2 = $scim->createToken($this->tenant, 'Suspended', null, null)['token'];
        DB::table('mail_tenants')->where('id', $this->tenant)->update(['status' => 'suspended']);
        try {
            $scim->authenticate($raw2);
            $this->fail('suspended tenant accepted');
        } catch (ScimException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    #[Test]
    public function scim_lists_only_this_universitys_members_and_supports_filters(): void
    {
        $ctx = $this->scimCtx();
        [$mine] = $this->studentWithMailbox('Mine Student');
        $outsider = $this->user('Outsider Student');
        $otherUni = DB::table('universities')->insertGetId(['user_id' => $this->user('Other Owner'), 'official_name_ar' => 'x', 'official_name_en' => 'Other']);
        DB::table('students')->insert(['user_id' => $outsider, 'university_id' => $otherUni]);
        $scim = app(ScimService::class);

        $all = $scim->list($ctx, null, 1, 100);
        $this->assertSame(1, $all['total']);
        $this->assertSame((string) $mine, $all['items'][0]['id']);
        $this->assertSame('mine.student@gmail.com', $all['items'][0]['userName']);
        $this->assertTrue($all['items'][0]['active']);
        $this->assertSame('mine.student@test.edu.eg', $all['items'][0][ScimService::EXT]['mailAddress']);

        $this->assertSame(1, $scim->list($ctx, 'userName eq "MINE.student@gmail.com"', 1, 10)['total']);
        $this->assertSame(0, $scim->list($ctx, 'userName eq "nobody@x.com"', 1, 10)['total']);

        try {
            $scim->list($ctx, 'userName co "x"', 1, 10);
            $this->fail('unsupported filter accepted');
        } catch (ScimException $e) {
            $this->assertSame('invalidFilter', $e->scimType);
        }
        try {
            $scim->get($ctx, $outsider);   // مستخدم من جامعة تانية
            $this->fail('cross-university read');
        } catch (ScimException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    #[Test]
    public function scim_patch_sets_external_id_and_membership_expiry_and_parses_entra_style_operations(): void
    {
        $ctx = $this->scimCtx();
        [$u, , $box] = $this->studentWithMailbox('Patch Student');
        $scim = app(ScimService::class);

        $attrs = $scim->patchToAttrs(['Operations' => [
            ['op' => 'Replace', 'path' => 'externalId', 'value' => 'ext-42'],
            ['op' => 'Replace', 'path' => ScimService::EXT . ':membershipExpiresAt', 'value' => now()->addYear()->toIso8601String()],
        ]]);
        $r = $scim->apply($ctx, $u, $attrs);
        $this->assertSame('ext-42', $r['externalId']);
        $this->assertNotNull($r[ScimService::EXT]['membershipExpiresAt']);
        $this->assertSame(1, $scim->list($ctx, 'externalId eq "ext-42"', 1, 10)['total']);

        $this->assertSame(['active' => 'False'], $scim->patchToAttrs(['Operations' => [['op' => 'replace', 'value' => ['active' => 'False']]]]));

        $r = $scim->apply($ctx, $u, [ScimService::EXT => ['membershipExpiresAt' => null]]);   // مسح التاريخ
        $this->assertArrayNotHasKey('membershipExpiresAt', $r[ScimService::EXT]);

        try {
            $scim->apply($ctx, $u, ['active' => 'maybe']);
            $this->fail('invalid active accepted');
        } catch (ScimException $e) {
            $this->assertSame(400, $e->status);
        }
        try {
            $scim->patchToAttrs(['Operations' => []]);
            $this->fail('empty ops accepted');
        } catch (ScimException $e) {
            $this->assertSame('invalidSyntax', $e->scimType);
        }
    }

    #[Test]
    public function scim_external_id_must_be_unique_per_tenant(): void
    {
        $ctx = $this->scimCtx();
        [$a] = $this->studentWithMailbox('First One');
        [$b] = $this->studentWithMailbox('Second One');
        $scim = app(ScimService::class);
        $scim->apply($ctx, $a, ['externalId' => 'dup']);
        try {
            $scim->apply($ctx, $b, ['externalId' => 'dup']);
            $this->fail('duplicate externalId accepted');
        } catch (ScimException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    /* ------------------------------------------------------------------ استيراد CSV (معاينة) */

    #[Test]
    public function csv_dry_run_flags_bad_duplicate_and_existing_rows_without_writing(): void
    {
        $this->user('Existing Person');   // existing.person@gmail.com
        $rows = [
            ['name_ar' => 'سارة محمود', 'name_en' => 'Sara Mahmoud', 'email' => 'sara.new@gmail.com'],
            ['name_ar' => 'سارة محمود', 'name_en' => 'Sara Mahmoud', 'email' => 'SARA.new@gmail.com'],      // مكرر
            ['name_ar' => 'س', 'name_en' => 'S', 'email' => 'existing.person@gmail.com'],                    // موجود
            ['name_ar' => 'بدون', 'name_en' => 'No Email', 'email' => 'not-an-email'],
        ];
        $before = DB::table('users')->count();
        $res = app(ProvisioningImportService::class)->run($this->uni, 1, 'student', $rows, null, 'en', true, false);

        $this->assertTrue($res['dry_run']);
        $this->assertSame(4, $res['total']);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(3, $res['skipped']);
        $this->assertSame([true, false, false, false], array_column($res['results'], 'success'));
        $this->assertSame($before, DB::table('users')->count());
        $this->assertSame(1, DB::table('provisioning_runs')->where('kind', 'csv')->where('dry_run', 1)->count());
    }

    #[Test]
    public function csv_import_rejects_bad_type_empty_and_oversized_files(): void
    {
        $svc = app(ProvisioningImportService::class);
        foreach ([['teacher', [['email' => 'a@gmail.com']], 422], ['student', [], 422], ['student', array_fill(0, ProvisioningImportService::MAX_ROWS + 1, ['email' => 'a@gmail.com']), 413]] as [$type, $rows, $code]) {
            try {
                $svc->run($this->uni, 1, $type, $rows, null, 'en', true, false);
                $this->fail('accepted invalid import');
            } catch (\DomainException $e) {
                $this->assertSame($code, $e->getCode());
            }
        }
        $this->assertStringStartsWith("\xEF\xBB\xBFname_ar,name_en,email,student_number", ProvisioningImportService::template('student'));
        $this->assertStringContainsString('staff_number', ProvisioningImportService::template('staff'));
    }
}
