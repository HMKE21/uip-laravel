<?php

namespace App\Services\Developer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OAuth 2.0 للتطبيقات: Authorization Code + PKCE (S256)، Refresh Token بتدوير (إعادة استخدام refresh قديم = سرقة → إلغاء كل توكنز الـ grant)،
 * و Client Credentials للتطبيقات السرّية (بتتصرّف باسم صاحب التطبيق). الأسرار كلها hash بس في الـ DB.
 * بيرمي OAuthException بأكواد RFC 6749.
 */
class OAuthService
{
    public function __construct(private DeveloperAppService $apps, private DevLogService $logs)
    {
    }

    /* ------------------------------------------------------------------ authorize (بعد ما المستخدم يسجّل دخول في المنصة) */

    /** @return array{app:object,redirect_uri:string,scopes:string[],state:?string,challenge:?string} */
    private function validateAuthorize(int $userId, array $q): array
    {
        if (!config('developer.enabled')) {
            throw OAuthException::make('temporarily_unavailable', 'The Developer API is disabled.', 503);
        }
        $app = DB::table('dev_apps')->where('client_id', (string) ($q['client_id'] ?? ''))->first();
        if (!$app) {
            throw OAuthException::make('invalid_client', 'Unknown application.', 404);
        }
        $registered = (array) json_decode((string) $app->redirect_uris, true);
        $redirect = trim((string) ($q['redirect_uri'] ?? ''));
        if ($redirect === '' && count($registered) === 1) {
            $redirect = $registered[0];
        }
        if ($redirect === '' || !in_array($redirect, $registered, true)) { // مفيش redirect هنا: التطابق لازم يكون حرفي
            throw OAuthException::make('invalid_request', 'The redirect URI does not match the app registration.', 400);
        }
        $state = isset($q['state']) ? substr((string) $q['state'], 0, 500) : null;
        $err = fn (string $code, string $msg, int $status = 400) => OAuthException::make($code, $msg, $status, $this->redirectWith($redirect, ['error' => $code, 'error_description' => $msg, 'state' => $state]));

        if ($app->status !== 'active') {
            throw $err('access_denied', 'This app is currently suspended.', 403);
        }
        $policy = $this->apps->policyFor($userId);
        if (!$policy['apps_enabled']) {
            throw $err('access_denied', 'Third-party apps are turned off for your account: ' . ($policy['access']['message'] ?? 'not allowed') , 403);
        }
        if ($policy['sandbox_only']) {
            throw $err('access_denied', 'Live OAuth access is not allowed for your account (sandbox only).', 403);
        }
        if (($q['response_type'] ?? '') !== 'code') {
            throw $err('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $appScopes = (array) json_decode((string) $app->scopes, true);
        $scopes = DevScopes::parse($q['scope'] ?? '') ?: $appScopes;
        if ($bad = DevScopes::unknown($scopes)) {
            throw $err('invalid_scope', 'Unknown scope: ' . implode(' ', $bad));
        }
        if ($extra = array_diff($scopes, $appScopes)) {
            throw $err('invalid_scope', 'The app is not registered for: ' . implode(' ', $extra));
        }
        $allowed = $policy['allowed_scopes'] ?? DevScopes::names();
        if ($denied = array_diff($scopes, $allowed)) {
            throw $err('invalid_scope', 'Your university does not allow: ' . implode(' ', $denied), 403);
        }
        $challenge = isset($q['code_challenge']) && $q['code_challenge'] !== '' ? (string) $q['code_challenge'] : null;
        if ($challenge !== null) {
            if (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_\-]{43,128}$/', $challenge)) {
                throw $err('invalid_request', 'PKCE requires code_challenge_method=S256 and a valid code_challenge.');
            }
        } elseif ($app->client_type === 'public') {
            throw $err('invalid_request', 'Public apps must use PKCE (code_challenge).');
        }
        return ['app' => $app, 'redirect_uri' => $redirect, 'scopes' => array_values($scopes), 'state' => $state, 'challenge' => $challenge];
    }

    private function redirectWith(string $uri, array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** شاشة الموافقة: بيانات التطبيق + الصلاحيات المطلوبة + صناديق المستخدم. */
    public function authorizeInfo(int $userId, array $q): array
    {
        $v = $this->validateAuthorize($userId, $q);
        $app = $v['app'];
        $grant = DB::table('dev_oauth_grants')->where('app_id', $app->id)->where('user_id', $userId)->whereNull('revoked_at')->first();
        $had = $grant ? (array) json_decode((string) $grant->scopes, true) : [];
        $owner = DB::table('users')->where('id', $app->owner_user_id)->value('full_name');
        return [
            'app' => ['name' => $app->name, 'description' => $app->description, 'homepage_url' => $app->homepage_url, 'client_id' => $app->client_id, 'client_type' => $app->client_type, 'owner_name' => $owner],
            'redirect_host' => parse_url($v['redirect_uri'], PHP_URL_HOST), 'redirect_uri' => $v['redirect_uri'], 'state' => $v['state'],
            'scopes' => $v['scopes'], 'scopes_described' => DevScopes::describe($v['scopes']),
            'already_granted' => $grant && !array_diff($v['scopes'], $had),
            'mail_accounts' => DB::table('mail_accounts')->where('user_id', $userId)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->get(['id', 'address', 'kind'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'address' => $a->address, 'kind' => $a->kind])->values()->all(),
        ];
    }

    /** @return array{redirect_to:string} المتصفح يتوجّه ليه (code أو error=access_denied). */
    public function decide(int $userId, array $q, bool $allow, ?int $mailAccountId = null): array
    {
        $v = $this->validateAuthorize($userId, $q);
        $app = $v['app'];
        if (!$allow) {
            $this->logs->record(['user_id' => $userId, 'app_id' => $app->id, 'kind' => 'oauth', 'path' => '/oauth/authorize', 'status' => 200, 'note' => 'denied by user']);
            return ['redirect_to' => $this->redirectWith($v['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user denied the request.', 'state' => $v['state']])];
        }
        if ($mailAccountId) {
            if (!DB::table('mail_accounts')->where('id', $mailAccountId)->where('user_id', $userId)->where('status', 'active')->exists()) {
                throw OAuthException::make('invalid_request', 'Mailbox not found.', 404);
            }
        } else {
            $mailAccountId = (int) DB::table('mail_accounts')->where('user_id', $userId)->orderByDesc('is_default')->orderBy('id')->value('id') ?: null;
        }
        $code = DevTokens::make('uipc_', 40);
        $isNew = false;
        DB::transaction(function () use ($app, $userId, $v, $mailAccountId, $code, &$isNew) {
            $g = DB::table('dev_oauth_grants')->where('app_id', $app->id)->where('user_id', $userId)->lockForUpdate()->first();
            if ($g) {
                $isNew = $g->revoked_at !== null;
                DB::table('dev_oauth_grants')->where('id', $g->id)->update(['scopes' => json_encode($v['scopes']), 'mail_account_id' => $mailAccountId, 'revoked_at' => null, 'updated_at' => now()]);
                $gid = (int) $g->id;
            } else {
                $isNew = true;
                $gid = (int) DB::table('dev_oauth_grants')->insertGetId(['app_id' => $app->id, 'user_id' => $userId, 'mail_account_id' => $mailAccountId, 'scopes' => json_encode($v['scopes']), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('dev_oauth_codes')->insert(['grant_id' => $gid, 'app_id' => $app->id, 'user_id' => $userId, 'code_hash' => DevTokens::hash($code), 'redirect_uri' => $v['redirect_uri'],
                'scopes' => json_encode($v['scopes']), 'code_challenge' => $v['challenge'], 'expires_at' => now()->addSeconds((int) config('developer.oauth.code_ttl', 600)), 'created_at' => now()]);
        });
        $this->logs->record(['user_id' => $userId, 'app_id' => $app->id, 'kind' => 'oauth', 'path' => '/oauth/authorize', 'status' => 200, 'note' => 'granted: ' . implode(' ', $v['scopes'])]);
        if ($isNew) {
            app(DevEventBus::class)->emit('app.authorized', (int) $app->owner_user_id, ['app_id' => (int) $app->id, 'scopes' => $v['scopes']], null, 'live', (int) $app->id);
        }
        return ['redirect_to' => $this->redirectWith($v['redirect_uri'], ['code' => $code, 'state' => $v['state']])];
    }

    /* ------------------------------------------------------------------ token endpoint */

    /** @return object تطبيق بعد التحقق من الـ client (Basic أو body). */
    private function authenticateClient(Request $r): object
    {
        $id = (string) $r->input('client_id', '');
        $secret = $r->input('client_secret');
        $basic = (string) $r->header('Authorization', '');
        if (stripos($basic, 'Basic ') === 0) {
            $dec = base64_decode(substr($basic, 6), true) ?: '';
            if (str_contains($dec, ':')) {
                [$bid, $bsec] = explode(':', $dec, 2);
                $id = rawurldecode($bid);
                $secret = rawurldecode($bsec);
            }
        }
        $app = $id !== '' ? DB::table('dev_apps')->where('client_id', $id)->first() : null;
        if (!$app) {
            throw OAuthException::make('invalid_client', 'Client authentication failed.', 401);
        }
        if ($app->client_type === 'confidential') {
            if (!is_string($secret) || $secret === '' || !$app->client_secret_hash || !hash_equals((string) $app->client_secret_hash, DevTokens::hash($secret))) {
                throw OAuthException::make('invalid_client', 'Client authentication failed.', 401);
            }
        }
        if ($app->status !== 'active') {
            throw OAuthException::make('invalid_client', 'This app is suspended.', 401);
        }
        return $app;
    }

    public function token(Request $r): array
    {
        if (!config('developer.enabled')) {
            throw OAuthException::make('temporarily_unavailable', 'The Developer API is disabled.', 503);
        }
        $app = $this->authenticateClient($r);
        $type = (string) $r->input('grant_type', '');
        $res = match ($type) {
            'authorization_code' => $this->grantCode($r, $app),
            'refresh_token' => $this->grantRefresh($r, $app),
            'client_credentials' => $this->grantClient($r, $app),
            default => throw OAuthException::make('unsupported_grant_type', 'Unsupported grant_type.'),
        };
        DB::table('dev_apps')->where('id', $app->id)->update(['last_used_at' => now()]);
        $this->logs->record(['user_id' => $res['_uid'] ?? null, 'app_id' => $app->id, 'kind' => 'oauth', 'method' => 'POST', 'path' => '/oauth/token', 'status' => 200, 'note' => $type, 'ip' => $r->ip()]);
        unset($res['_uid']);
        return $res;
    }

    private function userOk(int $userId, object $app): void
    {
        if (!$this->apps->userActive($userId)) {
            throw OAuthException::make('invalid_grant', 'The user account is no longer active.');
        }
        $p = $this->apps->policyFor($userId);
        if (!$p['apps_enabled']) {
            throw OAuthException::make('invalid_grant', 'Third-party apps are turned off for this account.');
        }
        if ($p['sandbox_only']) {
            throw OAuthException::make('invalid_grant', 'Live OAuth access is not allowed for this account (sandbox only).');
        }
    }

    private function issue(object $app, int $userId, ?int $grantId, ?int $mailAccountId, array $scopes, bool $withRefresh): array
    {
        $access = DevTokens::make('uipo_', 48);
        $refresh = $withRefresh ? DevTokens::make('uipr_', 48) : null;
        $ttl = (int) config('developer.oauth.access_ttl', 3600);
        DB::table('dev_oauth_tokens')->insert([
            'grant_id' => $grantId, 'app_id' => $app->id, 'user_id' => $userId, 'mail_account_id' => $mailAccountId, 'access_hash' => DevTokens::hash($access),
            'refresh_hash' => $refresh ? DevTokens::hash($refresh) : null, 'scopes' => json_encode(array_values($scopes)), 'access_expires_at' => now()->addSeconds($ttl),
            'refresh_expires_at' => $refresh ? now()->addDays((int) config('developer.oauth.refresh_ttl_days', 30)) : null, 'created_at' => now(),
        ]);
        $out = ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'scope' => implode(' ', $scopes), '_uid' => $userId];
        if ($refresh) {
            $out['refresh_token'] = $refresh;
        }
        return $out;
    }

    private function revokeGrantTokens(int $grantId): void
    {
        DB::table('dev_oauth_tokens')->where('grant_id', $grantId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function grantCode(Request $r, object $app): array
    {
        $raw = (string) $r->input('code', '');
        $code = $raw !== '' ? DB::table('dev_oauth_codes')->where('code_hash', DevTokens::hash($raw))->first() : null;
        if (!$code || (int) $code->app_id !== (int) $app->id) {
            throw OAuthException::make('invalid_grant', 'The authorization code is invalid.');
        }
        if ($code->used_at !== null) { // إعادة استخدام code = اشتباه سرقة: نلغي كل اللي اتصدر من الـ grant
            $this->revokeGrantTokens((int) $code->grant_id);
            throw OAuthException::make('invalid_grant', 'The authorization code was already used.');
        }
        if (now()->gte(\Illuminate\Support\Carbon::parse($code->expires_at, 'UTC'))) {
            throw OAuthException::make('invalid_grant', 'The authorization code expired.');
        }
        if ((string) $r->input('redirect_uri', '') !== (string) $code->redirect_uri) {
            throw OAuthException::make('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        if ($code->code_challenge) {
            $ver = (string) $r->input('code_verifier', '');
            if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $ver) || !hash_equals((string) $code->code_challenge, DevTokens::pkceS256($ver))) {
                throw OAuthException::make('invalid_grant', 'PKCE verification failed.');
            }
        } elseif ($app->client_type === 'public') {
            throw OAuthException::make('invalid_grant', 'PKCE verification failed.');
        }
        $grant = DB::table('dev_oauth_grants')->where('id', $code->grant_id)->first();
        if (!$grant || $grant->revoked_at !== null) {
            throw OAuthException::make('invalid_grant', 'The authorization was revoked.');
        }
        $this->userOk((int) $code->user_id, $app);
        // نستهلك الـ code بشكل ذرّي: لو طلبين متوازيين، واحد بس ينجح.
        $won = DB::table('dev_oauth_codes')->where('id', $code->id)->whereNull('used_at')->update(['used_at' => now()]);
        if (!$won) {
            $this->revokeGrantTokens((int) $code->grant_id);
            throw OAuthException::make('invalid_grant', 'The authorization code was already used.');
        }
        return $this->issue($app, (int) $code->user_id, (int) $grant->id, $grant->mail_account_id ? (int) $grant->mail_account_id : null, (array) json_decode((string) $code->scopes, true), true);
    }

    private function grantRefresh(Request $r, object $app): array
    {
        $raw = (string) $r->input('refresh_token', '');
        $tok = $raw !== '' ? DB::table('dev_oauth_tokens')->where('refresh_hash', DevTokens::hash($raw))->first() : null;
        if (!$tok || (int) $tok->app_id !== (int) $app->id || $tok->grant_id === null) {
            throw OAuthException::make('invalid_grant', 'The refresh token is invalid.');
        }
        if ($tok->rotated_at !== null) { // refresh اتستخدم قبل كده → سرقة محتملة
            $this->revokeGrantTokens((int) $tok->grant_id);
            throw OAuthException::make('invalid_grant', 'The refresh token was already used.');
        }
        if ($tok->revoked_at !== null || !$tok->refresh_expires_at || now()->gte(\Illuminate\Support\Carbon::parse($tok->refresh_expires_at, 'UTC'))) {
            throw OAuthException::make('invalid_grant', 'The refresh token expired or was revoked.');
        }
        $grant = DB::table('dev_oauth_grants')->where('id', $tok->grant_id)->first();
        if (!$grant || $grant->revoked_at !== null) {
            throw OAuthException::make('invalid_grant', 'The authorization was revoked.');
        }
        $this->userOk((int) $tok->user_id, $app);
        $scopes = (array) json_decode((string) $tok->scopes, true);
        if ($r->filled('scope')) { // ممكن تضيّق بس
            $want = DevScopes::parse($r->input('scope'));
            if ($extra = array_diff($want, $scopes)) {
                throw OAuthException::make('invalid_scope', 'You cannot widen the scopes: ' . implode(' ', $extra));
            }
            $scopes = $want;
        }
        $won = DB::table('dev_oauth_tokens')->where('id', $tok->id)->whereNull('rotated_at')->update(['rotated_at' => now(), 'revoked_at' => now()]);
        if (!$won) {
            $this->revokeGrantTokens((int) $tok->grant_id);
            throw OAuthException::make('invalid_grant', 'The refresh token was already used.');
        }
        return $this->issue($app, (int) $tok->user_id, (int) $tok->grant_id, $tok->mail_account_id ? (int) $tok->mail_account_id : null, $scopes, true);
    }

    private function grantClient(Request $r, object $app): array
    {
        if ($app->client_type !== 'confidential') {
            throw OAuthException::make('unauthorized_client', 'Public apps cannot use client_credentials.');
        }
        $owner = (int) $app->owner_user_id;
        $this->userOk($owner, $app);
        $appScopes = (array) json_decode((string) $app->scopes, true);
        $allowed = $this->apps->policyFor($owner)['allowed_scopes'] ?? DevScopes::names();
        $scopes = DevScopes::parse($r->input('scope', '')) ?: $appScopes;
        if ($bad = array_diff($scopes, $appScopes, [])) {
            throw OAuthException::make('invalid_scope', 'The app is not registered for: ' . implode(' ', $bad));
        }
        $scopes = array_values(array_intersect($scopes, $allowed));
        if (!$scopes) {
            throw OAuthException::make('invalid_scope', 'No allowed scopes.');
        }
        $acc = (int) DB::table('mail_accounts')->where('user_id', $owner)->orderByDesc('is_default')->orderBy('id')->value('id') ?: null;
        return $this->issue($app, $owner, null, $acc, $scopes, false);
    }

    /** RFC 7009: دايمًا OK (حتى لو التوكن مش موجود). */
    public function revoke(Request $r): void
    {
        $app = $this->authenticateClient($r);
        $raw = (string) $r->input('token', '');
        if ($raw === '') {
            return;
        }
        $h = DevTokens::hash($raw);
        DB::table('dev_oauth_tokens')->where('app_id', $app->id)->where(fn ($q) => $q->where('access_hash', $h)->orWhere('refresh_hash', $h))->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->logs->record(['app_id' => $app->id, 'kind' => 'oauth', 'method' => 'POST', 'path' => '/oauth/revoke', 'status' => 200, 'ip' => $r->ip()]);
    }
}
