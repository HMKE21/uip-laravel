<?php

namespace App\Support;

/**
 * حماية SSRF مركزية للروابط اللي جاية من مستخدم/مزوّد خارجي (صورة بروفايل جوجل/GitHub مثلًا).
 *
 * - https بس، بورت 443 بس، من غير user:pass ولا fragment ولا مسافات/محارف تحكم.
 * - الـ host لازم يطابق allowlist بالظبط (اسم كامل) أو بلاحقة تبدأ بنقطة (".googleusercontent.com").
 * - الـ DNS بيتحل هنا، وأي IP خاص/محجوز/loopback/link-local/CGNAT في النتايج = رفض.
 * - الـ IP اللي اتفحص بيتثبّت في cURL (CURLOPT_RESOLVE) فمفيش DNS rebinding بين الفحص والطلب.
 * - redirects مقفولة دايمًا.
 */
final class SafeUrl
{
    /**
     * @param  string[] $allowedHosts أسماء كاملة ("avatars.githubusercontent.com") أو لواحق بتبدأ بنقطة (".googleusercontent.com")
     * @return array{url:string,host:string,port:int,ip:string,options:array<string,mixed>}|null  null = مرفوض
     */
    public static function check(string $url, array $allowedHosts): ?array
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return null;
        }
        $host = strtolower((string) $p['host']);
        if ($host !== rtrim($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', $host) !== 1) {
            return null;
        }
        $ok = false;
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower($allowed);
            if (($allowed[0] ?? '') === '.' ? str_ends_with($host, $allowed) && strlen($host) > strlen($allowed) : $host === $allowed) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return null;
        }
        $ips = self::resolve($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::publicIp($ip)) {
                return null;
            }
        }
        $ip = $ips[0];
        $options = ['allow_redirects' => false, 'connect_timeout' => 5, 'http_errors' => false];
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]];
        }

        return ['url' => $url, 'host' => $host, 'port' => 443, 'ip' => $ip, 'options' => $options];
    }

    public static function publicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $l = ip2long($ip);
            if ($l !== false) {
                $l &= 0xFFFFFFFF;
                if (($l & 0xFFC00000) === 0x64400000) { // 100.64.0.0/10 (CGNAT)
                    return false;
                }
                if (($l & 0xFFFFFF00) === 0xC0000000 || ($l & 0xFFFE0000) === 0xC6120000) { // 192.0.0.0/24 · 198.18.0.0/15
                    return false;
                }
            }
        } else {
            $bin = @inet_pton($ip);
            if ($bin === false || (ord($bin[0]) === 0x20 && ord($bin[1]) === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8)) { // 2001:db8::/32
                return false;
            }
            if (($bin[0] & "\xfe") === "\xfc" || (ord($bin[0]) === 0xfe && (ord($bin[1]) & 0xc0) === 0x80)) { // fc00::/7 · fe80::/10
                return false;
            }
        }

        return true;
    }

    /** @return string[] */
    public static function resolve(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (!$ips) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
