<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingAdminSettings;
use App\Services\MeetingSignalingService;
use App\Services\MeetingTransportService;
use App\Services\SelfHostedSfuService;
use App\Services\SfuManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * سطح /api/v1/meetings/{uuid}/sfu/ms/* — بروكسي آمن بين المتصفح (mediasoup-client) وسيرفر mediasoup بتاعنا.
 * نفس فلسفة MeetingsSfuApiController: كل endpoint بيتأكد الأول إن الطالب جوه الاجتماع (resolveActor) وإن الـ session
 * بتاعته، وإن اللي بيسحبه (producerId) تابع لمشارك تاني في *نفس* الاجتماع. سر السيرفر مابيسيبش لارافيل أبدًا.
 *
 * التدفق (mediasoup-client):
 *   1) POST session                      → rtpCapabilities + sendTransport + recvTransport + ice_servers
 *   2) POST sessions/{id}/transports/{t}/connect {dtlsParameters}   (لما transport.on('connect'))
 *   3) POST sessions/{id}/produce {transportId, kind, rtpParameters, source}  (لما transport.on('produce')) → {id}
 *   4) POST sessions/{id}/consume {transportId, producerId, rtpCapabilities}  → {id, producerId, kind, rtpParameters}
 *      POST sessions/{id}/consumers/{c}/resume                      (بعد ما الـ consumer يجهز عند العميل)
 *   5) POST sessions/{id}/producers/{p}/close   (إيقاف كاميرا/مايك/مشاركة شاشة)
 *   6) POST sfu/leave (المشترك مع Cloudflare)   → بيحرّر الـ peer على السيرفر كمان
 * قايمة الناشرين (GET sfu/peers) بترجع نفس الشكل: trackName = producerId، وفيه source (mic|camera|screen|screen_audio).
 */
class MeetingsMediasoupApiController extends Controller
{
    /** peerId اللي لارافيل بيولّده: ms_ + 24 حرف/رقم. */
    private const SESSION_RE = '/^ms_[A-Za-z0-9]{24}$/';
    /** معرّفات mediasoup (transport/producer/consumer) كلها UUID. */
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_TRACKS_PER_PEER = 6; // مايك + كاميرا + شاشة + صوت الشاشة + هامش
    private const MAX_PARAMS_BYTES = 40000;

    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingTransportService $transport,
        private SfuManager $manager,
        private SelfHostedSfuService $sfu
    ) {
    }

    private function authUserId(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id !== null ? (int) $id : null;
    }

    /** @return array{0:mixed,1:array}|JsonResponse [meeting, actor] */
    private function context(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }
        try {
            $actor = $this->signaling->resolveActor($meeting, $this->authUserId($request), $request->input('guest_token'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }
        // مزوّد الغرفة (متثبّت لكل اجتماع + failover تلقائي). لو اتحوّلت لـ Cloudflare العميل يشوف provider في الـ 409 ويتحوّل.
        $provider = $this->transport->providerFor($meeting);
        if ($provider !== 'selfhosted' || !$this->sfu->enabled()) {
            return $this->apiError('The self-hosted SFU is not active for this meeting.', ['code' => 'sfu_provider_inactive', 'provider' => $provider], 409);
        }

        return [$meeting, $actor];
    }

    private function owned($meeting, array $actor, string $sessionId): bool
    {
        return preg_match(self::SESSION_RE, $sessionId) === 1
            && $this->transport->ownsSession($meeting, $actor['key'], $sessionId);
    }

    private function tooBig(mixed $value): bool
    {
        return strlen((string) json_encode($value)) > self::MAX_PARAMS_BYTES;
    }

    private function upstream(array $r, string $what): JsonResponse
    {
        if (!$r['ok']) {
            // 401 من السيرفر = مشكلة إعداد عندنا (السر غلط)، مايتبعتش للمتصفح كـ 401 عشان مايتفسرش كإن المستخدم اتفصل.
            $s = (int) $r['status'];
            $http = in_array($s, [400, 404, 503, 504], true) ? $s : 502;

            return $this->apiError("SFU {$what} failed.", [
                'code'            => $r['data']['error'] ?? 'sfu_error',
                'upstream_status' => $s,
                'upstream_detail' => mb_substr((string) ($r['data']['errorDescription'] ?? $r['data']['error'] ?? ''), 0, 200),
            ], $http);
        }

        return $this->apiSuccess($r['data'], 'OK');
    }

    /** POST ms/session — بيفتح peer جديد (transportين) على السيرفر ويسجّله باسم المشارك. */
    public function session(Request $request, string $uuid)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        $roomId = SelfHostedSfuService::roomId($meeting->uuid);

        // إعادة دخول/تحديث صفحة: نحرّر الـ peer القديم الأول عشان مايفضلش يستهلك موارد.
        $old = $this->transport->publisherSession($meeting, $actor['key']);
        if ($old !== null && str_starts_with($old, 'ms_')) {
            $this->sfu->leave($roomId, $old);
        }

        $peerId = 'ms_' . Str::random(24);
        $r = $this->sfu->createSession($roomId, $peerId);
        if (!$r['ok'] || empty($r['data']['sendTransport']['id']) || empty($r['data']['recvTransport']['id'])) {
            return $this->upstream(['ok' => false] + $r, 'session');
        }
        $this->transport->registerSession($meeting, $actor['key'], $peerId, 'pub');

        return $this->apiSuccess([
            'session_id'              => $peerId,
            'provider'                => 'selfhosted',
            'router_rtp_capabilities' => $r['data']['routerRtpCapabilities'] ?? null,
            'send_transport'          => $r['data']['sendTransport'],
            'recv_transport'          => $r['data']['recvTransport'],
            'ice_servers'             => $this->signaling->iceServers(),
            'max_video_subscriptions' => MeetingAdminSettings::sfu()['max_video_subscriptions'],
        ], 'SFU session created.');
    }

    /** POST ms/sessions/{sessionId}/transports/{transportId}/connect {dtlsParameters} */
    public function connectTransport(Request $request, string $uuid, string $sessionId, string $transportId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId) || preg_match(self::UUID_RE, $transportId) !== 1) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }
        $v = Validator::make($request->all(), [
            'dtlsParameters'              => 'required|array',
            'dtlsParameters.fingerprints' => 'required|array|min:1',
            'dtlsParameters.role'         => 'nullable|in:auto,client,server',
        ]);
        if ($v->fails() || $this->tooBig($request->input('dtlsParameters'))) {
            return $this->apiError($v->errors()->first() ?: 'Invalid parameters.', $v->errors()->toArray(), 422);
        }

        return $this->upstream($this->sfu->connectTransport(
            SelfHostedSfuService::roomId($meeting->uuid),
            $sessionId,
            $transportId,
            (array) $request->input('dtlsParameters')
        ), 'connect');
    }

    /** POST ms/sessions/{sessionId}/transports/{transportId}/restart-ice — لما الشبكة تتغيّر (واي فاي → 4G). */
    public function restartIce(Request $request, string $uuid, string $sessionId, string $transportId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId) || preg_match(self::UUID_RE, $transportId) !== 1) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }

        return $this->upstream($this->sfu->restartIce(SelfHostedSfuService::roomId($meeting->uuid), $sessionId, $transportId), 'restart-ice');
    }

    /** POST ms/sessions/{sessionId}/produce {transportId, kind, rtpParameters, source?} */
    public function produce(Request $request, string $uuid, string $sessionId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId)) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }
        $v = Validator::make($request->all(), [
            'transportId'   => ['required', 'string', 'regex:' . self::UUID_RE],
            'kind'          => 'required|in:audio,video',
            'rtpParameters' => 'required|array',
            'source'        => 'nullable|in:mic,camera,screen,screen_audio',
        ]);
        if ($v->fails() || $this->tooBig($request->input('rtpParameters'))) {
            return $this->apiError($v->errors()->first() ?: 'Invalid parameters.', $v->errors()->toArray(), 422);
        }
        if ($this->transport->trackCount($meeting, $actor['key']) >= self::MAX_TRACKS_PER_PEER) {
            return $this->apiError('Too many active tracks for this participant.', ['code' => 'too_many_tracks'], 422);
        }

        $kind = (string) $request->input('kind');
        $source = (string) ($request->input('source') ?: ($kind === 'audio' ? 'mic' : 'camera'));

        $r = $this->sfu->produce(
            SelfHostedSfuService::roomId($meeting->uuid),
            $sessionId,
            (string) $request->input('transportId'),
            $kind,
            (array) $request->input('rtpParameters')
        );
        $producerId = $r['data']['id'] ?? null;
        if ($r['ok'] && is_string($producerId) && preg_match(self::UUID_RE, $producerId) === 1) {
            $this->transport->recordTracks($meeting, $actor['key'], [['trackName' => $producerId, 'kind' => $kind, 'source' => $source]]);
        }

        return $this->upstream($r, 'produce');
    }

    /** POST ms/sessions/{sessionId}/consume {transportId, producerId, rtpCapabilities} */
    public function consume(Request $request, string $uuid, string $sessionId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId)) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }
        $v = Validator::make($request->all(), [
            'transportId'            => ['required', 'string', 'regex:' . self::UUID_RE],
            'producerId'             => ['required', 'string', 'regex:' . self::UUID_RE],
            'rtpCapabilities'        => 'required|array',
            'rtpCapabilities.codecs' => 'required|array|min:1',
        ]);
        if ($v->fails() || $this->tooBig($request->input('rtpCapabilities'))) {
            return $this->apiError($v->errors()->first() ?: 'Invalid parameters.', $v->errors()->toArray(), 422);
        }
        $producerId = (string) $request->input('producerId');
        if (!$this->transport->isOtherPeerTrack($meeting, $actor['key'], $producerId)) {
            return $this->apiError('Producer does not belong to this meeting.', null, 403);
        }

        return $this->upstream($this->sfu->consume(
            SelfHostedSfuService::roomId($meeting->uuid),
            $sessionId,
            (string) $request->input('transportId'),
            $producerId,
            (array) $request->input('rtpCapabilities')
        ), 'consume');
    }

    /** POST ms/sessions/{sessionId}/consumers/{consumerId}/resume */
    public function resumeConsumer(Request $request, string $uuid, string $sessionId, string $consumerId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId) || preg_match(self::UUID_RE, $consumerId) !== 1) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }

        return $this->upstream($this->sfu->resumeConsumer(SelfHostedSfuService::roomId($meeting->uuid), $sessionId, $consumerId), 'resume');
    }

    /** POST ms/sessions/{sessionId}/consumers/{consumerId}/close — العميل وقف سحب ترَك (بيحرّر موارد السيرفر). 404 من السيرفر = اتقفل لوحده = نجاح. */
    public function closeConsumer(Request $request, string $uuid, string $sessionId, string $consumerId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId) || preg_match(self::UUID_RE, $consumerId) !== 1) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }

        $r = $this->sfu->closeConsumer(SelfHostedSfuService::roomId($meeting->uuid), $sessionId, $consumerId);
        if ($r['ok'] || $r['status'] === 404) {
            return $this->apiSuccess(null, 'OK');
        }

        return $this->upstream($r, 'close-consumer');
    }

    /** POST ms/sessions/{sessionId}/producers/{producerId}/close — إيقاف مايك/كاميرا/مشاركة شاشة. */
    public function closeProducer(Request $request, string $uuid, string $sessionId, string $producerId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!$this->owned($meeting, $actor, $sessionId) || preg_match(self::UUID_RE, $producerId) !== 1) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }
        if (!$this->transport->ownsTrack($meeting, $actor['key'], $producerId)) {
            return $this->apiError('Unknown track.', null, 403);
        }

        $r = $this->sfu->closeProducer(SelfHostedSfuService::roomId($meeting->uuid), $sessionId, $producerId);
        // نشيله من السجل حتى لو السيرفر رد 404 (الـ producer اتقفل لوحده): المهم ماحدش يفضل يشوفه ناشر.
        if ($r['ok'] || $r['status'] === 404) {
            $this->transport->removeTrack($meeting, $actor['key'], $producerId);

            return $this->apiSuccess(null, 'OK');
        }

        return $this->upstream($r, 'close');
    }
}
