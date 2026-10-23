<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * إعدادات STUN/TURN للمكالمات (config/calls.php). بترجع قايمة iceServers جاهزة لـ RTCPeerConnection.
 * أي فشل في مزوّد الـ TURN بيرجّع STUN بس (المكالمة تشتغل على الشبكات البسيطة بدل ما تفشل خالص).
 * الـ credentials مؤقتة دايمًا — المفتاح الثابت عمره ما بيوصل للمتصفح (إلا لو اخترت provider=static).
 */
class CallIceService
{
    /** @return array{ice_servers:array<int,array<string,mixed>>, ttl:int, provider:string} */
    public function forUser(int $userId): array
    {
        $provider = $this->provider();
        $cacheTtl = max(0, (int) config('calls.turn.cache', 600));

        if ($provider === 'none' || $cacheTtl === 0) {
            return $this->build($provider);
        }
        return Cache::remember("calls_ice_{$provider}_u{$userId}", $cacheTtl, fn () => $this->build($provider));
    }

    /** المزوّد الفعلي: المحدد صراحة، وإلا أول واحد مفاتيحه كاملة، وإلا none (STUN بس). */
    public function provider(): string
    {
        $cfg = (array) config('calls.turn', []);
        $explicit = (string) ($cfg['provider'] ?? '');
        if (in_array($explicit, ['cloudflare', 'metered', 'coturn', 'static'], true)) {
            return $explicit;
        }
        if (!empty($cfg['cloudflare']['key_id']) && !empty($cfg['cloudflare']['api_token'])) {
            return 'cloudflare';
        }
        if (!empty($cfg['metered']['app']) && !empty($cfg['metered']['api_key'])) {
            return 'metered';
        }
        if (!empty(config('calls.turn_urls')) && !empty($cfg['secret'])) {
            return 'coturn';
        }
        if (!empty(config('calls.turn_urls')) && !empty($cfg['username'])) {
            return 'static';
        }
        return 'none';
    }

    /** @return array{ice_servers:array<int,array<string,mixed>>, ttl:int, provider:string} */
    private function build(string $provider): array
    {
        $ttl = max(60, (int) config('calls.turn.ttl', 14400));
        $stun = $this->stunServers();
        try {
            $turn = match ($provider) {
                'cloudflare' => $this->cloudflare($ttl),
                'metered'    => $this->metered(),
                'coturn'     => $this->coturn($ttl),
                'static'     => $this->staticTurn(),
                default      => [],
            };
        } catch (\Throwable $e) {
            Log::warning('Calls: TURN provider failed, falling back to STUN only', ['provider' => $provider, 'error' => $e->getMessage()]);
            $turn = [];
            $provider = 'none';
        }
        return ['ice_servers' => array_merge($stun, $turn), 'ttl' => $ttl, 'provider' => $provider];
    }

    private function stunServers(): array
    {
        $urls = (array) config('calls.stun_urls', []);
        return $urls ? [['urls' => array_values($urls)]] : [];
    }

    /** Cloudflare Realtime TURN — POST .../credentials/generate-ice-servers {ttl} => {iceServers:[{urls,username?,credential?}]} */
    private function cloudflare(int $ttl): array
    {
        $cfg = (array) config('calls.turn.cloudflare', []);
        if (empty($cfg['key_id']) || empty($cfg['api_token'])) {
            throw new \RuntimeException('CF_TURN_KEY_ID / CF_TURN_API_TOKEN not set.');
        }
        $res = Http::withToken((string) $cfg['api_token'])->acceptJson()->timeout(6)
            ->post('https://rtc.live.cloudflare.com/v1/turn/keys/' . rawurlencode((string) $cfg['key_id']) . '/credentials/generate-ice-servers', ['ttl' => $ttl]);
        if (!$res->successful()) {
            throw new \RuntimeException('Cloudflare TURN HTTP ' . $res->status());
        }
        $servers = $res->json('iceServers');
        if (!is_array($servers) || !$servers) {
            throw new \RuntimeException('Cloudflare TURN returned no iceServers.');
        }
        // الـ STUN بتاعنا موجود بالفعل؛ نأخد من كلاودفلير بس اللي عليه credentials، ونشيل بورت 53 (المتصفحات بتحجبه وبيعمل timeout).
        $out = [];
        foreach ($servers as $s) {
            if (empty($s['username']) || empty($s['credential'])) {
                continue;
            }
            $urls = array_values(array_filter((array) ($s['urls'] ?? []), fn ($u) => is_string($u) && !preg_match('/:53(\?|$)/', $u)));
            if ($urls) {
                $out[] = ['urls' => $urls, 'username' => (string) $s['username'], 'credential' => (string) $s['credential']];
            }
        }
        if (!$out) {
            throw new \RuntimeException('Cloudflare TURN returned no TURN urls.');
        }
        return $out;
    }

    /** Metered — GET https://{app}.metered.live/api/v1/turn/credentials?apiKey=.. => [{urls,username?,credential?}] */
    private function metered(): array
    {
        $cfg = (array) config('calls.turn.metered', []);
        if (empty($cfg['app']) || empty($cfg['api_key'])) {
            throw new \RuntimeException('METERED_APP / METERED_API_KEY not set.');
        }
        $app = preg_replace('/[^a-zA-Z0-9\-]/', '', (string) $cfg['app']);
        $res = Http::acceptJson()->timeout(6)->get("https://{$app}.metered.live/api/v1/turn/credentials", ['apiKey' => $cfg['api_key']]);
        if (!$res->successful() || !is_array($res->json())) {
            throw new \RuntimeException('Metered TURN HTTP ' . $res->status());
        }
        $out = [];
        foreach ($res->json() as $s) {
            if (!empty($s['username']) && !empty($s['credential']) && !empty($s['urls'])) {
                $out[] = ['urls' => (array) $s['urls'], 'username' => (string) $s['username'], 'credential' => (string) $s['credential']];
            }
        }
        if (!$out) {
            throw new \RuntimeException('Metered TURN returned no credentials.');
        }
        return $out;
    }

    /** coturn (use-auth-secret): username = "{expiry}:{uid}", credential = base64(HMAC-SHA1(secret, username)). */
    private function coturn(int $ttl): array
    {
        $secret = (string) config('calls.turn.secret', '');
        $urls = (array) config('calls.turn_urls', []);
        if ($secret === '' || !$urls) {
            throw new \RuntimeException('TURN_URLS / TURN_SECRET not set.');
        }
        $username = (time() + $ttl) . ':uip-call';
        return [['urls' => array_values($urls), 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))]];
    }

    private function staticTurn(): array
    {
        $urls = (array) config('calls.turn_urls', []);
        $user = (string) config('calls.turn.username', '');
        $cred = (string) config('calls.turn.credential', '');
        if (!$urls || $user === '') {
            throw new \RuntimeException('TURN_URLS / TURN_USERNAME not set.');
        }
        return [['urls' => array_values($urls), 'username' => $user, 'credential' => $cred]];
    }
}
