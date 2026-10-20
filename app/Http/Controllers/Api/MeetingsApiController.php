<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\MeetingAttachableService;
use App\Services\MeetingLobbyService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/* — Round 1 (Foundation) بس: CRUD الاجتماع نفسه،
 * قائمة المشاركين، ودعوة/رد على دعوة مستخدم واحد بالـ id. لسه من غير
 * أي حاجة خاصة بالانضمام الفعلي (lobby/waiting-room Round 2)، الاتصال
 * (signaling/WebRTC Round 3-4)، أو bulk invite بالـ role/group/project
 * (Round 8).
 *
 * RBAC: أي مستخدم مسجّل (أي uip_role) يقدر يستضيف اجتماع — مفيش
 * requireAcademicStaff() هنا زي exam-system، الموديول ده عام لكل
 * الأدوار. host_user_id دايمًا uip_user_id من التوكن، مش من العميل.
 * القرارات (مين يدير/يشوف) عدّاية على MeetingPolicyService، مش hardcoded
 * هنا في الكنترولر.
 */
class MeetingsApiController extends Controller
{
    public function __construct(
        private MeetingService $meetingService,
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy,
        private UserRepository $users,
        private AuditLogService $auditLog,
        private MeetingLobbyService $lobbyService,
        private MeetingAttachableService $attachables
    ) {
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function role(Request $request): string
    {
        return (string) $request->attributes->get('uip_role');
    }

    /** بترجع الشكل العلني للاجتماع + join_url/meeting_code — password_hash/join_token الخام مايترجعوش أبدًا. */
    private function present($meeting, bool $includeJoinDetails = false): array
    {
        $data = $meeting->toArray();
        $data['has_password'] = $meeting->hasPassword();

        if ($includeJoinDetails) {
            $base = rtrim((string) config('meetings.frontend_join_base_url'), '/');
            $data['meeting_code'] = $meeting->meeting_code;
            $data['join_url'] = $base !== '' ? $base . '/' . $meeting->join_token : $meeting->join_token;
        }

        return $data;
    }

    // ---------------------------------------------------------------
    // Meetings — /api/v1/meetings
    // ---------------------------------------------------------------

    public function index(Request $request)
    {
        $meetings = $this->meetings->forUser($this->userId($request));

        return $this->apiSuccess(array_map(fn ($m) => $this->present($m), $meetings), 'Meetings retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'                 => 'required|string|max:200',
            'description'           => 'nullable|string|max:5000',
            'type'                  => 'nullable|in:instant,scheduled',
            'scheduled_start_at'    => 'nullable|date',
            'duration_minutes'      => 'nullable|integer|min:5|max:' . $this->policy->maxDurationMinutes(),
            'max_participants'      => 'nullable|integer|min:2',
            'waiting_room_enabled'  => 'nullable|boolean',
            'allow_guests'          => 'nullable|boolean',
            'password'              => 'nullable|string|min:4|max:100',
            'settings'              => 'nullable|array',
            // Round 8، بند 26 — Project Integration.
            'attachable_type'       => 'nullable|string|max:40',
            'attachable_id'         => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        if (($request->input('type') ?? 'instant') === 'scheduled' && !$request->filled('scheduled_start_at')) {
            return $this->apiError('scheduled_start_at is required for a scheduled meeting.', null, 422);
        }

        $payload = $request->all();
        try {
            $payload = array_merge($payload, $this->attachables->resolve($this->userId($request), $this->role($request), $payload));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $meeting = $this->meetingService->create($this->userId($request), $payload);

        $this->auditLog->record($this->userId($request), 'meetings.meeting_created', 'Meeting', $meeting->id);

        return $this->apiSuccess($this->present($meeting, true), 'Meeting created successfully.', 201);
    }

    public function show(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canView($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $includeJoinDetails = $this->policy->canManage($meeting, $this->userId($request), $this->meetings);

        return $this->apiSuccess($this->present($meeting, $includeJoinDetails), 'Meeting retrieved successfully.');
    }

    public function update(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'title'                => 'sometimes|required|string|max:200',
            'description'          => 'nullable|string|max:5000',
            'scheduled_start_at'   => 'nullable|date',
            'duration_minutes'     => 'nullable|integer|min:5|max:' . $this->policy->maxDurationMinutes(),
            'max_participants'     => 'nullable|integer|min:2',
            'waiting_room_enabled' => 'nullable|boolean',
            'allow_guests'         => 'nullable|boolean',
            'password'             => 'nullable|string|min:4|max:100',
            'settings'             => 'nullable|array',
            // Round 8، بند 26 — Project Integration.
            'attachable_type'      => 'nullable|string|max:40',
            'attachable_id'        => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $payload = $request->all();
        if ($request->filled('attachable_type')) {
            try {
                $payload = array_merge($payload, $this->attachables->resolve($this->userId($request), $this->role($request), $payload));
            } catch (\RuntimeException $e) {
                return $this->apiError($e->getMessage(), null, 403);
            } catch (\InvalidArgumentException $e) {
                return $this->apiError($e->getMessage(), null, 422);
            }
        }

        $meeting = $this->meetingService->update($meeting, $payload);

        $this->auditLog->record($this->userId($request), 'meetings.meeting_updated', 'Meeting', $meeting->id);

        return $this->apiSuccess($this->present($meeting, true), 'Meeting updated successfully.');
    }

    public function destroy(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->isHost($meeting, $this->userId($request))) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $this->meetingService->delete($meeting);

        $this->auditLog->record($this->userId($request), 'meetings.meeting_deleted', 'Meeting', $meeting->id);

        return $this->apiSuccess(null, 'Meeting deleted successfully.');
    }

    public function cancel(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $meeting = $this->meetingService->cancel($meeting);

        $this->auditLog->record($this->userId($request), 'meetings.meeting_cancelled', 'Meeting', $meeting->id);

        return $this->apiSuccess($this->present($meeting), 'Meeting cancelled successfully.');
    }

    public function start(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $meeting = $this->meetingService->start($meeting);

        return $this->apiSuccess($this->present($meeting, true), 'Meeting started successfully.');
    }

    public function end(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $meeting = $this->meetingService->end($meeting);

        $this->auditLog->record($this->userId($request), 'meetings.meeting_ended', 'Meeting', $meeting->id);

        return $this->apiSuccess($this->present($meeting), 'Meeting ended successfully.');
    }

    // ---------------------------------------------------------------
    // Participants — /api/v1/meetings/{uuid}/participants
    // ---------------------------------------------------------------

    public function indexParticipants(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canView($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $participants = array_map(function ($p) {
            $row = $p->toArray();
            $row['user'] = $p->user ? [
                'id'        => $p->user->id,
                'full_name' => $p->user->full_name,
                'email'     => $p->user->email,
            ] : null;
            return $row;
        }, $this->meetings->participantsFor($meeting->id));

        return $this->apiSuccess($participants, 'Participants retrieved successfully.');
    }

    // ---------------------------------------------------------------
    // Invitations — /api/v1/meetings/{uuid}/invitations، /api/v1/meetings/invitations
    // ---------------------------------------------------------------

    public function indexInvitations(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        return $this->apiSuccess($this->meetings->invitationsFor($meeting->id), 'Invitations retrieved successfully.');
    }

    public function storeInvitation(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'message' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $invitedUser = $this->users->findById($request->input('user_id'));
        if (!$invitedUser) {
            return $this->apiError('User not found.', null, 404);
        }

        if ((int) $invitedUser->id === (int) $meeting->host_user_id) {
            return $this->apiError('The host is already part of this meeting.', null, 422);
        }

        $invitation = $this->meetingService->invite($meeting, $this->userId($request), (int) $invitedUser->id, $request->input('message'));

        $this->auditLog->record($this->userId($request), 'meetings.invitation_sent', 'MeetingInvitation', $invitation->id);

        return $this->apiSuccess($invitation->toArray(), 'Invitation sent successfully.', 201);
    }

    /** دعوات المستخدم الحالي المعلّقة عبر كل الاجتماعات — /api/v1/meetings/invitations */
    public function myInvitations(Request $request)
    {
        return $this->apiSuccess($this->meetings->pendingInvitationsFor($this->userId($request)), 'Invitations retrieved successfully.');
    }

    public function respondToInvitation(Request $request, string $uuid, $invitationId)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $invitation = $this->meetings->invitationsFor($meeting->id);
        $invitation = collect($invitation)->firstWhere('id', (int) $invitationId);
        if (!$invitation || (int) $invitation->invited_user_id !== $this->userId($request)) {
            return $this->apiError('Invitation not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'accept' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $invitation = $this->meetingService->respondToInvitation($invitation, $this->userId($request), (bool) $request->input('accept'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 409);
        }

        $this->auditLog->record($this->userId($request), 'meetings.invitation_responded', 'MeetingInvitation', $invitation->id);

        return $this->apiSuccess($invitation->toArray(), 'Invitation updated successfully.');
    }

    /** التحقق من كلمة سر الاجتماع — schema بس هنا، شاشة الـ lobby الفعلية Round 2. */
    public function verifyPassword(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        if (!$meeting->hasPassword()) {
            return $this->apiSuccess(['valid' => true], 'This meeting has no password.');
        }

        $valid = Hash::check((string) $request->input('password'), $meeting->password_hash);

        return $this->apiSuccess(['valid' => $valid], $valid ? 'Password is correct.' : 'Incorrect password.');
    }

    // ---------------------------------------------------------------
    // Waiting Room (host/co-host) — /api/v1/meetings/{uuid}/waiting-room
    // Round 2 (Lobby & Access, بند 22). طلبات الدخول نفسها (سواء
    // لمستخدم مسجّل أو ضيف) بتتخلق من سطح "الدخول بالرابط" العام
    // (join_token) في MeetingsLobbyApiController — هنا بس إدارتها من
    // ناحية الهوست، فضلت في نفس الكنترولر ده لأنها زي باقي إدارة
    // الاجتماع (uip.auth العادي + canManage()).
    // ---------------------------------------------------------------

    public function waitingRoomIndex(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $requests = array_map(function ($r) {
            return [
                'id'            => $r->id,
                'display_label' => $r->displayLabel(),
                'is_guest'      => $r->isGuest(),
                'requested_at'  => $r->requested_at,
            ];
        }, $this->meetings->pendingJoinRequestsFor($meeting->id));

        return $this->apiSuccess($requests, 'Waiting room retrieved successfully.');
    }

    private function decideWaitingRoomRequest(Request $request, string $uuid, int $requestId, bool $admit)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $joinRequest = $this->meetings->findJoinRequestForMeeting($meeting->id, $requestId);
        if (!$joinRequest) {
            return $this->apiError('Join request not found.', null, 404);
        }

        try {
            $joinRequest = $this->lobbyService->decide($meeting, $joinRequest, $admit, $this->userId($request));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 409);
        }

        $this->auditLog->record(
            $this->userId($request),
            $admit ? 'meetings.join_request_accepted' : 'meetings.join_request_rejected',
            'MeetingJoinRequest',
            $joinRequest->id
        );

        return $this->apiSuccess(
            ['id' => $joinRequest->id, 'status' => $joinRequest->status],
            $admit ? 'Participant admitted.' : 'Participant rejected.'
        );
    }

    public function waitingRoomAccept(Request $request, string $uuid, $requestId)
    {
        return $this->decideWaitingRoomRequest($request, $uuid, (int) $requestId, true);
    }

    public function waitingRoomReject(Request $request, string $uuid, $requestId)
    {
        return $this->decideWaitingRoomRequest($request, $uuid, (int) $requestId, false);
    }

    public function waitingRoomAcceptAll(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $count = $this->lobbyService->decideAll($meeting, true, $this->userId($request));

        $this->auditLog->record($this->userId($request), 'meetings.waiting_room_accept_all', 'Meeting', $meeting->id, null, ['count' => $count]);

        return $this->apiSuccess(['admitted' => $count], 'All pending requests have been admitted.');
    }

    public function waitingRoomRejectAll(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $count = $this->lobbyService->decideAll($meeting, false, $this->userId($request));

        $this->auditLog->record($this->userId($request), 'meetings.waiting_room_reject_all', 'Meeting', $meeting->id, null, ['count' => $count]);

        return $this->apiSuccess(['rejected' => $count], 'All pending requests have been rejected.');
    }
}
