<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SSO بـ OIDC لكل منصة (Microsoft Entra ID / Okta / Keycloak / Auth0 / Google Workspace…).
 * المالك بيدخل الـ issuer والـ client id (client عام، من غير secret). السيرفر بيقرا الـ discovery document من الـ issuer
 * ويتحقق من الـ ID token بنفسه (TenantOidcVerifier) — مبنصدّقش أي حاجة جاية من الفرونت.
 * حماية SSRF: الـ issuer وكل رابط بنقراه منه لازم https وعنوان عام (مش localhost ولا شبكة داخلية).
 */
final class TenantOidc
{
    /** فحص الـ issuer. $dns=false = فحص شكلي بس (من غير DNS) للاستخدام في كل request. */
    public static function issuerSafe(string $issuer, bool $dns = true): bool
    {
        $p = parse_url($issuer);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return false;
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            return false;
        }
        $host = strtolower((string) $p['host']);
        if ($host === 'localhost' || !str_contains($host, '.') || preg_match('/\.(local|internal|localhost|lan|home|corp|intranet)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return false; // IP مباشر: لأ
        }
        if (!$dns) {
            return true;
        }
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A) as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }
        foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /** رابط endpoint من الـ discovery: https وعنوان عام. */
    public static function urlSafe(string $url): bool
    {
        return self::issuerSafe(rtrim($url, '/'), true) || self::endpointSafe($url);
    }

    private static function endpointSafe(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return false;
        }
        $bare = 'https://' . strtolower((string) $p['host']);
        return strtolower((string) ($p['scheme'] ?? '')) === 'https' && !isset($p['user']) && !isset($p['fragment']) && self::issuerSafe($bare, true);
    }

    /**
     * @return array{issuer:string,authorization_endpoint:string,jwks_uri:string,token_endpoint:?string,response_types_supported:array}|null
     */
    public static function discover(string $issuer, bool $fresh = false): ?array
    {
        $issuer = rtrim($issuer, '/');
        $key = 'tenant_oidc_disc:' . md5($issuer);
        if (!$fresh) {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return $hit;
            }
        }
        if (!self::issuerSafe($issuer, true)) {
            return null;
        }
        try {
            $res = Http::timeout(8)->acceptJson()->withOptions(['allow_redirects' => false])->get($issuer . '/.well-known/openid-configuration');
        } catch (\Throwable $e) {
            return null;
        }
        $j = $res->successful() ? $res->json() : null;
        if (!is_array($j) || rtrim((string) ($j['issuer'] ?? ''), '/') !== $issuer) { // الـ issuer في الوثيقة لازم يطابق اللي المالك كتبه
            return null;
        }
        $auth = (string) ($j['authorization_endpoint'] ?? '');
        $jwks = (string) ($j['jwks_uri'] ?? '');
        $tok = (string) ($j['token_endpoint'] ?? '');
        if (!self::endpointSafe($auth) || !self::endpointSafe($jwks)) {
            return null;
        }
        $doc = [
            'issuer' => $issuer,
            'authorization_endpoint' => $auth,
            'jwks_uri' => $jwks,
            'token_endpoint' => ($tok !== '' && self::endpointSafe($tok)) ? $tok : null,
            'response_types_supported' => array_values(array_filter((array) ($j['response_types_supported'] ?? []), 'is_string')),
        ];
        Cache::put($key, $doc, 3600);
        return $doc;
    }
}
