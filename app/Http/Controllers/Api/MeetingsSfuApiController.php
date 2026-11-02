<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\CloudflareSfuService;
use App\Services\MeetingSignalingService;
use App\Services\MeetingTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/sfu/* — بروكسي آمن بين المتصفح وCloudflare Realtime SFU + قرار mesh/SFU للغرفة.
 * مسجّل تحت uip.auth.optional زي signaling بالظبط (الضيف المقبول بيستخدم guest_token). كل endpoint بيتأكد الأول إن الطالب
 * فعلًا جوه الاجتماع (resolveActor)، وإن sessionId بتاعه مسجّل باسمه، وإن الجلسة اللي بيسحب منها تابعة لمشارك تاني في
 * نفس الاجتماع — عشان مفتاح Cloudflare ماينفعش يتستخدم لسحب صوت/صورة اجتماع غير اجتماعه.
 */
class MeetingsSfuApiController extends Controller
{
    private const SESSION_RE = '/^[A-Za-z0-9_-]{8,128}$/';
    private const TRACK_RE = '/^[A-Za-z0-9_.:-]{1,100}$/';

    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private CloudflareSfuService $sfu,
        private MeetingTransportService $transport
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

        return [$meeting, $actor];
    }

    private function presentCount($meeting): int
    {
        try {
            return count($this->signaling->roster($meeting));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** ناشرين حاليين غيري، ومن اللي حاضرين فعلًا في الـ roster (مايظهرش سجل قديم لحد قفل التبويب من غير leave). */
    private function livePeers($meeting, array $actor): array
    {
        $present = null;
        try {
            $present = array_flip(array_column($this->signaling->roster($meeting), 'key'));
        } catch (\Throwable $e) {
            // لو الـ roster وقع نرجّع السجل كما هو
        }

        return array_values(array_filter(
            $this->transport->peers($meeting),
            fn ($p) => $p['key'] !== $actor['key'] && ($present === null || isset($present[$p['key']]))
        ));
    }

    /** الـ SDP لازم ينتهي بـ \r\n (أي trim في الطريق بيخلي Cloudflare يرفضه بـ 400). */
    private function sdp(string $sdp): string
    {
        return rtrim($sdp, "\r\n") . "\r\n";
    }

    private function upstream(array $r, string $what): JsonResponse
    {
        if (!$r['ok']) {
            $code = $r['status'] === 503 ? 503 : ($r['status'] === 504 ? 504 : 502);

            return $this->apiError("SFU {$what} failed.", [
                'code' => $r['data']['error'] ?? 'sfu_error',
                'upstream_status' => $r['status'],
                'upstream_detail' => mb_substr((string) ($r['data']['errorDescription'] ?? $r['data']['error'] ?? ''), 0, 200),
            ], $code);
        }

        return $this->apiSuccess($r['data'], 'OK');
    }

    /** GET sfu/config — الوضع الحالي للغرفة + إعدادات العميل + اللي ناشر دلوقتي. */
    public function config(Request $request, string $uuid)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        $state = $this->transport->evaluate($meeting, $this->presentCount($meeting), false);

        return $this->apiSuccess([
            'enabled'                 => $this->sfu->enabled(),
            'mode'                    => $state['mode'],
            'epoch'                   => $state['epoch'],
            'reason'                  => $state['reason'],
            'max_video_subscriptions' => \App\Services\MeetingAdminSettings::sfu()['max_video_subscriptions'],
            'peers'                   => $this->livePeers($meeting, $actor),
        ], 'SFU config retrieved successfully.');
    }

    /** GET sfu/peers — اللي ناشر فعلًا (للمنضم المتأخر ولإعادة المزامنة). */
    public function peers(Request $request, string $uuid)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        return $this->apiSuccess([
            'peers' => $this->livePeers($meeting, $actor),
        ], 'OK');
    }

    /** POST sfu/session {role: pub|sub} */
    public function session(Request $request, string $uuid)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        $v = Validator::make($request->all(), ['role' => 'required|in:pub,sub']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $r = $this->sfu->newSession();
        $sessionId = $r['data']['sessionId'] ?? null;
        if (!$r['ok'] || !is_string($sessionId) || !preg_match(self::SESSION_RE, $sessionId)) {
            return $this->upstream(['ok' => false] + $r, 'session');
        }
        $this->transport->registerSession($meeting, $actor['key'], $sessionId, $request->input('role'));

        return $this->apiSuccess(['session_id' => $sessionId, 'role' => $request->input('role')], 'SFU session created.');
    }

    /** POST sfu/sessions/{sessionId}/tracks — push (location=local) أو pull (location=remote) */
    public function tracks(Request $request, string $uuid, string $sessionId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!preg_match(self::SESSION_RE, $sessionId) || !$this->transport->ownsSession($meeting, $actor['key'], $sessionId)) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }

        $v = Validator::make($request->all(), [
            'sessionDescription'      => 'nullable|array',
            'sessionDescription.type' => 'required_with:sessionDescription|in:offer,answer',
            'sessionDescription.sdp'  => 'required_with:sessionDescription|string|max:100000',
            'tracks'                  => 'required|array|min:1|max:64',
            'tracks.*.location'       => 'required|in:local,remote',
            'tracks.*.trackName'      => ['required', 'string', 'regex:' . self::TRACK_RE],
            'tracks.*.mid'            => 'nullable|string|max:16',
            'tracks.*.sessionId'      => ['nullable', 'string', 'regex:' . self::SESSION_RE],
            'tracks.*.kind'           => 'nullable|in:audio,video',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $isPublisher = $this->transport->isPublisherSession($meeting, $actor['key'], $sessionId);
        $body = ['tracks' => []];
        $recorded = [];
        foreach ((array) $request->input('tracks') as $t) {
            if ($t['location'] === 'local') {
                if (!$isPublisher) {
                    return $this->apiError('Only the publisher session can push tracks.', null, 403);
                }
                $item = ['location' => 'local', 'trackName' => $t['trackName']];
                if (isset($t['mid'])) {
                    $item['mid'] = $t['mid'];
                }
                $recorded[] = ['trackName' => $t['trackName'], 'kind' => ($t['kind'] ?? null) === 'video' ? 'video' : 'audio'];
            } else {
                $remote = $t['sessionId'] ?? '';
                if ($isPublisher || !$this->transport->isOtherPublisherInMeeting($meeting, $actor['key'], $remote)) {
                    return $this->apiError('Remote session does not belong to this meeting.', null, 403);
                }
                $item = ['location' => 'remote', 'trackName' => $t['trackName'], 'sessionId' => $remote];
            }
            $body['tracks'][] = $item;
        }
        if ($request->filled('sessionDescription')) {
            $body['sessionDescription'] = [
                'type' => $request->input('sessionDescription.type'),
                'sdp'  => $this->sdp((string) $request->input('sessionDescription.sdp')),
            ];
        }

        $r = $this->sfu->tracksNew($sessionId, $body);
        if ($r['ok'] && $recorded) {
            // نسجّل بس الترَكات اللي Cloudflare قبلها فعلًا (من غير errorCode)
            $okNames = [];
            foreach ((array) ($r['data']['tracks'] ?? []) as $rt) {
                if (empty($rt['errorCode']) && isset($rt['trackName'])) {
                    $okNames[$rt['trackName']] = true;
                }
            }
            $recorded = array_values(array_filter($recorded, fn ($x) => $okNames ? isset($okNames[$x['trackName']]) : true));
            $this->transport->recordTracks($meeting, $actor['key'], $recorded);
        }

        return $this->upstream($r, 'tracks');
    }

    /** PUT sfu/sessions/{sessionId}/renegotiate */
    public function renegotiate(Request $request, string $uuid, string $sessionId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!preg_match(self::SESSION_RE, $sessionId) || !$this->transport->ownsSession($meeting, $actor['key'], $sessionId)) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }
        $v = Validator::make($request->all(), [
            'sessionDescription'      => 'required|array',
            'sessionDescription.type' => 'required|in:offer,answer',
            'sessionDescription.sdp'  => 'required|string|max:100000',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        return $this->upstream($this->sfu->renegotiate($sessionId, [
            'sessionDescription' => ['type' => $request->input('sessionDescription.type'), 'sdp' => $this->sdp((string) $request->input('sessionDescription.sdp'))],
        ]), 'renegotiate');
    }

    /** PUT sfu/sessions/{sessionId}/tracks/close */
    public function closeTracks(Request $request, string $uuid, string $sessionId)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        if (!preg_match(self::SESSION_RE, $sessionId) || !$this->transport->ownsSession($meeting, $actor['key'], $sessionId)) {
            return $this->apiError('Unknown SFU session.', null, 403);
        }
        $v = Validator::make($request->all(), [
            'tracks'                  => 'required|array|min:1|max:64',
            'tracks.*.mid'            => 'required|string|max:16',
            'force'                   => 'nullable|boolean',
            'sessionDescription'      => 'nullable|array',
            'sessionDescription.type' => 'required_with:sessionDescription|in:offer,answer',
            'sessionDescription.sdp'  => 'required_with:sessionDescription|string|max:100000',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $body = [
            'tracks' => array_map(fn ($t) => ['mid' => (string) $t['mid']], (array) $request->input('tracks')),
            'force'  => (bool) $request->boolean('force'),
        ];
        if ($request->filled('sessionDescription')) {
            $body['sessionDescription'] = [
                'type' => $request->input('sessionDescription.type'),
                'sdp'  => $this->sdp((string) $request->input('sessionDescription.sdp')),
            ];
        }

        return $this->upstream($this->sfu->tracksClose($sessionId, $body), 'close');
    }

    /** POST sfu/report — تقرير جودة/صحة دوري من العميل، بيغذّي قرار mesh/SFU. */
    public function report(Request $request, string $uuid)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;

        $v = Validator::make($request->all(), [
            'transport'  => 'required|in:mesh,sfu',
            'quality'    => 'required|in:excellent,good,poor,reconnecting',
            'sfu_ok'     => 'nullable|boolean',
            'sfu_rtt_ms' => 'nullable|integer|min:0|max:60000',
            'sfu_failed' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $state = $this->transport->report($meeting, $actor, $request->only(['transport', 'quality', 'sfu_ok', 'sfu_rtt_ms', 'sfu_failed']), $this->presentCount($meeting));

        return $this->apiSuccess($state, 'OK');
    }

    /** POST sfu/leave — العميل بيمسح نفسه من سجل الـ SFU (قبل ما يقفل الـ PeerConnection). */
    public function leave(Request $request, string $uuid)
    {
        $ctx = $this->context($request, $uuid);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$meeting, $actor] = $ctx;
        $this->transport->removeActor($meeting, $actor['key']);

        return $this->apiSuccess(null, 'OK');
    }
}
