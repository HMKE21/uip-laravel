<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Web search for the desktop "Browser" app (own UI, own result cards).
 *
 * Google's Custom Search JSON API is closed to new customers and ends on 2027-01-01, so the default provider is Serper
 * (real Google results through a paid-per-query API). Brave Search (independent index) and Google CSE (legacy keys) are supported too.
 *   WEB_SEARCH_PROVIDER = serper | brave | google_cse        WEB_SEARCH_KEY = the API key        WEB_SEARCH_CX = engine id (google_cse only)
 */
class WebSearchService
{
    public static function configured(): bool
    {
        $c = (array) config('services.websearch');
        if (trim((string) ($c['key'] ?? '')) === '') {
            return false;
        }
        return ($c['provider'] ?? 'serper') !== 'google_cse' || trim((string) ($c['cx'] ?? '')) !== '';
    }

    /** @return array<int,array{title:string,url:string,snippet:string,domain:string}> */
    public function search(string $q, string $lang = 'en', int $num = 10): array
    {
        $q = trim(mb_substr($q, 0, 200));
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
        $num = max(1, min(10, $num));
        return Cache::remember('websearch:' . sha1(config('services.websearch.provider') . "|$lang|$num|" . mb_strtolower($q)), 600, function () use ($q, $lang, $num) {
            $c = (array) config('services.websearch');
            $key = (string) $c['key'];
            switch ($c['provider'] ?? 'serper') {
                case 'brave':
                    $r = Http::timeout(8)->withHeaders(['X-Subscription-Token' => $key, 'Accept' => 'application/json'])
                        ->get('https://api.search.brave.com/res/v1/web/search', ['q' => $q, 'count' => $num, 'search_lang' => $lang, 'safesearch' => 'moderate']);
                    $rows = $r->successful() ? (array) data_get($r->json(), 'web.results', []) : [];
                    return $this->shape($rows, 'title', 'url', 'description');
                case 'google_cse':
                    $r = Http::timeout(8)->get('https://www.googleapis.com/customsearch/v1', ['key' => $key, 'cx' => (string) $c['cx'], 'q' => $q, 'num' => $num, 'hl' => $lang, 'safe' => 'active']);
                    return $this->shape($r->successful() ? (array) ($r->json('items') ?? []) : [], 'title', 'link', 'snippet');
                default: // serper
                    $r = Http::timeout(8)->withHeaders(['X-API-KEY' => $key])->asJson()
                        ->post('https://google.serper.dev/search', ['q' => $q, 'num' => $num, 'hl' => $lang]);
                    return $this->shape($r->successful() ? (array) ($r->json('organic') ?? []) : [], 'title', 'link', 'snippet');
            }
        });
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
