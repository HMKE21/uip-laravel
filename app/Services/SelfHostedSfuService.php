<?php

namespace App\Services;

use App\Contracts\SfuProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * بروكسي رفيع فوق سيرفر mediasoup بتاعنا (مشروع uipe-sfu). السر (SFU_SELFHOSTED_SECRET) بيفضل هنا بس،
 * والمتصفح مابيكلمش السيرفر ده مباشرة أبدًا — كل حاجة بتعدّي على MeetingsMediasoupApiController اللي بيتأكد الأول
 * إن الطالب جوه الاجتماع.
 *
 * كل ميثود بترجع ['ok'=>bool, 'status'=>int, 'data'=>array] ومابترميش exception (نفس عقد CloudflareSfuService).
 * مفيش إعادة محاولة تلقائية: كل العمليات (produce/consume/connect) مش آمنة تتكرر.
 */
class SelfHostedSfuService implements SfuProvider
{
    public function name(): string
    {
        return 'selfhosted';
    }

    /** الـ URL والسر موجودين (من غير ما نبص على زرار التشغيل في اللوحة). */
    public function configured(): bool
    {
        return $this->baseUrl() !== '' && $this->secret() !== '';
    }

    public function enabled(): bool
    {
        return MeetingAdminSettings::sfu()['enabled'] && $this->configured();
    }

    /** اسم الغرفة على سيرفر الـ SFU (حروف آمنة بس، أقل من 64). */
    public static function roomId(string $meetingUuid): string
    {
        return 'm_' . preg_replace('/[^A-Za-z0-9_-]/', '', $meetingUuid);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    public function ping(): array
    {
        if ($this->baseUrl() === '') {
            return ['ok' => false, 'status' => 503, 'data' => ['error' => 'sfu_disabled']];
        }
        try {
            $res = Http::acceptJson()->timeout(3)->get($this->baseUrl() . '/health');
            $data = $res->json();
            $ok = $res->successful() && is_array($data) && ($data['ok'] ?? false) === true;

            return ['ok' => $ok, 'status' => $res->status(), 'data' => is_array($data) ? $data : []];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 504, 'data' => ['error' => 'sfu_unreachable', 'errorDescription' => mb_substr($e->getMessage(), 0, 200)]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 502, 'data' => ['error' => 'sfu_error', 'errorDescription' => mb_substr($e->getMessage(), 0, 200)]];
        }
    }

    /**
     * اختبار كامل للأدمن: /health (من غير سر) + إنشاء session تجريبية وقفلها (بيتحقق إن السر صح).
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function test(): array
    {
        $p = $this->ping();
        if (!$p['ok']) {
            return $p;
        }
        $room = self::roomId('admin-test');
        $r = $this->createSession($room, 'ms_admin_test');
        $this->leave($room, 'ms_admin_test');

        return $r;
    }

    // ------------------------------------------------------------------ media operations

    /** @return array{ok:bool,status:int,data:array<string,mixed>} {routerRtpCapabilities, sendTransport, recvTransport} */
    public function createSession(string $roomId, string $peerId): array
    {
        return $this->call('rooms/' . rawurlencode($roomId) . '/sessions', ['peerId' => $peerId]);
    }

    /** @param array<string,mixed> $dtlsParameters */
    public function connectTransport(string $roomId, string $peerId, string $transportId, array $dtlsParameters): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/transports/' . rawurlencode($transportId) . '/connect', ['dtlsParameters' => $dtlsParameters]);
    }

    /** ICE restart (تغيّر الشبكة): بيرجّع iceParameters جديدة. */
    public function restartIce(string $roomId, string $peerId, string $transportId): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/transports/' . rawurlencode($transportId) . '/restart-ice');
    }

    /** @param array<string,mixed> $rtpParameters */
    public function produce(string $roomId, string $peerId, string $transportId, string $kind, array $rtpParameters): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/produce', [
            'transportId'   => $transportId,
            'kind'          => $kind,
            'rtpParameters' => $rtpParameters,
        ]);
    }

    /** @param array<string,mixed> $rtpCapabilities */
    public function consume(string $roomId, string $peerId, string $transportId, string $producerId, array $rtpCapabilities): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/consume', [
            'transportId'     => $transportId,
            'producerId'      => $producerId,
            'rtpCapabilities' => $rtpCapabilities,
        ]);
    }

    public function resumeConsumer(string $roomId, string $peerId, string $consumerId): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/consumers/' . rawurlencode($consumerId) . '/resume');
    }

    public function closeProducer(string $roomId, string $peerId, string $producerId): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/producers/' . rawurlencode($producerId) . '/close');
    }

    public function leave(string $roomId, string $peerId): array
    {
        return $this->call($this->peerPath($roomId, $peerId) . '/leave');
    }

    // ------------------------------------------------------------------ internals

    private function peerPath(string $roomId, string $peerId): string
    {
        return 'rooms/' . rawurlencode($roomId) . '/peers/' . rawurlencode($peerId);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('webrtc.selfhosted.url', ''), '/');
    }

    private function secret(): string
    {
        return (string) config('webrtc.selfhosted.secret', '');
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    private function call(string $path, array $body = []): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 503, 'data' => ['error' => 'sfu_disabled']];
        }

        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        try {
            $req = Http::withHeaders(['x-internal-secret' => $this->secret()])
                ->acceptJson()
                ->timeout((int) config('webrtc.selfhosted.timeout', 10));

            // body فاضي = "{}" صريح (express.json بيقبله)، مش [] اللي لارافيل ممكن يبعتها بشكل مختلف.
            $res = $body === []
                ? $req->withBody('{}', 'application/json')->post($url)
                : $req->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            Log::warning('Self-hosted SFU unreachable', ['path' => $path, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return ['ok' => false, 'status' => 504, 'data' => ['error' => 'sfu_unreachable', 'errorDescription' => mb_substr($e->getMessage(), 0, 200)]];
        } catch (\Throwable $e) {
            Log::warning('Self-hosted SFU call failed', ['path' => $path, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return ['ok' => false, 'status' => 502, 'data' => ['error' => 'sfu_error', 'errorDescription' => mb_substr($e->getMessage(), 0, 200)]];
        }

        $data = $res->json();
        if (!$res->successful()) {
            Log::warning('Self-hosted SFU non-2xx', ['path' => $path, 'status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 300)]);
        }

        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => is_array($data) ? $data : []];
    }
}
