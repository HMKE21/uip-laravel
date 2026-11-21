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

    /** @return array{users:array,created:array{email:string,password:?string}} الباسورد المتولّد بيرجع مرة واحدة بس. */
    public function create(int $actorId, int $tenantId, array $in): array
    {
        [$t] = $this->open($actorId, $tenantId);
        $slug = $this->roleSlug((string) ($in['role'] ?? 'employee'));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('Enter a valid email.');
        }
        $name = trim((string) ($in['name'] ?? '')) ?: Str::before($email, '@');
        $pass = (string) ($in['password'] ?? '');
        if ($pass !== '' && strlen($pass) < 8) {
            $this->fail('The password must be at least 8 characters.');
        }
        $generated = $pass === '' ? Str::password(14, true, true, true, false) : null;
        $pass = $pass !== '' ? $pass : $generated;

        TenantContext::run($t, function () use ($slug, $email, $name, $pass) {
            if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
                $this->fail('This email already has an account on this platform.', 409);
            }
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (!$roleId) {
                $this->fail("Role '{$slug}' was not found on this platform.", 500);
            }
            DB::transaction(function () use ($roleId, $email, $name, $pass) {
                $uid = (int) DB::table('users')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'full_name' => mb_substr($name, 0, 100), 'email' => $email,
                    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'status' => 'active', 'email_verified_at' => now(), 'password_changed_at' => now(),
                ]);
                DB::table('user_roles')->insert(['user_id' => $uid, 'role_id' => $roleId]);
            });
        });
        $this->tenants->audit($actorId, (int) $t->id, 'platform-user.create', $email);
        return ['users' => $this->list($actorId, $tenantId), 'created' => ['email' => $email, 'password' => $generated]];
    }

    /** @return array{users:array,reset:array{email:string,password:?string}} */
    public function resetPassword(int $actorId, int $tenantId, int $userId, ?string $password): array
    {
        [$t] = $this->open($actorId, $tenantId);
        $password = (string) $password;
        if ($password !== '' && strlen($password) < 8) {
            $this->fail('The password must be at least 8 characters.');
        }
        $generated = $password === '' ? Str::password(14, true, true, true, false) : null;
        $password = $password !== '' ? $password : $generated;
        $email = TenantContext::run($t, function () use ($userId, $password) {
            $u = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first(['id', 'email']);
            if (!$u) {
                $this->fail('User not found.', 404);
            }
            $data = ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => now()];
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
