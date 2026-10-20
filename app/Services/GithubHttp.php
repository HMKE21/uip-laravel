<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * عميل GitHub مشترك لمراجعة الكود وتحليل الـ AI. بيحل مشكلة "GitHub rate-limited this request":
 *  - توكن المنصة (GITHUB_TOKEN → config services.github.token) كبديل لما صاحب المشروع مش رابط حسابه،
 *    فالطلبات بتتحسب 5000/ساعة بدل 60/ساعة المجهولة على IP السيرفر كله.
 *  - محتوى الملفات بيتسحب من raw.githubusercontent.com للريبوز العامة (مش بيتحسب على حد الـ API).
 *  - كاش قصير (دقيقتين) لردود الـ API عشان إعادة التشغيل/التحليل ما يعيدش نفس الطلبات.
 *  - لو الحد خلص ووقت التجديد قريب (<= 20 ثانية) بيستنى ويعيد مرة؛ ولو بعيد بيقول الرسالة بالوقت الحقيقي.
 */
class GithubHttp
{
    private const CACHE_TTL = 120;

    public static function platformToken(): ?string
    {
        $t = config('services.github.token') ?: env('GITHUB_TOKEN') ?: getenv('GITHUB_TOKEN');
        return $t ? (string) $t : null;
    }

    /** @throws \RuntimeException */
    public static function json(string $url, ?string $token = null, bool $cache = true): ?array
    {
        $token = $token ?: self::platformToken();
        $key = 'gh:' . md5($url . '|' . ($token ? substr(sha1($token), 0, 12) : 'anon'));
        if ($cache && ($hit = Cache::get($key)) !== null) {
            return $hit === '__null__' ? null : $hit;
        }

        $res = self::request($url, $token, 'application/vnd.github+json');
        $decoded = $res->json();
        $decoded = is_array($decoded) ? $decoded : null;
        if ($cache) {
            Cache::put($key, $decoded ?? '__null__', self::CACHE_TTL);
        }
        return $decoded;
    }

    /** نص ملف خام. بيرجع null لو مش متاح (مش throw — ملف واحد وحش مايوقفش التحليل). */
    public static function file(string $owner, string $repo, string $branch, string $path, ?string $token = null): ?string
    {
        $token = $token ?: self::platformToken();
        $enc = implode('/', array_map('rawurlencode', explode('/', $path)));

        // 1) raw.githubusercontent.com: للريبوز العامة، من غير استهلاك حد الـ API.
        try {
            $raw = Http::withHeaders(['User-Agent' => 'UIP-Platform'])->timeout(12)
                ->get("https://raw.githubusercontent.com/{$owner}/{$repo}/" . rawurlencode($branch) . "/{$enc}");
            if ($raw->successful()) {
                return $raw->body();
            }
        } catch (\Throwable) {
        }

        // 2) الريبوهات الخاصة (أو فشل raw): contents API بالتوكن، بصيغة raw.
        try {
            $res = self::request("https://api.github.com/repos/{$owner}/{$repo}/contents/{$enc}?ref=" . rawurlencode($branch), $token, 'application/vnd.github.raw+json');
            return $res->body();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @throws \RuntimeException */
    private static function request(string $url, ?string $token, string $accept, bool $retried = false)
    {
        $req = Http::withHeaders(['User-Agent' => 'UIP-Platform', 'Accept' => $accept])->timeout(15);
        if ($token) {
            $req = $req->withToken($token);
        }
        try {
            $res = $req->get($url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach GitHub (' . $e->getMessage() . ').');
        }

        $status = $res->status();
        if ($res->successful()) {
            return $res;
        }
        if ($status === 404) {
            throw new \RuntimeException('Repository not found — check the URL is correct. For a private repository, connect your GitHub account first.');
        }
        if ($status === 401) {
            throw new \RuntimeException('GitHub rejected the access token — reconnect your GitHub account and try again.');
        }
        if ($status === 403 || $status === 429) {
            $remaining = $res->header('X-RateLimit-Remaining');
            $reset = (int) $res->header('X-RateLimit-Reset');
            $retryAfter = (int) $res->header('Retry-After');
            $isLimit = $status === 429 || $remaining === '0' || $retryAfter > 0 || stripos((string) $res->body(), 'rate limit') !== false;
            if ($isLimit) {
                $wait = $retryAfter > 0 ? $retryAfter : max(0, $reset - time());
                if (!$retried && $wait > 0 && $wait <= 20) {
                    sleep($wait + 1);
                    return self::request($url, $token, $accept, true);
                }
                $mins = $wait > 0 ? (int) ceil($wait / 60) : null;
                $hint = $token ? '' : ' Tip: connect your GitHub account (Settings → Integrations) to get a much higher limit.';
                throw new \RuntimeException('GitHub rate-limited this request' . ($mins ? " — try again in about {$mins} minute(s)." : '. Please try again in a few minutes.') . $hint);
            }
            throw new \RuntimeException('GitHub denied access to this repository (HTTP 403). If it is private, connect your GitHub account first.');
        }
        throw new \RuntimeException("GitHub API returned HTTP {$status}.");
    }
}
