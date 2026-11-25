<?php

namespace App\Support;

/**
 * Single place that signs the short-lived HS256 token the sandbox gateway (uip-sandbox) verifies.
 *
 * Before this class the same signing code was copy-pasted into four controller methods, each with its own TTL handling.
 * Now: one TTL clamp (30..MAX_TTL seconds — the gateway itself refuses anything above 900 s), one claim set, one audit trail.
 * Nothing here runs student code and nothing secret leaves the server: the token only carries user id + session key.
 */
class SandboxTokenSigner
{
    /** a token is used once, right after it is issued — there is never a reason to keep it valid for minutes */
    public const MAX_TTL = 120;
    public const MIN_TTL = 30;

    /** session key (`pid` claim): terminal / vscode / browser, drive-<id>, or a research project id — short and free of anything odd */
    private const PID_RE = '/^[A-Za-z0-9._-]{1,64}$/';

    /** @return array{url:string,secret:string}|null null = the sandbox is not configured on this server */
    public static function config(): ?array
    {
        $url    = rtrim((string) config('services.sandbox.url'), '/');
        $secret = (string) config('services.sandbox.secret');
        if ($url === '' || strlen($secret) < 32) {
            return null;
        }
        // https only (a plain-http gateway would send the token and the whole shell session in clear text); localhost is allowed for development
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            return null;
        }
        return ['url' => $url, 'secret' => $secret];
    }

    public static function ttl(): int
    {
        return max(self::MIN_TTL, min(self::MAX_TTL, (int) config('services.sandbox.token_ttl', 90)));
    }

    /**
     * @return array{url:string,token:string,expires_in:int}|null null = not configured
     */
    public static function issue(int $userId, string $pid, string $kind = 'terminal'): ?array
    {
        if (!in_array($kind, ['terminal', 'project', 'vscode', 'browser'], true)) {
            throw new \InvalidArgumentException('bad sandbox token kind');
        }
        $cfg = self::config();
        if ($cfg === null) {
            return null;
        }
        if (!preg_match(self::PID_RE, $pid)) {
            throw new \InvalidArgumentException('bad sandbox session key');
        }

        $ttl = self::ttl();
        $now = time();
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode([
            'iss' => 'uip-api', 'aud' => 'uip-sandbox', 'sub' => (string) $userId, 'pid' => $pid,
            // what this token may open: terminal | project | vscode | browser (the gateway refuses a VS Code / browser session on any other kind)
            'knd' => $kind,
            'iat' => $now, 'nbf' => $now - 5, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(16)),
        ]));
        $sig = $b64(hash_hmac('sha256', "{$head}.{$body}", $cfg['secret'], true));

        // audit trail: who opened which kind of sandbox, from where (never the token or the secret)
        try {
            SecurityLog::write('sandbox_token_issued', ['user_id' => $userId, 'kind' => $kind, 'pid' => $pid]);
        } catch (\Throwable $e) {
            // logging must never block a student
        }

        return ['url' => $cfg['url'], 'token' => "{$head}.{$body}.{$sig}", 'expires_in' => $ttl];
    }
}
