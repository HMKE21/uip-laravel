<?php

namespace App\Services\Developer;

use App\Services\RoleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * التحكم الكامل في وصول المطورين. ثلاث طبقات بترتيب الأولوية:
 *   1) المنصة (dev_tenant_policies.university_id = 0)  → سقف أعلى فوق كل الجامعات.
 *   2) الجامعة (dev_tenant_policies.university_id = X) + قواعدها (dev_access_rules) .
 *   3) المطوّر نفسه (dev_user_access) → سماح/حظر/طلب وصول + scopes وحدود خاصة بيه.
 *
 * قرار الوصول (evaluate — دالة نقية بدون DB عشان تتختبر):
 *   تعطيل عام / منصة / جامعة  →  حظر المطوّر  →  الأدوار المسموحة  →  قواعد المنع  →  وضع closed (لازم قاعدة سماح)  →  الموافقة المسبقة.
 *   السماح اليدوي من الجامعة بيتخطى (الأدوار + قواعد منع الجامعة + closed + الموافقة) لكن مش سقف/قواعد المنصة
 *   (إلا لو السماح اتحط من أدمن المنصة).
 * الـ scopes = (مطوّر ?? قاعدة سماح ?? سياسة الجامعة ?? الكل) ∩ سقف المنصة.
 */
class DevAccessService
{
    public const PLATFORM = 0;

    /** reason => [en, ar] */
    public const MESSAGES = [
        'disabled'          => ['The Developer Center is disabled.', 'مركز المطورين مقفول.'],
        'platform_disabled' => ['Developer access is turned off for the whole platform.', 'وصول المطورين مقفول على مستوى المنصة.'],
        'tenant_disabled'   => ['Developer access is turned off by your university.', 'الجامعة قافلة وصول المطورين.'],
        'user_blocked'      => ['Your developer access was blocked by an administrator.', 'وصولك كمطوّر اتحظر من الإدارة.'],
        'request_rejected'  => ['Your developer access request was declined.', 'طلب وصولك كمطوّر اترفض.'],
        'pending_approval'  => ['Your developer access request is waiting for approval.', 'طلب وصولك كمطوّر مستني الموافقة.'],
        'approval_required' => ['Developer access needs approval from your university.', 'وصول المطورين محتاج موافقة من الجامعة.'],
        'role_not_allowed'  => ['Your account role is not allowed to use developer tools.', 'دور حسابك مش مسموح له يستخدم أدوات المطورين.'],
        'rule_denied'       => ['Your email or role is blocked from developer tools.', 'إيميلك أو دورك ممنوع من أدوات المطورين.'],
        'not_allowlisted'   => ['Developer access is limited to approved emails and roles.', 'وصول المطورين متاح للإيميلات والأدوار المعتمدة بس.'],
    ];

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ================================================================== policy (university / platform) */

    public static function defaults(): array
    {
        return ['apps_enabled' => true, 'webhooks_enabled' => true, 'max_apps_per_user' => null, 'max_keys_per_user' => null, 'max_webhooks_per_user' => null,
            'allowed_scopes' => null, 'allowed_roles' => null, 'default_access' => 'open', 'require_approval' => false, 'sandbox_only' => false,
            'rate_limit_per_min' => null, 'allowed_events' => null];
    }

    public static function jsonList($v): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = is_array($v) ? $v : json_decode((string) $v, true);
        return is_array($d) ? array_values(array_map('strval', $d)) : null;
    }

    private static function intOrNull($v, int $min, int $max): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return max($min, min($max, (int) $v));
    }

    /** سياسة مستوى واحد (0 = المنصة). لو مفيش صف → الافتراضي (كله مفتوح). */
    public function rawPolicy(int $universityId): array
    {
        $base = ['university_id' => $universityId ?: null, 'level' => $universityId === self::PLATFORM ? 'platform' : 'university', 'configured' => false];
        $row = DB::table('dev_tenant_policies')->where('university_id', $universityId)->first();
        if (!$row) {
            return $base + self::defaults();
        }
        $scopes = self::jsonList($row->allowed_scopes ?? null);
        $events = self::jsonList($row->allowed_events ?? null);
        return array_merge($base, [
            'configured' => true,
            'apps_enabled' => (bool) $row->apps_enabled,
            'webhooks_enabled' => (bool) $row->webhooks_enabled,
            'max_apps_per_user' => ($row->max_apps_per_user ?? null) !== null ? (int) $row->max_apps_per_user : null,
            'max_keys_per_user' => ($row->max_keys_per_user ?? null) !== null ? (int) $row->max_keys_per_user : null,
            'max_webhooks_per_user' => ($row->max_webhooks_per_user ?? null) !== null ? (int) $row->max_webhooks_per_user : null,
            'allowed_scopes' => $scopes === null ? null : array_values(array_intersect(DevScopes::names(), $scopes)),
            'allowed_roles' => self::jsonList($row->allowed_roles ?? null),
            'default_access' => (($row->default_access ?? 'open') === 'closed') ? 'closed' : 'open',
            'require_approval' => (bool) ($row->require_approval ?? false),
            'sandbox_only' => (bool) ($row->sandbox_only ?? false),
            'rate_limit_per_min' => ($row->rate_limit_per_min ?? null) !== null ? (int) $row->rate_limit_per_min : null,
            'allowed_events' => $events === null ? null : array_values(array_intersect(array_keys((array) config('developer.events', [])), $events)),
        ]);
    }

    public function savePolicy(int $universityId, array $in, ?int $by = null): array
    {
        if ($universityId !== self::PLATFORM && !DB::table('universities')->where('id', $universityId)->exists()) {
            $this->fail('University not found.', 404);
        }
        $new = $this->rawPolicy($universityId);
        foreach (['apps_enabled', 'webhooks_enabled', 'require_approval', 'sandbox_only'] as $k) {
            if (array_key_exists($k, $in)) {
                $new[$k] = (bool) $in[$k];
            }
        }
        foreach ([['max_apps_per_user', 100], ['max_keys_per_user', 500], ['max_webhooks_per_user', 100]] as [$k, $mx]) {
            if (array_key_exists($k, $in)) {
                $new[$k] = self::intOrNull($in[$k], 0, $mx);
            }
        }
        if (array_key_exists('rate_limit_per_min', $in)) {
            $new['rate_limit_per_min'] = self::intOrNull($in['rate_limit_per_min'], 1, 100000);
        }
        if (array_key_exists('default_access', $in)) {
            if (!in_array($in['default_access'], ['open', 'closed'], true)) {
                $this->fail('default_access must be open or closed.');
            }
            $new['default_access'] = $in['default_access'];
        }
        if (array_key_exists('allowed_scopes', $in)) {
            if ($in['allowed_scopes'] === null) {
                $new['allowed_scopes'] = null;
            } else {
                $s = DevScopes::parse($in['allowed_scopes']);
                if ($bad = DevScopes::unknown($s)) {
                    $this->fail('Unknown scope: ' . implode(', ', $bad));
                }
                $new['allowed_scopes'] = $s;
            }
        }
        if (array_key_exists('allowed_roles', $in)) {
            if ($in['allowed_roles'] === null) {
                $new['allowed_roles'] = null;
            } else {
                $r = array_values(array_unique(array_map(fn ($x) => strtolower(trim((string) $x)), (array) $in['allowed_roles'])));
                $known = DB::table('roles')->pluck('slug')->all();
                if ($bad = array_diff($r, $known)) {
                    $this->fail('Unknown role: ' . implode(', ', $bad));
                }
                if (!$r) {
                    $this->fail('Choose at least one role, or allow all roles.');
                }
                $new['allowed_roles'] = $r;
            }
        }
        if (array_key_exists('allowed_events', $in)) {
            if ($in['allowed_events'] === null) {
                $new['allowed_events'] = null;
            } else {
                $e = array_values(array_unique(array_map(fn ($x) => trim((string) $x), (array) $in['allowed_events'])));
                if ($bad = array_diff($e, array_keys((array) config('developer.events', [])))) {
                    $this->fail('Unknown event: ' . implode(', ', $bad));
                }
                $new['allowed_events'] = $e;
            }
        }
        $row = [
            'apps_enabled' => (int) $new['apps_enabled'], 'webhooks_enabled' => (int) $new['webhooks_enabled'],
            'max_apps_per_user' => $new['max_apps_per_user'], 'max_keys_per_user' => $new['max_keys_per_user'], 'max_webhooks_per_user' => $new['max_webhooks_per_user'],
            'allowed_scopes' => $new['allowed_scopes'] === null ? null : json_encode($new['allowed_scopes']),
            'allowed_roles' => $new['allowed_roles'] === null ? null : json_encode($new['allowed_roles']),
            'default_access' => $new['default_access'], 'require_approval' => (int) $new['require_approval'], 'sandbox_only' => (int) $new['sandbox_only'],
            'rate_limit_per_min' => $new['rate_limit_per_min'],
            'allowed_events' => $new['allowed_events'] === null ? null : json_encode($new['allowed_events']),
            'updated_by' => $by, 'updated_at' => now(),
        ];
        if (DB::table('dev_tenant_policies')->where('university_id', $universityId)->exists()) {
            DB::table('dev_tenant_policies')->where('university_id', $universityId)->update($row);
        } else {
            DB::table('dev_tenant_policies')->insert($row + ['university_id' => $universityId, 'created_at' => now()]);
        }
        $this->bump();
        return $this->rawPolicy($universityId);
    }

    /** كتالوج الأدوار (للاختيار في الواجهة). */
    public function roleCatalog(): array
    {
        return DB::table('roles')->orderBy('id')->get(['slug', 'name_ar', 'name_en'])->map(fn ($r) => ['slug' => $r->slug, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en])->values()->all();
    }

    /* ================================================================== rules (إيميل / دومين / دور) */

    /** يطابق قاعدة على إيميلات المستخدم وأدواره. pattern لازم يكون lowercase. */
    public static function ruleMatches(string $kind, string $pattern, array $emails, array $roles): bool
    {
        $pattern = strtolower(trim($pattern));
        if ($kind === 'role') {
            return in_array($pattern, array_map('strtolower', $roles), true);
        }
        foreach ($emails as $e) {
            $e = strtolower(trim((string) $e));
            if ($e === '') {
                continue;
            }
            if ($kind === 'email') {
                if (!str_contains($pattern, '*')) {
                    if ($e === $pattern) {
                        return true;
                    }
                    continue;
                }
                $re = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                if (preg_match($re, $e)) {
                    return true;
                }
            } elseif ($kind === 'domain') {
                $at = strrpos($e, '@');
                if ($at === false) {
                    continue;
                }
                $d = substr($e, $at + 1);
                if (str_starts_with($pattern, '*.')) {
                    $base = substr($pattern, 2);
                    if ($d !== $base && str_ends_with($d, '.' . $base)) {
                        return true;
                    }
                } elseif ($d === $pattern) {
                    return true;
                }
            }
        }
        return false;
    }

    private function normalizePattern(string $kind, string $pattern): string
    {
        $p = strtolower(trim($pattern));
        if ($kind === 'role') {
            if (!DB::table('roles')->where('slug', $p)->exists()) {
                $this->fail('Unknown role: ' . $p);
            }
            return $p;
        }
        if ($kind === 'domain') {
            $p = ltrim($p, '@');
            if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/', $p)) {
                $this->fail('Invalid domain. Use example.edu.eg or *.edu.eg for all subdomains.');
            }
            return $p;
        }
        if ($kind === 'email') {
            if (strlen($p) > 190 || !preg_match('/^[a-z0-9._%+\-*]+@[a-z0-9.\-*]+$/', $p)) {
                $this->fail('Invalid email. Use name@x.edu.eg or a pattern like cs-*@x.edu.eg.');
            }
            if (preg_match('/^\*+@\*+$/', $p)) {
                $this->fail('This pattern matches every email. Use the default-access setting instead.');
            }
            return $p;
        }
        $this->fail('Rule type must be email, domain or role.');
    }

    public function rulePayload(object $r): array
    {
        return ['id' => (int) $r->id, 'university_id' => (int) $r->university_id ?: null, 'kind' => $r->kind, 'pattern' => $r->pattern, 'effect' => $r->effect,
            'scopes' => self::jsonList($r->scopes), 'max_apps' => $r->max_apps !== null ? (int) $r->max_apps : null, 'note' => $r->note,
            'expires_at' => DevTokens::iso($r->expires_at), 'created_at' => DevTokens::iso($r->created_at)];
    }

    public function listRules(int $universityId): array
    {
        return DB::table('dev_access_rules')->where('university_id', $universityId)->orderBy('effect')->orderBy('kind')->orderBy('pattern')->get()
            ->map(fn ($r) => $this->rulePayload($r))->values()->all();
    }

    private function cleanRuleExtras(array $in, array &$row): void
    {
        if (array_key_exists('scopes', $in)) {
            if ($in['scopes'] === null) {
                $row['scopes'] = null;
            } else {
                $s = DevScopes::parse($in['scopes']);
                if ($bad = DevScopes::unknown($s)) {
                    $this->fail('Unknown scope: ' . implode(', ', $bad));
                }
                $row['scopes'] = json_encode($s);
            }
        }
        if (array_key_exists('max_apps', $in)) {
            $row['max_apps'] = self::intOrNull($in['max_apps'], 0, 100);
        }
        if (array_key_exists('note', $in)) {
            $row['note'] = $in['note'] === null ? null : Str::limit(trim((string) $in['note']), 200, '');
        }
        if (array_key_exists('expires_at', $in)) {
            $row['expires_at'] = $this->futureDate($in['expires_at']);
        }
    }

    private function futureDate($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            $d = \Illuminate\Support\Carbon::parse((string) $v)->utc();
        } catch (\Throwable $e) {
            $this->fail('Invalid date.');
        }
        if ($d->isPast()) {
            $this->fail('The expiry date must be in the future.');
        }
        return $d->toDateTimeString();
    }

    public function addRule(int $universityId, array $in, ?int $by = null): array
    {
        $kind = (string) ($in['kind'] ?? '');
        $effect = (string) ($in['effect'] ?? 'allow');
        if (!in_array($effect, ['allow', 'deny'], true)) {
            $this->fail('Effect must be allow or deny.');
        }
        $pattern = $this->normalizePattern($kind, (string) ($in['pattern'] ?? ''));
        if (DB::table('dev_access_rules')->where('university_id', $universityId)->count() >= 500) {
            $this->fail('Too many rules (max 500).', 403);
        }
        if (DB::table('dev_access_rules')->where(['university_id' => $universityId, 'kind' => $kind, 'pattern' => $pattern])->exists()) {
            $this->fail('This rule already exists.', 409);
        }
        $row = ['university_id' => $universityId, 'kind' => $kind, 'pattern' => $pattern, 'effect' => $effect, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()];
        if ($effect === 'allow') {
            $this->cleanRuleExtras($in, $row);
        } else {
            $this->cleanRuleExtras(array_intersect_key($in, array_flip(['note', 'expires_at'])), $row);
        }
        $id = DB::table('dev_access_rules')->insertGetId($row);
        $this->bump();
        return $this->rulePayload(DB::table('dev_access_rules')->find($id));
    }

    public function updateRule(int $universityId, int $id, array $in): array
    {
        $r = DB::table('dev_access_rules')->where('id', $id)->where('university_id', $universityId)->first();
        if (!$r) {
            $this->fail('Rule not found.', 404);
        }
        $row = ['updated_at' => now()];
        if (array_key_exists('effect', $in)) {
            if (!in_array($in['effect'], ['allow', 'deny'], true)) {
                $this->fail('Effect must be allow or deny.');
            }
            $row['effect'] = $in['effect'];
        }
        $this->cleanRuleExtras($in, $row);
        if (($row['effect'] ?? $r->effect) === 'deny') {
            $row['scopes'] = null;
            $row['max_apps'] = null;
        }
        DB::table('dev_access_rules')->where('id', $r->id)->update($row);
        $this->bump();
        return $this->rulePayload(DB::table('dev_access_rules')->find($r->id));
    }

    public function deleteRule(int $universityId, int $id): void
    {
        if (!DB::table('dev_access_rules')->where('id', $id)->where('university_id', $universityId)->delete()) {
            $this->fail('Rule not found.', 404);
        }
        $this->bump();
    }

    /* ================================================================== per-developer access */

    public function accessRow(int $userId): ?object
    {
        $r = DB::table('dev_user_access')->where('user_id', $userId)->first();
        if ($r && $r->expires_at && now()->gte(\Illuminate\Support\Carbon::parse($r->expires_at, 'UTC'))) {
            return null;
        }
        return $r ?: null;
    }

    public function accessPayload(?object $r): ?array
    {
        if (!$r) {
            return null;
        }
        return ['status' => $r->status, 'set_by' => $r->set_by, 'scopes' => self::jsonList($r->scopes),
            'max_apps' => $r->max_apps !== null ? (int) $r->max_apps : null, 'max_keys' => $r->max_keys !== null ? (int) $r->max_keys : null,
            'max_webhooks' => $r->max_webhooks !== null ? (int) $r->max_webhooks : null,
            'webhooks_enabled' => $r->webhooks_enabled !== null ? (bool) $r->webhooks_enabled : null, 'sandbox_only' => $r->sandbox_only !== null ? (bool) $r->sandbox_only : null,
            'rate_limit_per_min' => $r->rate_limit_per_min !== null ? (int) $r->rate_limit_per_min : null, 'reason' => $r->reason, 'request_note' => $r->request_note,
            'expires_at' => DevTokens::iso($r->expires_at), 'decided_at' => DevTokens::iso($r->decided_at), 'updated_at' => DevTokens::iso($r->updated_at)];
    }

    /**
     * سماح/حظر + استثناءات لمطوّر واحد. status: allowed | blocked. أي مفتاح مش موجود في $in بيفضل زي ما هو.
     * @param string $byRole 'admin' (المنصة) أو 'university'
     */
    public function setAccess(int $userId, ?int $universityId, array $in, int $by, string $byRole): array
    {
        $cur = DB::table('dev_user_access')->where('user_id', $userId)->first();
        $row = ['updated_at' => now(), 'decided_by' => $by, 'decided_at' => now(), 'set_by' => $byRole === 'admin' ? 'admin' : 'university'];
        if ($universityId) {
            $row['university_id'] = $universityId;
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['allowed', 'blocked'], true)) {
                $this->fail('Status must be allowed or blocked.');
            }
            $row['status'] = $in['status'];
        } elseif (!$cur) {
            $row['status'] = 'allowed';
        } elseif (in_array($cur->status, ['pending', 'rejected'], true)) {
            $row['status'] = 'allowed';
        }
        if (array_key_exists('scopes', $in)) {
            if ($in['scopes'] === null) {
                $row['scopes'] = null;
            } else {
                $s = DevScopes::parse($in['scopes']);
                if ($bad = DevScopes::unknown($s)) {
                    $this->fail('Unknown scope: ' . implode(', ', $bad));
                }
                $row['scopes'] = json_encode($s);
            }
        }
        foreach ([['max_apps', 100], ['max_keys', 500], ['max_webhooks', 100]] as [$k, $mx]) {
            if (array_key_exists($k, $in)) {
                $row[$k] = self::intOrNull($in[$k], 0, $mx);
            }
        }
        foreach (['webhooks_enabled', 'sandbox_only'] as $k) {
            if (array_key_exists($k, $in)) {
                $row[$k] = $in[$k] === null ? null : (int) (bool) $in[$k];
            }
        }
        if (array_key_exists('rate_limit_per_min', $in)) {
            $row['rate_limit_per_min'] = self::intOrNull($in['rate_limit_per_min'], 1, 100000);
        }
        if (array_key_exists('reason', $in)) {
            $row['reason'] = $in['reason'] === null ? null : Str::limit(trim((string) $in['reason']), 200, '');
        }
        if (array_key_exists('expires_at', $in)) {
            $row['expires_at'] = $this->futureDate($in['expires_at']);
        }
        if ($cur) {
            DB::table('dev_user_access')->where('id', $cur->id)->update($row);
        } else {
            DB::table('dev_user_access')->insert($row + ['user_id' => $userId, 'created_at' => now()]);
        }
        $this->bump();
        return $this->accessPayload(DB::table('dev_user_access')->where('user_id', $userId)->first());
    }

    /** يرجّع المطوّر للسياسة العامة (يمسح الاستثناء). */
    public function clearAccess(int $userId): void
    {
        DB::table('dev_user_access')->where('user_id', $userId)->delete();
        $this->bump();
    }

    /** المطوّر نفسه يطلب وصول (لما الجامعة/المنصة مشترطة موافقة). */
    public function requestAccess(int $userId, ?string $note): array
    {
        $e = $this->effective($userId);
        if ($e['allowed']) {
            $this->fail('You already have developer access.', 409);
        }
        if ($e['reason'] === 'pending_approval') {
            $this->fail('Your request is already pending.', 409);
        }
        if (!$e['can_request']) {
            $this->fail($e['message'], 403);
        }
        $row = ['status' => 'pending', 'set_by' => 'user', 'university_id' => $e['university_id'], 'request_note' => $note !== null ? Str::limit(trim($note), 300, '') : null,
            'reason' => null, 'decided_by' => null, 'decided_at' => null, 'updated_at' => now()];
        if (DB::table('dev_user_access')->where('user_id', $userId)->exists()) {
            DB::table('dev_user_access')->where('user_id', $userId)->update($row);
        } else {
            DB::table('dev_user_access')->insert($row + ['user_id' => $userId, 'created_at' => now()]);
        }
        $this->bump();
        return $this->effective($userId);
    }

    /** الإدارة توافق/ترفض طلب وصول. */
    public function decide(int $userId, ?int $universityId, bool $approve, ?string $reason, int $by, string $byRole): array
    {
        $cur = DB::table('dev_user_access')->where('user_id', $userId)->first();
        if (!$cur || !in_array($cur->status, ['pending', 'rejected'], true)) {
            $this->fail('There is no pending request for this user.', 404);
        }
        DB::table('dev_user_access')->where('id', $cur->id)->update(['status' => $approve ? 'allowed' : 'rejected', 'set_by' => $byRole === 'admin' ? 'admin' : 'university',
            'reason' => $reason !== null ? Str::limit(trim($reason), 200, '') : null, 'decided_by' => $by, 'decided_at' => now(), 'updated_at' => now()]
            + ($universityId ? ['university_id' => $universityId] : []));
        $this->bump();
        return $this->accessPayload(DB::table('dev_user_access')->where('user_id', $userId)->first());
    }

    /** يوقف كل شيء بتاع المطوّر فورًا (مفاتيح، تطبيقات، webhooks) وممكن يحظره. */
    public function revokeAll(int $userId, ?int $universityId, string $byRole, ?string $reason, bool $block, int $actorId): array
    {
        $by = $byRole === 'admin' ? 'admin' : 'university';
        $reason = Str::limit(trim((string) $reason) ?: 'Revoked by an administrator', 200, '');
        $out = DB::transaction(function () use ($userId, $by, $reason) {
            $appIds = DB::table('dev_apps')->where('owner_user_id', $userId)->pluck('id')->all();
            return [
                'keys' => DB::table('dev_api_keys')->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]),
                'tokens' => $appIds ? DB::table('dev_oauth_tokens')->whereIn('app_id', $appIds)->whereNull('revoked_at')->update(['revoked_at' => now()]) : 0,
                'apps' => DB::table('dev_apps')->where('owner_user_id', $userId)->where('status', 'active')->update(['status' => 'suspended', 'suspended_by' => $by, 'suspended_reason' => $reason, 'updated_at' => now()]),
                'webhooks' => DB::table('dev_webhooks')->where('user_id', $userId)->where('is_active', 1)->update(['is_active' => 0, 'disabled_at' => now(), 'disabled_reason' => 'Disabled by an administrator', 'updated_at' => now()]),
            ];
        });
        if ($block) {
            $this->setAccess($userId, $universityId, ['status' => 'blocked', 'reason' => $reason], $actorId, $byRole);
        }
        return $out + ['blocked' => $block];
    }

    /** يشغّل تاني التطبيقات اللي الإدارة (مش المالك) هي اللي وقفتها. */
    public function resumeApps(int $userId): int
    {
        return DB::table('dev_apps')->where('owner_user_id', $userId)->where('status', 'suspended')->whereIn('suspended_by', ['university', 'admin'])
            ->update(['status' => 'active', 'suspended_by' => null, 'suspended_reason' => null, 'updated_at' => now()]);
    }

    /* ================================================================== effective access (كل الطبقات مع بعض) */

    private function epoch(): int
    {
        return (int) Cache::get('dev:access:epoch', 0);
    }

    /** أي تغيير في سياسة/قاعدة/استثناء بيلغي كاش الكل فورًا. */
    public function bump(): void
    {
        Cache::put('dev:access:epoch', $this->epoch() + 1, 86400 * 30);
    }

    public function universityOf(int $userId): ?int
    {
        $id = DB::table('mail_accounts')->where('user_id', $userId)->whereNotNull('university_id')->orderByDesc('is_default')->orderBy('id')->value('university_id');
        return $id ? (int) $id : null;
    }

    public function rolesOf(int $userId): array
    {
        $svc = app(RoleService::class);
        $roles = $svc->allRolesFor($userId);
        return $roles ?: [$svc->primaryRoleFor($userId)];
    }

    public function effective(int $userId): array
    {
        return Cache::remember('dev:access:' . $this->epoch() . ':' . $userId, 10, fn () => $this->compute($userId));
    }

    private function rulesFor(?int $universityId): array
    {
        $ids = array_values(array_filter([self::PLATFORM, $universityId], fn ($x) => $x !== null));
        return DB::table('dev_access_rules')->whereIn('university_id', $ids)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'kind' => $r->kind, 'pattern' => $r->pattern, 'effect' => $r->effect, 'scopes' => self::jsonList($r->scopes),
                'max_apps' => $r->max_apps !== null ? (int) $r->max_apps : null, 'level' => (int) $r->university_id === self::PLATFORM ? 'platform' : 'university'])->all();
    }

    private function compute(int $userId): array
    {
        $email = (string) DB::table('users')->where('id', $userId)->value('email');
        $uni = $this->universityOf($userId);
        $emails = array_values(array_unique(array_filter(array_merge([$email], DB::table('mail_accounts')->where('user_id', $userId)->pluck('address')->all()))));
        $roles = $this->rolesOf($userId);
        $row = $this->accessRow($userId);
        $res = self::evaluate([
            'enabled' => (bool) config('developer.enabled'), 'emails' => $emails, 'roles' => $roles, 'university_id' => $uni,
            'platform' => $this->rawPolicy(self::PLATFORM), 'uni' => $uni ? $this->rawPolicy($uni) : null,
            'row' => $row ? (array) $row : null, 'rules' => $this->rulesFor($uni),
        ]);
        $res['override'] = $this->accessPayload($row);
        $res['role'] = $roles[0] ?? null;
        return $res;
    }

    /** "لو إيميل كذا ودور كذا جه، يعمل إيه؟" — بدون مستخدم حقيقي (بيستخدمها زرار الاختبار في الإدارة). */
    public function simulate(?int $universityId, string $email, ?string $role): array
    {
        $roles = $role ? [strtolower(trim($role))] : [];
        $res = self::evaluate([
            'enabled' => (bool) config('developer.enabled'), 'emails' => [strtolower(trim($email))], 'roles' => $roles, 'university_id' => $universityId,
            'platform' => $this->rawPolicy(self::PLATFORM), 'uni' => $universityId ? $this->rawPolicy($universityId) : null, 'row' => null, 'rules' => $this->rulesFor($universityId),
        ]);
        $res['override'] = null;
        $res['role'] = $roles[0] ?? null;
        return $res;
    }

    /**
     * دالة نقية (بدون DB). المدخلات: enabled, emails[], roles[], university_id, platform (policy), uni (policy|null), row (dev_user_access|null), rules[].
     */
    public static function evaluate(array $c): array
    {
        $plat = $c['platform'];
        $uni = $c['uni'] ?? null;
        $row = $c['row'] ?? null;
        $roles = array_map('strtolower', (array) $c['roles']);
        $status = $row['status'] ?? null;
        $explicit = $status === 'allowed';
        $bypass = ['platform' => $explicit && ($row['set_by'] ?? '') === 'admin', 'university' => $explicit];
        $levels = ['platform' => $plat] + ($uni ? ['university' => $uni] : []);

        $matched = [];
        foreach ((array) $c['rules'] as $r) {
            if (self::ruleMatches($r['kind'], $r['pattern'], (array) $c['emails'], $roles)) {
                $matched[] = $r;
            }
        }
        $allowRules = array_values(array_filter($matched, fn ($r) => $r['effect'] === 'allow'));
        $brief = fn (array $rs) => array_map(fn ($r) => ['id' => $r['id'], 'kind' => $r['kind'], 'pattern' => $r['pattern'], 'effect' => $r['effect'], 'level' => $r['level']], $rs);

        $deny = function (string $reason, bool $canRequest = false) use ($c, $matched, $brief, $status) {
            [$en, $ar] = self::MESSAGES[$reason];
            return ['allowed' => false, 'reason' => $reason, 'message' => $en, 'message_ar' => $ar, 'can_request' => $canRequest,
                'request_status' => in_array($status, ['pending', 'rejected'], true) ? $status : null, 'via' => null, 'matched_rules' => $brief($matched),
                'scopes' => [], 'webhooks_enabled' => false, 'sandbox_only' => false, 'events' => [], 'limits' => ['apps' => 0, 'keys' => 0, 'webhooks' => 0, 'rate_per_min' => null],
                'university_id' => $c['university_id'] ?? null, 'roles' => $c['roles']];
        };

        if (!($c['enabled'] ?? true)) {
            return $deny('disabled');
        }
        if (!$plat['apps_enabled']) {
            return $deny('platform_disabled');
        }
        if ($uni && !$uni['apps_enabled']) {
            return $deny('tenant_disabled');
        }
        if ($status === 'blocked') {
            return $deny('user_blocked');
        }
        foreach ($levels as $lv => $p) {
            if ($p['allowed_roles'] !== null && !array_intersect($roles, array_map('strtolower', $p['allowed_roles'])) && !$bypass[$lv]) {
                return $deny('role_not_allowed');
            }
        }
        foreach ($matched as $r) {
            if ($r['effect'] === 'deny' && !$bypass[$r['level']]) {
                return $deny('rule_denied');
            }
        }
        $allowedAt = fn (string $lv) => (bool) array_filter($allowRules, fn ($r) => $r['level'] === $lv);
        foreach ($levels as $lv => $p) {
            if ($p['default_access'] === 'closed' && !$bypass[$lv] && !$allowedAt($lv)) {
                if (!$p['require_approval']) {
                    return $deny('not_allowlisted');
                }
                if ($status === 'pending') {
                    return $deny('pending_approval');
                }
                return $status === 'rejected' ? $deny('request_rejected', true) : $deny('not_allowlisted', true);
            }
        }
        foreach ($levels as $lv => $p) {
            if ($p['require_approval'] && !$bypass[$lv] && !$allowedAt($lv)) {
                if ($status === 'pending') {
                    return $deny('pending_approval');
                }
                return $deny($status === 'rejected' ? 'request_rejected' : 'approval_required', true);
            }
        }

        // ---- مسموح: scopes والحدود
        $all = DevScopes::names();
        $base = $uni['allowed_scopes'] ?? null;
        $ruleScopes = null;
        foreach ($allowRules as $r) {
            if ($r['scopes'] !== null) {
                $ruleScopes = array_values(array_unique(array_merge($ruleScopes ?? [], $r['scopes'])));
            }
        }
        if ($ruleScopes !== null) {
            $base = $ruleScopes;
        }
        $rowScopes = $row ? self::jsonList($row['scopes'] ?? null) : null;
        if ($rowScopes !== null) {
            $base = $rowScopes;
        }
        $scopes = array_values(array_intersect($all, $base ?? $all, $plat['allowed_scopes'] ?? $all));

        $cfg = (array) config('developer.limits', []);
        $ruleApps = null;
        foreach ($allowRules as $r) {
            if ($r['max_apps'] !== null) {
                $ruleApps = max($ruleApps ?? 0, $r['max_apps']);
            }
        }
        $cap = fn (?int $v, ?int $platCap) => $platCap === null ? $v : ($v === null ? $platCap : min($v, $platCap));
        $nz = fn ($v) => ($v !== null && $v !== '') ? (int) $v : null;
        $apps = $cap($nz($row['max_apps'] ?? null) ?? $ruleApps ?? ($uni['max_apps_per_user'] ?? null) ?? (int) ($cfg['apps_per_user'] ?? 10), $plat['max_apps_per_user']);
        $keys = $cap($nz($row['max_keys'] ?? null) ?? ($uni['max_keys_per_user'] ?? null) ?? (int) ($cfg['keys_per_user'] ?? 20), $plat['max_keys_per_user']);
        $hooks = $cap($nz($row['max_webhooks'] ?? null) ?? ($uni['max_webhooks_per_user'] ?? null) ?? (int) ($cfg['webhooks_per_user'] ?? 10), $plat['max_webhooks_per_user']);
        $rate = $cap($nz($row['rate_limit_per_min'] ?? null) ?? ($uni['rate_limit_per_min'] ?? null), $plat['rate_limit_per_min']);

        $rowHooks = $row['webhooks_enabled'] ?? null;
        $rowSbx = $row['sandbox_only'] ?? null;
        $events = null;
        foreach ([$plat['allowed_events'], $uni['allowed_events'] ?? null] as $ev) {
            if ($ev !== null) {
                $events = $events === null ? $ev : array_values(array_intersect($events, $ev));
            }
        }
        $via = $explicit ? 'override' : ($allowRules ? 'rule' : 'policy');
        return ['allowed' => true, 'reason' => null, 'message' => null, 'message_ar' => null, 'can_request' => false, 'request_status' => null, 'via' => $via,
            'matched_rules' => $brief($matched), 'scopes' => $scopes,
            'webhooks_enabled' => (bool) $plat['webhooks_enabled'] && ($rowHooks !== null ? (bool) $rowHooks : (bool) ($uni['webhooks_enabled'] ?? true)),
            'sandbox_only' => (bool) $plat['sandbox_only'] || ($rowSbx !== null ? (bool) $rowSbx : (bool) ($uni['sandbox_only'] ?? false)),
            'events' => $events, 'limits' => ['apps' => $apps, 'keys' => $keys, 'webhooks' => $hooks, 'rate_per_min' => $rate],
            'university_id' => $c['university_id'] ?? null, 'roles' => $c['roles']];
    }

    /* ================================================================== إدارة المطورين (قائمة / صلاحية الإدارة) */

    /** @param array{role:string,universityId:?int} $scope */
    public function assertManaged(array $scope, int $userId): void
    {
        if (!DB::table('users')->where('id', $userId)->whereNull('deleted_at')->exists()) {
            $this->fail('User not found.', 404);
        }
        if ($scope['role'] === 'admin') {
            return;
        }
        $uni = (int) $scope['universityId'];
        $in = DB::table('mail_accounts')->where('user_id', $userId)->where('university_id', $uni)->exists()
            || DB::table('dev_apps')->where('owner_user_id', $userId)->where('university_id', $uni)->exists()
            || DB::table('dev_user_access')->where('user_id', $userId)->where('university_id', $uni)->exists();
        if (!$in) {
            $this->fail('This user is not a member of your university.', 404);
        }
        if (in_array('admin', $this->rolesOf($userId), true)) {
            $this->fail('You cannot manage a platform administrator.', 403);
        }
    }

    private function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }

    /**
     * قائمة المطورين. mode=developers (الافتراضي): اللي عندهم تطبيقات/مفاتيح/webhooks/استثناء. mode=members: كل أعضاء الجامعة (للبحث وإضافة استثناء).
     * @param array{role:string,universityId:?int} $scope
     * @param array{q?:string,role?:string,status?:string,mode?:string} $f
     */
    public function developers(array $scope, array $f, int $page = 1, int $per = 25): array
    {
        $per = max(1, min(50, $per));
        $page = max(1, $page);
        $uni = $scope['universityId'];
        $q = DB::table('users as u')->whereNull('u.deleted_at');
        if ($uni) {
            $q->where(function ($w) use ($uni) {
                $w->whereIn('u.id', DB::table('mail_accounts')->where('university_id', $uni)->select('user_id'))
                    ->orWhereIn('u.id', DB::table('dev_user_access')->where('university_id', $uni)->select('user_id'))
                    ->orWhereIn('u.id', DB::table('dev_apps')->where('university_id', $uni)->select('owner_user_id'));
            });
        }
        $mode = (string) ($f['mode'] ?? 'developers');
        if ($mode !== 'members' || (!$uni && empty($f['q']))) {
            $q->where(function ($w) {
                $w->whereIn('u.id', DB::table('dev_apps')->select('owner_user_id'))
                    ->orWhereIn('u.id', DB::table('dev_api_keys')->select('user_id'))
                    ->orWhereIn('u.id', DB::table('dev_webhooks')->select('user_id'))
                    ->orWhereIn('u.id', DB::table('dev_user_access')->select('user_id'));
            });
        }
        if (!empty($f['q'])) {
            $like = $this->like(trim((string) $f['q']));
            $q->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like));
        }
        if (!empty($f['role'])) {
            $q->whereIn('u.id', DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('r.slug', (string) $f['role'])->select('ur.user_id'));
        }
        if (!empty($f['status'])) {
            $s = (string) $f['status'];
            if ($s === 'none') {
                $q->whereNotIn('u.id', DB::table('dev_user_access')->select('user_id'));
            } elseif (in_array($s, ['allowed', 'blocked', 'pending', 'rejected'], true)) {
                $q->whereIn('u.id', DB::table('dev_user_access')->where('status', $s)->select('user_id'));
            }
        }
        $total = (clone $q)->count();
        $users = $q->orderByDesc('u.id')->forPage($page, $per)->get(['u.id', 'u.full_name', 'u.email', 'u.status', 'u.avatar_path']);
        $ids = $users->pluck('id')->map(fn ($x) => (int) $x)->all();
        $apps = $ids ? DB::table('dev_apps')->whereIn('owner_user_id', $ids)->groupBy('owner_user_id')->selectRaw('owner_user_id as uid, count(*) as c, max(last_used_at) as lu')->get()->keyBy('uid') : collect();
        $keys = $ids ? DB::table('dev_api_keys')->whereIn('user_id', $ids)->whereNull('revoked_at')->groupBy('user_id')->selectRaw('user_id as uid, count(*) as c, max(last_used_at) as lu')->get()->keyBy('uid') : collect();
        $hooks = $ids ? DB::table('dev_webhooks')->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id as uid, count(*) as c')->get()->keyBy('uid') : collect();
        $access = $ids ? DB::table('dev_user_access')->whereIn('user_id', $ids)->get()->keyBy('user_id') : collect();
        $items = $users->map(function ($u) use ($apps, $keys, $hooks, $access) {
            $id = (int) $u->id;
            $e = $this->effective($id);
            $lu = collect([$apps[$id]->lu ?? null, $keys[$id]->lu ?? null])->filter()->max();
            return ['user_id' => $id, 'full_name' => $u->full_name, 'email' => $u->email, 'account_status' => $u->status, 'avatar_path' => $u->avatar_path,
                'role' => $e['role'], 'roles' => $e['roles'], 'university_id' => $e['university_id'],
                'apps' => (int) ($apps[$id]->c ?? 0), 'keys' => (int) ($keys[$id]->c ?? 0), 'webhooks' => (int) ($hooks[$id]->c ?? 0), 'last_used_at' => DevTokens::iso($lu),
                'access' => $this->accessPayload($access[$id] ?? null),
                'effective' => ['allowed' => $e['allowed'], 'reason' => $e['reason'], 'message' => $e['message'], 'message_ar' => $e['message_ar'], 'via' => $e['via'], 'scopes_count' => count($e['scopes'])]];
        })->values()->all();
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    /** ملخص أرقام لشريط الإدارة. */
    public function summary(array $scope): array
    {
        $uni = $scope['universityId'];
        $apps = DB::table('dev_apps')->when($uni, fn ($q) => $q->where('university_id', $uni));
        $acc = DB::table('dev_user_access')->when($uni, fn ($q) => $q->where('university_id', $uni));
        return [
            'apps' => (clone $apps)->count(), 'apps_suspended' => (clone $apps)->where('status', 'suspended')->count(),
            'developers_blocked' => (clone $acc)->where('status', 'blocked')->count(), 'requests_pending' => (clone $acc)->where('status', 'pending')->count(),
            'rules' => DB::table('dev_access_rules')->where('university_id', $uni ?: self::PLATFORM)->count(),
        ];
    }

    /** مستخدمين بأدوار وإيميلات (للبحث وإضافتهم كمطورين من غير ما يكون عندهم نشاط). */
    public function describeUser(int $userId): array
    {
        $u = DB::table('users')->where('id', $userId)->first(['id', 'full_name', 'email', 'status', 'avatar_path', 'last_login_at']);
        $e = $this->effective($userId);
        return ['user_id' => (int) $u->id, 'full_name' => $u->full_name, 'email' => $u->email, 'account_status' => $u->status, 'avatar_path' => $u->avatar_path,
            'last_login_at' => DevTokens::iso($u->last_login_at), 'role' => $e['role'], 'roles' => $e['roles'], 'university_id' => $e['university_id'],
            'mail_addresses' => DB::table('mail_accounts')->where('user_id', $userId)->pluck('address')->all(), 'effective' => $e];
    }
}
