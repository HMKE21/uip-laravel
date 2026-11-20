<?php

namespace App\Services\Developer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Developer Center: تطبيقات OAuth + API Keys + الموافقات (Grants) + سياسة الجامعة.
 * الأسرار (client_secret / API key) بتتعرض مرة واحدة وقت الإنشاء وبتتخزن sha256 بس.
 * أي خطأ استخدام بيترمي \DomainException(msg, http).
 */
class DeveloperAppService
{
    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ users & policy */

    public function userActive(int $userId): bool
    {
        $u = DB::table('users')->where('id', $userId)->first(['status', 'deleted_at']);
        return $u && $u->deleted_at === null && !in_array($u->status, ['suspended', 'banned'], true);
    }

    public function __construct(private DevAccessService $access)
    {
    }

    public function universityOf(int $userId): ?int
    {
        return $this->access->universityOf($userId);
    }

    /** سياسة جامعة واحدة (أو المنصة لو null/0) زي ما اتحفظت — للإدارة. */
    public function policyForUniversity(?int $universityId): array
    {
        return $this->access->rawPolicy((int) $universityId);
    }

    /**
     * السياسة الفعلية لمستخدم (المنصة + الجامعة + القواعد + استثناء المطوّر) بنفس الشكل القديم + حقول جديدة.
     * apps_enabled هنا = "المستخدم ده مسموح له يستخدم أدوات المطورين دلوقتي"، فكل الأماكن القديمة اللي بتفحصه اتحمت أوتوماتيك.
     */
    public function policyFor(int $userId): array
    {
        $e = $this->access->effective($userId);
        return [
            'university_id' => $e['university_id'], 'apps_enabled' => $e['allowed'], 'webhooks_enabled' => $e['allowed'] && $e['webhooks_enabled'],
            'max_apps_per_user' => $e['limits']['apps'], 'max_keys_per_user' => $e['limits']['keys'], 'max_webhooks_per_user' => $e['limits']['webhooks'],
            'rate_limit_per_min' => $e['limits']['rate_per_min'], 'allowed_scopes' => $e['scopes'], 'allowed_events' => $e['events'], 'sandbox_only' => $e['sandbox_only'],
            'access' => $e,
        ];
    }

    public function savePolicy(int $universityId, array $in, ?int $by = null): array
    {
        return $this->access->savePolicy($universityId, $in, $by);
    }

    /** scopes اللي مسموح للمستخدم يطلبها. */
    private function allowedScopesFor(int $userId): array
    {
        return $this->policyFor($userId)['allowed_scopes'];
    }

    private function assertDevAllowed(int $userId): array
    {
        $p = $this->policyFor($userId);
        if (!$p['apps_enabled']) {
            $a = $p['access'];
            $this->fail($a['message'] ?? 'Developer access is not available for your account.', $a['reason'] === 'disabled' ? 503 : 403);
        }
        return $p;
    }

    private function cleanScopes(int $userId, $in, bool $required = true): array
    {
        $scopes = DevScopes::parse($in);
        if ($required && !$scopes) {
            $this->fail('Choose at least one scope.');
        }
        if ($bad = DevScopes::unknown($scopes)) {
            $this->fail('Unknown scope: ' . implode(', ', $bad));
        }
        $denied = array_values(array_diff($scopes, $this->allowedScopesFor($userId)));
        if ($denied) {
            $this->fail('Your account is not allowed these scopes: ' . implode(', ', $denied), 403);
        }
        return $scopes;
    }

    /* ------------------------------------------------------------------ apps */

    private function cleanRedirect(string $u): string
    {
        $u = trim($u);
        if ($u === '' || strlen($u) > 400 || preg_match('/[\s\x00-\x1f]/', $u)) {
            $this->fail('Invalid redirect URI.');
        }
        $p = parse_url($u);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            $this->fail('Invalid redirect URI (no fragments or credentials allowed).');
        }
        if (str_contains($u, '*')) {
            $this->fail('Wildcards are not allowed in redirect URIs.');
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local && config('developer.oauth.allow_http_localhost'))) {
            $this->fail('Redirect URIs must use https (http is allowed for localhost only).');
        }
        return $u;
    }

    private function cleanRedirects($in): array
    {
        $list = array_values(array_unique(array_map(fn ($u) => $this->cleanRedirect((string) $u), array_filter((array) $in, fn ($u) => trim((string) $u) !== ''))));
        if (count($list) > (int) config('developer.limits.redirect_uris', 10)) {
            $this->fail('Too many redirect URIs.');
        }
        return $list;
    }

    private function cleanHomepage($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (strlen($v) > 300 || !preg_match('#^https?://[^\s]+$#i', $v) || !filter_var($v, FILTER_VALIDATE_URL)) {
            $this->fail('Invalid homepage URL.');
        }
        return $v;
    }

    public function appPayload(object $a, ?string $secret = null): array
    {
        $users = (int) DB::table('dev_oauth_grants')->where('app_id', $a->id)->whereNull('revoked_at')->count();
        $out = [
            'id' => (int) $a->id, 'name' => $a->name, 'description' => $a->description, 'homepage_url' => $a->homepage_url,
            'client_id' => $a->client_id, 'client_type' => $a->client_type, 'client_secret_hint' => $a->client_secret_hint,
            'redirect_uris' => (array) json_decode((string) $a->redirect_uris, true), 'scopes' => (array) json_decode((string) $a->scopes, true),
            'status' => $a->status, 'suspended_reason' => $a->suspended_reason, 'suspended_by' => $a->suspended_by,
            'rate_limit_per_min' => $a->rate_limit_per_min !== null ? (int) $a->rate_limit_per_min : null,
            'authorized_users' => $users, 'last_used_at' => DevTokens::iso($a->last_used_at), 'created_at' => DevTokens::iso($a->created_at),
            'owner_user_id' => (int) $a->owner_user_id, 'university_id' => $a->university_id ? (int) $a->university_id : null,
        ];
        if ($secret !== null) {
            $out['client_secret'] = $secret; // بيظهر مرة واحدة بس
        }
        return $out;
    }

    public function listApps(int $userId): array
    {
        return DB::table('dev_apps')->where('owner_user_id', $userId)->orderByDesc('id')->get()->map(fn ($a) => $this->appPayload($a))->values()->all();
    }

    public function ownApp(int $userId, int $id): object
    {
        $a = DB::table('dev_apps')->where('id', $id)->where('owner_user_id', $userId)->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        return $a;
    }

    public function getApp(int $userId, int $id): array
    {
        return $this->appPayload($this->ownApp($userId, $id));
    }

    /** @return array{app:array} + client_secret مرة واحدة للـ confidential */
    public function createApp(int $userId, array $in): array
    {
        $policy = $this->assertDevAllowed($userId);
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $this->fail('App name must be 3 to 80 characters.');
        }
        $type = (string) ($in['client_type'] ?? 'confidential');
        if (!in_array($type, ['confidential', 'public'], true)) {
            $this->fail('Invalid client type.');
        }
        $max = (int) $policy['max_apps_per_user'];
        if (DB::table('dev_apps')->where('owner_user_id', $userId)->count() >= $max) {
            $this->fail('You reached the maximum number of apps.', 403);
        }
        $scopes = $this->cleanScopes($userId, $in['scopes'] ?? ['profile.read']);
        $redirects = $this->cleanRedirects($in['redirect_uris'] ?? []);
        $secret = $type === 'confidential' ? DevTokens::make('uips_', 40) : null;
        $id = DB::table('dev_apps')->insertGetId([
            'owner_user_id' => $userId, 'university_id' => $policy['university_id'], 'name' => $name,
            'description' => isset($in['description']) ? Str::limit(trim((string) $in['description']), 500, '') : null,
            'homepage_url' => $this->cleanHomepage($in['homepage_url'] ?? null), 'client_id' => 'uipc_' . Str::lower(Str::random(24)),
            'client_secret_hash' => $secret ? DevTokens::hash($secret) : null, 'client_secret_hint' => $secret ? substr($secret, 0, 9) : null,
            'client_type' => $type, 'redirect_uris' => json_encode($redirects), 'scopes' => json_encode($scopes), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['app' => $this->appPayload(DB::table('dev_apps')->find($id), $secret)];
    }

    public function updateApp(int $userId, int $id, array $in): array
    {
        $this->assertDevAllowed($userId);
        $a = $this->ownApp($userId, $id);
        $upd = ['updated_at' => now()];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
                $this->fail('App name must be 3 to 80 characters.');
            }
            $upd['name'] = $name;
        }
        if (array_key_exists('description', $in)) {
            $upd['description'] = $in['description'] !== null ? Str::limit(trim((string) $in['description']), 500, '') : null;
        }
        if (array_key_exists('homepage_url', $in)) {
            $upd['homepage_url'] = $this->cleanHomepage($in['homepage_url']);
        }
        if (array_key_exists('redirect_uris', $in)) {
            $upd['redirect_uris'] = json_encode($this->cleanRedirects($in['redirect_uris']));
        }
        if (array_key_exists('scopes', $in)) {
            $upd['scopes'] = json_encode($this->cleanScopes($userId, $in['scopes']));
        }
        DB::table('dev_apps')->where('id', $a->id)->update($upd);
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    public function rotateSecret(int $userId, int $id): array
    {
        $this->assertDevAllowed($userId);
        $a = $this->ownApp($userId, $id);
        if ($a->client_type !== 'confidential') {
            $this->fail('Public apps have no client secret.');
        }
        $secret = DevTokens::make('uips_', 40);
        DB::table('dev_apps')->where('id', $a->id)->update(['client_secret_hash' => DevTokens::hash($secret), 'client_secret_hint' => substr($secret, 0, 9), 'updated_at' => now()]);
        return $this->appPayload(DB::table('dev_apps')->find($a->id), $secret);
    }

    /** صاحب التطبيق يوقفه/يشغّله. لو الجامعة/الأدمن هما اللي أوقفوه، صاحبه ميقدرش يشغّله. */
    public function setOwnerStatus(int $userId, int $id, bool $active): array
    {
        $a = $this->ownApp($userId, $id);
        if ($active) {
            if ($a->status === 'suspended' && $a->suspended_by && $a->suspended_by !== 'owner') {
                $this->fail('This app was suspended by your university or the platform.', 403);
            }
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'active', 'suspended_reason' => null, 'suspended_by' => null, 'updated_at' => now()]);
        } else {
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'suspended', 'suspended_by' => 'owner', 'suspended_reason' => 'Paused by the owner', 'updated_at' => now()]);
        }
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    public function deleteApp(int $userId, int $id): void
    {
        $a = $this->ownApp($userId, $id);
        DB::transaction(function () use ($a) {
            DB::table('dev_oauth_tokens')->where('app_id', $a->id)->delete();
            DB::table('dev_oauth_codes')->where('app_id', $a->id)->delete();
            DB::table('dev_oauth_grants')->where('app_id', $a->id)->delete();
            DB::table('dev_api_keys')->where('app_id', $a->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $hooks = DB::table('dev_webhooks')->where('app_id', $a->id)->pluck('id')->all();
            if ($hooks) {
                DB::table('dev_webhook_deliveries')->whereIn('webhook_id', $hooks)->delete();
                DB::table('dev_webhooks')->whereIn('id', $hooks)->delete();
            }
            DB::table('dev_apps')->where('id', $a->id)->delete();
        });
    }

    /* ------------------------------------------------------------------ API keys */

    public function keyPayload(object $k, ?string $raw = null): array
    {
        $out = [
            'id' => (int) $k->id, 'name' => $k->name, 'mode' => $k->mode, 'prefix' => $k->prefix, 'scopes' => (array) json_decode((string) $k->scopes, true),
            'app_id' => $k->app_id ? (int) $k->app_id : null, 'mail_account_id' => $k->mail_account_id ? (int) $k->mail_account_id : null,
            'ip_allowlist' => (array) json_decode((string) ($k->ip_allowlist ?? '[]'), true), 'expires_at' => DevTokens::iso($k->expires_at),
            'last_used_at' => DevTokens::iso($k->last_used_at), 'last_used_ip' => $k->last_used_ip, 'revoked_at' => DevTokens::iso($k->revoked_at),
            'created_at' => DevTokens::iso($k->created_at),
        ];
        if ($raw !== null) {
            $out['key'] = $raw; // بيظهر مرة واحدة بس
        }
        return $out;
    }

    public function listKeys(int $userId): array
    {
        return DB::table('dev_api_keys')->where('user_id', $userId)->orderByDesc('id')->get()->map(fn ($k) => $this->keyPayload($k))->values()->all();
    }

    private function cleanIpList($in): array
    {
        $out = [];
        foreach (array_filter((array) $in, fn ($v) => trim((string) $v) !== '') as $v) {
            $v = trim((string) $v);
            [$ip, $bits] = array_pad(explode('/', $v, 2), 2, null);
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || ($bits !== null && (!ctype_digit($bits) || (int) $bits > ($isV6 ? 128 : 32)))) {
                $this->fail('Invalid IP address: ' . $v);
            }
            $out[] = $v;
        }
        if (count($out) > 20) {
            $this->fail('Too many IP addresses (max 20).');
        }
        return array_values(array_unique($out));
    }

    public function createKey(int $userId, array $in): array
    {
        $policy = $this->assertDevAllowed($userId);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $this->fail('Give the key a name (up to 80 characters).');
        }
        $mode = (string) ($in['mode'] ?? ($policy['sandbox_only'] ? 'sandbox' : 'live'));
        if (!in_array($mode, ['live', 'sandbox'], true)) {
            $this->fail('Invalid mode.');
        }
        if ($mode === 'live' && $policy['sandbox_only']) {
            $this->fail('Your account can only create sandbox (test) keys.', 403);
        }
        if (DB::table('dev_api_keys')->where('user_id', $userId)->whereNull('revoked_at')->count() >= (int) $policy['max_keys_per_user']) {
            $this->fail('You reached the maximum number of API keys.', 403);
        }
        $scopes = $this->cleanScopes($userId, $in['scopes'] ?? []);
        $appId = !empty($in['app_id']) ? (int) $in['app_id'] : null;
        if ($appId) {
            $app = $this->ownApp($userId, $appId);
            if ($extra = array_diff($scopes, (array) json_decode((string) $app->scopes, true))) {
                $this->fail('The app does not have these scopes: ' . implode(', ', $extra));
            }
        }
        $accId = !empty($in['mail_account_id']) ? (int) $in['mail_account_id'] : null;
        if ($accId && !DB::table('mail_accounts')->where('id', $accId)->where('user_id', $userId)->exists()) {
            $this->fail('Mailbox not found.', 404);
        }
        $expires = null;
        if (!empty($in['expires_in_days'])) {
            $d = (int) $in['expires_in_days'];
            if ($d < 1 || $d > 730) {
                $this->fail('Expiry must be between 1 and 730 days.');
            }
            $expires = now()->addDays($d);
        }
        $raw = DevTokens::make($mode === 'sandbox' ? 'uipk_test_' : 'uipk_live_', 40);
        $id = DB::table('dev_api_keys')->insertGetId([
            'user_id' => $userId, 'app_id' => $appId, 'mail_account_id' => $accId, 'name' => $name, 'mode' => $mode, 'prefix' => substr($raw, 0, 18),
            'key_hash' => DevTokens::hash($raw), 'scopes' => json_encode($scopes), 'ip_allowlist' => json_encode($this->cleanIpList($in['ip_allowlist'] ?? [])),
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->keyPayload(DB::table('dev_api_keys')->find($id), $raw);
    }

    private function ownKey(int $userId, int $id): object
    {
        $k = DB::table('dev_api_keys')->where('id', $id)->where('user_id', $userId)->first();
        if (!$k) {
            $this->fail('API key not found.', 404);
        }
        return $k;
    }

    public function updateKey(int $userId, int $id, array $in): array
    {
        $k = $this->ownKey($userId, $id);
        $upd = ['updated_at' => now()];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '' || mb_strlen($name) > 80) {
                $this->fail('Give the key a name (up to 80 characters).');
            }
            $upd['name'] = $name;
        }
        if (array_key_exists('ip_allowlist', $in)) {
            $upd['ip_allowlist'] = json_encode($this->cleanIpList($in['ip_allowlist']));
        }
        DB::table('dev_api_keys')->where('id', $k->id)->update($upd);
        return $this->keyPayload(DB::table('dev_api_keys')->find($k->id));
    }

    public function revokeKey(int $userId, int $id): void
    {
        $k = $this->ownKey($userId, $id);
        DB::table('dev_api_keys')->where('id', $k->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ grants (التطبيقات اللي وافقت عليها) */

    public function listGrants(int $userId): array
    {
        return DB::table('dev_oauth_grants as g')->join('dev_apps as a', 'a.id', '=', 'g.app_id')->where('g.user_id', $userId)->whereNull('g.revoked_at')
            ->orderByDesc('g.id')->get(['g.id as gid', 'g.scopes as gscopes', 'g.mail_account_id', 'g.created_at as gcreated', 'g.last_used_at as glast', 'a.id as aid', 'a.name', 'a.description', 'a.homepage_url', 'a.client_id', 'a.status'])
            ->map(function ($r) {
                $scopes = (array) json_decode((string) $r->gscopes, true);
                return ['id' => (int) $r->gid, 'app' => ['id' => (int) $r->aid, 'name' => $r->name, 'description' => $r->description, 'homepage_url' => $r->homepage_url, 'client_id' => $r->client_id, 'status' => $r->status],
                    'scopes' => $scopes, 'scopes_described' => DevScopes::describe($scopes), 'mail_account_id' => $r->mail_account_id ? (int) $r->mail_account_id : null,
                    'created_at' => DevTokens::iso($r->gcreated), 'last_used_at' => DevTokens::iso($r->glast)];
            })->values()->all();
    }

    public function revokeGrant(int $userId, int $grantId): void
    {
        $g = DB::table('dev_oauth_grants')->where('id', $grantId)->where('user_id', $userId)->whereNull('revoked_at')->first();
        if (!$g) {
            $this->fail('Authorization not found.', 404);
        }
        DB::transaction(function () use ($g) {
            DB::table('dev_oauth_grants')->where('id', $g->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('dev_oauth_tokens')->where('grant_id', $g->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });
        $app = DB::table('dev_apps')->where('id', $g->app_id)->first(['id', 'owner_user_id', 'name']);
        if ($app) {
            app(DevEventBus::class)->emit('app.revoked', (int) $app->owner_user_id, ['app_id' => (int) $app->id, 'grant_id' => (int) $g->id], null, 'live', (int) $app->id);
        }
    }

    /* ------------------------------------------------------------------ admin (الجامعة / أدمن المنصة) */

    /** @param array{university_id?:int,status?:string,q?:string} $f */
    public function adminApps(array $f, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_apps as a')->leftJoin('users as u', 'u.id', '=', 'a.owner_user_id');
        if (!empty($f['university_id'])) {
            $q->where('a.university_id', (int) $f['university_id']);
        }
        if (!empty($f['status'])) {
            $q->where('a.status', (string) $f['status']);
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
            $q->where(fn ($w) => $w->where('a.name', 'like', $like)->orWhere('a.client_id', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('a.id')->forPage($page, $per)->get(['a.*', 'u.full_name as owner_name', 'u.email as owner_email'])->map(function ($a) {
            return $this->appPayload($a) + ['owner_name' => $a->owner_name ?? null, 'owner_email' => $a->owner_email ?? null,
                'keys' => (int) DB::table('dev_api_keys')->where('user_id', $a->owner_user_id)->whereNull('revoked_at')->count()];
        })->values()->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    /** $universityId = null → أدمن المنصة (أي جامعة). */
    public function adminSetStatus(int $appId, ?int $universityId, bool $suspend, string $by, ?string $reason = null): array
    {
        $a = DB::table('dev_apps')->where('id', $appId)->when($universityId, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        if ($suspend) {
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'suspended', 'suspended_by' => $by, 'suspended_reason' => Str::limit(trim((string) $reason) ?: 'Suspended by an administrator', 200, ''), 'updated_at' => now()]);
        } else {
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'active', 'suspended_by' => null, 'suspended_reason' => null, 'updated_at' => now()]);
        }
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    private function adminApp(int $appId, ?int $universityId): object
    {
        $a = DB::table('dev_apps')->where('id', $appId)->when($universityId, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        return $a;
    }

    /** تفاصيل تطبيق للإدارة: بياناته + مفاتيحه + Webhooks + عدد المستخدمين. */
    public function adminAppDetail(int $appId, ?int $universityId): array
    {
        $a = $this->adminApp($appId, $universityId);
        $owner = DB::table('users')->where('id', $a->owner_user_id)->first(['full_name', 'email']);
        return ['app' => $this->appPayload($a) + ['owner_name' => $owner->full_name ?? null, 'owner_email' => $owner->email ?? null],
            'keys' => DB::table('dev_api_keys')->where('app_id', $a->id)->orderByDesc('id')->get()->map(fn ($k) => $this->keyPayload($k))->values()->all(),
            'webhooks' => DB::table('dev_webhooks')->where('app_id', $a->id)->orderByDesc('id')->get(['id', 'name', 'url', 'events', 'mode', 'is_active', 'last_status', 'last_delivery_at'])
                ->map(fn ($h) => ['id' => (int) $h->id, 'name' => $h->name, 'url' => $h->url, 'events' => (array) json_decode((string) $h->events, true), 'mode' => $h->mode,
                    'is_active' => (bool) $h->is_active, 'last_status' => $h->last_status, 'last_delivery_at' => DevTokens::iso($h->last_delivery_at)])->values()->all(),
            'authorized_users' => (int) DB::table('dev_oauth_grants')->where('app_id', $a->id)->whereNull('revoked_at')->count()];
    }

    /** الإدارة تقيّد تطبيق: scopes (بتتقص من المفاتيح كمان) وحد الطلبات. */
    public function adminUpdateApp(int $appId, ?int $universityId, array $in): array
    {
        $a = $this->adminApp($appId, $universityId);
        $upd = ['updated_at' => now()];
        if (array_key_exists('scopes', $in)) {
            $scopes = DevScopes::parse($in['scopes']);
            if (!$scopes) {
                $this->fail('Choose at least one scope.');
            }
            if ($bad = DevScopes::unknown($scopes)) {
                $this->fail('Unknown scope: ' . implode(', ', $bad));
            }
            $upd['scopes'] = json_encode($scopes);
        }
        if (array_key_exists('rate_limit_per_min', $in)) {
            $upd['rate_limit_per_min'] = ($in['rate_limit_per_min'] === null || $in['rate_limit_per_min'] === '') ? null : max(1, min(100000, (int) $in['rate_limit_per_min']));
        }
        DB::table('dev_apps')->where('id', $a->id)->update($upd);
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    public function adminDeleteApp(int $appId, ?int $universityId): void
    {
        $a = $this->adminApp($appId, $universityId);
        $this->deleteApp((int) $a->owner_user_id, (int) $a->id);
    }

    /** @param array{university_id?:int,status?:string,mode?:string,q?:string,user_id?:int,app_id?:int} $f */
    public function adminKeys(array $f, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_api_keys as k')->leftJoin('users as u', 'u.id', '=', 'k.user_id');
        if (!empty($f['university_id'])) {
            $q->whereIn('k.user_id', $this->ownerIdsOfUniversity((int) $f['university_id']) ?: [0]);
        }
        foreach (['user_id' => 'k.user_id', 'app_id' => 'k.app_id'] as $in => $col) {
            if (!empty($f[$in])) {
                $q->where($col, (int) $f[$in]);
            }
        }
        if (!empty($f['mode']) && in_array($f['mode'], ['live', 'sandbox'], true)) {
            $q->where('k.mode', $f['mode']);
        }
        $st = (string) ($f['status'] ?? '');
        if ($st === 'active') {
            $q->whereNull('k.revoked_at')->where(fn ($w) => $w->whereNull('k.expires_at')->orWhere('k.expires_at', '>', now()));
        } elseif ($st === 'revoked') {
            $q->whereNotNull('k.revoked_at');
        } elseif ($st === 'expired') {
            $q->whereNull('k.revoked_at')->whereNotNull('k.expires_at')->where('k.expires_at', '<=', now());
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $q->where(fn ($w) => $w->where('k.name', 'like', $like)->orWhere('k.prefix', 'like', $like)->orWhere('u.email', 'like', $like)->orWhere('u.full_name', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('k.id')->forPage($page, $per)->get(['k.*', 'u.full_name as owner_name', 'u.email as owner_email'])
            ->map(fn ($k) => $this->keyPayload($k) + ['user_id' => (int) $k->user_id, 'owner_name' => $k->owner_name, 'owner_email' => $k->owner_email])->values()->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    public function adminRevokeKey(int $keyId, ?int $universityId): array
    {
        $k = DB::table('dev_api_keys')->where('id', $keyId)->when($universityId, fn ($q) => $q->whereIn('user_id', $this->ownerIdsOfUniversity($universityId) ?: [0]))->first();
        if (!$k) {
            $this->fail('API key not found.', 404);
        }
        DB::table('dev_api_keys')->where('id', $k->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        return $this->keyPayload(DB::table('dev_api_keys')->find($k->id)) + ['user_id' => (int) $k->user_id];
    }

    /** IDs أصحاب التطبيقات/المفاتيح في الجامعة (لتصفية السجل في لوحتها). */
    public function ownerIdsOfUniversity(int $universityId): array
    {
        return DB::table('mail_accounts')->where('university_id', $universityId)->distinct()->pluck('user_id')->map(fn ($x) => (int) $x)->all();
    }
}
