<?php

namespace App\Services\Tenancy;

use App\Services\KeycloakIdTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * بيتحقق من OIDC ID token بتاع منصة (RS256 + JWKS من الـ discovery + iss + aud + azp + exp/iat/nbf + nonce).
 * مختلف عن Keycloak الأساسي: IdP الشركات (Entra مثلًا) غالبًا مبيبعتش email_verified، فبنقبل غيابها ونرفض "false" صريحة؛
 * والإيميل بيتاخد من email أو preferred_username / upn لو شكله إيميل. المالك هو اللي اختار الـ IdP وبيتحكم في مستخدميه.
 */
final class TenantOidcVerifier
{
    /** سبب آخر رفض: invalid | no_email | email_unverified | nonce */
    public ?string $lastError = null;

    /** @return array{sub:string,email:string,name:string,picture:null}|null */
    public function verify(string $idToken, string $issuer, string $clientId, ?string $nonce = null): ?array
    {
        $this->lastError = 'invalid';
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || $issuer === '' || $clientId === '') {
            return null;
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode($this->b64($h64), true);
        $payload = json_decode($this->b64($p64), true);
        $sig = $this->b64($s64);
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid']) || $sig === '') {
            return null;
        }
        $disc = TenantOidc::discover($issuer);
        if (!$disc) {
            return null;
        }
        $pem = $this->pemFor($disc['jwks_uri'], (string) $header['kid']);
        if (!$pem) {
            return null;
        }
        $key = openssl_pkey_get_public($pem);
        if (!$key || openssl_verify($h64 . '.' . $p64, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        if (rtrim((string) ($payload['iss'] ?? ''), '/') !== rtrim($issuer, '/')) {
            return null;
        }
        $aud = $payload['aud'] ?? [];
        $aud = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $aud, true)) {
            return null;
        }
        if (isset($payload['azp']) && $payload['azp'] !== $clientId) {
            return null;
        }
        $now = time();
        if (!isset($payload['exp']) || $payload['exp'] < $now - 60 || ($payload['iat'] ?? 0) > $now + 60 || ($payload['nbf'] ?? 0) > $now + 60) {
            return null;
        }
        if (empty($payload['sub'])) {
            return null;
        }
        if ($nonce !== null && $nonce !== '' && !hash_equals($nonce, (string) ($payload['nonce'] ?? ''))) {
            $this->lastError = 'nonce';
            return null;
        }

        $email = '';
        foreach (['email', 'preferred_username', 'upn'] as $f) {
            $c = strtolower(trim((string) ($payload[$f] ?? '')));
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $c;
                break;
            }
        }
        if ($email === '') {
            $this->lastError = 'no_email';
            return null;
        }
        $verified = $payload['email_verified'] ?? true; // غياب الـ claim = الـ IdP هو المرجع
        if ($verified === false || $verified === 'false' || $verified === 0) {
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

    private function pemFor(string $jwksUri, string $kid): ?string
    {
        $keys = $this->keys($jwksUri, false);
        if (!isset($keys[$kid])) {
            $keys = $this->keys($jwksUri, true); // kid جديد (تدوير مفاتيح)
        }
        return $keys[$kid] ?? null;
    }

    /** @return array<string,string> kid => PEM */
    private function keys(string $jwksUri, bool $refresh): array
    {
        $ck = 'tenant_oidc_jwks:' . md5($jwksUri);
        if ($refresh) {
            if (!Cache::add($ck . ':lock', 1, 60)) { // إعادة تحميل واحدة في الدقيقة بالكتير
                return (array) Cache::get($ck, []);
            }
            Cache::forget($ck);
        }
        $cached = Cache::get($ck);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
        try {
            $res = Http::timeout(8)->acceptJson()->withOptions(['allow_redirects' => false])->get($jwksUri);
        } catch (\Throwable $e) {
            return [];
        }
        $json = $res->successful() ? $res->json() : null;
        $out = [];
        foreach ((array) ($json['keys'] ?? []) as $k) {
            if (empty($k['kid']) || ($k['kty'] ?? '') !== 'RSA' || (isset($k['use']) && $k['use'] !== 'sig')) {
                continue;
            }
            $pem = null;
            if (!empty($k['n']) && !empty($k['e'])) {
                $pem = KeycloakIdTokenVerifier::rsaPem($this->b64((string) $k['n']), $this->b64((string) $k['e']));
            } elseif (!empty($k['x5c'][0])) {
                $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $k['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            }
            if ($pem) {
                $out[(string) $k['kid']] = $pem;
            }
        }
        if ($out !== []) {
            Cache::put($ck, $out, 3600);
        }
        return $out;
    }

    private function b64(string $v): string
    {
        return (string) base64_decode(strtr($v, '-_', '+/'));
    }
}
