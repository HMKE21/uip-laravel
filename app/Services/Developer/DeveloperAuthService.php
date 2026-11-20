<?php

namespace App\Services\Developer;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** يتحقق من Bearer (API key uipk_… أو OAuth access token uipo_…) ويرجّع سياق الطلب. بيرمي DevAuthException (msg, http) مع errorCode. */
class DeveloperAuthService
{
    public function __construct(private DeveloperAppService $apps, private DevLogService $logs)
    {
    }

    private function deny(string $msg, int $http, string $code, array $extra = []): never
    {
        $e = new DevAuthException($msg, $http);
        $e->errorCode = $code;
        $e->headers = $extra;
        throw $e;
    }

    /** @return array{kind:string,user_id:int,scopes:string[],app_id:?int,key_id:?int,token_id:?int,mode:string,mail_account_id:?int,limit:int,remaining:int,rate_key:string} */
    public function authenticate(Request $r): array
    {
        if (!config('developer.enabled')) {
            $this->deny('The Developer API is disabled.', 503, 'disabled');
        }
        $h = (string) $r->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            $this->deny('Missing Bearer credentials.', 401, 'missing_credentials', ['WWW-Authenticate' => 'Bearer realm="uipe"']);
        }
        $raw = $m[1];
        if (str_starts_with($raw, 'uipk_')) {
            $ctx = $this->fromKey($raw, (string) $r->ip());
        } elseif (str_starts_with($raw, 'uipo_')) {
            $ctx = $this->fromToken($raw);
        } else {
            $this->deny('Invalid credentials.', 401, 'invalid_token', ['WWW-Authenticate' => 'Bearer realm="uipe", error="invalid_token"']);
        }

        // المستخدم لازم يكون نشط (كاش 10 ثواني زي بقية المنصة).
        $acc = Cache::remember('dev:acct:' . $ctx['user_id'], 10, fn () => (array) (DB::table('users')->where('id', $ctx['user_id'])->first(['status', 'deleted_at']) ?: ['status' => 'gone', 'deleted_at' => 'x']));
        if ($acc['deleted_at'] !== null || in_array($acc['status'], ['suspended', 'banned', 'gone'], true)) {
            $this->deny('The account is no longer active.', 401, 'account_inactive');
        }

        $policy = $this->apps->policyFor($ctx['user_id']);
        if (!$policy['apps_enabled']) {
            $a = $policy['access'];
            $this->deny($a['message'] ?? 'Developer access is turned off.', 403, $a['reason'] ?? 'tenant_disabled');
        }
        if ($policy['sandbox_only'] && $ctx['mode'] !== 'sandbox') {
            $this->deny('This account can only use sandbox (test) credentials.', 403, 'sandbox_only');
        }
        $ctx['scopes'] = array_values(array_intersect($ctx['scopes'], $policy['allowed_scopes'] ?? DevScopes::names()));
        $ctx['webhooks_enabled'] = $policy['webhooks_enabled'];
        if (!empty($policy['rate_limit_per_min'])) {
            $ctx['limit'] = max(1, min($ctx['limit'], (int) $policy['rate_limit_per_min']));
        }

        // الصندوق: لازم لسه بتاع المستخدم ونشط، وإلا الافتراضي.
        // اعتماد sandbox مبيشيلش أي صندوق حقيقي أبدًا (حتى لو المفتاح اتربط بصندوق قبل كده).
        if ($ctx['mode'] === 'sandbox') {
            $ctx['mail_account_id'] = null;
        } else {
            $accId = $ctx['mail_account_id'];
            $ok = $accId && DB::table('mail_accounts')->where('id', $accId)->where('user_id', $ctx['user_id'])->exists();
            $ctx['mail_account_id'] = $ok ? $accId : ((int) DB::table('mail_accounts')->where('user_id', $ctx['user_id'])->orderByDesc('is_default')->orderBy('id')->value('id') ?: null);
        }

        // Rate limit لكل مفتاح/توكن.
        $rk = 'dev:rl:' . $ctx['rate_key'];
        if (RateLimiter::tooManyAttempts($rk, $ctx['limit'])) {
            $this->noteThrottled($r, $ctx);
            $this->deny('Rate limit exceeded.', 429, 'rate_limited', ['Retry-After' => (string) max(1, RateLimiter::availableIn($rk)), 'X-RateLimit-Limit' => (string) $ctx['limit'], 'X-RateLimit-Remaining' => '0']);
        }
        RateLimiter::hit($rk, 60);
        $ctx['remaining'] = max(0, $ctx['limit'] - RateLimiter::attempts($rk));
        return $ctx;
    }

    /**
     * الـ 429 كانت بتتحجب من غير ما تتسجّل، فشاشة الاستهلاك ماكانتش هتشوفها. بنسجّل صف واحد بس في الدقيقة لكل مفتاح/توكن:
     * حد يغرّق الـ API بعد ما يتحجب ميقدرش يملا الـ DB (الـ Cache::add ذرّي، ومفيش كتابة تانية لحد ما الدقيقة تخلص).
     */
    private function noteThrottled(Request $r, array $ctx): void
    {
        if (Cache::add('dev:rl429:' . $ctx['rate_key'], 1, 60)) {
            $this->logs->fromRequest($r, 429, $ctx, 0, 'rate_limited');
        }
    }

    private function fromKey(string $raw, string $ip): array
    {
        $k = DB::table('dev_api_keys')->where('key_hash', DevTokens::hash($raw))->first();
        if (!$k || $k->revoked_at !== null) {
            $this->deny('Invalid or revoked API key.', 401, 'invalid_key', ['WWW-Authenticate' => 'Bearer realm="uipe", error="invalid_token"']);
        }
        if ($k->expires_at && now()->gte(Carbon::parse($k->expires_at, 'UTC'))) {
            $this->deny('This API key has expired.', 401, 'key_expired');
        }
        $allow = (array) json_decode((string) ($k->ip_allowlist ?? '[]'), true);
        if ($allow && !$this->ipAllowed($ip, $allow)) {
            $this->deny('This IP address is not allowed for the API key.', 403, 'ip_not_allowed');
        }
        $scopes = (array) json_decode((string) $k->scopes, true);
        $limit = (int) config('developer.limits.rate_per_min', 300);
        if ($k->app_id) {
            $app = DB::table('dev_apps')->where('id', $k->app_id)->first();
            if (!$app || $app->status !== 'active') {
                $this->deny('The app of this key is suspended.', 403, 'app_suspended');
            }
            $scopes = array_values(array_intersect($scopes, (array) json_decode((string) $app->scopes, true)));
            $limit = $app->rate_limit_per_min ? (int) $app->rate_limit_per_min : $limit;
        }
        if (Cache::add('dev:lu:key:' . $k->id, 1, 60)) {
            DB::table('dev_api_keys')->where('id', $k->id)->update(['last_used_at' => now(), 'last_used_ip' => substr($ip, 0, 45)]);
        }
        return ['kind' => 'key', 'user_id' => (int) $k->user_id, 'scopes' => $scopes, 'app_id' => $k->app_id ? (int) $k->app_id : null, 'key_id' => (int) $k->id, 'token_id' => null,
            'mode' => $k->mode === 'sandbox' ? 'sandbox' : 'live', 'mail_account_id' => $k->mail_account_id ? (int) $k->mail_account_id : null, 'limit' => max(1, $limit), 'remaining' => 0, 'rate_key' => 'key:' . $k->id];
    }

    private function fromToken(string $raw): array
    {
        $t = DB::table('dev_oauth_tokens')->where('access_hash', DevTokens::hash($raw))->first();
        $bad = ['WWW-Authenticate' => 'Bearer realm="uipe", error="invalid_token"'];
        if (!$t || $t->revoked_at !== null) {
            $this->deny('Invalid or revoked access token.', 401, 'invalid_token', $bad);
        }
        if (now()->gte(Carbon::parse($t->access_expires_at, 'UTC'))) {
            $this->deny('The access token expired.', 401, 'token_expired', ['WWW-Authenticate' => 'Bearer realm="uipe", error="invalid_token", error_description="expired"']);
        }
        $app = DB::table('dev_apps')->where('id', $t->app_id)->first();
        if (!$app || $app->status !== 'active') {
            $this->deny('The app is suspended.', 403, 'app_suspended');
        }
        if ((int) $t->user_id !== (int) $app->owner_user_id && !$this->apps->reviewAllows($app)) { // App Review: التوكنز بتقف لو الموافقة اتسحبت/بطلت
            $this->deny('This app is not approved by the university.', 403, 'app_not_approved');
        }
        $scopes = (array) json_decode((string) $t->scopes, true);
        if ($t->grant_id) {
            $g = DB::table('dev_oauth_grants')->where('id', $t->grant_id)->first();
            if (!$g || $g->revoked_at !== null) {
                $this->deny('The authorization was revoked.', 401, 'invalid_token', $bad);
            }
            $scopes = array_values(array_intersect($scopes, (array) json_decode((string) $g->scopes, true)));
            if (Cache::add('dev:lu:grant:' . $g->id, 1, 60)) {
                DB::table('dev_oauth_grants')->where('id', $g->id)->update(['last_used_at' => now()]);
            }
        }
        // "خروج من كل الأجهزة" (المرحلة 5): توكنز OAuth اللي اتصدرت قبلها بتبطل.
        $cut = (DB::table('users')->where('id', $t->user_id)->first())->tokens_valid_after ?? null; // العمود جاي من المرحلة 5
        if ($cut && Carbon::parse($t->created_at, 'UTC')->lt(Carbon::parse($cut, 'UTC'))) {
            $this->deny('The access token was revoked.', 401, 'invalid_token', $bad);
        }
        $scopes = array_values(array_intersect($scopes, (array) json_decode((string) $app->scopes, true)));
        if (Cache::add('dev:lu:tok:' . $t->id, 1, 60)) {
            DB::table('dev_oauth_tokens')->where('id', $t->id)->update(['last_used_at' => now()]);
        }
        $limit = $app->rate_limit_per_min ? (int) $app->rate_limit_per_min : (int) config('developer.limits.rate_per_min', 300);
        return ['kind' => 'oauth', 'user_id' => (int) $t->user_id, 'scopes' => $scopes, 'app_id' => (int) $app->id, 'key_id' => null, 'token_id' => (int) $t->id, 'mode' => 'live',
            'mail_account_id' => $t->mail_account_id ? (int) $t->mail_account_id : null, 'limit' => max(1, $limit), 'remaining' => 0, 'rate_key' => 'tok:' . $t->id];
    }

    /** يطابق IP أو CIDR (IPv4/IPv6). */
    public function ipAllowed(string $ip, array $allow): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($allow as $entry) {
            [$net, $bits] = array_pad(explode('/', (string) $entry, 2), 2, null);
            $nb = @inet_pton($net);
            if ($nb === false || strlen($nb) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($nb) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($nb[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    public function requireScope(array $ctx, string $scope): void
    {
        if (!DevScopes::has($ctx['scopes'], $scope)) {
            $this->deny("This credential is missing the '{$scope}' scope.", 403, 'insufficient_scope', ['WWW-Authenticate' => 'Bearer realm="uipe", error="insufficient_scope", scope="' . $scope . '"']);
        }
    }
}
