<?php

namespace App\Services\Tenancy;

use App\Services\Developer\DevLogService;
use App\Services\Developer\DevUsageService;
use App\Services\Developer\DeveloperAppService;
use App\Services\Developer\WebhookService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tenants (الخطوة 1): إنشاء، أعضاء وأدوار، وموارد الـ Developer API (apps / keys / webhooks) المعزولة بـ tenant_id.
 *
 * العزل:
 *  - كل مورد بيتعمل جوه tenant بيتختم بـ tenant_id، وكل قراءة/حذف من جوه الـ tenant بتفلتر بيه (مفيش وصول لمورد tenant تاني أو شخصي).
 *  - مفتاح/تطبيق tenant ممكن يتعمل بس لعضو دوره owner|admin|developer، وبيقف فورًا لو الـ tenant اتوقف أو العضو اتشال/بقى viewer (gate).
 *  - مفتاح tenant مينفعش يتربط بتطبيق خارج نفس الـ tenant.
 */
final class TenantService
{
    public const ABILITIES = [
        'view' => ['owner', 'admin', 'developer', 'viewer', 'employee'],
        'resources' => ['owner', 'admin', 'developer'],
        'branding' => ['owner', 'admin'],
        'members' => ['owner', 'admin'],
        'domains' => ['owner', 'admin'],
        'embed' => ['owner', 'admin'],
        'site' => ['owner', 'admin'],
        'features' => ['owner', 'admin'],
        'teams' => ['owner', 'admin'],
        'roles' => ['owner', 'admin'],
        'activity' => ['owner', 'admin'],
        'delete' => ['owner'],
    ];

    public function __construct(private DeveloperAppService $apps, private WebhookService $hooks, private DevLogService $logs, private DevUsageService $usage, private TenantParticipantService $participants, private TenantEmbedService $embed)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ lookup & permissions */

    public function limits(object $tenant): array
    {
        return array_merge((array) config('tenancy.limits'), array_filter((array) json_decode((string) ($tenant->limits ?? '[]'), true), 'is_int'));
    }

    public function payload(object $t, ?string $role = null): array
    {
        return [
            'id' => (int) $t->id, 'public_id' => $t->public_id, 'name' => $t->name, 'slug' => $t->slug, 'kind' => $t->kind,
            'university_id' => $t->university_id ? (int) $t->university_id : null, 'status' => $t->status, 'suspended_reason' => $t->suspended_reason,
            'plan' => $t->plan, 'features' => (array) json_decode((string) ($t->features ?? '[]'), true), 'limits' => $this->limits($t),
            'role' => $role, 'mode' => $t->mode ?? 'shared', 'created_at' => $t->created_at,
        ];
    }

    /* ------------------------------------------------------------------ custom roles support */

    /** @var array<string,array|null> */
    private static array $customMemo = [];
    private static ?bool $customOk = null;

    public static function forgetCustomRoles(): void
    {
        self::$customMemo = [];
    }

    /** الأدوار المخصصة شغّالة (الـ migration اتعمل)؟ */
    public static function customRolesReady(): bool
    {
        if (self::$customOk === null) {
            try {
                self::$customOk = Schema::hasTable('tenant_custom_roles') && Schema::hasColumn('tenant_members', 'custom_role_id');
            } catch (\Throwable $e) {
                self::$customOk = false;
            }
        }
        return self::$customOk;
    }

    /** @return array{id:int,name_en:?string,name_ar:?string,description:?string,permissions:array<string,string>}|null */
    public static function customRole(int $roleId, int $tenantId): ?array
    {
        if ($roleId <= 0 || !self::customRolesReady()) {
            return null;
        }
        $k = $tenantId . ':' . $roleId;
        if (!array_key_exists($k, self::$customMemo)) {
            $r = DB::table('tenant_custom_roles')->where('id', $roleId)->where('tenant_id', $tenantId)->first();
            self::$customMemo[$k] = $r ? ['id' => (int) $r->id, 'name_en' => $r->name_en, 'name_ar' => $r->name_ar, 'description' => $r->description,
                'permissions' => TenantPermissions::normalize((array) json_decode((string) $r->permissions, true))] : null;
        }
        return self::$customMemo[$k];
    }

    /** @return string[] abilities of a built-in role */
    public static function abilitiesOfRole(string $role): array
    {
        return array_keys(array_filter(self::ABILITIES, static fn ($r) => in_array($role, $r, true)));
    }

    /** @return array{0:object,1:object,2:string,3:?array} [tenant, member row, role, custom role] — يرمي 404 لو المستخدم مش عضو. */
    private function resolve(int $userId, int $tenantId): array
    {
        $t = DB::table('tenants')->where('id', $tenantId)->first();
        $m = $t ? DB::table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $userId)->first() : null;
        if (!$t || !$m || !$m->role) {
            $this->fail('Workspace not found.', 404);
        }
        $cr = self::customRole((int) ($m->custom_role_id ?? 0), $tenantId);
        return [$t, $m, (string) $m->role, $cr];
    }

    /** abilities الفعلية لعضو (دور أساسي أو مخصص). @return string[] */
    public function abilitiesOfMember(int $tenantId, int $userId): array
    {
        $m = DB::table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
        if (!$m) {
            return [];
        }
        $cr = self::customRole((int) ($m->custom_role_id ?? 0), $tenantId);
        return $cr ? TenantPermissions::abilities($cr['permissions']) : self::abilitiesOfRole((string) $m->role);
    }

    /** @return array{0:object,1:string} [tenant, role] — يرمي 404 لو المستخدم مش عضو (من غير ما يكشف إن الـ tenant موجود). */
    public function authorize(int $userId, int $tenantId, string $ability = 'view'): array
    {
        [$t, , $role, $cr] = $this->resolve($userId, $tenantId);
        $allowed = $cr ? in_array($ability, TenantPermissions::abilities($cr['permissions']), true) : in_array($role, self::ABILITIES[$ability] ?? [], true);
        if (!$allowed) {
            $this->fail('Your role in this workspace does not allow this.', 403);
        }
        if ($ability !== 'view' && $t->status !== 'active') {
            $this->fail('This workspace is suspended.', 403);
        }
        return [$t, $role];
    }

    /** قراءة قسم معيّن: الدور المخصص ممكن يكون مخفي عنه القسم ده (none). الأدوار الأساسية تقرأ كل حاجة ما عدا سجل النشاط (owner/admin). */
    public function authorizeRead(int $userId, int $tenantId, string $section): array
    {
        [$t, , $role, $cr] = $this->resolve($userId, $tenantId);
        if ($cr ? TenantPermissions::level($cr['permissions'], $section) === 'none' : ($section === 'activity' && !in_array($role, self::ABILITIES['activity'], true))) {
            $this->fail('Your role in this workspace does not allow this.', 403);
        }
        return [$t, $role];
    }

    /** 'custom:12' → ['viewer', 12] · 'admin' → ['admin', null] */
    public static function parseRole(string $role): array
    {
        if (preg_match('/^custom:(\d+)$/', $role, $m) === 1) {
            return ['viewer', (int) $m[1]];
        }
        return [$role, null];
    }

    /** مين مش owner/admin ميقدرش يدي (أو يعدّل في) حد صلاحياته أكبر من صلاحياته هو. */
    private function assertNotAbove(int $tenantId, int $actorId, string $actorRole, array $targetAbilities): void
    {
        if (in_array($actorRole, ['owner', 'admin'], true)) {
            return;
        }
        $mine = $this->abilitiesOfMember($tenantId, $actorId);
        if (array_diff($targetAbilities, $mine)) {
            $this->fail('You cannot give or manage permissions beyond your own.', 403);
        }
    }

    private function grantAbilities(int $tenantId, string $base, ?int $customId): array
    {
        if ($customId) {
            $cr = self::customRole($customId, $tenantId);
            if (!$cr) {
                $this->fail('Role not found.', 404);
            }
            return TenantPermissions::abilities($cr['permissions']);
        }
        return self::abilitiesOfRole($base);
    }

    public function mine(int $userId): array
    {
        return DB::table('tenant_members as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')->where('m.user_id', $userId)->orderBy('t.name')
            ->get(['t.*', 'm.role as my_role'])->map(fn ($t) => $this->payload($t, $t->my_role))->all();
    }

    public function show(int $userId, int $tenantId): array
    {
        [$t, $role] = $this->authorize($userId, $tenantId);
        $counts = [
            'members' => DB::table('tenant_members')->where('tenant_id', $t->id)->count(),
            'domains' => DB::table('tenant_domains')->where('tenant_id', $t->id)->count(),
            'apps' => DB::table('dev_apps')->where('tenant_id', $t->id)->count(),
            'keys' => DB::table('dev_api_keys')->where('tenant_id', $t->id)->whereNull('revoked_at')->count(),
            'webhooks' => DB::table('dev_webhooks')->where('tenant_id', $t->id)->count(),
            'participants' => Schema::hasTable('tenant_participants') ? DB::table('tenant_participants')->where('tenant_id', $t->id)->count() : 0,
            'embed_origins' => Schema::hasTable('tenant_embed_origins') ? DB::table('tenant_embed_origins')->where('tenant_id', $t->id)->count() : 0,
        ];
        $cr = self::customRolesReady() ? self::customRole((int) (DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->value('custom_role_id') ?? 0), (int) $t->id) : null;
        return ['tenant' => $this->payload($t, $role), 'branding' => TenantBranding::get((int) $t->id), 'counts' => $counts,
            'abilities' => $this->abilitiesOfMember((int) $t->id, $userId),
            'custom_role' => $cr ? ['id' => $cr['id'], 'name_en' => $cr['name_en'], 'name_ar' => $cr['name_ar']] : null,
            'permissions' => $cr ? $cr['permissions'] : null];
    }

    /* ------------------------------------------------------------------ create / update / delete */

    private function slugFor(string $name): string
    {
        $base = Str::limit(Str::slug($name), 40, '');
        $base = $base !== '' ? $base : 'workspace';
        $slug = $base;
        for ($i = 0; DB::table('tenants')->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . Str::lower(Str::random(4 + min($i, 4)));
        }
        return $slug;
    }

    public function create(int $userId, array $in): array
    {
        $u = DB::table('users')->where('id', $userId)->first(['status', 'deleted_at']);
        if (!$u || $u->deleted_at !== null || in_array($u->status, ['suspended', 'banned'], true)) {
            $this->fail('Your account cannot create a workspace.', 403);
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $this->fail('The workspace name must be 3 to 100 characters.');
        }
        $kind = (string) ($in['kind'] ?? 'company');
        if (!in_array($kind, ['company', 'university', 'developer'], true)) {
            $this->fail('Invalid workspace type.');
        }
        if (DB::table('tenant_members')->where('user_id', $userId)->where('role', 'owner')->count() >= (int) config('tenancy.max_owned_per_user', 5)) {
            $this->fail('You reached the maximum number of workspaces you can own.', 403);
        }
        $uniId = null;
        if ($kind === 'university') {
            $uniId = DB::table('universities')->where('user_id', $userId)->whereNull('deleted_at')->value('id');
            if (!$uniId) {
                $this->fail('Only a university account can create a university workspace.', 403);
            }
            if (DB::table('tenants')->where('university_id', $uniId)->exists()) {
                $this->fail('This university already has a workspace.', 409);
            }
        }
        $id = DB::transaction(function () use ($userId, $name, $kind, $uniId) {
            $id = DB::table('tenants')->insertGetId([
                'public_id' => 'tnt_' . Str::lower(Str::random(20)), 'name' => $name, 'slug' => $this->slugFor($name), 'kind' => $kind,
                'university_id' => $uniId, 'owner_user_id' => $userId, 'status' => 'active', 'plan' => 'free', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tenant_members')->insert(['tenant_id' => $id, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_branding')->insert(['tenant_id' => $id, 'display_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            return $id;
        });
        $this->audit($userId, $id, 'create');
        return $this->show($userId, (int) $id);
    }

    /** الاختيارات المتاحة في معالج الإطلاق: القوالب والميزات (وأنهي بتتفعّل فورًا). */
    public function launchOptions(): array
    {
        $auto = (array) config('tenancy.launch.auto_features', []);
        $features = [];
        foreach (TenantFeatures::catalog() as $key => $f) {
            $features[] = ['key' => $key, 'label_en' => $f['label_en'] ?? $key, 'label_ar' => $f['label_ar'] ?? $key, 'instant' => in_array($key, $auto, true)];
        }
        return [
            'enabled' => (bool) config('tenancy.launch.enabled', true) && (bool) config('tenancy.isolation.enabled', true) && TenantAddress::enabled(),
            'templates' => array_values(array_map([TenantSiteTemplates::class, 'card'], TenantSiteTemplates::builtin())),
            'features' => $features,
        ];
    }

    /**
     * إطلاق منصة معزولة بالكامل (Shopify-style): قاعدة بيانات + أدمن أول + قالب الموقع منشور + الميزات المختارة، والرابط /w/<slug> جاهز.
     * المنشئ بيبقى owner في tenant_members (يدير المنصة من مركز المطورين). أدمن المنصة نفسها حساب منفصل جوّه قاعدتها.
     * @return array{launch_id:string,status:string,progress:int}
     */
    public function launch(int $userId, array $in): array
    {
        if (!$this->launchOptions()['enabled']) {
            $this->fail('Launching platforms is not enabled.', 403);
        }
        $u = DB::table('users')->where('id', $userId)->first(['status', 'deleted_at']);
        if (!$u || $u->deleted_at !== null || in_array($u->status, ['suspended', 'banned'], true)) {
            $this->fail('Your account cannot launch a platform.', 403);
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $this->fail('The platform name must be 3 to 100 characters.');
        }
        $kind = (string) ($in['kind'] ?? 'company');
        if (!in_array($kind, ['company', 'developer'], true)) {
            $this->fail('Invalid platform type.');
        }
        if (DB::table('tenant_members')->where('user_id', $userId)->where('role', 'owner')->count() >= (int) config('tenancy.max_owned_per_user', 5)) {
            $this->fail('You reached the maximum number of workspaces you can own.', 403);
        }
        $adminEmail = strtolower(trim((string) ($in['admin_email'] ?? '')));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('Enter a valid email for the first admin.');
        }
        $adminPass = (string) ($in['admin_password'] ?? '');
        if ($adminPass !== '' && strlen($adminPass) < 8) {
            $this->fail('The admin password must be at least 8 characters.');
        }
        $catalog = array_keys(TenantFeatures::catalog());
        $wanted = array_values(array_unique(array_map('strval', (array) ($in['features'] ?? []))));
        foreach ($wanted as $f) {
            if (!in_array($f, $catalog, true)) {
                $this->fail("Unknown feature '{$f}'.");
            }
        }
        $templateKey = (string) ($in['template'] ?? '');
        if ($templateKey !== '' && !TenantSiteTemplates::find($templateKey)) {
            $this->fail('Template not found.', 404);
        }

        if (DB::table('tenant_launches')->where('user_id', $userId)->whereIn('status', ['queued', 'running'])->where('updated_at', '>', now()->subMinutes(20))->exists()) {
            $this->fail('A platform is already being created for you. Wait until it finishes.', 429);
        }
        $uuid = (string) Str::uuid();
        $launchId = (int) DB::table('tenant_launches')->insertGetId([
            'uuid' => $uuid, 'user_id' => $userId, 'status' => 'queued', 'step' => 'queued', 'progress' => 0,
            'payload' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
                'name' => $name, 'kind' => $kind, 'admin_email' => $adminEmail, 'admin_name' => trim((string) ($in['admin_name'] ?? '')),
                'admin_password' => $adminPass, 'template' => $templateKey, 'features' => $wanted,
            ], JSON_UNESCAPED_UNICODE)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // after_response: بيشتغل بعد ما الرد يتبعت (من غير queue worker). queue: لو عندك worker شغّال.
        if (config('tenancy.launch.run', 'after_response') === 'queue') {
            \App\Jobs\RunTenantLaunch::dispatch($launchId);
        } else {
            \App\Jobs\RunTenantLaunch::dispatchAfterResponse($launchId);
        }
        return ['launch_id' => $uuid, 'status' => 'queued', 'progress' => 0];
    }

    public function launchStatus(int $userId, string $uuid): array
    {
        return app(TenantLauncher::class)->status($userId, $uuid) ?? $this->fail('Launch not found.', 404);
    }

    public function activeLaunch(int $userId, bool $peek = false): array
    {
        return ['launch' => app(TenantLauncher::class)->active($userId, $peek)];
    }

    public function rename(int $userId, int $tenantId, string $name): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'branding');
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $this->fail('The workspace name must be 3 to 100 characters.');
        }
        DB::table('tenants')->where('id', $t->id)->update(['name' => $name, 'updated_at' => now()]);
        $this->audit($userId, (int) $t->id, 'rename');
        return $this->show($userId, $tenantId);
    }

    public function updateBranding(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'branding');
        $b = TenantBranding::update($t, $in);
        $this->forgetHosts((int) $t->id);
        $this->audit($userId, (int) $t->id, 'branding');
        return $b;
    }

    /** حذف كامل: بيشيل مفاتيح وتطبيقات وwebhooks الـ tenant بنفس مسارات الحذف القديمة، وبعدها الـ tenant. للـ owner بس ومحتاج تأكيد بالـ slug. */
    public function delete(int $userId, int $tenantId, string $confirmSlug): void
    {
        [$t] = $this->authorize($userId, $tenantId, 'delete');
        if (!hash_equals((string) $t->slug, trim($confirmSlug))) {
            $this->fail('Type the workspace slug exactly to confirm.');
        }
        $this->forgetHosts((int) $t->id);
        DB::table('dev_api_keys')->where('tenant_id', $t->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('dev_webhooks')->where('tenant_id', $t->id)->get(['id', 'user_id']) as $h) {
            $this->hooks->delete((int) $h->user_id, (int) $h->id);
        }
        foreach (DB::table('dev_apps')->where('tenant_id', $t->id)->get(['id', 'owner_user_id']) as $a) {
            $this->apps->deleteApp((int) $a->owner_user_id, (int) $a->id);
        }
        DB::table('dev_api_keys')->where('tenant_id', $t->id)->update(['tenant_id' => null]);   // الصفوف الملغاة بتفضل في السجل من غير ربط
        foreach (['tenant_embed_sessions', 'tenant_participants', 'tenant_embed_origins'] as $tbl) {   // من غير الاعتماد على cascade
            if (Schema::hasTable($tbl)) {
                DB::table($tbl)->where('tenant_id', $t->id)->delete();
            }
        }
        DB::table('tenants')->where('id', $t->id)->delete();                                      // members / branding / domains cascade
        $this->audit($userId, null, 'delete', $t->slug);
    }

    /* ------------------------------------------------------------------ members */

    public function members(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'members');
        $ready = self::customRolesReady();
        $cols = ['m.user_id', 'm.role', 'm.created_at', 'u.email', 'u.full_name'] + ($ready ? [5 => 'm.custom_role_id'] : []);
        $teams = [];
        if ($ready && Schema::hasTable('tenant_team_members')) {
            foreach (DB::table('tenant_team_members')->where('tenant_id', $t->id)->get(['team_id', 'user_id']) as $r) {
                $teams[(int) $r->user_id][] = (int) $r->team_id;
            }
        }
        return DB::table('tenant_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $t->id)->orderBy('m.id')
            ->get($cols)
            ->map(function ($m) use ($t, $teams) {
                $cr = self::customRole((int) ($m->custom_role_id ?? 0), (int) $t->id);
                return ['user_id' => (int) $m->user_id, 'role' => $m->role, 'email' => $m->email, 'name' => $m->full_name, 'joined_at' => $m->created_at,
                    'custom_role' => $cr ? ['id' => $cr['id'], 'name_en' => $cr['name_en'], 'name_ar' => $cr['name_ar']] : null, 'teams' => $teams[(int) $m->user_id] ?? []];
            })->all();
    }

    /** @return array<string,array{0:?string,1:?string}> role => [English, Arabic] — الأدوار اللي ليها اسم مخصص بس. */
    public function roleLabels(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'members');
        try {
            return DB::table('tenant_role_labels')->where('tenant_id', $t->id)->get(['role', 'label_en', 'label_ar'])
                ->mapWithKeys(fn ($r) => [$r->role => [$r->label_en, $r->label_ar]])->all();
        } catch (\Throwable $e) {
            return []; // الـ migration لسه متشغلتش
        }
    }

    /** @param array<string,mixed> $labels role => ['en' => ..., 'ar' => ...]؛ الاتنين فاضيين = رجوع للاسم الافتراضي. */
    public function saveRoleLabels(int $actorId, int $tenantId, array $labels): array
    {
        [$t] = $this->authorize($actorId, $tenantId, 'members');
        foreach ((array) config('tenancy.roles') as $role) {
            if (!isset($labels[$role]) || !is_array($labels[$role])) {
                continue;
            }
            $en = trim(strip_tags((string) ($labels[$role]['en'] ?? '')));
            $ar = trim(strip_tags((string) ($labels[$role]['ar'] ?? '')));
            $en = mb_substr($en, 0, 40);
            $ar = mb_substr($ar, 0, 40);
            $q = DB::table('tenant_role_labels')->where('tenant_id', $t->id)->where('role', $role);
            if ($en === '' && $ar === '') {
                $q->delete();
            } elseif ($q->exists()) {
                $q->update(['label_en' => $en ?: null, 'label_ar' => $ar ?: null, 'updated_at' => now()]);
            } else {
                DB::table('tenant_role_labels')->insert(['tenant_id' => $t->id, 'role' => $role, 'label_en' => $en ?: null, 'label_ar' => $ar ?: null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->audit($actorId, (int) $t->id, 'role.labels', '');
        return $this->roleLabels($actorId, $tenantId);
    }

    private function assertAssignable(string $actorRole, string $newRole, ?string $targetCurrent = null): void
    {
        if (!in_array($newRole, (array) config('tenancy.roles'), true)) {
            $this->fail('Invalid role.');
        }
        if ($actorRole !== 'owner' && (in_array($newRole, ['owner', 'admin'], true) || in_array($targetCurrent, ['owner', 'admin'], true))) {
            $this->fail('Only an owner can manage owners and admins.', 403);
        }
    }

    public function addMember(int $actorId, int $tenantId, string $email, string $role): array
    {
        [$t, $actorRole] = $this->authorize($actorId, $tenantId, 'members');
        [$base, $customId] = self::parseRole($role);
        $this->assertAssignable($actorRole, $base);
        $this->assertNotAbove((int) $t->id, $actorId, $actorRole, $this->grantAbilities((int) $t->id, $base, $customId));
        if (DB::table('tenant_members')->where('tenant_id', $t->id)->count() >= (int) $this->limits($t)['members']) {
            $this->fail('This workspace reached its member limit.', 403);
        }
        $domains = (array) (TenantSecurity::forTenant((int) $t->id)['allowed_domains'] ?? []);
        if (!TenantSecurity::emailAllowed($email, $domains)) {
            $this->fail('Only these email domains are allowed in this workspace: ' . implode(', ', array_map(fn ($d) => '@' . $d, $domains)), 422);
        }
        $u = DB::table('users')->whereRaw('lower(email) = ?', [strtolower(trim($email))])->whereNull('deleted_at')->first(['id', 'status']);
        if (!$u || in_array($u->status, ['suspended', 'banned'], true)) {
            $this->fail('No active UIPE account uses this email.', 404);
        }
        if (DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $u->id)->exists()) {
            $this->fail('This person is already a member.', 409);
        }
        DB::table('tenant_members')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role' => $base, 'invited_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]
            + (self::customRolesReady() ? ['custom_role_id' => $customId] : []));
        WorkspaceAccess::forget((int) $t->id, (int) $u->id);
        $this->audit($actorId, (int) $t->id, 'member.add', '#' . $u->id . ' ' . ($customId ? 'custom:' . $customId : $base));
        return $this->members($actorId, $tenantId);
    }

    private function ownerCount(int $tenantId): int
    {
        return (int) DB::table('tenant_members')->where('tenant_id', $tenantId)->where('role', 'owner')->count();
    }

    public function setRole(int $actorId, int $tenantId, int $userId, string $role): array
    {
        [$t, $actorRole] = $this->authorize($actorId, $tenantId, 'members');
        $cur = DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->value('role');
        if (!$cur) {
            $this->fail('Member not found.', 404);
        }
        [$base, $customId] = self::parseRole($role);
        $this->assertAssignable($actorRole, $base, (string) $cur);
        $this->assertNotAbove((int) $t->id, $actorId, $actorRole, $this->grantAbilities((int) $t->id, $base, $customId));
        if ($userId !== $actorId) {
            $this->assertNotAbove((int) $t->id, $actorId, $actorRole, $this->abilitiesOfMember((int) $t->id, $userId));
        }
        if ($cur === 'owner' && $base !== 'owner' && $this->ownerCount((int) $t->id) <= 1) {
            $this->fail('A workspace needs at least one owner.', 409);
        }
        DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->update(['role' => $base, 'updated_at' => now()]
            + (self::customRolesReady() ? ['custom_role_id' => $customId] : []));
        Cache::forget("tenant:gate:{$t->id}:{$userId}");
        $this->audit($actorId, (int) $t->id, 'member.role', '#' . $userId . ' ' . $cur . '→' . ($customId ? 'custom:' . $customId : $base));
        return $this->members($actorId, $tenantId);
    }

    /** إزالة عضو، أو خروج المستخدم بنفسه. مفاتيحه وتطبيقاته وwebhooks بتاعته داخل الـ tenant بتقف. */
    public function removeMember(int $actorId, int $tenantId, int $userId): void
    {
        $self = $actorId === $userId;
        [$t, $actorRole] = $this->authorize($actorId, $tenantId, $self ? 'view' : 'members');
        $cur = DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->value('role');
        if (!$cur) {
            $this->fail('Member not found.', 404);
        }
        if (!$self && $actorRole !== 'owner' && in_array($cur, ['owner', 'admin'], true)) {
            $this->fail('Only an owner can remove owners and admins.', 403);
        }
        if (!$self) {
            $this->assertNotAbove((int) $t->id, $actorId, $actorRole, $this->abilitiesOfMember((int) $t->id, $userId));
        }
        if ($cur === 'owner' && $this->ownerCount((int) $t->id) <= 1) {
            $this->fail('A workspace needs at least one owner. Make someone else an owner first.', 409);
        }
        DB::transaction(function () use ($t, $userId) {
            DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->delete();
            if (self::customRolesReady() && Schema::hasTable('tenant_team_members')) {
                DB::table('tenant_team_members')->where('tenant_id', $t->id)->where('user_id', $userId)->delete();
            }
            $this->stopMemberResources((int) $t->id, $userId, 'Owner left the workspace');
        });
        Cache::forget("tenant:gate:{$t->id}:{$userId}");
        WorkspaceAccess::forget((int) $t->id, $userId);
        $this->audit($actorId, (int) $t->id, $self ? 'member.leave' : 'member.remove', '#' . $userId);
    }

    private function stopMemberResources(int $tenantId, int $userId, string $why): void
    {
        DB::table('dev_api_keys')->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('dev_apps')->where('tenant_id', $tenantId)->where('owner_user_id', $userId)->where('status', 'active')
            ->update(['status' => 'suspended', 'suspended_by' => 'tenant', 'suspended_reason' => $why, 'updated_at' => now()]);
        DB::table('dev_webhooks')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('is_active', 1)
            ->update(['is_active' => 0, 'disabled_at' => now(), 'disabled_reason' => $why, 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ domains */

    public function domains(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'domains');
        return TenantDomainService::list((int) $t->id);
    }

    public function addDomain(int $userId, int $tenantId, string $domain): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'domains');
        if (!TenantBranding::hasFeature($t, 'custom_domain')) {
            $this->fail('Custom domains are not enabled for this workspace. Ask the platform team to enable them.', 403);
        }
        $d = TenantDomainService::register((int) $t->id, $domain, (int) $this->limits($t)['domains']);
        $this->audit($userId, (int) $t->id, 'domain.add', $d['domain']);
        return $d;
    }

    public function verifyDomain(int $userId, int $tenantId, int $id): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'domains');
        return TenantDomainService::verify((int) $t->id, $id);
    }

    public function primaryDomain(int $userId, int $tenantId, int $id): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'domains');
        return TenantDomainService::setPrimary((int) $t->id, $id);
    }

    public function removeDomain(int $userId, int $tenantId, int $id): void
    {
        [$t] = $this->authorize($userId, $tenantId, 'domains');
        TenantDomainService::remove((int) $t->id, $id);
        $this->audit($userId, (int) $t->id, 'domain.remove', '#' . $id);
    }

    private function forgetHosts(int $tenantId): void
    {
        foreach (DB::table('tenant_domains')->where('tenant_id', $tenantId)->pluck('domain') as $d) {
            TenantDomainService::forget((string) $d);
        }
    }

    /* ------------------------------------------------------------------ resources (apps / keys / webhooks) */

    public function listApps(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'resources');
        return DB::table('dev_apps')->where('tenant_id', $t->id)->orderByDesc('id')->get()->map(fn ($a) => $this->apps->appPayload($a))->all();
    }

    public function listKeys(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'resources');
        return DB::table('dev_api_keys')->where('tenant_id', $t->id)->orderByDesc('id')->get()->map(fn ($k) => $this->apps->keyPayload($k) + ['owner_user_id' => (int) $k->user_id])->all();
    }

    public function listWebhooks(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'resources');
        return DB::table('dev_webhooks')->where('tenant_id', $t->id)->orderByDesc('id')->get()->map(fn ($h) => $this->hooks->payload($h) + ['owner_user_id' => (int) $h->user_id])->all();
    }

    private function assertAppInTenant(int $appId, int $tenantId): void
    {
        if (!DB::table('dev_apps')->where('id', $appId)->where('tenant_id', $tenantId)->exists()) {
            $this->fail('The app must belong to the same workspace.', 422);
        }
    }

    public function createApp(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'resources');
        if (DB::table('dev_apps')->where('tenant_id', $t->id)->count() >= (int) $this->limits($t)['apps']) {
            $this->fail('This workspace reached its app limit.', 403);
        }
        $out = $this->apps->createApp($userId, $in);
        DB::table('dev_apps')->where('id', $out['app']['id'])->update(['tenant_id' => $t->id]);
        $out['app']['tenant_id'] = (int) $t->id;
        $this->audit($userId, (int) $t->id, 'app.create', '#' . $out['app']['id']);
        return $out;
    }

    public function createKey(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'resources');
        if (DB::table('dev_api_keys')->where('tenant_id', $t->id)->whereNull('revoked_at')->count() >= (int) $this->limits($t)['keys']) {
            $this->fail('This workspace reached its API key limit.', 403);
        }
        if (!empty($in['app_id'])) {
            $this->assertAppInTenant((int) $in['app_id'], (int) $t->id);
        }
        $out = $this->apps->createKey($userId, $in);
        DB::table('dev_api_keys')->where('id', $out['id'])->update(['tenant_id' => $t->id]);
        $out['tenant_id'] = (int) $t->id;
        $this->audit($userId, (int) $t->id, 'key.create', '#' . $out['id']);
        return $out;
    }

    public function createWebhook(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'resources');
        if (DB::table('dev_webhooks')->where('tenant_id', $t->id)->count() >= (int) $this->limits($t)['webhooks']) {
            $this->fail('This workspace reached its webhook limit.', 403);
        }
        if (!empty($in['app_id'])) {
            $this->assertAppInTenant((int) $in['app_id'], (int) $t->id);
        }
        $out = $this->hooks->create($userId, $in);
        $hid = (int) ($out['id'] ?? ($out['webhook']['id'] ?? 0));
        if ($hid) {
            DB::table('dev_webhooks')->where('id', $hid)->update(['tenant_id' => $t->id]);
        }
        $this->audit($userId, (int) $t->id, 'webhook.create', '#' . $hid);
        return $out;
    }

    /** owner/admin يشيلوا أي مورد في الـ tenant، والـ developer بتاعه هو بس. بيعدّي من نفس مسارات الحذف القديمة بهوية صاحب المورد. */
    private function assertCanManage(string $role, int $actorId, int $ownerId): void
    {
        if (!in_array($role, ['owner', 'admin'], true) && $actorId !== $ownerId) {
            $this->fail('You can only manage your own resources in this workspace.', 403);
        }
    }

    public function revokeKey(int $userId, int $tenantId, int $keyId): void
    {
        [$t, $role] = $this->authorize($userId, $tenantId, 'resources');
        $k = DB::table('dev_api_keys')->where('id', $keyId)->where('tenant_id', $t->id)->first();
        if (!$k) {
            $this->fail('API key not found.', 404);
        }
        $this->assertCanManage($role, $userId, (int) $k->user_id);
        $this->apps->revokeKey((int) $k->user_id, (int) $k->id);
        $this->audit($userId, (int) $t->id, 'key.revoke', '#' . $keyId);
    }

    public function deleteApp(int $userId, int $tenantId, int $appId): void
    {
        [$t, $role] = $this->authorize($userId, $tenantId, 'resources');
        $a = DB::table('dev_apps')->where('id', $appId)->where('tenant_id', $t->id)->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        $this->assertCanManage($role, $userId, (int) $a->owner_user_id);
        $this->apps->deleteApp((int) $a->owner_user_id, (int) $a->id);
        $this->audit($userId, (int) $t->id, 'app.delete', '#' . $appId);
    }

    public function deleteWebhook(int $userId, int $tenantId, int $hookId): void
    {
        [$t, $role] = $this->authorize($userId, $tenantId, 'resources');
        $h = DB::table('dev_webhooks')->where('id', $hookId)->where('tenant_id', $t->id)->first();
        if (!$h) {
            $this->fail('Webhook not found.', 404);
        }
        $this->assertCanManage($role, $userId, (int) $h->user_id);
        $this->hooks->delete((int) $h->user_id, (int) $h->id);
        $this->audit($userId, (int) $t->id, 'webhook.delete', '#' . $hookId);
    }

    /* ------------------------------------------------------------------ participants (المستخدمين النهائيين للعميل — من غير حساب UIPE) */

    public function listParticipants(int $userId, int $tenantId, array $f = []): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'embed');
        return $this->participants->list((int) $t->id, $f);
    }

    public function saveParticipant(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'resources');
        $out = $this->participants->upsert((int) $t->id, $in, (int) $this->limits($t)['participants']);
        $this->audit($userId, (int) $t->id, 'participant.' . ($out['created'] ? 'create' : 'update'), (string) $out['participant']['external_id']);
        return ['participant' => $out['participant'], 'created' => $out['created']];
    }

    public function deleteParticipant(int $userId, int $tenantId, string $externalId): void
    {
        [$t] = $this->authorize($userId, $tenantId, 'resources');
        $this->participants->delete((int) $t->id, $externalId);
        $this->audit($userId, (int) $t->id, 'participant.delete', $externalId);
    }

    /* ------------------------------------------------------------------ embed: النطاقات المسموحة */

    public function embedOrigins(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'embed');
        return ['origins' => $this->embed->listOrigins((int) $t->id), 'frame_ancestors' => $this->embed->frameAncestors((int) $t->id)];
    }

    public function addEmbedOrigin(int $userId, int $tenantId, string $origin): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'embed');
        $o = $this->embed->addOrigin((int) $t->id, $origin, (int) $this->limits($t)['embed_origins']);
        $this->audit($userId, (int) $t->id, 'embed.origin.add', $o['origin']);
        return $o;
    }

    public function removeEmbedOrigin(int $userId, int $tenantId, int $id): void
    {
        [$t] = $this->authorize($userId, $tenantId, 'embed');
        $this->embed->removeOrigin((int) $t->id, $id);
        $this->audit($userId, (int) $t->id, 'embed.origin.remove', '#' . $id);
    }

    /* ------------------------------------------------------------------ نفس العمليات لمفتاح Developer API تابع لـ tenant (من غير عضو) */

    private function activeTenant(int $tenantId): object
    {
        $t = DB::table('tenants')->where('id', $tenantId)->first();
        if (!$t || $t->status !== 'active') {
            $this->fail('This workspace is not available.', 403);
        }
        return $t;
    }

    public function apiListParticipants(int $tenantId, array $f): array
    {
        return $this->participants->list((int) $this->activeTenant($tenantId)->id, $f);
    }

    public function apiGetParticipant(int $tenantId, string $externalId): array
    {
        return $this->participants->get((int) $this->activeTenant($tenantId)->id, $externalId);
    }

    public function apiSaveParticipant(int $tenantId, array $in): array
    {
        $t = $this->activeTenant($tenantId);
        $out = $this->participants->upsert((int) $t->id, $in, (int) $this->limits($t)['participants']);
        $this->audit(0, (int) $t->id, 'participant.' . ($out['created'] ? 'create' : 'update'), (string) $out['participant']['external_id']);
        return ['participant' => $out['participant'], 'created' => $out['created']];
    }

    public function apiDeleteParticipant(int $tenantId, string $externalId): void
    {
        $t = $this->activeTenant($tenantId);
        $this->participants->delete((int) $t->id, $externalId);
        $this->audit(0, (int) $t->id, 'participant.delete', $externalId);
    }

    /** توكن دخول Embed لمشارك (upsert في نفس الطلب). */
    public function apiCreateEmbedSession(int $tenantId, array $in): array
    {
        $t = $this->activeTenant($tenantId);
        $out = $this->embed->createSession($t, $in, (int) $this->limits($t)['participants']);
        $this->audit(0, (int) $t->id, 'embed.session', (string) $out['participant']['external_id']);
        return $out;
    }

    /** استهلاك الـ Workspace (Rate Limit Dashboard) بنفس شكل /developer/usage — مفاتيح وتطبيقات الـ tenant بس. ?range=1h|24h|7d */
    public function usageReport(int $userId, int $tenantId, string $range): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'resources');
        return $this->usage->report($userId, $range, (int) $t->id);
    }

    /** سجل الـ Workspace المعزول: كل الأعضاء بيشوفوا طلبات tenant بتاعهم بس (مفاتيحه وتطبيقاته وأحداث إدارته). */
    public function logs(int $userId, int $tenantId, array $filters, int $page = 1, int $per = 30): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'resources');
        $allowed = array_intersect_key($filters, array_flip(['kind', 'status', 'from', 'to', 'q', 'app_id', 'api_key_id']));
        return $this->logs->search($allowed + ['tenant_id' => (int) $t->id], $page, $per);
    }

    /* ------------------------------------------------------------------ gate for the Developer API */

    /**
     * بتتنادى من DeveloperAuthService لكل طلب بمفتاح/توكن تابع لـ tenant. بترجّع كود الرفض أو null لو سليم.
     * متكاشّية 10 ثواني (نفس كاش حالة الحساب)، وبتتمسح لما الدور يتغيّر أو العضو يتشال.
     */
    public static function gate(int $tenantId, int $userId): ?string
    {
        $code = Cache::remember("tenant:gate:{$tenantId}:{$userId}", 10, function () use ($tenantId, $userId) {
            $status = DB::table('tenants')->where('id', $tenantId)->value('status');
            if ($status === null) {
                return 'tenant_not_found';
            }
            if ($status !== 'active') {
                return 'tenant_suspended';
            }
            $m = DB::table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
            if (!$m || !$m->role) {
                return 'tenant_membership_revoked';
            }
            $cr = self::customRole((int) ($m->custom_role_id ?? 0), $tenantId);
            $ok = $cr ? in_array('resources', TenantPermissions::abilities($cr['permissions']), true) : in_array($m->role, self::ABILITIES['resources'], true);
            return $ok ? '' : 'tenant_role_insufficient';
        });
        return $code === '' ? null : $code;
    }

    /* ------------------------------------------------------------------ platform admin */

    public function adminList(?string $q, ?string $status, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $qb = DB::table('tenants as t')->when($status, fn ($x) => $x->where('t.status', $status))
            ->when($q, fn ($x) => $x->where(fn ($y) => $y->where('t.name', 'like', '%' . addcslashes($q, '%_\\') . '%')->orWhere('t.slug', 'like', '%' . addcslashes($q, '%_\\') . '%')->orWhere('t.public_id', $q)));
        $total = (clone $qb)->count();
        $rows = $qb->orderByDesc('t.id')->forPage($page, $per)->get(['t.*'])->map(function ($t) {
            return $this->payload($t) + [
                'members' => DB::table('tenant_members')->where('tenant_id', $t->id)->count(),
                'domains' => DB::table('tenant_domains')->where('tenant_id', $t->id)->count(),
                'keys' => DB::table('dev_api_keys')->where('tenant_id', $t->id)->whereNull('revoked_at')->count(),
            ];
        })->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    /** أدمن المنصة: حالة (توقيف/تشغيل) + خطة + features + حدود. */
    public function adminUpdate(int $adminId, int $tenantId, array $in): array
    {
        $t = DB::table('tenants')->where('id', $tenantId)->first();
        if (!$t) {
            $this->fail('Workspace not found.', 404);
        }
        $upd = [];
        if (isset($in['status'])) {
            if (!in_array($in['status'], ['active', 'suspended'], true)) {
                $this->fail('Invalid status.');
            }
            $upd['status'] = $in['status'];
            $upd['suspended_reason'] = $in['status'] === 'suspended' ? mb_substr(trim((string) ($in['suspended_reason'] ?? '')), 0, 190) ?: null : null;
        }
        if (isset($in['plan'])) {
            $plan = trim((string) $in['plan']);
            if (!preg_match('/^[a-z0-9_-]{1,20}$/', $plan)) {
                $this->fail('Invalid plan name.');
            }
            $upd['plan'] = $plan;
        }
        if (isset($in['features'])) {
            $f = array_values(array_unique(array_map('strval', (array) $in['features'])));
            if ($bad = array_diff($f, (array) config('tenancy.features'))) {
                $this->fail('Unknown feature: ' . implode(', ', $bad));
            }
            $upd['features'] = json_encode($f);
        }
        if (isset($in['limits'])) {
            $lim = [];
            foreach ((array) $in['limits'] as $k => $v) {
                if (!array_key_exists($k, (array) config('tenancy.limits')) || !is_numeric($v) || (int) $v < 0 || (int) $v > 100000) {
                    $this->fail("Invalid limit '{$k}'.");
                }
                $lim[$k] = (int) $v;
            }
            $upd['limits'] = json_encode($lim);
        }
        if ($upd) {
            DB::table('tenants')->where('id', $tenantId)->update($upd + ['updated_at' => now()]);
            $this->forgetHosts($tenantId);
            foreach (DB::table('tenant_members')->where('tenant_id', $tenantId)->pluck('user_id') as $uid) {
                Cache::forget("tenant:gate:{$tenantId}:{$uid}");
            }
            $this->audit($adminId, $tenantId, 'admin.update', implode(',', array_keys($upd)));
        }
        return $this->payload(DB::table('tenants')->find($tenantId));
    }

    /* ------------------------------------------------------------------ features / sign-in / checklist */

    private const SELF_OFF_NOTE = 'turned off by the workspace';

    /** @return array<int,array<string,mixed>> كتالوج الميزات بحالة كل ميزة للـ workspace (+ instant = بتتفعّل فورًا). */
    private function featureRows(int $tenantId): array
    {
        $auto = (array) config('tenancy.launch.auto_features', []);
        return array_map(static fn (array $r) => $r + ['instant' => in_array($r['feature'], $auto, true)], TenantFeatures::stateFor($tenantId));
    }

    public function features(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'features');
        return ['features' => $this->featureRows((int) $t->id)];
    }

    /**
     * المالك بيحدد الميزات اللي عايزها: الفورية بتتفعّل على طول، والباقي بيتبعت كطلب لفريق UIPE (pending).
     * اللي اتقفلت/اترفضت من فريق المنصة مش بترجع فورًا: بتتحوّل لطلب جديد.
     * @param string[] $selected
     */
    public function setFeatures(int $userId, int $tenantId, array $selected): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'features');
        $tid = (int) $t->id;
        $catalog = array_keys(TenantFeatures::catalog());
        $selected = array_values(array_unique(array_map('strval', $selected)));
        foreach ($selected as $f) {
            if (!in_array($f, $catalog, true)) {
                $this->fail("Unknown feature '{$f}'.");
            }
        }
        $auto = (array) config('tenancy.launch.auto_features', []);
        $cur = [];
        foreach (TenantFeatures::stateFor($tid) as $r) {
            $cur[$r['feature']] = $r;
        }
        $toRequest = [];
        foreach ($catalog as $f) {
            $status = $cur[$f]['status'] ?? 'none';
            if (in_array($f, $selected, true)) {
                if (in_array($status, ['enabled', 'pending'], true)) {
                    continue;
                }
                $instantOk = in_array($f, $auto, true) && ($status === 'none' || ($status === 'disabled' && ($cur[$f]['decision_note'] ?? null) === self::SELF_OFF_NOTE));
                if ($instantOk) {
                    TenantFeatures::decide($tid, $f, 'enabled', null, 'enabled by the workspace');
                } else {
                    $toRequest[] = $f;
                }
            } elseif ($status === 'enabled') {
                TenantFeatures::decide($tid, $f, 'disabled', $userId, self::SELF_OFF_NOTE);
            } elseif ($status === 'pending') {
                TenantFeatures::cancelRequest($tid, $f);
            }
        }
        if ($toRequest) {
            TenantFeatures::request($tid, $toRequest, $userId, 'requested from the developer center');
        }
        $this->audit($userId, $tid, 'features', implode(',', $selected));
        return ['features' => $this->featureRows($tid)];
    }

    private function signInPayload(object $t): array
    {
        $isolated = ($t->mode ?? 'shared') === 'isolated';
        return ['isolated' => $isolated, 'slug' => $t->slug, 'settings' => TenantAuthSettings::forTenant((int) $t->id)];
    }

    public function signInSettings(int $userId, int $tenantId): array
    {
        [$t] = $this->authorizeRead($userId, $tenantId, 'site');
        return $this->signInPayload($t);
    }

    public function saveSignInSettings(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->authorize($userId, $tenantId, 'site');
        if (($t->mode ?? 'shared') !== 'isolated') {
            $this->fail('The sign-in page is only available for launched platforms.', 403);
        }
        try {
            TenantAuthSettings::save((int) $t->id, $in);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        $this->audit($userId, (int) $t->id, 'sign_in');
        return $this->signInPayload($t);
    }

    /** بطاقة "ابدأ" (Shopify-style): بتتحسب من الحالة الفعلية. @return array{can_manage:bool,complete:bool,done:int,total:int,items:array} */
    public function checklist(int $userId, int $tenantId): array
    {
        [$t, $role] = $this->authorize($userId, $tenantId);
        $tid = (int) $t->id;
        $isolated = ($t->mode ?? 'shared') === 'isolated';
        $logo = trim((string) (TenantBranding::get($tid)['logo_url'] ?? '')) !== '';
        $feat = false;
        foreach (TenantFeatures::stateFor($tid) as $r) {
            if (in_array($r['status'], ['enabled', 'pending'], true)) {
                $feat = true;
                break;
            }
        }
        $team = DB::table('tenant_members')->where('tenant_id', $tid)->count() > 1;
        $domain = Schema::hasTable('tenant_domains') && DB::table('tenant_domains')->where('tenant_id', $tid)->exists();
        $items = [
            ['key' => 'logo', 'section' => 'branding', 'done' => $logo, 'required' => true],
            ['key' => 'features', 'section' => 'features', 'done' => $feat, 'required' => true],
            ['key' => 'team', 'section' => 'members', 'done' => $team, 'required' => true],
        ];
        if ($isolated) {
            $s = TenantAuthSettings::forTenant($tid);
            $items[] = ['key' => 'signin', 'section' => 'signin', 'done' => TenantAuthSettings::exists($tid), 'required' => true];
            $items[] = ['key' => 'google', 'section' => 'signin', 'done' => !empty($s['google']['enabled']) || !empty($s['microsoft']['enabled']), 'required' => false];
        }
        $items[] = ['key' => 'domain', 'section' => 'domains', 'done' => $domain, 'required' => false];
        $done = count(array_filter($items, static fn ($i) => $i['done']));
        $complete = count(array_filter($items, static fn ($i) => $i['required'] && !$i['done'])) === 0;
        return [
            'can_manage' => in_array('site', $this->abilitiesOfMember($tid, $userId), true),
            'complete' => $complete, 'done' => $done, 'total' => count($items), 'items' => $items,
        ];
    }

    /* ------------------------------------------------------------------ audit */

    public function audit(int $userId, ?int $tenantId, string $action, ?string $note = null): void
    {
        $this->logs->record(['user_id' => $userId ?: null, 'kind' => 'admin', 'method' => 'TENANT', 'path' => 'tenant/' . ($tenantId ?? '-') . '/' . $action, 'status' => 200,
            'note' => $note, 'tenant_id' => $tenantId, 'ip' => app()->runningInConsole() ? null : request()->ip()]);
    }
}
