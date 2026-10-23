<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * يتحقق من Microsoft (Entra ID) ID token (RS256) على السيرفر — مش بنصدّق أي حاجة جاية من الفرونت.
 *
 * بيتأكد من: التوقيع (بمفاتيح مايكروسوفت العامة)، iss (لازم https://login.microsoftonline.com/{tid}/v2.0 بنفس tid اللي في التوكن)،
 * aud (لازم MS_CLIENT_ID)، exp/iat، ووجود sub.
 *
 * ⚠ الإيميل: مايكروسوفت مبتضمنش "email_verified". ده بيخلّي أي حد عنده tenant خاص بيه يكتب أي إيميل في بروفايله
 * ويبان إنه صاحبه (nOAuth). فمبنثقش في الإيميل غير في حالتين:
 *   1) حساب مايكروسوفت الشخصي (tid = 9188040d-…): الإيميل متأكَّد منه عند مايكروسوفت نفسها.
 *   2) حساب شغل/جامعة وفيه الـ claim  xms_edov = true  (ملكية دومين الإيميل متأكدة). بيتفعّل من Azure:
 *      App registration → Token configuration → Add optional claim → ID → email + xms_edov.
 * غير كده verify() بترجّع null و lastError بيشرح السبب.
 */
class MicrosoftIdTokenVerifier
{
    private const KEYS_URL    = 'https://login.microsoftonline.com/common/discovery/v2.0/keys';
    public const CONSUMER_TID = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /** سبب آخر رفض: invalid | email_unverified | no_email */
    public ?string $lastError = null;

    public function clientId(): string
    {
        return trim((string) config('services.microsoft.client_id', ''));
    }

    public function enabled(): bool
    {
        return $this->clientId() !== '';
    }

    /**
     * @return array{sub:string,email:string,name:string,picture:null,tid:string}|null
     */
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
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            return null;
        }

        $pem = $this->keys()[$header['kid']] ?? null;
        if (!$pem) {
            return null;
        }
        $key = openssl_pkey_get_public($pem);
        if (!$key || openssl_verify($h64 . '.' . $p64, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $tid = (string) ($payload['tid'] ?? '');
        if ($tid === '' || !preg_match('/^[0-9a-f-]{36}$/i', $tid)) {
            return null;
        }
        if (($payload['iss'] ?? '') !== "https://login.microsoftonline.com/{$tid}/v2.0") {
            return null;
        }
        if (($payload['aud'] ?? '') !== $this->clientId()) {
            return null;
        }
        if (($payload['exp'] ?? 0) < time() - 60 || ($payload['iat'] ?? 0) > time() + 60) {
            return null;
        }
        if (empty($payload['sub'])) {
            return null;
        }

        $consumer = strcasecmp($tid, self::CONSUMER_TID) === 0;
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' && $consumer) {
            // حساب شخصي: preferred_username هو الإيميل نفسه.
            $email = strtolower(trim((string) ($payload['preferred_username'] ?? '')));
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'no_email';
            return null;
        }
        $edov = $payload['xms_edov'] ?? false;
        $domainOwned = $edov === true || $edov === 1 || $edov === '1' || $edov === 'true';
        if (!$consumer && !$domainOwned) {
            $this->lastError = 'email_unverified';
            return null;
        }

        $this->lastError = null;

        return [
            'sub'     => $tid . '.' . (string) $payload['sub'],
            'email'   => $email,
            'name'    => (string) ($payload['name'] ?? ''),
            'picture' => null,
            'tid'     => $tid,
        ];
    }

    /** @return array<string,string> kid => PEM */
    private function keys(): array
    {
        return Cache::remember('microsoft_oauth_keys', 3600, function () {
            $res  = Http::timeout(8)->get(self::KEYS_URL);
            $json = $res->successful() ? $res->json() : null;
            $out  = [];
            foreach ((array) ($json['keys'] ?? []) as $k) {
                $x5c = $k['x5c'][0] ?? null;
                if (!empty($k['kid']) && $x5c) {
                    $out[$k['kid']] = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x5c, 64, "\n") . "-----END CERTIFICATE-----\n";
                }
            }
            return $out;
        });
    }

    private function b64(string $v): string
    {
        return (string) base64_decode(strtr($v, '-_', '+/'));
    }
}
