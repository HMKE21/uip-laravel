<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** إنشاء عميل معزول: قاعدة + migrations + أدوار + أول أدمن + دومين. بيستخدمه أمر tenant:create. */
class TenantProvisioner
{
    /**
     * @param array{name:string,domain:string,admin_email:string,admin_name?:string,admin_password?:string,slug?:?string,kind?:string} $in
     * @return array{tenant_id:int,public_id:string,db_name:string,domain:string,admin_email:string,admin_password:?string}
     */
    public function create(array $in, ?callable $log = null): array
    {
        $log ??= static function (string $m): void {};
        $central = TenantContext::centralConnection();
        $db = DB::connection($central);

        $name = trim($in['name']);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Name must be 3 to 100 characters.');
        }
        $domain = !empty($in['domain']) ? TenantDomainService::normalize($in['domain']) : null; // من غير دومين: العميل بيدخل من رابط /w/<slug>
        if ($domain !== null && $db->table('tenant_domains')->where('domain', $domain)->exists()) {
            throw new \InvalidArgumentException("Domain {$domain} is already registered.");
        }
        $email = strtolower(trim($in['admin_email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid admin email.');
        }
        $kind = (string) ($in['kind'] ?? 'company');
        if (!in_array($kind, ['company', 'university', 'developer'], true)) {
            throw new \InvalidArgumentException('Invalid kind.');
        }
        $slug = $this->uniqueSlug($db, $in['slug'] ?? $name);
        $dbName = $this->dbNameFor($slug);
        $plainPassword = null;
        $password = (string) ($in['admin_password'] ?? '');
        if ($password === '') {
            $password = $plainPassword = Str::password(16, true, true, true, false);
        } elseif (strlen($password) < 8) {
            throw new \InvalidArgumentException('Admin password must be at least 8 characters.');
        }

        if ($this->databaseExists($dbName)) {
            throw new \RuntimeException("Database {$dbName} already exists — refusing to use it.");
        }

        $tenantId = (int) $db->table('tenants')->insertGetId([
            'public_id' => 'tnt_' . Str::lower(Str::random(20)), 'name' => $name, 'slug' => $slug, 'kind' => $kind,
            'owner_user_id' => (int) ($in['owner_user_id'] ?? 0), 'status' => 'active', 'plan' => 'free',
            'mode' => 'isolated', 'db_name' => $dbName, 'db_status' => 'provisioning',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (isset($in['on_created']) && is_callable($in['on_created'])) {
            ($in['on_created'])($tenantId, $slug); // الإطلاق في الخلفية: نعرف الـ id بدري عشان شاشة التقدّم
        }
        $createdDb = false;
        try {
            $db->table('tenant_branding')->insert(['tenant_id' => $tenantId, 'display_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            if ($domain !== null) {
                $db->table('tenant_domains')->insert([
                    'tenant_id' => $tenantId, 'domain' => $domain, 'is_primary' => 1, 'status' => 'active',
                    'verification_token' => Str::random(32), 'verified_at' => now(), 'activated_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $log("Creating database {$dbName} ...");
            $this->createDatabase($dbName);
            $createdDb = true;

            $tenant = $db->table('tenants')->where('id', $tenantId)->first();
            TenantContext::run($tenant, function () use ($log, $central, $email, $in, $password, $plainPassword) {
                $log('Running migrations ...');
                $this->migrateCurrent();
                $log('Copying roles and permissions ...');
                $this->copyCatalogFrom($central);
                $log('Creating first admin ...');
                $adminId = $this->createAdmin($email, (string) ($in['admin_name'] ?? 'Admin'), $password);
                // أدمن المنصة: 2FA إجباري (بعد فترة سماح قصيرة)، وباسورد متولّد = لازم يتغيّر عند أول دخول.
                try {
                    app(\App\Services\MfaPolicyService::class)->updatePolicy(['enforced_roles' => ['admin'], 'grace_period_days' => (int) config('tenancy.launch.admin_mfa_grace_days', 3)], $adminId);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Tenant admin MFA policy not set', ['error' => $e->getMessage()]);
                }
                if ($plainPassword !== null) {
                    DB::table('users')->where('id', $adminId)->update(['must_change_password' => 1]);
                }
            });

            $db->table('tenants')->where('id', $tenantId)->update(['db_status' => 'ready', 'provisioned_at' => now(), 'updated_at' => now()]);
            if ($domain !== null) {
                TenantResolver::forget($domain);
            }
        } catch (\Throwable $e) {
            // نرجّع كل حاجة زي ما كانت عشان الأمر يتعاد من غير بقايا.
            try {
                TenantContext::deactivate();
                if ($createdDb) {
                    $this->dropDatabase($dbName);
                }
                $db->table('tenants')->where('id', $tenantId)->delete(); // الـ cascade بيمسح branding/domains
            } catch (\Throwable $cleanup) {
                $log('Cleanup failed: ' . $cleanup->getMessage());
            }
            throw $e;
        }

        return [
            'tenant_id' => $tenantId,
            'public_id' => (string) $db->table('tenants')->where('id', $tenantId)->value('public_id'),
            'db_name' => $dbName, 'slug' => $slug, 'domain' => $domain, 'admin_email' => $email, 'admin_password' => $plainPassword,
        ];
    }

    /** يشغّل أي migrations جديدة على قاعدة العميل الحالي (لازم TenantContext يكون active). */
    public function migrateCurrent(): void
    {
        $code = Artisan::call('migrate', ['--database' => TenantContext::CONNECTION, '--force' => true]);
        if ($code !== 0) {
            throw new \RuntimeException('Tenant migration failed: ' . trim(Artisan::output()));
        }
    }

    /** ينسخ الأدوار والصلاحيات من المنصة الأم كما هي (بنفس الـ ids). لازم TenantContext active. */
    public function copyCatalogFrom(string $centralConnection): void
    {
        $tables = (array) config('tenancy.isolation.copy_tables', []);
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_reverse($tables) as $t) {
                DB::table($t)->delete();
            }
            foreach ($tables as $t) {
                DB::connection($centralConnection)->table($t)->orderBy(Schema::connection($centralConnection)->hasColumn($t, 'id') ? 'id' : DB::raw('1'))
                    ->chunk(200, function ($rows) use ($t) {
                        DB::table($t)->insert($rows->map(fn ($r) => (array) $r)->all());
                    });
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function createAdmin(string $email, string $name, string $password): int
    {
        $roleSlug = (string) config('tenancy.isolation.admin_role', 'admin');
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        if (!$roleId) {
            throw new \RuntimeException("Role '{$roleSlug}' was not found in the tenant database.");
        }
        $uid = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => $name, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active', 'email_verified_at' => now(), 'password_changed_at' => now(),
        ]);
        DB::table('user_roles')->insert(['user_id' => $uid, 'role_id' => $roleId]);
        return $uid;
    }

    private function uniqueSlug($db, string $base): string
    {
        $slug = Str::slug($base) ?: 'tenant';
        $slug = rtrim(substr($slug, 0, 36), '-');
        if (strlen($slug) < 3) {
            $slug .= '-hub'; // الـ slug لازم 3 حروف على الأقل (TenantAddress::PATTERN)
        }
        $try = $slug;
        for ($i = 2; $db->table('tenants')->where('slug', $try)->exists(); $i++) {
            $try = $slug . '-' . $i;
        }
        return $try;
    }

    private function dbNameFor(string $slug): string
    {
        $name = (string) config('tenancy.isolation.db_prefix', 'uipt_') . str_replace('-', '_', $slug);
        $name = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($name)), 0, 64);
        if ($name === '') {
            throw new \RuntimeException('Cannot derive a database name.');
        }
        return $name;
    }

    private function adminConnection()
    {
        $name = (string) (config('tenancy.isolation.admin_connection') ?: TenantContext::centralConnection());
        return DB::connection($name);
    }

    private function driver(): string
    {
        return $this->adminConnection()->getDriverName();
    }

    private function databaseExists(string $db): bool
    {
        $c = $this->adminConnection();
        if ($this->driver() === 'sqlite') {
            return is_file($db);
        }
        return $c->selectOne('SELECT SCHEMA_NAME AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]) !== null;
    }

    private function createDatabase(string $db): void
    {
        if ($this->driver() === 'sqlite') {
            touch($db);
            return;
        }
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $db)) {
            throw new \RuntimeException('Unsafe database name.');
        }
        $this->adminConnection()->statement("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dropDatabase(string $db): void
    {
        if ($this->driver() === 'sqlite') {
            @unlink($db);
            return;
        }
        if (preg_match('/^[a-z0-9_]{1,64}$/', $db)) {
            $this->adminConnection()->statement("DROP DATABASE IF EXISTS `{$db}`");
        }
    }
}
