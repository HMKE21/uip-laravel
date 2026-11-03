<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Can this page be shown inside an <iframe> of the Browser app?"  -> yes: open it instantly (no server needed); no: use the cloud browser.
 * A page says "no" with X-Frame-Options or a CSP frame-ancestors that does not allow everyone.
 *
 * SSRF: the URL is user-supplied, so every hop (redirects are followed by hand, max 4) is resolved here, must be a PUBLIC address on port
 * 80 / 443, and the connection is pinned to the address that was checked (CURLOPT_RESOLVE), so DNS cannot change between check and use.
 * Only the response HEADERS are read; the body is never downloaded.
 */
class WebProbeService
{
    /** @return array{ok:bool,embeddable:bool,url:string,reason:string} */
    public function probe(string $url): array
    {
        $url = trim($url);
        if (!$this->plainUrl($url)) {
            return ['ok' => false, 'embeddable' => false, 'url' => $url, 'reason' => 'invalid'];
        }
        return Cache::remember('webprobe:' . sha1($url), 3600, fn () => $this->run($url));
    }

    private function plainUrl(string $u): bool
    {
        $p = parse_url($u);
        return $p && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && !empty($p['host']) && empty($p['user']) && empty($p['pass']) && strlen($u) <= 2000;
    }

    /** the public IP of a host, or null when it is private / reserved / unresolvable */
    private function publicIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
                $ips[] = $r['ip'] ?? '';
            }
        }
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) { // every address must be public, not just one of them
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_IPV4)) {
                return null;
            }
        }
        return $ips[0];
    }

    private function run(string $url): array
    {
        for ($hop = 0; $hop <= 4; $hop++) {
            $p = parse_url($url);
            $https = strtolower($p['scheme']) === 'https';
            $port = (int) ($p['port'] ?? ($https ? 443 : 80));
            $host = strtolower($p['host']);
            if (!in_array($port, [80, 443], true) || ($ip = $this->publicIp($host)) === null) {
                return ['ok' => false, 'embeddable' => false, 'url' => $url, 'reason' => 'blocked'];
            }
            try {
                $res = Http::timeout(6)->withOptions([
                    'allow_redirects' => false, 'stream' => true, 'http_errors' => false,
                    'curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]],
                ])->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; UIPE-Browser-Probe)', 'Accept' => 'text/html'])->get($url);
            } catch (\Throwable $e) {
                return ['ok' => false, 'embeddable' => false, 'url' => $url, 'reason' => 'unreachable'];
            }
            $code = $res->status();
            if ($code >= 300 && $code < 400 && $res->header('Location')) {
                $url = $this->absolute($url, (string) $res->header('Location'));
                if (!$this->plainUrl($url)) {
                    return ['ok' => false, 'embeddable' => false, 'url' => $url, 'reason' => 'invalid'];
                }
                continue;
            }
            if ($code >= 400) {
                return ['ok' => false, 'embeddable' => false, 'url' => $url, 'reason' => "http_$code"];
            }
            return ['ok' => true, 'embeddable' => $this->allowsFraming($res->header('X-Frame-Options'), $res->header('Content-Security-Policy')), 'url' => $url, 'reason' => ''];
        }
        return ['ok' => false, 'embeddable' => false, 'url' => $url, 'reason' => 'redirects'];
    }

    private function allowsFraming(?string $xfo, ?string $csp): bool
    {
        if ($xfo !== null && trim($xfo) !== '') {
            return false; // DENY / SAMEORIGIN / ALLOW-FROM: never for us
        }
        if ($csp && preg_match('/frame-ancestors\s+([^;]*)/i', $csp, $m)) {
            $v = trim($m[1]);
            return $v === '*' || preg_match('/(^|\s)(\*|https:|http:)(\s|$)/', $v) === 1;
        }
        return true;
    }

    private function absolute(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($loc, '//')) {
            return $p['scheme'] . ':' . $loc;
        }
        if (str_starts_with($loc, '/')) {
            return $origin . $loc;
        }
        return $origin . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
    }
}
