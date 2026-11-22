<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * مستخدمو المنصة المعزولة (قاعدتها الخاصة): المالك بيضيف أدمن/موظف بإيميل وباسورد من غير ما يحتاج حساب UIPE،
 * ويعمل إعادة ضبط باسورد وإيقاف/تفعيل. بيشتغل جوّه TenantContext::run على قاعدة المنصة نفسها.
 * المالك/المدير بس (ability members). المنصات المشتركة (shared) مفيهاش حسابات خاصة: أعضاؤها حسابات UIPE.
 */
final class PlatformUsers
{
    public function __construct(private TenantService $tenants)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /** @return array{0:object,1:string} [tenant row, actor role] */
    private function open(int $actorId, int $tenantId): array
    {
        [$t, $role] = $this->tenants->authorize($actorId, $tenantId, 'members');
        if (($t->mode ?? 'shared') !== 'isolated' || $t->db_status !== 'ready' || empty($t->db_name)) {
            $this->fail('This workspace uses UIPE accounts. Launch a platform to give people their own accounts.', 409);
        }
        return [$t, $role];
    }

    private function roleSlug(string $role): string
    {
        $map = (array) config('tenancy.platform_users.roles', []);
        if (!isset($map[$role])) {
            $this->fail('Invalid role.');
        }
        return (string) $map[$role];
    }

    /** قيد دومين الإيميل اللي ضابطه المالك (company.com…). فاضي = كله مسموح. */
    private function assertDomainAllowed(object $t, string $email): void
    {
        $domains = (array) (TenantSecurity::forTenant((int) $t->id)['allowed_domains'] ?? []);
        if (!TenantSecurity::emailAllowed($email, $domains)) {
            $this->fail('Only these email domains are allowed on this platform: ' . implode(', ', array_map(fn ($d) => '@' . $d, $domains)), 422);
        }
    }

    /** سياسة الباسورد بتاعة المنصة (جوه سياقها) على باسورد كتبه المالك. */
    private function assertPasswordOk(object $t, string $pass): void
    {
        try {
            TenantContext::run($t, static fn () => app(\App\Services\PasswordPolicyService::class)->assertValid($pass));
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
    }

    /** باسورد متولّد بيطابق سياسة المنصة (الطول + أنواع الحروف). */
    private function generatePassword(object $t): string
    {
        $min = (int) (TenantContext::run($t, static fn () => app(\App\Services\PasswordPolicyService::class)->getPolicy()['min_length'] ?? 8));
        $len = max(14, $min + 2);
        for ($i = 0; $i < 10; $i++) {
            $p = Str::password($len, true, true, true, true);
            try {
                TenantContext::run($t, static fn () => app(\App\Services\PasswordPolicyService::class)->assertValid($p));
                return $p;
            } catch (\InvalidArgumentException $e) {
                // جرّب تاني
            }
        }
        return Str::password($len, true, true, true, true);
    }

    private static function fp(?string $hash): string
    {
        return substr(hash('sha256', (string) $hash), 0, 20);
    }

    /** توكن دعوة/إعداد باسورد: بيبطل لوحده أول ما الباسورد يتغيّر (بصمة الهاش جواه). */
    /**
     * بيتولّد جوّه سياق المنصة (سر التوقيع + claim tid خاصين بيها): مينفعش يتفك في منصة تانية ولا في المنصة الأم.
     * مهم: لو اتولّد برّه السياق مش هيتفك لما صاحبه يفتح اللينك (بيتفك جوّه سياق المنصة).
     */
    public static function mintInviteToken(object $t, int $uid, ?string $hash, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? max(1, (int) config('tenancy.launch.invite_days', 7)) * 86400;
        return TenantContext::run($t, static fn () => \App\Services\UipJwtService::encode(['typ' => 'ws_invite', 'uid' => $uid, 'tn' => (int) $t->id, 'fp' => self::fp($hash)], $ttl));
    }

    private function inviteLink(object $t, int $uid, ?string $hash): ?string
    {
        $base = TenantMailBrand::frontendUrl((int) $t->id); // دومين المنصة الشغّال لو فيه، وإلا الرابط الجاهز /w/<slug>
        return $base ? $base . '/invite?token=' . self::mintInviteToken($t, $uid, $hash) : null;
    }

    /** يُستدعى جوّه سياق المنصة: المستخدم صاحب التوكن لو لسه سليم، وإلا null. */
    public static function userFromInvite(string $token): ?object
    {
        $c = \App\Services\UipJwtService::decode($token);
        if (!$c || ($c['typ'] ?? null) !== 'ws_invite' || (int) ($c['tn'] ?? 0) !== (int) TenantContext::id()) {
            return null;
        }
        $u = DB::table('users')->where('id', (int) ($c['uid'] ?? 0))->whereNull('deleted_at')->first();
        if (!$u || in_array($u->status, ['suspended', 'banned'], true) || !hash_equals(self::fp($u->password_hash), (string) ($c['fp'] ?? ''))) {
            return null;
        }
        return $u;
    }

    private function mailInvite(object $t, string $email, string $name, ?string $link, string $locale): bool
    {
        if (!$link) {
            return false;
        }
        $ar = $locale === 'ar';
        try {
            // الطلب جاي من مركز المطوّرين (على المنصة الأم): بنلبس الإيميل هوية المنصة (اسم/لوجو/لون/سيندر) من غير ما نبدّل قاعدة البيانات
            // (إعدادات مزوّد البريد لازم تتقرا من المنصة الأم).
            return (bool) TenantMailBrand::using($t, static fn () => app(\App\Services\MailService::class)->sendNotificationEmail(
                $email, $name,
                $ar ? "اتدعيت تنضم لـ {$t->name}" : "You're invited to {$t->name}",
                $ar ? 'اضغط على الزرار واختار كلمة المرور بتاعتك عشان تبدأ. اللينك شغّال لمدة محدودة.' : 'Use the button to choose your password and get started. The link works for a limited time.',
                $link, $locale
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Platform invite email failed', ['tenant' => $t->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function count(object $t): int
    {
        return (int) TenantContext::run($t, static fn () => DB::table('users')->whereNull('deleted_at')->count());
    }

    public function list(int $actorId, int $tenantId): array
    {
        [$t] = $this->open($actorId, $tenantId);
        $slugToRole = array_flip((array) config('tenancy.platform_users.roles', []));
        return TenantContext::run($t, function () use ($slugToRole) {
            return DB::table('users as u')->leftJoin('user_roles as ur', 'ur.user_id', '=', 'u.id')->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
                ->whereNull('u.deleted_at')->orderBy('u.id')->limit(500)
                ->get(['u.id', 'u.full_name', 'u.email', 'u.status', 'u.created_at', 'r.slug as role_slug'])
                ->unique('id')
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->full_name, 'email' => $u->email, 'status' => $u->status, 'created_at' => $u->created_at,
                    'role' => $slugToRole[$u->role_slug] ?? (string) $u->role_slug])->values()->all();
        });
    }

    /**
     * مود invite (الافتراضي): بنعمل الحساب ونبعت لينك يختار بيه الشخص باسورده (من غير ما حد يعرف باسورد).
     * مود password: باسورد مؤقت (بيكتبه المالك أو يتولّد)، وبشكل افتراضي لازم يتغيّر عند أول دخول.
     * @return array{users:array,created:array{email:string,password:?string,invited:bool,emailed:bool,invite_link:?string}}
     */
    public function create(int $actorId, int $tenantId, array $in): array
    {
        [$t] = $this->open($actorId, $tenantId);
        $slug = $this->roleSlug((string) ($in['role'] ?? 'employee'));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('Enter a valid email.');
        }
        $this->assertDomainAllowed($t, $email);
        $name = trim((string) ($in['name'] ?? '')) ?: Str::before($email, '@');
        $invite = (string) ($in['mode'] ?? 'invite') !== 'password';
        $require = !array_key_exists('require_change', $in) || (bool) $in['require_change'];
        $pass = (string) ($in['password'] ?? '');
        if (!$invite && $pass !== '') {
            $this->assertPasswordOk($t, $pass);
        }
        $generated = null;
        if ($invite) {
            $pass = Str::random(48); // مش بيتعرض لحد: الشخص بيختار باسورده من اللينك
        } elseif ($pass === '') {
            $generated = $pass = $this->generatePassword($t);
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $uid = TenantContext::run($t, function () use ($slug, $email, $name, $hash, $invite, $require) {
            if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
                $this->fail('This email already has an account on this platform.', 409);
            }
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (!$roleId) {
                $this->fail("Role '{$slug}' was not found on this platform.", 500);
            }
            return DB::transaction(function () use ($roleId, $email, $name, $hash, $invite, $require) {
                $uid = (int) DB::table('users')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'full_name' => mb_substr($name, 0, 100), 'email' => $email,
                    'password_hash' => $hash, 'status' => 'active', 'email_verified_at' => now(), 'password_changed_at' => now(),
                    'must_change_password' => (!$invite && $require) ? 1 : 0,
                ]);
                DB::table('user_roles')->insert(['user_id' => $uid, 'role_id' => $roleId]);
                return $uid;
            });
        });
        $link = $invite ? $this->inviteLink($t, $uid, $hash) : null;
        $emailed = $invite ? $this->mailInvite($t, $email, $name, $link, (string) ($in['locale'] ?? 'ar')) : false;
        $this->tenants->audit($actorId, (int) $t->id, $invite ? 'platform-user.invite' : 'platform-user.create', $email);
        return ['users' => $this->list($actorId, $tenantId), 'created' => ['email' => $email, 'password' => $generated, 'invited' => $invite, 'emailed' => $emailed, 'invite_link' => $link]];
    }

    /** يبعت لينك إعداد باسورد جديد لحساب موجود (بديل إعادة الضبط من غير ما حد يستلم باسورد). */
    public function sendInvite(int $actorId, int $tenantId, int $userId, string $locale = 'ar'): array
    {
        [$t] = $this->open($actorId, $tenantId);
        $u = TenantContext::run($t, static fn () => DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first(['id', 'email', 'full_name', 'password_hash']));
        if (!$u) {
            $this->fail('User not found.', 404);
        }
        $link = $this->inviteLink($t, (int) $u->id, $u->password_hash);
        $emailed = $this->mailInvite($t, (string) $u->email, (string) $u->full_name, $link, $locale);
        $this->tenants->audit($actorId, (int) $t->id, 'platform-user.invite-resend', '#' . $userId);
        return ['users' => $this->list($actorId, $tenantId), 'created' => ['email' => $u->email, 'password' => null, 'invited' => true, 'emailed' => $emailed, 'invite_link' => $link]];
    }

    /** @return array{users:array,reset:array{email:string,password:?string}} */
    public function resetPassword(int $actorId, int $tenantId, int $userId, ?string $password, bool $requireChange = true): array
    {
        [$t] = $this->open($actorId, $tenantId);
        $password = (string) $password;
        if ($password !== '') {
            $this->assertPasswordOk($t, $password);
        }
        $generated = $password === '' ? $this->generatePassword($t) : null;
        $password = $password !== '' ? $password : $generated;
        $email = TenantContext::run($t, function () use ($userId, $password, $requireChange) {
            $u = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first(['id', 'email']);
            if (!$u) {
                $this->fail('User not found.', 404);
            }
            $data = ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => now(), 'must_change_password' => $requireChange ? 1 : 0];
            if (Schema::hasColumn('users', 'tokens_valid_after')) {
                $data['tokens_valid_after'] = now(); // الجلسات القديمة بتبطل
            }
            DB::table('users')->where('id', $userId)->update($data);
            return $u->email;
        });
        $this->tenants->audit($actorId, (int) $t->id, 'platform-user.reset', '#' . $userId);
        return ['users' => $this->list($actorId, $tenantId), 'reset' => ['email' => $email, 'password' => $generated]];
    }

    public function setActive(int $actorId, int $tenantId, int $userId, bool $active): array
    {
        [$t] = $this->open($actorId, $tenantId);
        TenantContext::run($t, function () use ($userId, $active) {
            $last = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('r.slug', $this->roleSlug('admin'))->where('u.status', 'active')->whereNull('u.deleted_at');
            if (!$active && (clone $last)->count() <= 1 && (clone $last)->where('u.id', $userId)->exists()) {
                $this->fail('The platform needs at least one active admin.', 409);
            }
            $n = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->update(['status' => $active ? 'active' : 'suspended']);
            if (!$n) {
                $this->fail('User not found.', 404);
            }
        });
        $this->tenants->audit($actorId, (int) $t->id, $active ? 'platform-user.activate' : 'platform-user.suspend', '#' . $userId);
        return $this->list($actorId, $tenantId);
    }
}
