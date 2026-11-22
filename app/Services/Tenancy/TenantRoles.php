<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أدوار مخصصة بمصفوفة صلاحيات + أقسام/فرق + سجل نشاط المساحة (مين دخل، مين غيّر إيه).
 * الصلاحيات نفسها بتتفرض في TenantService::authorize / authorizeRead — هنا الإدارة والعرض بس.
 */
final class TenantRoles
{
    public const MAX_ROLES = 20;
    public const MAX_TEAMS = 30;

    /** action => [English, Arabic] لسجل النشاط. الباقي بيتعرض باسمه الخام. */
    private const ACTIONS = [
        'create' => ['Created the workspace', 'أنشأ المساحة'], 'launch' => ['Launched the platform', 'أطلق المنصة'], 'rename' => ['Renamed the workspace', 'غيّر اسم المساحة'],
        'branding' => ['Updated branding', 'عدّل الهوية'], 'delete' => ['Deleted the workspace', 'حذف المساحة'], 'admin.update' => ['UIPE team updated the workspace', 'فريق UIPE عدّل المساحة'],
        'member.add' => ['Added a member', 'أضاف عضو'], 'member.role' => ['Changed a member\'s role', 'غيّر دور عضو'], 'member.remove' => ['Removed a member', 'شال عضو'], 'member.leave' => ['Left the workspace', 'غادر المساحة'],
        'role.labels' => ['Renamed roles', 'غيّر أسماء الأدوار'], 'role.create' => ['Created a role', 'أنشأ دور'], 'role.update' => ['Updated a role', 'عدّل دور'], 'role.delete' => ['Deleted a role', 'حذف دور'],
        'team.create' => ['Created a team', 'أنشأ قسم'], 'team.update' => ['Updated a team', 'عدّل قسم'], 'team.delete' => ['Deleted a team', 'حذف قسم'],
        'features' => ['Changed features', 'غيّر الميزات'], 'sign_in' => ['Updated the sign-in page', 'عدّل صفحة الدخول'], 'publish' => ['Published the website', 'نشر الموقع'],
        'discard' => ['Discarded website changes', 'تجاهل تعديلات الموقع'], 'restore' => ['Restored a website version', 'رجّع نسخة من الموقع'], 'apply-template' => ['Applied a website template', 'طبّق قالب موقع'],
        'save-template' => ['Saved a website template', 'حفظ قالب موقع'], 'delete-template' => ['Deleted a website template', 'حذف قالب موقع'], 'slug' => ['Changed the workspace address', 'غيّر عنوان المساحة'],
        'preview-token' => ['Created a preview link', 'عمل رابط معاينة'], 'domain.add' => ['Added a domain', 'أضاف دومين'], 'domain.remove' => ['Removed a domain', 'شال دومين'],
        'app.create' => ['Created an app', 'أنشأ تطبيق'], 'app.delete' => ['Deleted an app', 'حذف تطبيق'], 'key.create' => ['Created an API key', 'أنشأ مفتاح'], 'key.revoke' => ['Revoked an API key', 'ألغى مفتاح'],
        'webhook.create' => ['Created a webhook', 'أنشأ webhook'], 'webhook.delete' => ['Deleted a webhook', 'حذف webhook'], 'embed.origin.add' => ['Allowed an embed origin', 'سمح بموقع تضمين'],
        'embed.origin.remove' => ['Removed an embed origin', 'شال موقع تضمين'], 'participant.create' => ['Added a participant', 'أضاف مشارك'], 'participant.update' => ['Updated a participant', 'عدّل مشارك'],
        'participant.delete' => ['Removed a participant', 'شال مشارك'], 'embed.session' => ['Started an embed session', 'بدأ جلسة تضمين'],
        'platform-user.create' => ['Created a platform account', 'أنشأ حساب في المنصة'], 'platform-user.invite' => ['Invited a platform account', 'دعا حساب للمنصة'], 'platform-user.reset' => ['Reset a platform password', 'صفّر باسورد حساب'],
        'platform-user.suspend' => ['Suspended a platform account', 'أوقف حساب'], 'platform-user.activate' => ['Re-activated a platform account', 'فعّل حساب'], 'platform-user.invite-resend' => ['Re-sent an invitation', 'أعاد إرسال دعوة'],
    ];

    public function __construct(private TenantService $tenants)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private function ready(): void
    {
        if (!TenantService::customRolesReady() || !Schema::hasTable('tenant_teams')) {
            $this->fail('Roles and teams are not set up yet. Run: php artisan migrate', 503);
        }
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = mb_substr(trim(strip_tags((string) $v)), 0, $max);
        return $v === '' ? null : $v;
    }

    /* ------------------------------------------------------------------ roles */

    /** @return array{builtin:array,custom:array,sections:array} */
    public function roles(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'members');
        $sections = [];
        foreach (TenantPermissions::sections() as $k => $s) {
            $sections[] = ['key' => $k] + $s;
        }
        $builtin = [];
        foreach ((array) config('tenancy.roles') as $role) {
            $ab = TenantService::abilitiesOfRole($role);
            $perm = [];
            foreach (TenantPermissions::sections() as $k => $s) {
                $perm[$k] = $k === 'activity' ? (in_array('activity', $ab, true) ? 'view' : 'none') : ($s['ability'] && in_array($s['ability'], $ab, true) ? 'edit' : 'view');
            }
            $builtin[] = ['role' => $role, 'permissions' => $perm];
        }
        $custom = [];
        if (TenantService::customRolesReady()) {
            $counts = DB::table('tenant_members')->where('tenant_id', $t->id)->whereNotNull('custom_role_id')->selectRaw('custom_role_id, count(*) as n')->groupBy('custom_role_id')->pluck('n', 'custom_role_id');
            foreach (DB::table('tenant_custom_roles')->where('tenant_id', $t->id)->orderBy('id')->get() as $r) {
                $custom[] = ['id' => (int) $r->id, 'name_en' => $r->name_en, 'name_ar' => $r->name_ar, 'description' => $r->description,
                    'permissions' => TenantPermissions::normalize((array) json_decode((string) $r->permissions, true)), 'members' => (int) ($counts[$r->id] ?? 0), 'created_at' => $r->created_at];
            }
        }
        return ['builtin' => $builtin, 'custom' => $custom, 'sections' => $sections, 'ready' => TenantService::customRolesReady()];
    }

    public function saveRole(int $actorId, int $tenantId, ?int $roleId, array $in): array
    {
        $this->ready();
        [$t, $actorRole] = $this->tenants->authorize($actorId, $tenantId, 'roles');
        $en = $this->text($in['name_en'] ?? '', 60);
        $ar = $this->text($in['name_ar'] ?? '', 60);
        if ($en === null && $ar === null) {
            $this->fail('Give the role a name.');
        }
        $perms = TenantPermissions::normalize((array) ($in['permissions'] ?? []));
        $data = ['name_en' => $en, 'name_ar' => $ar, 'description' => $this->text($in['description'] ?? '', 160), 'permissions' => json_encode($perms), 'updated_at' => now()];
        if ($roleId) {
            if (!DB::table('tenant_custom_roles')->where('id', $roleId)->where('tenant_id', $t->id)->exists()) {
                $this->fail('Role not found.', 404);
            }
            DB::table('tenant_custom_roles')->where('id', $roleId)->update($data);
            foreach (DB::table('tenant_members')->where('tenant_id', $t->id)->where('custom_role_id', $roleId)->pluck('user_id') as $uid) {
                Cache::forget("tenant:gate:{$t->id}:{$uid}");
            }
            $act = 'role.update';
        } else {
            if (DB::table('tenant_custom_roles')->where('tenant_id', $t->id)->count() >= self::MAX_ROLES) {
                $this->fail('You reached the maximum number of custom roles (' . self::MAX_ROLES . ').', 403);
            }
            $roleId = (int) DB::table('tenant_custom_roles')->insertGetId($data + ['tenant_id' => $t->id, 'created_by' => $actorId, 'created_at' => now()]);
            $act = 'role.create';
        }
        TenantService::forgetCustomRoles();
        $this->tenants->audit($actorId, (int) $t->id, $act, ($en ?? $ar) . ' #' . $roleId);
        return $this->roles($actorId, $tenantId);
    }

    public function deleteRole(int $actorId, int $tenantId, int $roleId): array
    {
        $this->ready();
        [$t] = $this->tenants->authorize($actorId, $tenantId, 'roles');
        $r = DB::table('tenant_custom_roles')->where('id', $roleId)->where('tenant_id', $t->id)->first();
        if (!$r) {
            $this->fail('Role not found.', 404);
        }
        $users = DB::table('tenant_members')->where('tenant_id', $t->id)->where('custom_role_id', $roleId)->pluck('user_id');
        DB::transaction(function () use ($t, $roleId) {
            DB::table('tenant_members')->where('tenant_id', $t->id)->where('custom_role_id', $roleId)->update(['custom_role_id' => null, 'role' => 'viewer', 'updated_at' => now()]);
            DB::table('tenant_custom_roles')->where('id', $roleId)->delete();
        });
        foreach ($users as $uid) {
            Cache::forget("tenant:gate:{$t->id}:{$uid}");
        }
        TenantService::forgetCustomRoles();
        $this->tenants->audit($actorId, (int) $t->id, 'role.delete', ($r->name_en ?? $r->name_ar) . ' · ' . count($users) . ' members → viewer');
        return $this->roles($actorId, $tenantId);
    }

    /* ------------------------------------------------------------------ teams */

    public function teams(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'teams');
        if (!TenantService::customRolesReady() || !Schema::hasTable('tenant_teams')) {
            return ['teams' => [], 'ready' => false];
        }
        $rows = DB::table('tenant_team_members as tm')->join('users as u', 'u.id', '=', 'tm.user_id')->where('tm.tenant_id', $t->id)
            ->orderBy('tm.id')->get(['tm.team_id', 'tm.user_id', 'tm.is_lead', 'u.full_name', 'u.email'])->groupBy('team_id');
        $teams = DB::table('tenant_teams')->where('tenant_id', $t->id)->orderBy('id')->get()->map(fn ($x) => [
            'id' => (int) $x->id, 'name_en' => $x->name_en, 'name_ar' => $x->name_ar, 'color' => $x->color,
            'members' => ($rows[$x->id] ?? collect())->map(fn ($m) => ['user_id' => (int) $m->user_id, 'name' => $m->full_name, 'email' => $m->email, 'lead' => (bool) $m->is_lead])->values()->all(),
        ])->all();
        return ['teams' => $teams, 'ready' => true];
    }

    /** @param array{name_en?:string,name_ar?:string,color?:?string,members?:array<int,array{user_id:int,lead?:bool}>} $in */
    public function saveTeam(int $actorId, int $tenantId, ?int $teamId, array $in): array
    {
        $this->ready();
        [$t] = $this->tenants->authorize($actorId, $tenantId, 'teams');
        $en = $this->text($in['name_en'] ?? '', 60);
        $ar = $this->text($in['name_ar'] ?? '', 60);
        if ($en === null && $ar === null) {
            $this->fail('Give the team a name.');
        }
        $color = isset($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $in['color']) === 1 ? strtoupper((string) $in['color']) : null;
        $valid = DB::table('tenant_members')->where('tenant_id', $t->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $members = [];
        foreach ((array) ($in['members'] ?? []) as $m) {
            $uid = (int) (is_array($m) ? ($m['user_id'] ?? 0) : $m);
            if ($uid && in_array($uid, $valid, true)) {
                $members[$uid] = is_array($m) && !empty($m['lead']);
            }
        }
        $isNew = !$teamId;
        DB::transaction(function () use (&$teamId, $t, $en, $ar, $color, $members) {
            $data = ['name_en' => $en, 'name_ar' => $ar, 'color' => $color, 'updated_at' => now()];
            if ($teamId) {
                if (!DB::table('tenant_teams')->where('id', $teamId)->where('tenant_id', $t->id)->exists()) {
                    $this->fail('Team not found.', 404);
                }
                DB::table('tenant_teams')->where('id', $teamId)->update($data);
            } else {
                if (DB::table('tenant_teams')->where('tenant_id', $t->id)->count() >= self::MAX_TEAMS) {
                    $this->fail('You reached the maximum number of teams (' . self::MAX_TEAMS . ').', 403);
                }
                $teamId = (int) DB::table('tenant_teams')->insertGetId($data + ['tenant_id' => $t->id, 'created_at' => now()]);
            }
            DB::table('tenant_team_members')->where('team_id', $teamId)->delete();
            foreach ($members as $uid => $lead) {
                DB::table('tenant_team_members')->insert(['team_id' => $teamId, 'tenant_id' => $t->id, 'user_id' => $uid, 'is_lead' => $lead ? 1 : 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->tenants->audit($actorId, (int) $t->id, $isNew ? 'team.create' : 'team.update', ($en ?? $ar) . ' #' . $teamId . ' · ' . count($members) . ' members');
        return $this->teams($actorId, $tenantId);
    }

    public function deleteTeam(int $actorId, int $tenantId, int $teamId): array
    {
        $this->ready();
        [$t] = $this->tenants->authorize($actorId, $tenantId, 'teams');
        $x = DB::table('tenant_teams')->where('id', $teamId)->where('tenant_id', $t->id)->first();
        if (!$x) {
            $this->fail('Team not found.', 404);
        }
        DB::table('tenant_teams')->where('id', $teamId)->delete(); // الأعضاء بيتمسحوا بالـ cascade
        $this->tenants->audit($actorId, (int) $t->id, 'team.delete', ($x->name_en ?? $x->name_ar) . ' #' . $teamId);
        return $this->teams($actorId, $tenantId);
    }

    /* ------------------------------------------------------------------ activity */

    /**
     * type=changes: مين غيّر إيه (من سجل إدارة المساحة).
     * type=logins : مين دخل — جلسات أعضاء الإدارة (حسابات UIPE) + مستخدمي المنصة المعزولة لو المساحة معزولة.
     * @param array{type?:string,action?:string,user_id?:int,q?:string,from?:string,to?:string} $f
     */
    public function activity(int $userId, int $tenantId, array $f, int $page = 1, int $per = 30): array
    {
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'activity');
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        return ($f['type'] ?? 'changes') === 'logins' ? $this->logins($t, $f, $page, $per) : $this->changes($t, $f, $page, $per);
    }

    private function like(string $v): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%';
    }

    private function dt(?string $v): ?string
    {
        try {
            return $v ? \Illuminate\Support\Carbon::parse($v)->utc()->toDateTimeString() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function changes(object $t, array $f, int $page, int $per): array
    {
        $tid = (int) $t->id;
        $q = DB::table('dev_request_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.method', 'TENANT')->where('l.path', 'like', 'tenant/' . $tid . '/%');
        if (!empty($f['user_id'])) {
            $q->where('l.user_id', (int) $f['user_id']);
        }
        if (!empty($f['action'])) {
            $q->where('l.path', 'like', 'tenant/' . $tid . '/' . str_replace(['%', '_'], '', (string) $f['action']) . '%');
        }
        if (!empty($f['q'])) {
            $like = $this->like((string) $f['q']);
            $q->where(fn ($w) => $w->where('l.note', 'like', $like)->orWhere('l.path', 'like', $like)->orWhere('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like));
        }
        if ($d = $this->dt($f['from'] ?? null)) {
            $q->where('l.created_at', '>=', $d);
        }
        if ($d = $this->dt($f['to'] ?? null)) {
            $q->where('l.created_at', '<=', $d);
        }
        $total = (clone $q)->count();
        $prefix = 'tenant/' . $tid . '/';
        $items = $q->orderByDesc('l.id')->forPage($page, $per)->get(['l.id', 'l.user_id', 'l.path', 'l.note', 'l.ip', 'l.created_at', 'u.full_name', 'u.email'])->map(function ($r) use ($prefix) {
            $action = substr((string) $r->path, strlen($prefix));
            $lab = self::ACTIONS[$action] ?? [$action, $action];
            return ['id' => (int) $r->id, 'action' => $action, 'label_en' => $lab[0], 'label_ar' => $lab[1], 'note' => $r->note, 'ip' => $r->ip,
                'actor' => $r->user_id ? ['id' => (int) $r->user_id, 'name' => $r->full_name, 'email' => $r->email] : null, 'at' => \App\Services\Developer\DevTokens::iso($r->created_at)];
        })->all();
        return ['type' => 'changes', 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    private function loginRows(callable $query, string $scope, array $f, int $take): array
    {
        try {
            $q = $query();
            if (!empty($f['q'])) {
                $like = $this->like((string) $f['q']);
                $q->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like));
            }
            if ($d = $this->dt($f['from'] ?? null)) {
                $q->where('s.created_at', '>=', $d);
            }
            if ($d = $this->dt($f['to'] ?? null)) {
                $q->where('s.created_at', '<=', $d);
            }
            $total = (clone $q)->count();
            $rows = $q->orderByDesc('s.id')->limit($take)->get(['s.user_id', 's.ip_address', 's.device_label', 's.location_label', 's.created_at', 'u.full_name', 'u.email'])
                ->map(fn ($r) => ['scope' => $scope, 'user' => ['id' => (int) $r->user_id, 'name' => $r->full_name, 'email' => $r->email], 'ip' => $r->ip_address, 'device' => $r->device_label, 'location' => $r->location_label, 'at' => \App\Services\Developer\DevTokens::iso($r->created_at)])->all();
            return [$rows, $total];
        } catch (\Throwable $e) {
            return [[], 0];
        }
    }

    private function logins(object $t, array $f, int $page, int $per): array
    {
        $take = min(1000, $page * $per);
        $tid = (int) $t->id;
        [$a, $na] = $this->loginRows(fn () => DB::table('user_sessions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.user_id', DB::table('tenant_members')->where('tenant_id', $tid)->select('user_id')), 'management', $f, $take);
        $b = [];
        $nb = 0;
        if (($t->mode ?? 'shared') === 'isolated') {
            try {
                [$b, $nb] = TenantContext::run($t, fn () => $this->loginRows(fn () => DB::table('user_sessions as s')->join('users as u', 'u.id', '=', 's.user_id'), 'platform', $f, $take));
            } catch (\Throwable $e) {
                [$b, $nb] = [[], 0];
            }
        }
        $all = array_merge($a, $b);
        usort($all, static fn ($x, $y) => strcmp((string) $y['at'], (string) $x['at']));
        return ['type' => 'logins', 'items' => array_slice($all, ($page - 1) * $per, $per), 'total' => $na + $nb, 'page' => $page, 'per_page' => $per, 'isolated' => ($t->mode ?? 'shared') === 'isolated'];
    }
}
