<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingRemoteControlService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/meetings/{uuid}/remote-control/* — التحكم عن بُعد في شاشة مشارك (زي Zoom). نفس نمط uip.auth.optional بتاع signaling
 * (الضيف المقبول يقدر يطلب أو يوافق). راجع MeetingRemoteControlService للقواعد الأمنية.
 */
class MeetingsRemoteControlApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingRemoteControlService $remote
    ) {
    }

    private function run(Request $request, string $uuid, array $rules, callable $fn, string $okMessage)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }
        $validator = Validator::make($request->all(), $rules + ['guest_token' => 'nullable|string']);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }
        try {
            $id = $request->attributes->get('uip_user_id');
            $actor = $this->signaling->resolveActor($meeting, $id !== null ? (int) $id : null, $request->input('guest_token'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }
        try {
            $fn($meeting, $actor);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 409);
        }

        return $this->apiSuccess(null, $okMessage);
    }

    public function request(Request $request, string $uuid)
    {
        return $this->run($request, $uuid, ['target_key' => 'required|string|max:60'],
            fn ($m, $a) => $this->remote->request($m, $a, $request->input('target_key')), 'Control requested.');
    }

    public function respond(Request $request, string $uuid)
    {
        return $this->run($request, $uuid, ['controller_key' => 'required|string|max:60', 'allow' => 'required|boolean'],
            fn ($m, $a) => $this->remote->respond($m, $a, $request->input('controller_key'), $request->boolean('allow')), 'Response saved.');
    }

    public function stop(Request $request, string $uuid)
    {
        return $this->run($request, $uuid, [], fn ($m, $a) => $this->remote->stop($m, $a), 'Remote control stopped.');
    }

    public function input(Request $request, string $uuid)
    {
        return $this->run($request, $uuid, ['events' => 'required|array|max:40'],
            fn ($m, $a) => $this->remote->relayInput($m, $a, $request->input('events', [])), 'ok');
    }
}
