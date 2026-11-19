<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * يتحقق من Keycloak ID token (RS256) على السيرفر — مش بنصدّق أي حاجة جاية من الفرونت.
 * بيتأكد من: التوقيع (JWKS الـ realm)، iss، aud (لازم يحتوي KEYCLOAK_CLIENT_ID)، azp، exp/iat/nbf، sub،
 * ووجود email مع email_verified = true. أي alg غير RS256 مرفوض.
 * صلاحيات Keycloak (roles) مبتتستخدمش: الأدوار بتفضل في قاعدة بيانات UIP.
 *
 * KEYCLOAK_BASE_URL / KEYCLOAK_REALM / KEYCLOAK_CLIENT_ID (client عام بدون secret) / KEYCLOAK_ISSUER / KEYCLOAK_JWKS_URL (اختياريين)
 */
class KeycloakIdTokenVerifier
{
    /** سبب آخر رفض: invalid | email_unverified | no_email */
    public ?string $lastError = null;

    public function issuer(): string
    {
        $iss = trim((string) config('services.keycloak.issuer', ''));
        if ($iss !== '') {
            return rtrim($iss, '/');
        }
        $base  = rtrim(trim((string) config('services.keycloak.base_url', '')), '/');
        $realm = trim((string) config('services.keycloak.realm', ''));
        return $base !== '' && $realm !== '' ? "{$base}/realms/{$realm}" : '';
    }

    public function clientId(): string
    {
        return trim((string) config('services.keycloak.client_id', ''));
    }

    public function jwksUrl(): string
    {
        $url = trim((string) config('services.keycloak.jwks_url', ''));
        return $url !== '' ? $url : ($this->issuer() !== '' ? $this->issuer() . '/protocol/openid-connect/certs' : '');
    }

    public function enabled(): bool
    {
        return $this->issuer() !== '' && $this->clientId() !== '';
    }

    /** @return array{sub:string,email:string,name:string,picture:null}|null */
    public function verify(string $idToken): ?array
    {
        $this->lastError = 'invalid';
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || !$this->enabled()) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;

        $header  = json_decode($this->b64($h64), true);
        $payload = json_decode($this->b64($p64), true);
        $sig     = $this->b64($s64);
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid']) || $sig === '') {
            return null;
        }

        $pem = $this->pemFor((string) $header['kid']);
        if (!$pem) {
            return null;
        }
        $key = openssl_pkey_get_public($pem);
        if (!$key || openssl_verify($h64 . '.' . $p64, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        if (($payload['iss'] ?? '') !== $this->issuer()) {
            return null;
        }
        $aud = $payload['aud'] ?? [];
        $aud = is_array($aud) ? $aud : [$aud];
        if (!in_array($this->clientId(), $aud, true)) {
            return null;
        }
        if (isset($payload['azp']) && $payload['azp'] !== $this->clientId()) {
            return null;
        }
        $now = time(); // 60 ثانية سماح لفرق الساعة
        if (!isset($payload['exp']) || $payload['exp'] < $now - 60 || ($payload['iat'] ?? 0) > $now + 60 || ($payload['nbf'] ?? 0) > $now + 60) {
            return null;
        }
        if (empty($payload['sub'])) {
            return null;
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'no_email';
            return null;
        }
        $verified = $payload['email_verified'] ?? false;
        if (!($verified === true || $verified === 'true' || $verified === 1)) {
            $this->lastError = 'email_unverified';
            return null;
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($payload['given_name'] ?? '')) . ' ' . ((string) ($payload['family_name'] ?? '')));
        }

        $this->lastError = null;
        return ['sub' => (string) $payload['sub'], 'email' => $email, 'name' => $name, 'picture' => null];
    }

    /** PEM للـ kid؛ لو kid جديد (تدوير مفاتيح) بيعيد تحميل JWKS مرة. */
    private function pemFor(string $kid): ?string
    {
        $keys = $this->keys(false);
        if (!isset($keys[$kid])) {
            $keys = $this->keys(true);
        }
        return $keys[$kid] ?? null;
    }

    /** @return array<string,string> kid => PEM */
    protected function keys(bool $refresh): array
    {
        $cacheKey = 'keycloak_jwks:' . md5($this->jwksUrl());
        if ($refresh) {
            // إعادة تحميل واحدة كل 60 ثانية بالكتير (توكنات بـ kid عشوائي متضربش Keycloak).
            if (!Cache::add($cacheKey . ':refresh_lock', 1, 60)) {
                return (array) Cache::get($cacheKey, []);
            }
            Cache::forget($cacheKey);
        }
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $res = Http::timeout(8)->acceptJson()->get($this->jwksUrl());
        } catch (\Throwable $e) {
            return [];
        }
        $json = $res->successful() ? $res->json() : null;
        $out  = [];
        foreach ((array) ($json['keys'] ?? []) as $k) {
            if (empty($k['kid']) || ($k['kty'] ?? '') !== 'RSA' || (isset($k['use']) && $k['use'] !== 'sig') || (isset($k['alg']) && $k['alg'] !== 'RS256')) {
                continue;
            }
            $pem = null;
            if (!empty($k['x5c'][0])) {
                $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $k['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            } elseif (!empty($k['n']) && !empty($k['e'])) {
                $pem = self::rsaPem($this->b64((string) $k['n']), $this->b64((string) $k['e']));
            }
            if ($pem) {
                $out[(string) $k['kid']] = $pem;
            }
        }
        if ($out !== []) { // مبنخزّنش نتيجة فاضية: فشل مؤقت ميقفلش الدخول ساعة
            Cache::put($cacheKey, $out, 3600);
        }
        return $out;
    }

    /** PEM (SubjectPublicKeyInfo) من modulus + exponent. */
    public static function rsaPem(string $n, string $e): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || (ord($b[0]) & 0x80)) {
                $b = "\x00" . $b;
            }
            return "\x02" . self::derLen(strlen($b)) . $b;
        };
        $seq   = fn (string $c): string => "\x30" . self::derLen(strlen($c)) . $c;
        $rsa   = $seq($int($n) . $int($e));
        $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bits  = "\x03" . self::derLen(strlen($rsa) + 1) . "\x00" . $rsa;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($seq($algId . $bits)), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $s = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($s)) . $s;
    }

    private function b64(string $v): string
    {
        return (string) base64_decode(strtr($v, '-_', '+/'));
    }
}
