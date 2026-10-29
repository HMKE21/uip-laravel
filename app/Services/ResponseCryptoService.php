<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-client response encryption key, negotiated with ephemeral ECDH (P-256) and
 * HKDF-SHA256 -> AES-256-GCM. Every browser tab/page load gets a fresh key, so
 * there is no static key shipped in the JS bundle and past traffic stays safe if
 * a later key leaks (forward secrecy for this layer). The key only lives in the
 * server cache for `key_ttl` seconds.
 */
class ResponseCryptoService
{
    /** Why the last bindClient() refused a key: missing | ua | sub (diagnostics only). */
    public string $lastFailure = '';

    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /**
     * What a key is bound to. Browsers: the User-Agent. The Android app talks through two stacks (native HTTP and the WebView: uploads,
     * streams, XHR) that can send different User-Agents, so for it the binding is the app marker header instead (the app token and the
     * account binding below still apply).
     */
    public static function clientFingerprint(?\Illuminate\Http\Request $request = null): string
    {
        $request ??= request();
        if (strtolower((string) $request->header('X-UIP-Client', '')) === 'android') {
            return 'uip-android-app';
        }

        return (string) $request->userAgent();
    }

    /** @return array{kid:string,server_pub:string,salt:string,server_time:int}|null */
    public function handshake(string $clientPubB64u): ?array
    {
        $point = self::b64uDecode($clientPubB64u);
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            return null;
        }

        $der = hex2bin(self::SPKI_P256_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $clientKey = @openssl_pkey_get_public($pem);
        if (!$clientKey) {
            return null; // not a valid curve point
        }

        $server = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$server) {
            return null;
        }
        $ec = openssl_pkey_get_details($server)['ec'] ?? null;
        if (!$ec) {
            return null;
        }
        $serverPub = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $shared = openssl_pkey_derive($clientKey, $server, 32);
        if ($shared === false) {
            return null;
        }

        $salt = random_bytes(32);
        $kid  = bin2hex(random_bytes(16));
        // Two independent keys: server->client (responses) and client->server (requests).
        $keys = [
            'resp' => hash_hkdf('sha256', $shared, 32, 'uip-resp-enc-v1', $salt),
            'req'  => hash_hkdf('sha256', $shared, 32, 'uip-req-enc-v1', $salt),
        ];

        $ttl = (int) config('secure_transport.key_ttl', 7200);
        // The key id is bound to the client that did the handshake (User-Agent hash now, account id on its first
        // authenticated call), so a leaked/stolen X-Enc-Kid cannot be replayed from another browser or another account.
        $record = array_map('base64_encode', $keys) + ['ua' => hash('sha256', self::clientFingerprint()), 'sub' => '', 'exp' => time() + $ttl];
        Cache::put('resp_enc:' . $kid, json_encode($record), $ttl);

        return ['kid' => $kid, 'server_pub' => self::b64uEncode($serverPub), 'salt' => self::b64uEncode($salt), 'server_time' => (int) round(microtime(true) * 1000)];
    }

    /** @return array{resp:string,req:string}|null */
    public function keysFor(string $kid): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $kid)) {
            return null;
        }
        $v = Cache::get('resp_enc:' . $kid);
        $arr = $v ? json_decode($v, true) : null;
        if (!is_array($arr) || !isset($arr['resp'], $arr['req'])) {
            return null;
        }

        return ['resp' => base64_decode($arr['resp']), 'req' => base64_decode($arr['req'])];
    }

    /**
     * True when this request may use the key `$kid`: same User-Agent as at handshake, and (once bound) same account.
     * The account id is read from the Bearer JWT WITHOUT verifying it — it is only a binding label; real authentication
     * still happens in the auth middleware. A forged `sub` can only make the key stop working, never grant access.
     */
    public function bindClient(string $kid, string $userAgent, string $authHeader): bool
    {
        $v = Cache::get('resp_enc:' . $kid);
        $rec = $v ? json_decode($v, true) : null;
        $this->lastFailure = '';
        if (!is_array($rec)) {
            $this->lastFailure = 'missing';
            return false;
        }
        if (isset($rec['ua']) && !hash_equals((string) $rec['ua'], hash('sha256', $userAgent))) {
            $this->lastFailure = 'ua';
            return false;
        }
        $sub = '';
        if (preg_match('/^Bearer\s+([\w-]+)\.([\w-]+)\.[\w-]+$/', $authHeader, $m)) {
            $claims = json_decode(self::b64uDecode($m[2]), true);
            $sub = is_array($claims) && isset($claims['sub']) && is_scalar($claims['sub']) ? substr((string) $claims['sub'], 0, 64) : '';
        }
        if ($sub === '') {
            return true;
        }
        $bound = (string) ($rec['sub'] ?? '');
        if ($bound === '') {
            $rec['sub'] = $sub;
            $left = max(1, (int) ($rec['exp'] ?? 0) - time());
            Cache::put('resp_enc:' . $kid, json_encode($rec), $left);

            return true;
        }

        if (!hash_equals($bound, $sub)) {
            $this->lastFailure = 'sub';
            return false;
        }

        return true;
    }

    /**
     * Decrypts a client->server envelope body. AAD binds it to key id + method + path, and the
     * plaintext carries a timestamp + nonce so a captured request cannot be replayed.
     * @return array|null decoded JSON body, or null when invalid/stale/replayed
     */
    public function decryptRequest(string $d, string $key, string $kid, string $method, string $path): ?array
    {
        $raw = self::b64uDecode($d);
        if (strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ct  = substr($raw, 12, -16);

        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "uip-req|$kid|" . strtoupper($method) . '|' . $path);
        if ($plain === false) {
            return null;
        }
        $wrap = json_decode($plain, true);
        if (!is_array($wrap) || !isset($wrap['t'], $wrap['n'])) {
            return null;
        }
        if (abs((int) (microtime(true) * 1000) - (int) $wrap['t']) > 120000) {
            return null; // stale
        }
        if (!Cache::add('req_nonce:' . $kid . ':' . substr((string) $wrap['n'], 0, 40), 1, 300)) {
            return null; // replay
        }

        $body = json_decode((string) ($wrap['b'] ?? ''), true);

        return is_array($body) ? $body : [];
    }

    /** AES-256-GCM; AAD binds the ciphertext to this key id and HTTP status. Output = base64url(iv|ct|tag). */
    public function encrypt(string $plain, string $key, string $kid, int $status): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "uip-resp|$kid|$status", 16);
        if ($ct === false) {
            throw new \RuntimeException('Response encryption failed.');
        }

        return self::b64uEncode($iv . $ct . $tag);
    }

    /**
     * Admin debugging aid: decrypts a captured response envelope with the session key still held in cache.
     * The key itself never leaves the server. Tries the usual HTTP statuses (they are part of the AAD) unless one is given.
     * @return array{status:int,plain:string}|null null when the key expired (key_ttl) or the data does not match
     */
    public function decryptResponse(string $d, string $kid, ?int $status = null): ?array
    {
        $key = $this->keysFor($kid)['resp'] ?? null;
        $raw = self::b64uDecode($d);
        if ($key === null || strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ct  = substr($raw, 12, -16);
        foreach ($status ? [$status] : [200, 201, 202, 204, 400, 401, 403, 404, 405, 409, 410, 413, 422, 423, 428, 429, 500, 502, 503] as $s) {
            $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "uip-resp|$kid|$s");
            if ($plain !== false) {
                return ['status' => $s, 'plain' => $plain];
            }
        }

        return null;
    }

    /** Server-side health check of everything the handshake needs (booleans only, no secrets). */
    public function selfTest(): array
    {
        $r = ['openssl' => extension_loaded('openssl'), 'hkdf' => function_exists('hash_hkdf'), 'ec_keygen' => false, 'derive' => false, 'cache' => false];
        try {
            $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
            $a = openssl_pkey_new($opts);
            $b = openssl_pkey_new($opts);
            $r['ec_keygen'] = (bool) ($a && $b);
            if ($r['ec_keygen']) {
                $pubB = openssl_pkey_get_public(openssl_pkey_get_details($b)['key']);
                $r['derive'] = $pubB && openssl_pkey_derive($pubB, $a, 32) !== false;
            }
        } catch (\Throwable $e) {
            // stays false
        }
        try {
            Cache::put('resp_enc_selftest', '1', 30);
            $r['cache'] = Cache::get('resp_enc_selftest') === '1';
        } catch (\Throwable $e) {
            // stays false
        }
        $r['ok'] = $r['openssl'] && $r['hkdf'] && $r['ec_keygen'] && $r['derive'] && $r['cache'];

        return $r;
    }
}
