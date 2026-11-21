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
            'role' => $role, 'created_at' => $t->created_at,
        ];
    }

    /** @return array{0:object,1:string} [tenant, role] — يرمي 404 لو المستخدم مش عضو (من غير ما يكشف إن الـ tenant موجود). */
    public function authorize(int $userId, int $tenantId, string $ability = 'view'): array
    {
        $t = DB::table('tenants')->where('id', $tenantId)->first();
        $role = $t ? DB::table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('role') : null;
        if (!$t || !$role) {
            $this->fail('Workspace not found.', 404);
        }
        if (!in_array($role, self::ABILITIES[$ability] ?? [], true)) {
            $this->fail('Your role in this workspace does not allow this.', 403);
        }
        if ($ability !== 'view' && $t->status !== 'active') {
            $this->fail('This workspace is suspended.', 403);
        }
        return [$t, (string) $role];
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
        return ['tenant' => $this->payload($t, $role), 'branding' => TenantBranding::get((int) $t->id), 'counts' => $counts, 'abilities' => array_keys(array_filter(self::ABILITIES, fn ($r) => in_array($role, $r, true)))];
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
     * @return array{tenant:array,launch:array}
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

        @set_time_limit(300); // الـ migrations كلها بتتشغّل لقاعدة العميل الجديدة
        @ignore_user_abort(true);
        try {
            $r = app(TenantProvisioner::class)->create([
                'name' => $name, 'domain' => null, 'slug' => null, 'kind' => $kind, 'owner_user_id' => $userId,
                'admin_email' => $adminEmail, 'admin_name' => trim((string) ($in['admin_name'] ?? '')) ?: 'Admin', 'admin_password' => $adminPass,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Platform launch failed', ['user' => $userId, 'error' => $e->getMessage()]);
            $this->fail('Could not create the platform right now. Please try again in a minute.', 500);
        }
        $id = (int) $r['tenant_id'];
        DB::table('tenant_members')->insert(['tenant_id' => $id, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($userId, $id, 'launch', $kind);

        $auto = array_values(array_intersect($wanted, (array) config('tenancy.launch.auto_features', [])));
        $pending = array_values(array_diff($wanted, $auto));
        foreach ($auto as $f) {
            TenantFeatures::decide($id, $f, 'enabled', null, 'enabled at launch');
        }
        if ($pending) {
            TenantFeatures::request($id, $pending, $userId, 'requested at launch');
        }
        if ($templateKey !== '') {
            try { // فشل الموقع مينفعش يبوّظ الإطلاق: يختار القالب بعدين من منشئ الموقع
                $site = app(TenantSiteService::class);
                $site->applyTemplate($userId, $id, ['key' => $templateKey]);
                $site->publish($userId, $id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Launch: site template not applied', ['tenant' => $id, 'error' => $e->getMessage()]);
            }
        }
        $url = TenantAddress::urlFor($id);
        return [
            'tenant' => $this->show($userId, $id)['tenant'],
            'launch' => [
                'slug' => $r['slug'], 'url' => $url, 'login_url' => $url ? $url . '/login' : null,
                'admin_email' => $adminEmail, 'admin_password' => $r['admin_password'], // مولّد وبيظهر مرة واحدة بس لو المستخدم مكتبش باسورد
                'features_enabled' => $auto, 'features_pending' => $pending,
            ],
        ];
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
        [$t] = $this->authorize($userId, $tenantId);
        return DB::table('tenant_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $t->id)->orderBy('m.id')
            ->get(['m.user_id', 'm.role', 'm.created_at', 'u.email', 'u.full_name'])
            ->map(fn ($m) => ['user_id' => (int) $m->user_id, 'role' => $m->role, 'email' => $m->email, 'name' => $m->full_name, 'joined_at' => $m->created_at])->all();
    }

    /** @return array<string,array{0:?string,1:?string}> role => [English, Arabic] — الأدوار اللي ليها اسم مخصص بس. */
    public function roleLabels(int $userId, int $tenantId): array
    {
        [$t] = $this->authorize($userId, $tenantId);
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
        $this->assertAssignable($actorRole, $role);
        if (DB::table('tenant_members')->where('tenant_id', $t->id)->count() >= (int) $this->limits($t)['members']) {
            $this->fail('This workspace reached its member limit.', 403);
        }
        $u = DB::table('users')->whereRaw('lower(email) = ?', [strtolower(trim($email))])->whereNull('deleted_at')->first(['id', 'status']);
        if (!$u || in_array($u->status, ['suspended', 'banned'], true)) {
            $this->fail('No active UIPE account uses this email.', 404);
        }
        if (DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $u->id)->exists()) {
            $this->fail('This person is already a member.', 409);
        }
        DB::table('tenant_members')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role' => $role, 'invited_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        WorkspaceAccess::forget((int) $t->id, (int) $u->id);
        $this->audit($actorId, (int) $t->id, 'member.add', '#' . $u->id . ' ' . $role);
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
        $this->assertAssignable($actorRole, $role, (string) $cur);
        if ($cur === 'owner' && $role !== 'owner' && $this->ownerCount((int) $t->id) <= 1) {
            $this->fail('A workspace needs at least one owner.', 409);
        }
        DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->update(['role' => $role, 'updated_at' => now()]);
        Cache::forget("tenant:gate:{$t->id}:{$userId}");
        $this->audit($actorId, (int) $t->id, 'member.role', '#' . $userId . ' ' . $cur . '→' . $role);
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
        if ($cur === 'owner' && $this->ownerCount((int) $t->id) <= 1) {
            $this->fail('A workspace needs at least one owner. Make someone else an owner first.', 409);
        }
        DB::transaction(function () use ($t, $userId) {
            DB::table('tenant_members')->where('tenant_id', $t->id)->where('user_id', $userId)->delete();
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
        [$t] = $this->authorize($userId, $tenantId);
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
        [$t] = $this->authorize($userId, $tenantId);
        return DB::table('dev_apps')->where('tenant_id', $t->id)->orderByDesc('id')->get()->map(fn ($a) => $this->apps->appPayload($a))->all();
    }

    public function listKeys(int $userId, int $tenantId): array
    {
        [$t] = $this->authorize($userId, $tenantId);
        return DB::table('dev_api_keys')->where('tenant_id', $t->id)->orderByDesc('id')->get()->map(fn ($k) => $this->apps->keyPayload($k) + ['owner_user_id' => (int) $k->user_id])->all();
    }

    public function listWebhooks(int $userId, int $tenantId): array
    {
        [$t] = $this->authorize($userId, $tenantId);
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
        [$t] = $this->authorize($userId, $tenantId);
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
        [$t] = $this->authorize($userId, $tenantId);
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
        [$t] = $this->authorize($userId, $tenantId);
        return $this->usage->report($userId, $range, (int) $t->id);
    }

    /** سجل الـ Workspace المعزول: كل الأعضاء بيشوفوا طلبات tenant بتاعهم بس (مفاتيحه وتطبيقاته وأحداث إدارته). */
    public function logs(int $userId, int $tenantId, array $filters, int $page = 1, int $per = 30): array
    {
        [$t] = $this->authorize($userId, $tenantId);
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
            $role = DB::table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('role');
            if (!$role) {
                return 'tenant_membership_revoked';
            }
            return in_array($role, self::ABILITIES['resources'], true) ? '' : 'tenant_role_insufficient';
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

    /* ------------------------------------------------------------------ audit */

    private function audit(int $userId, ?int $tenantId, string $action, ?string $note = null): void
    {
        $this->logs->record(['user_id' => $userId ?: null, 'kind' => 'admin', 'method' => 'TENANT', 'path' => 'tenant/' . ($tenantId ?? '-') . '/' . $action, 'status' => 200,
            'note' => $note]);
    }
}
