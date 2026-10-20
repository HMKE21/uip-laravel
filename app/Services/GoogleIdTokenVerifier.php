<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * يتحقق من Google ID token (RS256) على السيرفر — مش بنصدّق أي حاجة جاية من الفرونت.
 *
 * بيتأكد من: التوقيع (بشهادات جوجل العامة)، iss، aud (لازم واحد من GOOGLE_CLIENT_IDS)،
 * exp، وإن email_verified = true. نفس الـ token بيطلع من زرار الويب (Google Identity
 * Services) ومن تطبيق الأندرويد (Credential Manager) طالما الاتنين بيستخدموا نفس الـ Web client ID.
 */
class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
    private const ISSUERS   = ['https://accounts.google.com', 'accounts.google.com'];

    /** @return string[] */
    public function clientIds(): array
    {
        $raw = (string) \App\Support\PlatformPolicy::value('google_client_ids');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function enabled(): bool
    {
        return $this->clientIds() !== [];
    }

    /**
     * @return array{sub:string,email:string,name:string,given_name:string,family_name:string,picture:?string,locale:?string}|null
     *         null لو التوكن مش سليم لأي سبب.
     */
    public function verify(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || !$this->enabled()) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;

        $header  = json_decode($this->b64($h64), true);
        $payload = json_decode($this->b64($p64), true);
        $sig     = $this->b64($s64);
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            return null;
        }

        $pem = $this->certs()[$header['kid']] ?? null;
        if (!$pem) {
            return null;
        }
        $key = openssl_pkey_get_public($pem);
        if (!$key || openssl_verify($h64 . '.' . $p64, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        if (!in_array($payload['iss'] ?? '', self::ISSUERS, true)) {
            return null;
        }
        if (!in_array($payload['aud'] ?? '', $this->clientIds(), true)) {
            return null;
        }
        // 60 ثانية سماح لفرق الساعة بين السيرفرين.
        if (($payload['exp'] ?? 0) < time() - 60 || ($payload['iat'] ?? 0) > time() + 60) {
            return null;
        }
        $verified = $payload['email_verified'] ?? false;
        if (empty($payload['sub']) || empty($payload['email']) || !($verified === true || $verified === 'true')) {
            return null;
        }

        return [
            'sub'         => (string) $payload['sub'],
            'email'       => strtolower((string) $payload['email']),
            'name'        => (string) ($payload['name'] ?? ''),
            'given_name'  => (string) ($payload['given_name'] ?? ''),
            'family_name' => (string) ($payload['family_name'] ?? ''),
            'picture'     => isset($payload['picture']) ? (string) $payload['picture'] : null,
            'locale'      => isset($payload['locale']) ? (string) $payload['locale'] : null,
        ];
    }

    /** @return array<string,string> kid => PEM certificate */
    private function certs(): array
    {
        return Cache::remember('google_oauth_certs', 3600, function () {
            $res = Http::timeout(8)->get(self::CERTS_URL);
            $json = $res->successful() ? $res->json() : null;
            return is_array($json) ? $json : [];
        });
    }

    private function b64(string $v): string
    {
        return (string) base64_decode(strtr($v, '-_', '+/'));
    }
}
