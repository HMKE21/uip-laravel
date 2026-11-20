<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — إدارة الوصول: مين يستخدم البريد، مين يراسل مين، مين يتعمله صندوق، ودومينات محظورة/مسموحة.
 *
 * النطاقات (scope): 0 = المنصة، أي رقم تاني = جامعة. القاعدة الفعلية = AND بين المنصة والجامعة (الأشد يكسب).
 * داخل كل نطاق: استثناء المستخدم (mail_user_overrides) يغلب سياسة الدور (mail_role_policies)، والافتراضي = مسموح.
 * دور admin (أدمن المنصة) مستثنى من كل القيود (حماية من قفل النظام على نفسه).
 *
 * التطبيق: UipMailAccessMiddleware (الدخول) · MailboxService::resolveRecipients (الإرسال/الاستقبال/الدومينات/الأدوار)
 *          · MailTenantService::provisionMembers + MailboxService::accountFor (إنشاء الصناديق).
 * ملحوظة: مفيش استقبال بريد خارجي وارد حاليًا (Brevo للإرسال والـ bounces بس) فمفيش تحكم "استقبال خارجي".
 */
class MailAccessService
{
    public const PERMS = ['use', 'send', 'receive', 'send_external', 'create'];
    public const EXEMPT_ROLE = 'admin';
    private const SNAP_KEY = 'mailaccess:snapshot:v1';

    /** @var array<int,?string> */
    private array $roleMemo = [];
    /** @var array<int,array<int,object>> */
    private array $ovMemo = [];

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    public function flush(): void
    {
        Cache::forget(self::SNAP_KEY);
        $this->roleMemo = [];
        $this->ovMemo = [];
    }

    /* ------------------------------------------------------------------ snapshot (قواعد صغيرة → كاش 60 ثانية) */

    /** @return array{settings:array,policies:array,blocks:array,domains:array} */
    private function snapshot(): array
    {
        return Cache::remember(self::SNAP_KEY, 60, function () {
            $snap = ['settings' => [], 'policies' => [], 'blocks' => [], 'domains' => []];
            if (!Schema::hasTable('mail_role_policies')) {
                return $snap;
            }
            foreach (DB::table('mail_access_settings')->get() as $s) {
                $snap['settings'][(int) $s->university_id] = ['external_domain_mode' => (string) $s->external_domain_mode, 'personal_mailbox_creation' => (string) $s->personal_mailbox_creation];
            }
            foreach (DB::table('mail_role_policies')->get() as $p) {
                foreach (self::PERMS as $k) {
                    $snap['policies'][(int) $p->university_id][(string) $p->role_key][$k] = (bool) $p->{'can_' . $k};
                }
            }
            foreach (DB::table('mail_role_blocks')->get(['university_id', 'from_role', 'to_role']) as $b) {
                $snap['blocks'][(int) $b->university_id][$b->from_role . '>' . $b->to_role] = true;
            }
            foreach (DB::table('mail_domain_rules')->get(['id', 'university_id', 'domain', 'type', 'direction']) as $d) {
                $snap['domains'][(int) $d->university_id][] = ['id' => (int) $d->id, 'domain' => (string) $d->domain, 'type' => (string) $d->type, 'direction' => (string) $d->direction];
            }
            return $snap;
        });
    }

    /** @return int[] */
    private function scopes(?int $uni): array
    {
        return $uni ? [0, $uni] : [0];
    }

    public function roleOf(int $userId): ?string
    {
        if (array_key_exists($userId, $this->roleMemo)) {
            return $this->roleMemo[$userId];
        }
        try {
            $slug = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->orderBy('ur.role_id')->value('r.slug');
        } catch (\Throwable) {
            $slug = null;
        }
        return $this->roleMemo[$userId] = $slug ? (string) $slug : null;
    }

    private function overrideRow(int $scope, int $userId): ?object
    {
        if (!isset($this->ovMemo[$userId])) {
            try {
                $this->ovMemo[$userId] = DB::table('mail_user_overrides')->where('user_id', $userId)->get()->keyBy(fn ($r) => (int) $r->university_id)->all();
            } catch (\Throwable) {
                $this->ovMemo[$userId] = [];
            }
        }
        return $this->ovMemo[$userId][$scope] ?? null;
    }

    private static function uniOf(MailAccount $a): ?int
    {
        return $a->tenant_id && $a->university_id ? (int) $a->university_id : null;
    }

    private static function domainOf(string $address): string
    {
        return strtolower((string) substr(strrchr($address, '@') ?: '', 1));
    }

    /* ------------------------------------------------------------------ evaluation */

    /** @return array{allowed:bool,by:string,scope:?int,role:?string} by: exempt|override|role|default */
    public function evaluate(string $perm, ?int $userId, ?int $uni): array
    {
        $role = $userId ? $this->roleOf($userId) : null;
        if ($role === self::EXEMPT_ROLE) {
            return ['allowed' => true, 'by' => 'exempt', 'scope' => null, 'role' => $role];
        }
        $snap = $this->snapshot();
        $by = 'default';
        foreach ($this->scopes($uni) as $scope) {
            $ov = $userId ? $this->overrideRow($scope, $userId) : null;
            if ($ov && $ov->{'can_' . $perm} !== null) {
                if (!(bool) $ov->{'can_' . $perm}) {
                    return ['allowed' => false, 'by' => 'override', 'scope' => $scope, 'role' => $role];
                }
                $by = 'override';
                continue;
            }
            if ($role !== null && isset($snap['policies'][$scope][$role][$perm])) {
                if (!$snap['policies'][$scope][$role][$perm]) {
                    return ['allowed' => false, 'by' => 'role', 'scope' => $scope, 'role' => $role];
                }
                if ($by === 'default') {
                    $by = 'role';
                }
            }
        }
        return ['allowed' => true, 'by' => $by, 'scope' => null, 'role' => $role];
    }

    private function denyText(string $perm, array $e): string
    {
        $who = $e['by'] === 'override' ? ['تم تقييد حسابك من الإدارة', 'Your account has been restricted by an administrator'] : ['دورك غير مسموح له بذلك', 'Your role is not allowed to do this'];
        $what = [
            'use' => ['استخدام البريد', 'use mail'], 'send' => ['إرسال الرسائل', 'send messages'], 'receive' => ['استقبال الرسائل', 'receive messages'],
            'send_external' => ['الإرسال لعناوين خارجية', 'send to external addresses'], 'create' => ['إنشاء صندوق بريد', 'have a mailbox created'],
        ][$perm];
        return "{$who[0]} ({$what[0]}). / {$who[1]} ({$what[1]}).";
    }

    /** سبب منع الدخول للبريد أو null. بيستخدمه الـ middleware. */
    public function useBlockReason(int $userId, ?int $uni): ?string
    {
        $e = $this->evaluate('use', $userId, $uni);
        return $e['allowed'] ? null : $this->denyText('use', $e);
    }

    /** سبب منع إنشاء صندوق للمستخدم ده أو null. $personal=true → الصندوق الشخصي (بيحترم وضع admin_only). */
    public function createBlockReason(int $userId, ?int $uni, bool $personal = false): ?string
    {
        $e = $this->evaluate('create', $userId, $uni);
        if (!$e['allowed']) {
            return $this->denyText('create', $e);
        }
        if ($personal && $e['by'] !== 'exempt' && ($this->snapshot()['settings'][0]['personal_mailbox_creation'] ?? 'auto') === 'admin_only') {
            return 'صندوقك لم يُنشأ بعد — تواصل مع الإدارة. / Your mailbox has not been created yet — please contact your administrator.';
        }
        return null;
    }

    private static function matches(string $pattern, string $domain): bool
    {
        if ($pattern === $domain) {
            return true;
        }
        if (str_starts_with($pattern, '*.')) {
            $base = substr($pattern, 2);
            return $domain === $base || str_ends_with($domain, '.' . $base);
        }
        return false;
    }

    /** @return ?array{scope:int,rule:array} أول قاعدة تطابق. */
    private function domainHit(string $domain, string $type, string $direction, array $scopes): ?array
    {
        $snap = $this->snapshot();
        foreach ($scopes as $scope) {
            foreach ($snap['domains'][$scope] ?? [] as $r) {
                if ($r['type'] === $type && in_array($r['direction'], [$direction, 'both'], true) && self::matches($r['domain'], $domain)) {
                    return ['scope' => $scope, 'rule' => $r];
                }
            }
        }
        return null;
    }

    /** وضع allowlist شغّال في نطاق ومفيش allow يطابق الدومين → النطاق ده (وإلا null). */
    private function allowlistMiss(string $domain, array $scopes): ?int
    {
        $snap = $this->snapshot();
        foreach ($scopes as $scope) {
            if (($snap['settings'][$scope]['external_domain_mode'] ?? 'open') === 'allowlist' && !$this->domainHit($domain, 'allow', 'send', [$scope])) {
                return $scope;
            }
        }
        return null;
    }

    private function v(string $code, ?string $address, string $ar, string $en, ?int $scope = null): array
    {
        return ['code' => $code, 'address' => $address, 'scope' => $scope === null ? null : ($scope === 0 ? 'platform' : 'university'), 'message' => "{$ar} / {$en}"];
    }

    /**
     * كل المخالفات لرسالة من $sender لـ $addresses. $accounts: address → MailAccount (الصناديق الداخلية؛ الباقي خارجي).
     * @return array<int,array{code:string,address:?string,scope:?string,message:string}>
     */
    public function violations(MailAccount $sender, array $addresses, Collection $accounts): array
    {
        $sUser = (int) $sender->user_id;
        if ($this->roleOf($sUser) === self::EXEMPT_ROLE) {
            return [];
        }
        $out = [];
        $sUni = self::uniOf($sender);
        $sRole = $this->roleOf($sUser);
        $sScopes = $this->scopes($sUni);
        $sDomain = self::domainOf((string) $sender->address);
        $snap = $this->snapshot();

        $e = $this->evaluate('send', $sUser, $sUni);
        if (!$e['allowed']) {
            $out[] = $this->v('send_denied', null, 'غير مسموح لك بإرسال الرسائل.', 'You are not allowed to send messages.', $e['scope']);
        }

        foreach ($addresses as $addr) {
            $acc = $accounts->get($addr);
            if ($acc && (int) $acc->user_id === $sUser) {
                continue; // رسالة لنفسك
            }
            $domain = self::domainOf($addr);

            if ($hit = $this->domainHit($domain, 'block', 'send', $sScopes)) {
                $out[] = $this->v('domain_blocked', $addr, "الإرسال إلى الدومين {$domain} محظور.", "Sending to the domain {$domain} is blocked.", $hit['scope']);
                continue;
            }

            if (!$acc) { // عنوان خارجي
                $x = $this->evaluate('send_external', $sUser, $sUni);
                if (!$x['allowed']) {
                    $out[] = $this->v('send_external_denied', $addr, 'غير مسموح لك بالإرسال لعناوين خارجية.', 'You are not allowed to send to external addresses.', $x['scope']);
                } elseif (($miss = $this->allowlistMiss($domain, $sScopes)) !== null) {
                    $out[] = $this->v('domain_not_allowed', $addr, "الدومين {$domain} غير موجود في قائمة الدومينات المسموحة.", "The domain {$domain} is not on the allowed domains list.", $miss);
                }
                continue;
            }

            // صندوق داخلي
            $rUni = self::uniOf($acc);
            $rUser = (int) $acc->user_id;
            $r = $this->evaluate('receive', $rUser, $rUni);
            if (!$r['allowed']) {
                $out[] = $this->v('receive_denied', $addr, "{$addr} غير مسموح له باستقبال الرسائل.", "{$addr} is not allowed to receive messages.", $r['scope']);
                continue;
            }
            if ($hit = $this->domainHit($sDomain, 'block', 'receive', $this->scopes($rUni))) {
                $out[] = $this->v('sender_domain_blocked', $addr, "{$addr} لا يستقبل رسائل من الدومين {$sDomain}.", "{$addr} does not accept mail from the domain {$sDomain}.", $hit['scope']);
                continue;
            }
            $rRole = $this->roleOf($rUser);
            if ($sRole && $rRole && $rRole !== self::EXEMPT_ROLE) {
                foreach (array_unique(array_merge($sScopes, $this->scopes($rUni))) as $scope) {
                    if (isset($snap['blocks'][$scope][$sRole . '>' . $rRole])) {
                        $out[] = $this->v('role_blocked', $addr, "ممنوع على دور ({$sRole}) مراسلة دور ({$rRole}).", "The role {$sRole} may not email the role {$rRole}.", $scope);
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /** بيرمي 403 بأول مخالفة. */
    public function assertMayEmail(MailAccount $sender, array $addresses, Collection $accounts): void
    {
        $v = $this->violations($sender, $addresses, $accounts);
        if ($v) {
            $this->fail($v[0]['message'], 403);
        }
    }

    /** @return array<string,mixed> */
    public function settingsFor(int $scope): array
    {
        return $this->snapshot()['settings'][$scope] ?? ['external_domain_mode' => 'open', 'personal_mailbox_creation' => 'auto'];
    }

    /* ================================================================== admin: read */

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        return DB::table('roles as r')->orderByDesc('r.is_system')->orderBy('r.id')
            ->get(['r.slug', 'r.name_ar', 'r.name_en', 'r.is_system', DB::raw('(SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) as users_count')])
            ->map(fn ($r) => ['slug' => $r->slug, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en, 'is_system' => (bool) $r->is_system, 'users' => (int) $r->users_count])->all();
    }

    private function policyRow(array $snapPolicies, int $scope, string $slug): array
    {
        $row = [];
        foreach (self::PERMS as $k) {
            $row[$k] = $snapPolicies[$scope][$slug][$k] ?? true;
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function overview(int $scope): array
    {
        $this->flush();
        $snap = $this->snapshot();
        $roles = array_map(function ($r) use ($snap, $scope) {
            $r['policy'] = $this->policyRow($snap['policies'], $scope, $r['slug']);
            $r['inherited'] = $scope === 0 ? null : $this->policyRow($snap['policies'], 0, $r['slug']);
            $r['locked'] = $r['slug'] === self::EXEMPT_ROLE ? 'exempt' : ($scope > 0 && $r['slug'] === 'university' ? 'self' : null);
            return $r;
        }, $this->catalog());
        return [
            'scope' => $scope === 0 ? 'platform' : 'university', 'university_id' => $scope ?: null,
            'settings' => $this->settingsFor($scope),
            'platform_settings' => $scope === 0 ? null : $this->settingsFor(0),
            'roles' => $roles,
            'stats' => [
                'domain_blocks' => DB::table('mail_domain_rules')->where('university_id', $scope)->where('type', 'block')->count(),
                'domain_allows' => DB::table('mail_domain_rules')->where('university_id', $scope)->where('type', 'allow')->count(),
                'role_blocks' => DB::table('mail_role_blocks')->where('university_id', $scope)->count(),
                'user_overrides' => DB::table('mail_user_overrides')->where('university_id', $scope)->count(),
                'restricted_roles' => count(array_filter($roles, fn ($r) => in_array(false, $r['policy'], true))),
            ],
            'perms' => self::PERMS,
        ];
    }

    /* ================================================================== admin: write */

    public function saveSettings(int $scope, array $in, ?int $by): array
    {
        $cur = $this->settingsFor($scope);
        $mode = $in['external_domain_mode'] ?? $cur['external_domain_mode'];
        $create = $in['personal_mailbox_creation'] ?? $cur['personal_mailbox_creation'];
        if ($scope !== 0) {
            $create = 'auto'; // طريقة إنشاء الصندوق الشخصي قرار منصة
        }
        DB::table('mail_access_settings')->updateOrInsert(['university_id' => $scope], ['external_domain_mode' => $mode, 'personal_mailbox_creation' => $create, 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        $this->flush();
        return $this->settingsFor($scope);
    }

    /** @param array<string,bool> $perms */
    public function saveRolePolicy(int $scope, string $slug, array $perms, ?int $by): array
    {
        if (!DB::table('roles')->where('slug', $slug)->exists()) {
            $this->fail('Unknown role.', 404);
        }
        if ($slug === self::EXEMPT_ROLE) {
            $this->fail('Platform admins cannot be restricted. / لا يمكن تقييد أدمن المنصة.', 422);
        }
        $row = $this->policyRow($this->snapshot()['policies'], $scope, $slug);
        foreach (self::PERMS as $k) {
            if (array_key_exists($k, $perms)) {
                $row[$k] = (bool) $perms[$k];
            }
        }
        if ($scope > 0 && $slug === 'university' && (!$row['use'] || !$row['send'] || !$row['receive'])) {
            $this->fail('You cannot restrict the university admin role itself. / لا يمكن تقييد دور أدمن الجامعة نفسه.', 422);
        }
        $now = now();
        DB::table('mail_role_policies')->updateOrInsert(['university_id' => $scope, 'role_key' => $slug], [
            'can_use' => $row['use'], 'can_send' => $row['send'], 'can_receive' => $row['receive'], 'can_send_external' => $row['send_external'],
            'can_create' => $row['create'], 'updated_by' => $by, 'updated_at' => $now, 'created_at' => $now,
        ]);
        $this->flush();
        return $row;
    }

    /** @return array{roles:array,blocked:array} blocked = [{from,to}] */
    public function matrix(int $scope): array
    {
        return [
            'roles' => array_values(array_filter($this->catalog(), fn ($r) => $r['slug'] !== self::EXEMPT_ROLE)),
            'blocked' => DB::table('mail_role_blocks')->where('university_id', $scope)->orderBy('from_role')->orderBy('to_role')->get(['from_role', 'to_role'])
                ->map(fn ($b) => ['from' => $b->from_role, 'to' => $b->to_role])->all(),
        ];
    }

    /** @param array<int,array{from:string,to:string}> $pairs استبدال كامل لمصفوفة النطاق. */
    public function saveMatrix(int $scope, array $pairs, ?int $by): array
    {
        $valid = DB::table('roles')->where('slug', '!=', self::EXEMPT_ROLE)->pluck('slug')->all();
        $rows = [];
        foreach ($pairs as $p) {
            $f = (string) ($p['from'] ?? '');
            $t = (string) ($p['to'] ?? '');
            if (!in_array($f, $valid, true) || !in_array($t, $valid, true)) {
                $this->fail('Unknown role in the matrix.', 422);
            }
            $rows[$f . '>' . $t] = ['university_id' => $scope, 'from_role' => $f, 'to_role' => $t, 'created_by' => $by, 'created_at' => now()];
        }
        DB::transaction(function () use ($scope, $rows) {
            DB::table('mail_role_blocks')->where('university_id', $scope)->delete();
            if ($rows) {
                DB::table('mail_role_blocks')->insert(array_values($rows));
            }
        });
        $this->flush();
        return $this->matrix($scope);
    }

    /** @return array<int,array<string,mixed>> */
    public function domainRules(int $scope): array
    {
        return DB::table('mail_domain_rules as d')->leftJoin('users as u', 'u.id', '=', 'd.created_by')->where('d.university_id', $scope)->orderBy('d.type')->orderBy('d.domain')
            ->get(['d.id', 'd.domain', 'd.type', 'd.direction', 'd.note', 'd.created_at', 'u.full_name as created_by_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'domain' => $r->domain, 'type' => $r->type, 'direction' => $r->direction, 'note' => $r->note,
                'created_by' => $r->created_by_name, 'created_at' => \Illuminate\Support\Carbon::parse($r->created_at, 'UTC')->toIso8601String()])->all();
    }

    public static function cleanDomain(string $d): string
    {
        $d = strtolower(trim($d));
        $d = preg_replace('#^[a-z]+://#', '', $d) ?? $d;
        $d = ltrim($d, '@');
        return rtrim(explode('/', $d)[0], '.');
    }

    public function addDomainRule(int $scope, string $domain, string $type, string $direction, ?string $note, ?int $by): array
    {
        $domain = self::cleanDomain($domain);
        if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) || strlen($domain) > 120) {
            $this->fail('Invalid domain. Use example.com or *.example.com. / دومين غير صالح.', 422);
        }
        if ($type === 'block') { // حماية: ممنوع تحظر دومين المنصة أو دومينات جامعتك
            $own = [strtolower((string) config('mailbox.domain', 'uipe.local'))];
            if ($scope > 0) {
                $own = array_merge($own, DB::table('mail_domains as d')->join('mail_tenants as t', 't.id', '=', 'd.tenant_id')->where('t.university_id', $scope)->pluck('d.domain')->map(fn ($x) => strtolower((string) $x))->all());
            }
            foreach ($own as $o) {
                if (self::matches($domain, $o)) {
                    $this->fail('You cannot block your own mail domain. / لا يمكن حظر دومين البريد الخاص بالمنصة أو الجامعة.', 422);
                }
            }
        }
        if (DB::table('mail_domain_rules')->where(['university_id' => $scope, 'domain' => $domain, 'type' => $type, 'direction' => $direction])->exists()) {
            $this->fail('This rule already exists. / القاعدة موجودة بالفعل.', 409);
        }
        $id = DB::table('mail_domain_rules')->insertGetId(['university_id' => $scope, 'domain' => $domain, 'type' => $type, 'direction' => $direction,
            'note' => $note !== null ? mb_substr($note, 0, 190) : null, 'created_by' => $by, 'created_at' => now()]);
        $this->flush();
        return ['id' => (int) $id, 'domain' => $domain, 'type' => $type, 'direction' => $direction];
    }

    public function removeDomainRule(int $scope, int $id): array
    {
        $r = DB::table('mail_domain_rules')->where('id', $id)->where('university_id', $scope)->first();
        if (!$r) {
            $this->fail('Rule not found.', 404);
        }
        DB::table('mail_domain_rules')->where('id', $id)->delete();
        $this->flush();
        return ['domain' => $r->domain, 'type' => $r->type];
    }

    /** @return array{id:int,name:string,email:string} */
    private function resolveUser(int $scope, string $ident): array
    {
        $ident = strtolower(trim($ident));
        $q = DB::table('users')->whereNull('deleted_at');
        if (ctype_digit($ident)) {
            $q->where('id', (int) $ident);
        } elseif (str_contains($ident, '@')) {
            $viaMail = DB::table('mail_accounts')->where('address', $ident)->value('user_id');
            $q->where(fn ($w) => $w->whereRaw('LOWER(email) = ?', [$ident])->when($viaMail, fn ($x) => $x->orWhere('id', $viaMail)));
        } else {
            $this->fail('Enter the email address or user id. / اكتب الإيميل أو رقم المستخدم.', 422);
        }
        $u = $q->first(['id', 'full_name', 'email']);
        if (!$u || ($scope > 0 && !DB::table('mail_accounts')->where('user_id', $u->id)->where('university_id', $scope)->exists())) {
            $this->fail('User not found in this scope. / المستخدم غير موجود في هذا النطاق.', 404);
        }
        if ($this->roleOf((int) $u->id) === self::EXEMPT_ROLE) {
            $this->fail('Platform admins cannot be restricted. / لا يمكن تقييد أدمن المنصة.', 422);
        }
        return ['id' => (int) $u->id, 'name' => (string) $u->full_name, 'email' => (string) $u->email];
    }

    /** @return array{items:array,total:int,page:int,per_page:int} */
    public function overrides(int $scope, ?string $q, int $page = 1, int $perPage = 25): array
    {
        $perPage = min(100, max(5, $perPage));
        $base = DB::table('mail_user_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')->where('o.university_id', $scope);
        if ($q !== null && trim($q) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($q)) . '%';
            $base->where(fn ($w) => $w->where('u.email', 'like', $like)->orWhere('u.full_name', 'like', $like));
        }
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('o.id')->forPage(max(1, $page), $perPage)
            ->get(['o.id', 'o.user_id', 'o.can_use', 'o.can_send', 'o.can_receive', 'o.can_send_external', 'o.can_create', 'o.reason', 'o.updated_at', 'u.full_name', 'u.email']);
        $items = $rows->map(function ($r) {
            $perms = [];
            foreach (self::PERMS as $k) {
                $perms[$k] = $r->{'can_' . $k} === null ? null : (bool) $r->{'can_' . $k};
            }
            return ['id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'name' => $r->full_name, 'email' => $r->email, 'role' => $this->roleOf((int) $r->user_id),
                'perms' => $perms, 'reason' => $r->reason, 'updated_at' => \Illuminate\Support\Carbon::parse($r->updated_at, 'UTC')->toIso8601String()];
        })->all();
        return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'per_page' => $perPage];
    }

    /** @param array<string,?bool> $perms  null = يورث. لو الكل null → الاستثناء بيتمسح. */
    public function saveOverride(int $scope, string $ident, array $perms, ?string $reason, ?int $by): array
    {
        $u = $this->resolveUser($scope, $ident);
        $vals = [];
        foreach (self::PERMS as $k) {
            $vals['can_' . $k] = array_key_exists($k, $perms) && $perms[$k] !== null ? (bool) $perms[$k] : null;
        }
        if (!array_filter($vals, fn ($v) => $v !== null)) {
            DB::table('mail_user_overrides')->where(['university_id' => $scope, 'user_id' => $u['id']])->delete();
            $this->flush();
            return $u + ['removed' => true];
        }
        $now = now();
        DB::table('mail_user_overrides')->updateOrInsert(['university_id' => $scope, 'user_id' => $u['id']],
            $vals + ['reason' => $reason !== null ? mb_substr($reason, 0, 190) : null, 'created_by' => $by, 'updated_at' => $now, 'created_at' => $now]);
        $this->flush();
        return $u + ['removed' => false];
    }

    public function removeOverride(int $scope, int $id): array
    {
        $o = DB::table('mail_user_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')->where('o.id', $id)->where('o.university_id', $scope)->first(['o.id', 'o.user_id', 'u.email']);
        if (!$o) {
            $this->fail('Override not found.', 404);
        }
        DB::table('mail_user_overrides')->where('id', $id)->delete();
        $this->flush();
        return ['user_id' => (int) $o->user_id, 'email' => $o->email];
    }

    /**
     * أداة اختبار: "المستخدم س يقدر يبعت لـ ص؟" بنفس منطق الإرسال الحقيقي بدون ما يتبعت حاجة.
     * @return array<string,mixed>
     */
    public function check(int $scope, string $senderIdent, string $recipient): array
    {
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Invalid recipient address. / عنوان المستلم غير صالح.', 422);
        }
        $ident = strtolower(trim($senderIdent));
        $uq = DB::table('users')->whereNull('deleted_at');
        if (ctype_digit($ident)) {
            $uq->where('id', (int) $ident);
        } else {
            $viaMail = DB::table('mail_accounts')->where('address', $ident)->value('user_id');
            $uq->where(fn ($w) => $w->whereRaw('LOWER(email) = ?', [$ident])->when($viaMail, fn ($x) => $x->orWhere('id', $viaMail)));
        }
        $u = $uq->first(['id', 'full_name', 'email']);
        $sender = $u ? MailAccount::where('user_id', $u->id)->orderByDesc('is_default')->orderBy('id')->first() : null;
        if (!$u || !$sender || ($scope > 0 && (int) $sender->university_id !== $scope)) {
            $this->fail('Sender has no mailbox in this scope. / المرسل ليس لديه صندوق في هذا النطاق.', 404);
        }
        $acc = MailAccount::where('address', $recipient)->first();
        if (!$acc) {
            $uid = DB::table('users')->whereRaw('LOWER(email) = ?', [$recipient])->value('id');
            $acc = $uid ? MailAccount::where('user_id', $uid)->orderByDesc('is_default')->orderBy('id')->first() : null;
        }
        $accounts = $acc ? collect([$recipient => $acc]) : collect();
        $useErr = $this->useBlockReason((int) $u->id, self::uniOf($sender));
        $v = $this->violations($sender, [$recipient], $accounts);
        if ($useErr) {
            array_unshift($v, ['code' => 'use_denied', 'address' => null, 'scope' => null, 'message' => $useErr]);
        }
        return [
            'allowed' => !$v, 'violations' => $v,
            'sender' => ['user_id' => (int) $u->id, 'name' => $u->full_name, 'address' => $sender->address, 'role' => $this->roleOf((int) $u->id)],
            'recipient' => ['address' => $recipient, 'internal' => (bool) $acc, 'role' => $acc ? $this->roleOf((int) $acc->user_id) : null, 'domain' => self::domainOf($recipient)],
        ];
    }
}
