<?php

namespace App\Services\Developer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Webhooks: تسجيل، توقيع HMAC، تسليم مع إعادة محاولة (backoff)، حماية SSRF، تعطيل تلقائي، وإعادة إرسال. */
class WebhookService
{
    public function __construct(private DevLogService $logs, private DeveloperAppService $apps)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------ SSRF */

    public function publicIp(string $ip): bool
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
            if ($bin !== false && (ord($bin[0]) === 0x20 && ord($bin[1]) === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8)) { // 2001:db8::/32 (توثيق)
                return false;
            }
        }
        return true;
    }

    /** @return string[] */
    private function resolve(string $host): array
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return [trim($host, '[]')];
        }
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

    /**
     * يتحقق من الرابط (https، من غير credentials، مش localhost، ومفيش IP خاص/داخلي في أي من نتايج الـ DNS).
     * @return array{host:string,port:int,ip:?string,url:string}
     */
    public function assertSafeUrl(string $url): array
    {
        $url = trim($url);
        $p = parse_url($url);
        if ($url === '' || strlen($url) > 500 || !$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\s\x00-\x1f]/', $url)) {
            $this->fail('Invalid webhook URL.');
        }
        $scheme = strtolower($p['scheme']);
        $allowHttp = (bool) config('developer.webhooks.allow_http');
        if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
            $this->fail('Webhook URLs must use https.');
        }
        $host = strtolower(trim((string) $p['host'], '[]'));
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            $this->fail('Invalid webhook port.');
        }
        $allowPrivate = (bool) config('developer.webhooks.allow_private');
        if (!$allowPrivate && ($host === 'localhost' || preg_match('/\.(local|localhost|internal|lan|home|intranet)$/', $host))) {
            $this->fail('Webhook URLs must point to a public host.');
        }
        $ips = $this->resolve($host);
        if (!$ips) {
            $this->fail('Could not resolve the webhook host.');
        }
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (!$this->publicIp($ip)) {
                    $this->fail('Webhook URLs must point to a public host.');
                }
            }
        }
        $v4 = array_values(array_filter($ips, fn ($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        $isLiteral = (bool) filter_var($host, FILTER_VALIDATE_IP);
        return ['host' => $host, 'port' => $port, 'ip' => $isLiteral ? null : ($v4[0] ?? $ips[0]), 'url' => $url];
    }

    /* ------------------------------------------------------------ CRUD */

    private function secretOf(object $h): string
    {
        try {
            return Crypt::decryptString((string) $h->secret_enc);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function payload(object $h, ?string $secret = null): array
    {
        $out = [
            'id' => (int) $h->id, 'name' => $h->name, 'url' => $h->url, 'events' => (array) json_decode((string) $h->events, true), 'mode' => $h->mode,
            'app_id' => $h->app_id ? (int) $h->app_id : null, 'mail_account_id' => $h->mail_account_id ? (int) $h->mail_account_id : null,
            'is_active' => (bool) $h->is_active, 'failure_count' => (int) $h->failure_count, 'last_status' => $h->last_status, 'last_delivery_at' => DevTokens::iso($h->last_delivery_at),
            'disabled_at' => DevTokens::iso($h->disabled_at), 'disabled_reason' => $h->disabled_reason, 'created_at' => DevTokens::iso($h->created_at),
        ];
        if ($secret !== null) {
            $out['secret'] = $secret; // بيظهر مرة واحدة بس
        }
        return $out;
    }

    private function assertAllowed(int $userId): array
    {
        if (!config('developer.enabled')) {
            $this->fail('The Developer Center is disabled.', 503);
        }
        $p = $this->apps->policyFor($userId);
        if (!$p['apps_enabled']) {
            $this->fail($p['access']['message'] ?? 'Developer access is not available for your account.', 403);
        }
        if (!$p['webhooks_enabled']) {
            $this->fail('Webhooks are turned off for your account.', 403);
        }
        return $p;
    }

    public function list(int $userId): array
    {
        return DB::table('dev_webhooks')->where('user_id', $userId)->orderByDesc('id')->get()->map(fn ($h) => $this->payload($h))->values()->all();
    }

    public function own(int $userId, int $id): object
    {
        $h = DB::table('dev_webhooks')->where('id', $id)->where('user_id', $userId)->first();
        if (!$h) {
            $this->fail('Webhook not found.', 404);
        }
        return $h;
    }

    private function cleanEvents($in, ?array $allowed = null): array
    {
        $ev = array_values(array_unique(array_filter(array_map(fn ($e) => trim((string) $e), (array) $in), fn ($e) => $e !== '')));
        if (!$ev) {
            $this->fail('Choose at least one event.');
        }
        $known = array_keys((array) config('developer.events', []));
        if ($bad = array_diff($ev, $known)) {
            $this->fail('Unknown event: ' . implode(', ', $bad));
        }
        if ($allowed !== null && ($denied = array_diff($ev, $allowed, ['webhook.test']))) {
            $this->fail('These events are not allowed for your account: ' . implode(', ', $denied), 403);
        }
        return $ev;
    }

    public function create(int $userId, array $in, ?int $appId = null): array
    {
        $policy = $this->assertAllowed($userId);
        if (DB::table('dev_webhooks')->where('user_id', $userId)->count() >= (int) $policy['max_webhooks_per_user']) {
            $this->fail('You reached the maximum number of webhooks.', 403);
        }
        $this->assertSafeUrl((string) ($in['url'] ?? ''));
        $mode = (string) ($in['mode'] ?? ($policy['sandbox_only'] ? 'sandbox' : 'live'));
        if (!in_array($mode, ['live', 'sandbox'], true)) {
            $this->fail('Invalid mode.');
        }
        if ($mode === 'live' && $policy['sandbox_only']) {
            $this->fail('Your account can only create sandbox (test) webhooks.', 403);
        }
        $appId = $appId ?: (!empty($in['app_id']) ? (int) $in['app_id'] : null);
        if ($appId && !DB::table('dev_apps')->where('id', $appId)->where('owner_user_id', $userId)->exists()) {
            $this->fail('App not found.', 404);
        }
        $accId = !empty($in['mail_account_id']) ? (int) $in['mail_account_id'] : null;
        if ($accId && !DB::table('mail_accounts')->where('id', $accId)->where('user_id', $userId)->exists()) {
            $this->fail('Mailbox not found.', 404);
        }
        $secret = DevTokens::make('whsec_', 40);
        $id = DB::table('dev_webhooks')->insertGetId([
            'user_id' => $userId, 'app_id' => $appId, 'mail_account_id' => $accId, 'name' => isset($in['name']) ? Str::limit(trim((string) $in['name']), 80, '') : null,
            'url' => trim((string) $in['url']), 'secret_enc' => Crypt::encryptString($secret), 'events' => json_encode($this->cleanEvents($in['events'] ?? [], $policy['allowed_events'])),
            'mode' => $mode, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->payload(DB::table('dev_webhooks')->find($id), $secret);
    }

    public function update(int $userId, int $id, array $in): array
    {
        $policy = $this->assertAllowed($userId);
        $h = $this->own($userId, $id);
        $upd = ['updated_at' => now()];
        if (array_key_exists('name', $in)) {
            $upd['name'] = $in['name'] !== null ? Str::limit(trim((string) $in['name']), 80, '') : null;
        }
        if (array_key_exists('url', $in)) {
            $this->assertSafeUrl((string) $in['url']);
            $upd['url'] = trim((string) $in['url']);
        }
        if (array_key_exists('events', $in)) {
            $upd['events'] = json_encode($this->cleanEvents($in['events'], $policy['allowed_events']));
        }
        if (array_key_exists('mail_account_id', $in)) {
            $accId = $in['mail_account_id'] ? (int) $in['mail_account_id'] : null;
            if ($accId && !DB::table('mail_accounts')->where('id', $accId)->where('user_id', $userId)->exists()) {
                $this->fail('Mailbox not found.', 404);
            }
            $upd['mail_account_id'] = $accId;
        }
        DB::table('dev_webhooks')->where('id', $h->id)->update($upd);
        return $this->payload(DB::table('dev_webhooks')->find($h->id));
    }

    public function setActive(int $userId, int $id, bool $active): array
    {
        $this->assertAllowed($userId);
        $h = $this->own($userId, $id);
        if ($active) {
            if ($h->disabled_reason === 'Disabled by an administrator') {
                $this->fail('This webhook was disabled by an administrator.', 403);
            }
            $this->assertSafeUrl((string) $h->url);
            DB::table('dev_webhooks')->where('id', $h->id)->update(['is_active' => 1, 'failure_count' => 0, 'disabled_at' => null, 'disabled_reason' => null, 'updated_at' => now()]);
        } else {
            DB::table('dev_webhooks')->where('id', $h->id)->update(['is_active' => 0, 'disabled_at' => now(), 'disabled_reason' => 'Paused by the owner', 'updated_at' => now()]);
        }
        return $this->payload(DB::table('dev_webhooks')->find($h->id));
    }

    public function rotateSecret(int $userId, int $id): array
    {
        $h = $this->own($userId, $id);
        $secret = DevTokens::make('whsec_', 40);
        DB::table('dev_webhooks')->where('id', $h->id)->update(['secret_enc' => Crypt::encryptString($secret), 'updated_at' => now()]);
        return $this->payload(DB::table('dev_webhooks')->find($h->id), $secret);
    }

    public function delete(int $userId, int $id): void
    {
        $h = $this->own($userId, $id);
        DB::transaction(function () use ($h) {
            DB::table('dev_webhook_deliveries')->where('webhook_id', $h->id)->delete();
            DB::table('dev_webhooks')->where('id', $h->id)->delete();
        });
    }

    public function deliveries(int $userId, int $webhookId, int $page = 1, int $per = 25): array
    {
        $h = $this->own($userId, $webhookId);
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_webhook_deliveries')->where('webhook_id', $h->id);
        $total = (clone $q)->count();
        $items = $q->orderByDesc('id')->forPage($page, $per)->get()->map(fn ($d) => $this->deliveryPayload($d))->values()->all();
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    private function deliveryPayload(object $d, bool $withBody = false): array
    {
        $out = ['id' => (int) $d->id, 'event_id' => $d->event_id, 'event' => $d->event, 'status' => $d->status, 'attempts' => (int) $d->attempts, 'next_attempt_at' => DevTokens::iso($d->next_attempt_at),
            'response_code' => $d->response_code !== null ? (int) $d->response_code : null, 'response_ms' => $d->response_ms !== null ? (int) $d->response_ms : null,
            'response_excerpt' => $d->response_excerpt, 'error' => $d->error, 'delivered_at' => DevTokens::iso($d->delivered_at), 'created_at' => DevTokens::iso($d->created_at)];
        if ($withBody) {
            $out['payload'] = json_decode((string) $d->payload, true);
        }
        return $out;
    }

    public function delivery(int $userId, int $deliveryId): array
    {
        $d = DB::table('dev_webhook_deliveries as d')->join('dev_webhooks as w', 'w.id', '=', 'd.webhook_id')->where('d.id', $deliveryId)->where('w.user_id', $userId)->first(['d.*']);
        if (!$d) {
            $this->fail('Delivery not found.', 404);
        }
        return $this->deliveryPayload($d, true);
    }

    /* ------------------------------------------------------------ admin (الجامعة / أدمن المنصة) */

    /** @param array{university_id?:int,q?:string,active?:mixed,user_id?:int} $f */
    public function adminList(array $f, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_webhooks as w')->leftJoin('users as u', 'u.id', '=', 'w.user_id');
        if (!empty($f['university_id'])) {
            $q->whereIn('w.user_id', $this->apps->ownerIdsOfUniversity((int) $f['university_id']) ?: [0]);
        }
        if (!empty($f['user_id'])) {
            $q->where('w.user_id', (int) $f['user_id']);
        }
        if (isset($f['active']) && $f['active'] !== '' && $f['active'] !== null) {
            $q->where('w.is_active', filter_var($f['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $q->where(fn ($w) => $w->where('w.name', 'like', $like)->orWhere('w.url', 'like', $like)->orWhere('u.email', 'like', $like)->orWhere('u.full_name', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('w.id')->forPage($page, $per)->get(['w.*', 'u.full_name as owner_name', 'u.email as owner_email'])
            ->map(fn ($h) => $this->payload($h) + ['user_id' => (int) $h->user_id, 'owner_name' => $h->owner_name, 'owner_email' => $h->owner_email])->values()->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    /** الإدارة توقف/تشغّل webhook. اللي الإدارة وقفته المالك مينفعش يشغّله (disabled_reason بيبيّن ده). */
    public function adminSetActive(int $id, ?int $universityId, bool $active): array
    {
        $h = DB::table('dev_webhooks')->where('id', $id)->when($universityId, fn ($q) => $q->whereIn('user_id', $this->apps->ownerIdsOfUniversity($universityId) ?: [0]))->first();
        if (!$h) {
            $this->fail('Webhook not found.', 404);
        }
        if ($active) {
            $this->assertSafeUrl((string) $h->url);
            DB::table('dev_webhooks')->where('id', $h->id)->update(['is_active' => 1, 'failure_count' => 0, 'disabled_at' => null, 'disabled_reason' => null, 'updated_at' => now()]);
        } else {
            DB::table('dev_webhooks')->where('id', $h->id)->update(['is_active' => 0, 'disabled_at' => now(), 'disabled_reason' => 'Disabled by an administrator', 'updated_at' => now()]);
        }
        return $this->payload(DB::table('dev_webhooks')->find($h->id)) + ['user_id' => (int) $h->user_id];
    }

    /* ------------------------------------------------------------ enqueue / deliver */

    public function enqueue(object $hook, string $event, array $data, ?string $eventId = null): int
    {
        $eventId = $eventId ?: (string) Str::uuid();
        $body = ['id' => $eventId, 'type' => $event, 'api_version' => (string) config('developer.api_version'), 'created_at' => now()->toIso8601String(), 'mode' => $hook->mode, 'data' => $data];
        $id = (int) DB::table('dev_webhook_deliveries')->insertGetId(['webhook_id' => $hook->id, 'event_id' => $eventId, 'event' => $event, 'payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending', 'attempts' => 0, 'created_at' => now()]);
        $this->dispatch($id);
        return $id;
    }

    private function dispatch(int $deliveryId): void
    {
        try {
            if (config('queue.default') === 'sync') {
                \App\Jobs\DeliverWebhookJob::dispatchAfterResponse($deliveryId);
            } else {
                \App\Jobs\DeliverWebhookJob::dispatch($deliveryId)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('Webhook dispatch failed', ['delivery' => $deliveryId, 'error' => $e->getMessage()]);
        }
    }

    /** ping تجريبي (بيتسلّم فورًا ومتزامن عشان نرجّع النتيجة للمطوّر). */
    public function test(int $userId, int $id): array
    {
        $this->assertAllowed($userId);
        $h = $this->own($userId, $id);
        $eventId = (string) Str::uuid();
        $body = ['id' => $eventId, 'type' => 'webhook.test', 'api_version' => (string) config('developer.api_version'), 'created_at' => now()->toIso8601String(), 'mode' => $h->mode, 'data' => ['message' => 'This is a test event from UIPE.']];
        $did = (int) DB::table('dev_webhook_deliveries')->insertGetId(['webhook_id' => $h->id, 'event_id' => $eventId, 'event' => 'webhook.test', 'payload' => json_encode($body), 'status' => 'pending', 'attempts' => 0, 'created_at' => now()]);
        $this->deliver($did, true);
        return $this->deliveryPayload(DB::table('dev_webhook_deliveries')->find($did), true);
    }

    public function redeliver(int $userId, int $deliveryId): array
    {
        $this->assertAllowed($userId);
        $d = DB::table('dev_webhook_deliveries as d')->join('dev_webhooks as w', 'w.id', '=', 'd.webhook_id')->where('d.id', $deliveryId)->where('w.user_id', $userId)->first(['d.*']);
        if (!$d) {
            $this->fail('Delivery not found.', 404);
        }
        $id = (int) DB::table('dev_webhook_deliveries')->insertGetId(['webhook_id' => $d->webhook_id, 'event_id' => $d->event_id, 'event' => $d->event, 'payload' => $d->payload, 'status' => 'pending', 'attempts' => 0, 'created_at' => now()]);
        $this->deliver($id, true);
        return $this->deliveryPayload(DB::table('dev_webhook_deliveries')->find($id));
    }

    public function sign(string $secret, int $ts, string $body): string
    {
        return WebhookSignature::sign($secret, $ts, $body);
    }

    /**
     * أداة تشخيص للمالك: بيلصق الـ raw body وهيدر X-UIPE-Signature اللي وصله، ونتحقق بـ secret الـ webhook (الـ secret مبيسيبش السيرفر).
     * بترجّع السبب، وعمر التوقيع، وهل نفس الـ event id اتفحص قبل كده جوه النافذة (replayed).
     */
    public function checkSignature(int $userId, int $id, string $payload, string $signature, ?int $tolerance = null): array
    {
        $this->assertAllowed($userId);
        $h = $this->own($userId, $id);
        $tol = $tolerance !== null && $tolerance > 0 ? min($tolerance, 3600) : WebhookSignature::tolerance();
        $secret = $this->secretOf($h);
        $res = WebhookSignature::inspect($payload, $signature, $secret, $tol);
        $eventId = null;
        $json = json_decode($payload, true);
        if (is_array($json) && isset($json['id']) && is_string($json['id']) && $json['id'] !== '') {
            $eventId = Str::limit($json['id'], 64, '');
        }
        $replayed = false;
        if ($res['valid'] && $eventId !== null) {
            $replayed = !WebhookReplayGuard::firstSeen('verify:' . $h->id, $eventId, $tol * 2);
        }
        return [
            'valid' => $res['valid'], 'reason' => $res['reason'], 'message' => WebhookSignature::explain($res['reason']),
            'timestamp' => $res['timestamp'], 'age_seconds' => $res['age_seconds'], 'tolerance_seconds' => $res['tolerance_seconds'],
            'event_id' => $eventId, 'replayed' => $replayed,
        ];
    }

    /** يسلّم محاولة واحدة. $manual = تسليم يدوي (اختبار/إعادة إرسال): مبيحسبش على فشل الـ webhook ولا بيعمل retry. */
    public function deliver(int $deliveryId, bool $manual = false): void
    {
        $d = DB::table('dev_webhook_deliveries')->where('id', $deliveryId)->first();
        if (!$d || !in_array($d->status, ['pending', 'retrying'], true)) {
            return;
        }
        $h = DB::table('dev_webhooks')->where('id', $d->webhook_id)->first();
        if (!$h || (!$h->is_active && !$manual)) {
            DB::table('dev_webhook_deliveries')->where('id', $d->id)->update(['status' => 'failed', 'error' => 'Webhook is disabled or deleted.']);
            return;
        }
        $attempt = (int) $d->attempts + 1;
        $ms = null; $code = null; $excerpt = null; $err = null; $ok = false;
        try {
            $safe = $this->assertSafeUrl((string) $h->url);
            $secret = $this->secretOf($h);
            if ($secret === '') {
                throw new \RuntimeException('Webhook secret is unreadable. Rotate the secret.');
            }
            $ts = time(); // بيتحدد وقت الإرسال الفعلي (مش وقت الـ enqueue)، فتأخير الـ queue ومحاولات الـ retry كلها بتتوقّع بتوقيت جديد
            $body = (string) $d->payload;
            $opts = ['allow_redirects' => false, 'connect_timeout' => 5, 'http_errors' => false];
            if ($safe['ip'] && defined('CURLOPT_RESOLVE')) { // نثبّت الـ IP اللي اتفحص (ضد DNS rebinding)
                $ip = str_contains($safe['ip'], ':') ? '[' . $safe['ip'] . ']' : $safe['ip'];
                $opts['curl'] = [CURLOPT_RESOLVE => ["{$safe['host']}:{$safe['port']}:{$ip}"]];
            }
            $t0 = microtime(true);
            $res = Http::withOptions($opts)->timeout((int) config('developer.webhooks.timeout', 8))->withHeaders([
                'User-Agent' => 'UIPE-Webhooks/1.0', 'Content-Type' => 'application/json', 'X-UIPE-Event' => $d->event, 'X-UIPE-Event-Id' => $d->event_id,
                'X-UIPE-Delivery' => (string) $d->id, WebhookSignature::HEADER => WebhookSignature::header($secret, $body, $ts),
            ])->withBody($body, 'application/json')->post($safe['url']);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $code = $res->status();
            $excerpt = Str::limit(preg_replace('/[^\P{C}\n]+/u', '', (string) $res->body()) ?? '', (int) config('developer.webhooks.excerpt_bytes', 500), '');
            $ok = $code >= 200 && $code < 300;
            if (!$ok) {
                $err = $code >= 300 && $code < 400 ? 'Redirects are not followed.' : 'The endpoint answered HTTP ' . $code . '.';
            }
        } catch (\DomainException $e) {
            $err = $e->getMessage();
        } catch (\Throwable $e) {
            $err = Str::limit('Delivery failed: ' . preg_replace('/\s+/', ' ', $e->getMessage()), 190, '');
        }

        if ($ok) {
            DB::table('dev_webhook_deliveries')->where('id', $d->id)->update(['status' => 'success', 'attempts' => $attempt, 'response_code' => $code, 'response_ms' => $ms, 'response_excerpt' => $excerpt, 'error' => null, 'next_attempt_at' => null, 'delivered_at' => now()]);
            if (!$manual) {
                DB::table('dev_webhooks')->where('id', $h->id)->update(['failure_count' => 0, 'last_status' => 'success', 'last_delivery_at' => now()]);
            }
            return;
        }

        $max = (int) config('developer.webhooks.max_attempts', 6);
        $backoff = (array) config('developer.webhooks.backoff', [60, 300, 1800, 7200, 21600]);
        $final = $manual || $attempt >= $max;
        DB::table('dev_webhook_deliveries')->where('id', $d->id)->update([
            'status' => $final ? 'failed' : 'retrying', 'attempts' => $attempt, 'response_code' => $code, 'response_ms' => $ms, 'response_excerpt' => $excerpt, 'error' => Str::limit((string) $err, 190, ''),
            'next_attempt_at' => $final ? null : now()->addSeconds((int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 3600)),
        ]);
        $this->logs->record(['user_id' => $h->user_id, 'app_id' => $h->app_id, 'kind' => 'webhook', 'method' => 'POST', 'path' => 'webhook#' . $h->id, 'status' => $code, 'duration_ms' => $ms, 'note' => Str::limit($d->event . ' · attempt ' . $attempt . ' · ' . $err, 190, '')]);
        if ($manual) {
            return;
        }
        $fails = (int) $h->failure_count + ($final ? 1 : 0);
        DB::table('dev_webhooks')->where('id', $h->id)->update(['failure_count' => $fails, 'last_status' => 'failed', 'last_delivery_at' => now()]);
        if ($final && $fails >= (int) config('developer.webhooks.disable_after', 20)) {
            $this->autoDisable($h);
        }
    }

    private function autoDisable(object $h): void
    {
        $n = DB::table('dev_webhooks')->where('id', $h->id)->where('is_active', 1)->update(['is_active' => 0, 'disabled_at' => now(), 'disabled_reason' => 'Too many consecutive failures', 'updated_at' => now()]);
        if ($n) {
            try {
                app(\App\Services\NotificationService::class)->notify((int) $h->user_id, 'developer_webhook_disabled', 'تم إيقاف Webhook تلقائيًا',
                    'الـ Webhook ' . ($h->name ?: $h->url) . ' فشل مرات كتير ورا بعض فاتوقف. صلّح الـ endpoint وفعّله تاني من مركز المطورين.', null, 'high');
            } catch (\Throwable $e) {
                Log::warning('Webhook disable notice failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /** المحاولات المستحقة (scheduler كل دقيقة). */
    public function retryDue(int $limit = 100): int
    {
        $ids = DB::table('dev_webhook_deliveries')->where('status', 'retrying')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) {
            $claimed = DB::table('dev_webhook_deliveries')->where('id', $id)->where('status', 'retrying')->update(['next_attempt_at' => now()->addMinutes(5)]); // حجز: عامل تاني مياخدهاش
            if ($claimed) {
                $this->deliver((int) $id);
            }
        }
        return count($ids);
    }

    /** pending اتعلّقت (الـ queue وقف) أكتر من 5 دقايق → نبعتها. */
    public function sweepStuck(int $limit = 100): int
    {
        $ids = DB::table('dev_webhook_deliveries')->where('status', 'pending')->where('created_at', '<', now()->subMinutes(5))->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) {
            $this->deliver((int) $id);
        }
        return count($ids);
    }
}
