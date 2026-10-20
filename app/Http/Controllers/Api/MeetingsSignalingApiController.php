<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\AuditLogService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/signaling/* — Round 3 (Signaling): بند 6
 * (الجزء الخاص بالـ Signaling)، بند 33 (Real-Time Architecture).
 *
 * مسجّلة تحت uip.auth.optional (زي meetings/join بالظبط في Round 2) —
 * مش uip.auth العادي، عشان ضيف مقبول (بند 23) يقدر يستخدم القناة
 * والـ media state بتاعته من غير حساب UIP. الفرق بين مستخدم وضيف هنا
 * كله جوه MeetingSignalingService::resolveActor() (guest_token في
 * الـ body/query بدل uip_user_id).
 *
 * "auth" هنا هو endpoint توقيع القناة بروتوكول Pusher (اللي Reverb
 * متوافق معاه) — مش /broadcasting/auth القياسي بتاع لارافيل. راجع
 * docblock config/broadcasting.php وMeetingSignalingService لتفاصيل
 * السبب.
 *
 * Round 5 (Live Collaboration) إضافات هنا: hand()/reaction() (بند 11)،
 * screenShare*() (بند 9 — host controls). الشات نفسه (بند 7/8) في
 * MeetingsChatApiController منفصل — راجع docblock هناك ليه.
 */
class MeetingsSignalingApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private AuditLogService $auditLog
    ) {
    }

    private function authUserId(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id !== null ? (int) $id : null;
    }

    private function findMeeting(string $uuid)
    {
        return $this->meetings->findByUuid($uuid);
    }

    private function resolveActorOr403(Request $request, $meeting)
    {
        try {
            return $this->signaling->resolveActor($meeting, $this->authUserId($request), $request->input('guest_token'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }
    }

    public function authorizeChannel(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'socket_id'    => 'required|string|max:100',
            'channel_name' => 'required|string|max:200',
            'guest_token'  => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $payload = $this->signaling->authorizeChannel(
                $meeting,
                $this->authUserId($request),
                $request->input('guest_token'),
                $request->input('socket_id'),
                $request->input('channel_name')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        // شكل الرد ده بالظبط اللي Laravel Echo/pusher-js بيستنوه من
        // endpoint الـ auth (مفتاح "auth"، وcamelCase channel_data
        // كنص JSON مش object) — من غير الغلاف المعتاد apiSuccess/data.
        return response()->json($payload);
    }

    public function mediaState(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'mic_enabled'    => 'sometimes|boolean',
            'camera_enabled' => 'sometimes|boolean',
            'screen_sharing' => 'sometimes|boolean',
            'guest_token'    => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $wasSharing = (bool) ($actor['model']->screen_sharing ?? false);

        try {
            $state = $this->signaling->updateMediaState($meeting, $actor, $request->only(['mic_enabled', 'camera_enabled', 'screen_sharing']));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        // بند 38 (Audit & Security Logs — "Screen sharing started/stopped").
        if ($request->has('screen_sharing')) {
            $nowSharing = (bool) $request->boolean('screen_sharing');
            if ($nowSharing !== $wasSharing) {
                $this->auditLog->record(
                    $this->authUserId($request) ?? null,
                    $nowSharing ? 'meetings.screen_share_started' : 'meetings.screen_share_stopped',
                    'Meeting',
                    $meeting->id
                );
            }
        }

        return $this->apiSuccess($state, 'Media state updated successfully.');
    }

    public function connectionState(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'connection_state' => 'required|in:connecting,connected,reconnecting,disconnected',
            'guest_token'       => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $state = $this->signaling->updateConnectionState($meeting, $actor, $request->input('connection_state'));

        return $this->apiSuccess(['connection_state' => $state], 'Connection state updated successfully.');
    }

    public function heartbeat(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $this->signaling->heartbeat($actor);

        return $this->apiSuccess(null, 'Heartbeat recorded.');
    }

    public function leave(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $this->signaling->leave($meeting, $actor);
        $this->auditLog->record($this->authUserId($request) ?? null, 'meetings.signaling_left', 'Meeting', $meeting->id);

        return $this->apiSuccess(null, 'You have left the meeting.');
    }

    /**
     * Round 4 (WebRTC Core، بند 6). لازم actor محلول الأول (زي باقي
     * الميثودز فوق) — سر TURN المؤقت (راجع docblock
     * MeetingSignalingService::iceServers()) ميتوزّعش على حد مش داخل
     * الاجتماع فعلًا.
     */
    public function iceServers(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        return $this->apiSuccess(['ice_servers' => $this->signaling->iceServers()], 'ICE servers retrieved successfully.');
    }

    /** Round 4 (WebRTC Core، بند 34). */
    public function connectionQuality(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'connection_quality' => 'required|in:excellent,good,poor,reconnecting',
            'guest_token'         => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $quality = $this->signaling->updateConnectionQuality($meeting, $actor, $request->input('connection_quality'));

        return $this->apiSuccess(['connection_quality' => $quality], 'Connection quality updated successfully.');
    }

    /** Round 4 (WebRTC Core، بند 34 — "clear feedback instead of silently failing"). */
    public function reportFailure(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'failure_type' => 'required|in:camera,microphone,webrtc,network',
            'message'      => 'nullable|string|max:500',
            'guest_token'  => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $this->signaling->reportFailure($meeting, $actor, $request->input('failure_type'), $request->input('message'));

        // Round 10 (بند 39 — Admin Monitoring "Failed connection
        // statistics"). audit_logs هو المخزن المستمر الوحيد للفشل ده
        // (reportFailure() فوق بيبعت event لحظي بس، مفيش جدول مخصص) —
        // MeetingAnalyticsService::platformMonitoring() بيعدّها من هنا.
        $this->auditLog->record(
            $this->authUserId($request) ?? null,
            'meetings.connection_failure',
            'Meeting',
            $meeting->id,
            null,
            ['failure_type' => $request->input('failure_type'), 'message' => $request->input('message')]
        );

        return $this->apiSuccess(null, 'Failure reported.');
    }

    /**
     * Round 4 (WebRTC Core، بند 4 — Participant Grid). بعكس
     * MeetingsApiController::indexParticipants() (Round 1، مسجلة اجتماع
     * مسجّل بس تحت uip.auth) — دي uip.auth.optional زي باقي الـ
     * signaling عشان الضيف كمان يقدر يشوف باقي الحاضرين في شبكة الـ
     * grid بتاعته، وبترجع الضيوف المقبولين كمان مش المستخدمين المسجلين
     * بس. راجع docblock MeetingSignalingService::roster().
     */
    public function roster(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        return $this->apiSuccess([
            'roster'        => $this->signaling->roster($meeting),
            'mesh_capacity' => $this->signaling->meshCapacity($meeting),
        ], 'Roster retrieved successfully.');
    }

    /** Round 5 (بند 11 — Raise Hand / Lower Hand). */
    public function hand(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'raised'      => 'required|boolean',
            'guest_token' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $state = $this->signaling->raiseHand($meeting, $actor, (bool) $request->input('raised'));

        return $this->apiSuccess($state, 'Hand state updated successfully.');
    }

    /** Round 5 (بند 11 — Thumbs Up/Applause/Laugh/Heart/Celebrate/Other، ephemeral). */
    public function reaction(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'type'        => 'required|in:thumbs_up,applause,laugh,heart,celebrate,other',
            'emoji'       => 'nullable|string|max:8',
            'guest_token' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $this->signaling->sendReaction($meeting, $actor, $request->input('type'), $request->input('emoji'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Reaction sent.');
    }

    /** Round 5 (بند 9 — "Allow screen sharing" / "Disable screen sharing"، قفل عام). host/co-host بس. */
    public function screenSharePolicy(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'locked'      => 'required|boolean',
            'guest_token' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $locked = $this->signaling->setScreenSharingLock($meeting, $actor, (bool) $request->input('locked'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess(['locked' => $locked], 'Screen sharing policy updated successfully.');
    }

    /** Round 5 (بند 9 — استثناء/منع شخص بعينه). host/co-host بس. */
    public function screenShareParticipantPolicy(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'participant_key' => 'required|string|max:40',
            'allowed'         => 'nullable|boolean',
            'guest_token'     => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $this->signaling->setParticipantScreenSharePolicy(
                $meeting,
                $actor,
                $request->input('participant_key'),
                $request->has('allowed') ? $request->boolean('allowed') : null
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess(null, 'Participant screen sharing permission updated successfully.');
    }

    /** Round 5 (بند 9 — "Stop another participant's sharing session"). host/co-host بس. */
    public function screenShareStop(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'participant_key' => 'required|string|max:40',
            'guest_token'     => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $this->signaling->stopParticipantScreenShare($meeting, $actor, $request->input('participant_key'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        // بند 38 (Audit & Security Logs — "Screen sharing stopped").
        $this->auditLog->record($this->authUserId($request) ?? null, 'meetings.screen_share_force_stopped', 'Meeting', $meeting->id, null, ['participant_key' => $request->input('participant_key')]);

        return $this->apiSuccess(null, 'The participant\'s screen share has been stopped.');
    }
}
