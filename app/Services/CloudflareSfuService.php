<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * بروكسي رفيع فوق Cloudflare Realtime SFU HTTPS API. السر (CF_SFU_APP_SECRET) بيفضل هنا بس.
 *
 *   POST {base}/apps/{app}/sessions/new
 *   POST {base}/apps/{app}/sessions/{session}/tracks/new        (push local / pull remote)
 *   PUT  {base}/apps/{app}/sessions/{session}/renegotiate
 *   PUT  {base}/apps/{app}/sessions/{session}/tracks/close
 *
 * ⚠️ مكتوب من معرفة بالـ API (صفحة التوثيق ماكانتش متاحة وقت الكتابة): راجع
 * https://developers.cloudflare.com/realtime/sfu/https-api/ قبل الإنتاج، خصوصًا شكل ردّ tracks/close.
 * كل ميثود بترجع ['ok'=>bool, 'status'=>int, 'data'=>array] ومابترميش exception، عشان الكنترولر يرد 502 نضيف.
 */
class CloudflareSfuService
{
    public function enabled(): bool
    {
        $c = MeetingAdminSettings::sfu(); // اللوحة (Admin → Meetings Control → Video) تغلب الـ env

        return $c['enabled'] && $c['app_id'] !== '' && $c['app_secret'] !== '';
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function newSession(): array
    {
        return $this->call('POST', 'sessions/new', []);
    }

    /** @param array<string,mixed> $body */
    public function tracksNew(string $sessionId, array $body): array
    {
        return $this->call('POST', "sessions/{$sessionId}/tracks/new", $body);
    }

    /** @param array<string,mixed> $body */
    public function renegotiate(string $sessionId, array $body): array
    {
        return $this->call('PUT', "sessions/{$sessionId}/renegotiate", $body);
    }

    /** @param array<string,mixed> $body */
    public function tracksClose(string $sessionId, array $body): array
    {
        return $this->call('PUT', "sessions/{$sessionId}/tracks/close", $body);
    }

    /** @param array<string,mixed> $body */
    private function call(string $method, string $path, array $body): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'status' => 503, 'data' => ['error' => 'sfu_disabled']];
        }

        $c = MeetingAdminSettings::sfu();
        $url = config('webrtc.sfu.base_url') . '/apps/' . rawurlencode($c['app_id']) . '/' . $path;

        try {
            $req = Http::withToken($c['app_secret'])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('webrtc.sfu.timeout', 8));
            $res = $method === 'PUT' ? $req->put($url, $body) : $req->post($url, $body ?: (object) []);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 504, 'data' => ['error' => 'sfu_unreachable']];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Meetings SFU call failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 502, 'data' => ['error' => 'sfu_error', 'errorDescription' => mb_substr($e->getMessage(), 0, 200)]];
        }

        $data = $res->json();
        if (!$res->successful()) {
            \Illuminate\Support\Facades\Log::warning('Meetings SFU non-2xx', ['path' => $path, 'status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 500)]);
        }

        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => is_array($data) ? $data : []];
    }
}
