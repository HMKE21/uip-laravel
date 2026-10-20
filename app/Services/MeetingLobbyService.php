<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Repositories\MeetingRepository;
use Illuminate\Support\Facades\Hash;

/**
 * Meetings & Collaboration Platform — Round 2 (Lobby & Access): بند 3
 * (Join Meeting Experience / Pre-Join Screen)، بند 22 (Waiting Room)،
 * بند 23 (Guest Access). نفس فلسفة MeetingService بتاعة Round 1: المنطق
 * كله هنا، الكنترولرات بس بتتحقق من الشكل وبتحوّل الاستثناءات لردود API.
 *
 * اختبار الكاميرا/المايك نفسه (جزء من بند 3) مفيش له حاجة هنا خالص —
 * ده منطق متصفح بحت (getUserMedia)، الـ backend بس بيستقبل اختيار
 * الجهاز النهائي (device_preferences) كمعلومة يحفظها لحد ما يوصل غرفة
 * الاجتماع الفعلية (Round 4).
 */
class MeetingLobbyService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * بند 12 — "Participant joined". بس للحالة اللي الهوست مايكونش عارف
     * بيها أصلًا (waiting room متعطل، فالعنصر دخل من غير ما الهوست يعمل
     * أي قرار) — لو الهوست هو نفسه اللي وافق (decide()) مش محتاج
     * يتاخد إشعار بقرار هو نفسه اللي اتخده.
     */
    private function notifyHostOfJoin(Meeting $meeting, string $displayName): void
    {
        $this->notifications?->notify(
            $meeting->host_user_id,
            'meeting_participant_joined',
            $displayName . ' joined your meeting: ' . $meeting->title,
            null,
            null,
            'low'
        );
    }

    /**
     * هل الاجتماع لسه ممكن حد يحاول يدخله؟ مش منتهي/ملغي، **و** مش
     * مقفول (بند 5 — Round 6 "Lock meeting") إلا لو الداخل ده هوست/
     * co-host أصلًا (زي bypassesWaitingRoom بالظبط — القفل لحد جديد
     * بس، مش لمين بيدير الاجتماع). $authUserId اختياري عشان preJoinInfo()
     * برضو تعرض "مقفول" صح لحد لسه مسجّل دخول بحساب UIP بيشوف الشاشة.
     */
    public function isJoinable(Meeting $meeting, ?int $authUserId = null): bool
    {
        if (in_array($meeting->status, ['ended', 'cancelled'], true)) {
            return false;
        }

        if ($meeting->locked && !($authUserId !== null && $this->policy->canManage($meeting, $authUserId, $this->meetings))) {
            return false;
        }

        return true;
    }

    public function verifyPassword(Meeting $meeting, ?string $password): bool
    {
        if (!$meeting->hasPassword()) {
            return true;
        }

        return $password !== null && $password !== '' && Hash::check($password, $meeting->password_hash);
    }

    /**
     * بيانات شاشة الـ pre-join (بند 3) — عام عمدًا، مفيش فيه join_token
     * ولا password_hash أبدًا (نفس منطق present() في MeetingsApiController).
     */
    public function preJoinInfo(Meeting $meeting, ?int $authUserId): array
    {
        $isParticipant = $authUserId !== null && $this->meetings->isParticipant($meeting->id, $authUserId);
        $bypassesWaitingRoom = $authUserId !== null && $this->policy->canManage($meeting, $authUserId, $this->meetings);

        return [
            'meeting' => [
                'uuid'               => $meeting->uuid,
                'title'              => $meeting->title,
                'description'        => $meeting->description,
                'type'               => $meeting->type,
                'status'             => $meeting->status,
                'scheduled_start_at' => $meeting->scheduled_start_at,
                'duration_minutes'   => $meeting->duration_minutes,
                'meeting_code'       => $meeting->meeting_code,
            ],
            'host'                  => $meeting->host ? ['full_name' => $meeting->host->full_name] : null,
            'requires_password'     => $meeting->hasPassword(),
            'waiting_room_enabled'  => (bool) $meeting->waiting_room_enabled,
            'allow_guests'          => (bool) $meeting->allow_guests,
            'joinable'              => $this->isJoinable($meeting, $authUserId),
            'is_authenticated'      => $authUserId !== null,
            'already_participant'   => $isParticipant,
            'bypasses_waiting_room' => $bypassesWaitingRoom,
            'device_defaults'       => [
                'mute_on_entry'   => (bool) ($meeting->settings['mute_on_entry'] ?? false),
                'camera_on_entry' => (bool) ($meeting->settings['camera_on_entry'] ?? true),
            ],
        ];
    }

    /**
     * بند 22 + 23 — طلب الدخول للاجتماع (عبر join_token، مش uuid).
     * @param array $data password?, display_name? (ضيوف بس), device_preferences?
     * @return array{status:string, join_request?:MeetingJoinRequest, participant?:MeetingParticipant, guest_token?:string}
     *
     * @throws \InvalidArgumentException خطأ إدخال (كلمة سر غلط، اسم عرض
     *         ناقص، الضيوف مش مسموحين) — الكنترولر بيرجعها 422.
     * @throws \RuntimeException الاجتماع مش متاح للدخول أو وصل للحد
     *         الأقصى — الكنترولر بيرجعها 409.
     */
    public function requestAccess(Meeting $meeting, ?int $authUserId, array $data): array
    {
        if (!$this->isJoinable($meeting, $authUserId)) {
            throw new \RuntimeException($meeting->locked ? 'This meeting is locked by the host.' : 'This meeting is no longer available to join.');
        }

        if (!$this->verifyPassword($meeting, $data['password'] ?? null)) {
            throw new \InvalidArgumentException('Incorrect meeting password.');
        }

        $devicePreferences = $data['device_preferences'] ?? null;

        // الهوست/co-host بيدخلوا على طول دايمًا — مفيش waiting room ليهم.
        if ($authUserId !== null && $this->policy->canManage($meeting, $authUserId, $this->meetings)) {
            $participant = $this->admitAuthenticatedUser($meeting, $authUserId, $devicePreferences);

            return ['status' => 'admitted', 'participant' => $participant];
        }

        $isGuest = $authUserId === null;

        if ($isGuest && !$meeting->allow_guests) {
            throw new \InvalidArgumentException('Guest access is not enabled for this meeting. Please sign in to join.');
        }

        $guestName = null;
        if ($isGuest) {
            $guestName = trim((string) ($data['display_name'] ?? ''));
            if ($guestName === '') {
                throw new \InvalidArgumentException('A display name is required to join as a guest.');
            }
        }

        if ($meeting->max_participants !== null
            && $this->meetings->countActiveParticipants($meeting->id) >= $meeting->max_participants) {
            throw new \RuntimeException('This meeting has reached its maximum number of participants.');
        }

        $existing = $authUserId !== null ? $this->meetings->pendingJoinRequestForUser($meeting->id, $authUserId) : null;

        if (!$meeting->waiting_room_enabled) {
            // مفيش انتظار — يدخل على طول. الهوست مايعرفش بالدخول ده إلا
            // عن طريق إشعار (مافيش قرار هو اتخده)، عكس decide() تحت.
            if ($authUserId !== null) {
                $participant = $this->admitAuthenticatedUser($meeting, $authUserId, $devicePreferences);
                if ($existing) {
                    $existing->fill([
                        'status'              => 'admitted',
                        'decided_at'          => now(),
                        'password_verified'   => true,
                        'device_preferences'  => $devicePreferences,
                    ])->save();
                }
                $this->notifyHostOfJoin($meeting, $participant->user->full_name ?? ('User #' . $authUserId));

                return ['status' => 'admitted', 'participant' => $participant];
            }

            $joinRequest = $this->meetings->createJoinRequest([
                'meeting_id'         => $meeting->id,
                'user_id'            => null,
                'guest_name'         => $guestName,
                'status'             => 'admitted',
                'password_verified'  => true,
                'device_preferences' => $devicePreferences,
                'requested_at'       => now(),
                'decided_at'         => now(),
            ]);
            $this->notifyHostOfJoin($meeting, $joinRequest->displayLabel());

            return ['status' => 'admitted', 'join_request' => $joinRequest, 'guest_token' => $this->issueGuestToken($joinRequest)];
        }

        // waiting room مفعّل — الطلب بيستنى قرار الهوست.
        if ($existing) {
            $existing->fill(['password_verified' => true, 'device_preferences' => $devicePreferences])->save();
            $joinRequest = $existing;
        } else {
            $joinRequest = $this->meetings->createJoinRequest([
                'meeting_id'         => $meeting->id,
                'user_id'            => $authUserId,
                'guest_name'         => $guestName,
                'status'             => 'pending',
                'password_verified'  => true,
                'device_preferences' => $devicePreferences,
                'requested_at'       => now(),
            ]);
        }

        $result = ['status' => 'pending', 'join_request' => $joinRequest];
        if ($authUserId === null) {
            $result['guest_token'] = $this->issueGuestToken($joinRequest);
        }

        return $result;
    }

    private function admitAuthenticatedUser(Meeting $meeting, int $userId, ?array $devicePreferences): MeetingParticipant
    {
        $participant = $this->meetings->findParticipant($meeting->id, $userId);
        if ($participant) {
            $participant->status = 'joined';
            $participant->joined_at = $participant->joined_at ?? now();
            $participant->save();

            return $participant;
        }

        return $this->meetings->addParticipant([
            'meeting_id' => $meeting->id,
            'user_id'    => $userId,
            'role'       => 'participant',
            'status'     => 'joined',
            'joined_at'  => now(),
        ]);
    }

    /** توكن ضيف قصير الأجل (JWT عادي بنفس UipJwtService) — بيثبت ملكية الطلب من غير حساب UIP. */
    public function issueGuestToken(MeetingJoinRequest $joinRequest): string
    {
        $ttlSeconds = $this->policy->guestSessionTtlMinutes() * 60;

        return UipJwtService::encode([
            'typ'             => 'meeting_guest',
            'join_request_id' => $joinRequest->id,
            'meeting_id'      => $joinRequest->meeting_id,
        ], $ttlSeconds);
    }

    /** فك توكن الضيف والتأكد إنه لسه بيتكلم عن نفس الطلب/الاجتماع قبل ما نوريه أي حاجة. */
    public function resolveGuestToken(string $token, int $meetingId): ?MeetingJoinRequest
    {
        if ($token === '') {
            return null;
        }

        $claims = UipJwtService::decode($token);
        if (!$claims || ($claims['typ'] ?? null) !== 'meeting_guest' || (int) ($claims['meeting_id'] ?? 0) !== $meetingId) {
            return null;
        }

        $joinRequest = $this->meetings->findJoinRequest((int) ($claims['join_request_id'] ?? 0));
        if (!$joinRequest || (int) $joinRequest->meeting_id !== $meetingId || $joinRequest->user_id !== null) {
            return null;
        }

        return $joinRequest;
    }

    public function requestStatus(MeetingJoinRequest $joinRequest): array
    {
        return [
            'id'            => $joinRequest->id,
            'status'        => $joinRequest->status,
            'is_guest'      => $joinRequest->isGuest(),
            'requested_at'  => $joinRequest->requested_at,
            'decided_at'    => $joinRequest->decided_at,
        ];
    }

    /** بند 22 — قبول/رفض طلب واحد من ناحية الهوست/co-host. */
    public function decide(Meeting $meeting, MeetingJoinRequest $joinRequest, bool $admit, int $deciderUserId): MeetingJoinRequest
    {
        if ((int) $joinRequest->meeting_id !== (int) $meeting->id) {
            throw new \InvalidArgumentException('This request does not belong to this meeting.');
        }
        if ($joinRequest->status !== 'pending') {
            throw new \RuntimeException('This join request has already been decided.');
        }

        $joinRequest->status = $admit ? 'admitted' : 'rejected';
        $joinRequest->decided_at = now();
        $joinRequest->decided_by_user_id = $deciderUserId;
        $joinRequest->save();

        if ($admit && $joinRequest->user_id !== null) {
            $this->admitAuthenticatedUser($meeting, (int) $joinRequest->user_id, $joinRequest->device_preferences);
        }

        return $joinRequest;
    }

    /** بند 22 — "Accept all" / "Reject all". @return int عدد الطلبات اللي اتقرر مصيرها. */
    public function decideAll(Meeting $meeting, bool $admit, int $deciderUserId): int
    {
        $pending = $this->meetings->pendingJoinRequestsFor($meeting->id);
        foreach ($pending as $joinRequest) {
            $this->decide($meeting, $joinRequest, $admit, $deciderUserId);
        }

        return count($pending);
    }
}
