<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\AuditLogService;
use App\Services\MeetingLobbyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح "الدخول للاجتماع" العام (join_token، مش uuid) — Round 2 (Lobby &
 * Access): شاشة الـ pre-join (بند 3)، طلب الدخول/الـ waiting room
 * (بند 22)، والدخول كضيف بدون حساب UIP (بند 23).
 *
 * مسجّلة تحت prefix('meetings/join') بميدلوير uip.auth.optional (مش
 * uip.auth العادي) — عشان ضيف من غير Bearer token يقدر يوصلها برضو.
 * لو فيه توكن صالح بيتحط في $request->attributes زي uip.auth بالظبط،
 * لو مفيش أو باظ الريكوست بيكمل عادي (احتياط guest fallback)،
 * MeetingLobbyService هو اللي بيقرر بعدين لو allow_guests=false على
 * الاجتماع نفسه.
 *
 * إدارة الـ waiting room من ناحية الهوست (قبول/رفض/قبول الكل/رفض الكل)
 * لسه في MeetingsApiController القديم — نفس مجموعة uip.auth الأصلية،
 * مفيش داعي لتكرارها هنا.
 */
class MeetingsLobbyApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingLobbyService $lobby,
        private AuditLogService $auditLog
    ) {
    }

    private function authUserId(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id !== null ? (int) $id : null;
    }

    public function info(Request $request, string $joinToken)
    {
        $meeting = $this->meetings->findByJoinToken($joinToken);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        return $this->apiSuccess(
            $this->lobby->preJoinInfo($meeting, $this->authUserId($request)),
            'Meeting information retrieved successfully.'
        );
    }

    public function verifyPassword(Request $request, string $joinToken)
    {
        $meeting = $this->meetings->findByJoinToken($joinToken);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $valid = $this->lobby->verifyPassword($meeting, $request->input('password'));

        if (!$valid) {
            // بند 38 (Audit & Security Logs — "Failed join attempt").
            $this->auditLog->record($this->authUserId($request) ?? null, 'meetings.join_failed_wrong_password', 'Meeting', $meeting->id);
        }

        return $this->apiSuccess(['valid' => $valid], $valid ? 'Password is correct.' : 'Incorrect password.');
    }

    public function requestAccess(Request $request, string $joinToken)
    {
        $meeting = $this->meetings->findByJoinToken($joinToken);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'password'                           => 'nullable|string|max:100',
            'display_name'                        => 'nullable|string|max:100',
            'device_preferences'                  => 'nullable|array',
            'device_preferences.mic_enabled'      => 'nullable|boolean',
            'device_preferences.camera_enabled'   => 'nullable|boolean',
            'device_preferences.microphone_id'    => 'nullable|string|max:255',
            'device_preferences.camera_id'        => 'nullable|string|max:255',
            'device_preferences.speaker_id'       => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->lobby->requestAccess($meeting, $this->authUserId($request), $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 409);
        }

        $this->auditLog->record($this->authUserId($request) ?? null, 'meetings.join_requested', 'Meeting', $meeting->id);

        $payload = ['status' => $result['status']];
        if (isset($result['join_request'])) {
            $payload['request_id'] = $result['join_request']->id;
        }
        if (isset($result['guest_token'])) {
            $payload['guest_token'] = $result['guest_token'];
        }
        if (isset($result['participant'])) {
            $payload['participant_id'] = $result['participant']->id;
        }

        $admitted = $result['status'] === 'admitted';

        return $this->apiSuccess(
            $payload,
            $admitted ? 'You have joined the meeting.' : 'Your request has been sent to the host.',
            $admitted ? 200 : 202
        );
    }

    /** Polling endpoint — العنصر (يوزر مسجّل أو ضيف) بيسأل هل الهوست قبل/رفض لسه. */
    public function requestStatus(Request $request, string $joinToken, $requestId)
    {
        $meeting = $this->meetings->findByJoinToken($joinToken);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $joinRequest = $this->meetings->findJoinRequestForMeeting($meeting->id, (int) $requestId);
        if (!$joinRequest) {
            return $this->apiError('Join request not found.', null, 404);
        }

        $authUserId = $this->authUserId($request);

        if (!$joinRequest->isGuest()) {
            if ($authUserId === null || (int) $joinRequest->user_id !== $authUserId) {
                return $this->apiError('You are not authorized to view this request.', null, 403);
            }
        } else {
            $guestRequest = $this->lobby->resolveGuestToken((string) $request->query('guest_token', ''), $meeting->id);
            if (!$guestRequest || $guestRequest->id !== $joinRequest->id) {
                return $this->apiError('You are not authorized to view this request.', null, 403);
            }
        }

        return $this->apiSuccess($this->lobby->requestStatus($joinRequest), 'Request status retrieved successfully.');
    }
}
