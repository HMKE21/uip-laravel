<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Web search for the desktop "Browser" app (own UI, own result cards).
 *
 * Google's Custom Search JSON API is closed to new customers and ends on 2027-01-01, so the default provider is Serper
 * (real Google results through a paid-per-query API). Brave Search (independent index) and Google CSE (legacy keys) are supported too.
 * Admin → My PC Control → "Search & browser" overrides these .env values at runtime (see DriveAdminSettings::webSearch()); .env stays the fallback.
 *   WEB_SEARCH_PROVIDER = serper | brave | google_cse | searxng
 *   WEB_SEARCH_KEY = the API key (not needed for searxng)   WEB_SEARCH_CX = engine id (google_cse only)   WEB_SEARCH_URL = base address of your own SearXNG (searxng only)
 *
 * SearXNG is free and self-hosted: it asks Google, Bing, DuckDuckGo… for you and needs no key and no per-query fee. Run it next to this API
 * (docker run -d -p 8888:8080 searxng/searxng, then add `json` under search.formats in its settings.yml) and set WEB_SEARCH_URL=http://127.0.0.1:8888.
 * A provider that fails (quota used up, key rejected, network) throws instead of returning an empty list, so the failure is NEVER cached for 10 minutes
 * and the app can tell the person "search is down" instead of "no results".
 */
class WebSearchService
{
    /** @param array|null $c إعدادات جاهزة (للتجربة قبل الحفظ)؛ وإلا من الأدمن/.env */
    public static function configured(?array $c = null): bool
    {
        $c ??= DriveAdminSettings::webSearch();
        $provider = (string) ($c['provider'] ?? 'serper');
        if ($provider === 'searxng') {
            return preg_match('#^https?://#i', trim((string) ($c['url'] ?? ''))) === 1;
        }
        if (trim((string) ($c['key'] ?? '')) === '') {
            return false;
        }
        return $provider !== 'google_cse' || trim((string) ($c['cx'] ?? '')) !== '';
    }

    /** @return array<int,array{title:string,url:string,snippet:string,domain:string}> */
    public function search(string $q, string $lang = 'en', int $num = 10, ?array $override = null, int $page = 1): array
    {
        $q = trim(mb_substr($q, 0, 200));
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
        $num = max(1, min(10, $num));
        $page = max(1, min(10, $page));
        $c = $override ?? DriveAdminSettings::webSearch();
        $run = function () use ($q, $lang, $num, $c, $page) {
            $key = (string) $c['key'];
            switch ($c['provider'] ?? 'serper') {
                case 'brave':
                    $r = Http::timeout(8)->withHeaders(['X-Subscription-Token' => $key, 'Accept' => 'application/json'])
                        ->get('https://api.search.brave.com/res/v1/web/search', ['q' => $q, 'count' => $num, 'search_lang' => $lang, 'safesearch' => 'moderate', 'offset' => $page - 1]);
                    $this->ok($r);
                    return $this->shape((array) data_get($r->json(), 'web.results', []), 'title', 'url', 'description');
                case 'google_cse':
                    $r = Http::timeout(8)->get('https://www.googleapis.com/customsearch/v1', ['key' => $key, 'cx' => (string) $c['cx'], 'q' => $q, 'num' => $num, 'hl' => $lang, 'safe' => 'active', 'start' => ($page - 1) * $num + 1]);
                    $this->ok($r);
                    return $this->shape((array) ($r->json('items') ?? []), 'title', 'link', 'snippet');
                case 'searxng':
                    $r = Http::timeout(10)->acceptJson()->get(rtrim((string) $c['url'], '/') . '/search', ['q' => $q, 'format' => 'json', 'language' => $lang, 'safesearch' => 1, 'pageno' => $page]);
                    $this->ok($r);
                    return array_slice($this->shape((array) ($r->json('results') ?? []), 'title', 'url', 'content'), 0, $num);
                default: // serper
                    $r = Http::timeout(8)->withHeaders(['X-API-KEY' => $key])->asJson()
                        ->post('https://google.serper.dev/search', ['q' => $q, 'num' => $num, 'hl' => $lang, 'page' => $page]);
                    $this->ok($r);
                    return $this->shape((array) ($r->json('organic') ?? []), 'title', 'link', 'snippet');
            }
        };
        if ($override !== null) { // تجربة من الأدمن: من غير كاش
            return $run();
        }
        // الكاش بيتفتّح بالمزوّد + العنوان + بصمة المفتاح، فتغيير الإعدادات من الأدمن يسري فورًا.
        $fp = sha1(($c['provider'] ?? '') . '|' . ($c['url'] ?? '') . '|' . ($c['cx'] ?? '') . '|' . ($c['key'] ?? ''));
        return Cache::remember('websearch:' . sha1($fp . "|$lang|$num|$page|" . mb_strtolower($q)), 600, $run);
    }

    /** a rejected key, a used-up quota or a provider outage must not look like "no results" (and must not be cached) */
    private function ok($response): void
    {
        if (!$response->successful()) {
            throw new \RuntimeException('search_provider_failed:' . $response->status());
        }
    }

    private function shape(array $rows, string $t, string $u, string $s): array
    {
        $out = [];
        foreach ($rows as $row) {
            $url = (string) ($row[$u] ?? '');
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $out[] = [
                'title'   => trim(html_entity_decode(strip_tags((string) ($row[$t] ?? $url)), ENT_QUOTES | ENT_HTML5)) ?: $url,
                'url'     => $url,
                'snippet' => trim(html_entity_decode(strip_tags((string) ($row[$s] ?? '')), ENT_QUOTES | ENT_HTML5)),
                'domain'  => preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)),
            ];
        }
        return $out;
    }
}
