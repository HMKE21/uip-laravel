<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Drive backup link that survives: the browser can only get 1-hour access tokens from Google Identity Services,
 * so the link is made with the OAuth authorization-code flow instead. Google returns a REFRESH token (access_type=offline),
 * we keep it encrypted, and hand the browser a fresh 1-hour access token whenever it asks (/cloud/google/token).
 * Scope = drive.file (only files this app creates), so no Google security review is needed.
 *
 * Needs: GOOGLE_CLIENT_IDS (Web client), GOOGLE_CLIENT_SECRET, GOOGLE_DRIVE_REDIRECT (= <api>/api/v1/cloud/google/callback,
 * added under "Authorized redirect URIs" of that Web client).
 */
class GoogleDriveOAuthService
{
    private const STATE_TTL_SECONDS = 600;
    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    private function clientId(): string
    {
        $ids = preg_split('/\s*,\s*/', trim((string) config('services.google.client_ids')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return (string) ($ids[0] ?? '');
    }
    private function clientSecret(): string { return trim((string) config('services.google.client_secret')); }
    private function redirectUri(): string { return trim((string) config('services.google.drive_redirect')); }

    public function authorizeUrl(int $userId, ?string $loginHint = null): string
    {
        $state = bin2hex(random_bytes(24));
        Cache::put($this->stateKey($state), $userId, self::STATE_TTL_SECONDS);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array_filter([
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE . ' https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',   // → refresh token
            'prompt'        => 'consent',   // make sure Google returns the refresh token every time
            'state'         => $state,
            'login_hint'    => $loginHint,
        ]));
    }

    /** @throws \RuntimeException expired_state | token_exchange_failed | no_refresh_token | scope_missing */
    public function completeCallback(string $code, string $state): GoogleDriveConnection
    {
        $userId = Cache::pull($this->stateKey($state));
        if (!$userId) {
            throw new \RuntimeException('expired_state');
        }

        $res = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (!$res->ok() || !$res->json('access_token')) {
            Log::warning('Google Drive OAuth exchange failed', ['status' => $res->status(), 'error' => $res->json('error')]);
            throw new \RuntimeException('token_exchange_failed');
        }
        $refresh = (string) $res->json('refresh_token');
        if ($refresh === '') {
            throw new \RuntimeException('no_refresh_token');
        }
        $scope = (string) $res->json('scope');
        if (!str_contains($scope, 'drive.file')) {
            throw new \RuntimeException('scope_missing'); // the Drive tick-box was left unchecked on the consent screen
        }

        $email = '';
        try {
            $me = Http::withToken((string) $res->json('access_token'))->timeout(10)->get('https://www.googleapis.com/oauth2/v3/userinfo');
            $email = (string) ($me->json('email') ?? '');
        } catch (\Throwable) { /* optional */ }

        $conn = GoogleDriveConnection::firstOrNew(['user_id' => (int) $userId]);
        $conn->fill(['user_id' => (int) $userId, 'google_email' => $email ?: null, 'scope' => $scope, 'connected_at' => now()]);
        $conn->setRefreshToken($refresh);
        $conn->save();

        Cache::put($this->tokenKey((int) $userId), $this->cachePayload($res->json('access_token'), (int) $res->json('expires_in', 3600)), 300);
        Log::info('Google Drive connected', ['user_id' => $userId]);
        return $conn;
    }

    public function connectionFor(int $userId): ?GoogleDriveConnection
    {
        return GoogleDriveConnection::where('user_id', $userId)->first();
    }

    /**
     * A valid access token for the browser.
     * @return array{access_token:string,expires_in:int}
     * @throws \RuntimeException('unauthorized') the student revoked access (or the token is unusable) → connection removed, must reconnect
     * @throws \RuntimeException('google_unavailable') temporary problem
     */
    public function accessToken(GoogleDriveConnection $conn, bool $force = false): array
    {
        $key = $this->tokenKey((int) $conn->user_id);
        if (!$force && ($hit = Cache::get($key)) && $hit['expires_at'] > time() + 300) {
            return ['access_token' => $hit['token'], 'expires_in' => $hit['expires_at'] - time()];
        }

        $refresh = $conn->refreshToken();
        if (!$refresh) {
            $conn->delete();
            throw new \RuntimeException('unauthorized');
        }

        try {
            $res = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, [
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ]);
        } catch (\Throwable) {
            throw new \RuntimeException('google_unavailable');
        }

        if ($res->status() === 400 && $res->json('error') === 'invalid_grant') { // revoked / expired (e.g. app in "Testing" for 7 days)
            $conn->delete();
            Cache::forget($key);
            throw new \RuntimeException('unauthorized');
        }
        if (!$res->ok() || !$res->json('access_token')) {
            Log::warning('Google Drive token refresh failed', ['status' => $res->status(), 'error' => $res->json('error')]);
            throw new \RuntimeException('google_unavailable');
        }

        $ttl = (int) $res->json('expires_in', 3600);
        Cache::put($key, $this->cachePayload($res->json('access_token'), $ttl), $ttl);
        $conn->last_used_at = now();
        $conn->save();
        return ['access_token' => (string) $res->json('access_token'), 'expires_in' => $ttl];
    }

    public function disconnect(GoogleDriveConnection $conn): void
    {
        $token = $conn->refreshToken();
        if ($token) {
            try {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
            } catch (\Throwable $e) {
                Log::info('Google revoke failed (ignored)', ['error' => $e->getMessage()]);
            }
        }
        Cache::forget($this->tokenKey((int) $conn->user_id));
        $conn->delete();
    }

    private function cachePayload(string $token, int $ttl): array
    {
        return ['token' => $token, 'expires_at' => time() + $ttl];
    }

    private function stateKey(string $state): string { return 'gdrive_oauth_state:' . hash('sha256', $state); }
    private function tokenKey(int $userId): string { return 'gdrive_access_token:' . $userId; }
}
