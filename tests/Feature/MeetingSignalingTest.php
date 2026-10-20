<?php

namespace Tests\Feature;

use App\Events\Meetings\MeetingStatusBroadcast;
use App\Events\Meetings\ParticipantConnectionStateChanged;
use App\Events\Meetings\ParticipantLeftMeeting;
use App\Events\Meetings\ParticipantMediaStateChanged;
use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;
use App\Services\MeetingLobbyService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingService;
use App\Services\MeetingSignalingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لـ Round 3 (Signaling) بتاع موديول الاجتماعات:
 * MeetingSignalingService (resolveActor لمستخدم مسجّل/ضيف، توقيع القناة
 * بروتوكول Pusher، media/connection state، heartbeat، leave،
 * broadcastStatus) + تكامل MeetingService::start/end/cancel معاها.
 *
 * ملحوظة بيئة: زي MeetingLobbyTest بالظبط — DatabaseTransactions +
 * JWT_SECRET ثابتة في setUp(). REVERB_APP_KEY/SECRET بييجوا من .env
 * العادي (مش متظبطين في phpunit.xml)، فبنتأكدهم في setUp() بقيم تجريبية
 * ثابتة (putenv) بدل ما نفترض قيمة .env في أي بيئة CI، ونمسح الـ config
 * cache بتاعهم بـ config()-> set عشان signChannel() يشوفهم فورًا.
 */
class MeetingSignalingTest extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingLobbyService $lobby;
    private MeetingSignalingService $signaling;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-meeting-signaling-tests');
        }

        config([
            'broadcasting.connections.reverb.key'    => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
        ]);

        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->lobby = new MeetingLobbyService($this->meetings, $this->policy);
        $this->signaling = new MeetingSignalingService($this->meetings, $this->lobby, $this->policy);
        $this->service = new MeetingService($this->meetings, $this->policy, null, $this->signaling);
    }

    private function makeUser(string $name = 'Test User'): int
    {
        return DB::table('users')->insertGetId([
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'full_name'     => $name,
            'email'         => 'user_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'),
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // resolveActor (مستخدم مسجّل مقابل ضيف)
    // -----------------------------------------------------------------

    #[Test]
    public function resolve_actor_returns_a_participant_for_a_joined_user(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->assertSame('participant', $actor['type']);
        $this->assertSame('user:' . $hostId, $actor['key']);
        $this->assertSame('host', $actor['role']);
        $this->assertSame('Host', $actor['display_name']);
    }

    #[Test]
    public function resolve_actor_rejects_a_user_who_never_joined(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $this->expectException(\RuntimeException::class);
        $this->signaling->resolveActor($meeting, $strangerId, null);
    }

    #[Test]
    public function resolve_actor_returns_a_guest_for_a_valid_guest_token(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => false,
        ]);

        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);

        $actor = $this->signaling->resolveActor($meeting, null, $result['guest_token']);

        $this->assertSame('guest', $actor['type']);
        $this->assertSame('guest:' . $result['join_request']->id, $actor['key']);
        $this->assertSame('Ahmed', $actor['display_name']);
        $this->assertSame('guest', $actor['role']);
    }

    #[Test]
    public function resolve_actor_rejects_an_invalid_guest_token(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Public Seminar', 'allow_guests' => true]);

        $this->expectException(\RuntimeException::class);
        $this->signaling->resolveActor($meeting, null, 'not-a-real-token');
    }

    #[Test]
    public function resolve_actor_rejects_a_guest_still_pending_in_the_waiting_room(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => true,
        ]);

        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);

        $this->expectException(\RuntimeException::class);
        $this->signaling->resolveActor($meeting, null, $result['guest_token']);
    }

    // -----------------------------------------------------------------
    // Channel auth (بند 6/33 — بروتوكول Pusher/Reverb)
    // -----------------------------------------------------------------

    #[Test]
    public function expected_channel_name_is_a_presence_channel_scoped_to_the_meeting_uuid(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $this->assertSame('presence-meeting.' . $meeting->uuid, $this->signaling->expectedChannelName($meeting));
    }

    #[Test]
    public function authorize_channel_signs_correctly_for_a_joined_participant(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $channelName = $this->signaling->expectedChannelName($meeting);

        $payload = $this->signaling->authorizeChannel($meeting, $hostId, null, 'socket-123.456', $channelName);

        $this->assertArrayHasKey('auth', $payload);
        $this->assertArrayHasKey('channel_data', $payload);
        $this->assertStringStartsWith('test-reverb-key:', $payload['auth']);

        $decodedChannelData = json_decode($payload['channel_data'], true);
        $this->assertSame('user:' . $hostId, $decodedChannelData['user_id']);
        $this->assertSame('Host', $decodedChannelData['user_info']['name']);
        $this->assertFalse($decodedChannelData['user_info']['is_guest']);

        // نتأكد إن التوقيع فعلًا HMAC-SHA256 بنفس البروتوكول اللي Reverb/pusher-js بيفهمه.
        $stringToSign = "socket-123.456:{$channelName}:{$payload['channel_data']}";
        $expectedSignature = hash_hmac('sha256', $stringToSign, 'test-reverb-secret');
        $this->assertSame('test-reverb-key:' . $expectedSignature, $payload['auth']);
    }

    #[Test]
    public function authorize_channel_marks_a_guest_as_is_guest_true_in_channel_data(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => false,
        ]);
        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);
        $channelName = $this->signaling->expectedChannelName($meeting);

        $payload = $this->signaling->authorizeChannel($meeting, null, $result['guest_token'], 'socket-abc', $channelName);

        $decodedChannelData = json_decode($payload['channel_data'], true);
        $this->assertSame('guest:' . $result['join_request']->id, $decodedChannelData['user_id']);
        $this->assertTrue($decodedChannelData['user_info']['is_guest']);
    }

    #[Test]
    public function authorize_channel_rejects_a_channel_name_for_a_different_meeting(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $this->expectException(\InvalidArgumentException::class);
        $this->signaling->authorizeChannel($meeting, $hostId, null, 'socket-123', 'presence-meeting.some-other-uuid');
    }

    #[Test]
    public function authorize_channel_rejects_a_stranger_who_never_joined(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $this->expectException(\RuntimeException::class);
        $this->signaling->authorizeChannel($meeting, $strangerId, null, 'socket-123', $this->signaling->expectedChannelName($meeting));
    }

    #[Test]
    public function sign_channel_omits_channel_data_for_a_plain_private_channel(): void
    {
        $payload = $this->signaling->signChannel('socket-1', 'private-something', null);

        $this->assertArrayHasKey('auth', $payload);
        $this->assertArrayNotHasKey('channel_data', $payload);

        $expectedSignature = hash_hmac('sha256', 'socket-1:private-something', 'test-reverb-secret');
        $this->assertSame('test-reverb-key:' . $expectedSignature, $payload['auth']);
    }

    // -----------------------------------------------------------------
    // Media / connection state (بند 6/34)
    // -----------------------------------------------------------------

    #[Test]
    public function update_media_state_persists_the_flags_and_broadcasts_the_change(): void
    {
        Event::fake([ParticipantMediaStateChanged::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $result = $this->signaling->updateMediaState($meeting, $actor, [
            'mic_enabled'    => false,
            'camera_enabled' => true,
            'screen_sharing' => true,
        ]);

        $this->assertFalse($result['mic_enabled']);
        $this->assertTrue($result['camera_enabled']);
        $this->assertTrue($result['screen_sharing']);

        $participant = $this->meetings->findParticipant($meeting->id, $hostId);
        $this->assertFalse($participant->mic_enabled);
        $this->assertTrue($participant->camera_enabled);
        $this->assertTrue($participant->screen_sharing);
        $this->assertNotNull($participant->last_seen_at);

        Event::assertDispatched(ParticipantMediaStateChanged::class, function ($event) use ($meeting, $hostId) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === 'user:' . $hostId
                && $event->micEnabled === false
                && $event->cameraEnabled === true;
        });
    }

    #[Test]
    public function update_media_state_only_touches_the_keys_that_were_sent(): void
    {
        Event::fake([ParticipantMediaStateChanged::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->signaling->updateMediaState($meeting, $actor, ['mic_enabled' => false, 'camera_enabled' => false]);
        $result = $this->signaling->updateMediaState($meeting, $actor, ['screen_sharing' => true]);

        $this->assertFalse($result['mic_enabled']);
        $this->assertFalse($result['camera_enabled']);
        $this->assertTrue($result['screen_sharing']);
    }

    #[Test]
    public function update_connection_state_persists_and_broadcasts_a_valid_state(): void
    {
        Event::fake([ParticipantConnectionStateChanged::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $state = $this->signaling->updateConnectionState($meeting, $actor, 'reconnecting');

        $this->assertSame('reconnecting', $state);
        $participant = $this->meetings->findParticipant($meeting->id, $hostId);
        $this->assertSame('reconnecting', $participant->connection_state);

        Event::assertDispatched(ParticipantConnectionStateChanged::class, function ($event) use ($meeting, $hostId) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === 'user:' . $hostId
                && $event->connectionState === 'reconnecting';
        });
    }

    #[Test]
    public function update_connection_state_rejects_an_unknown_state(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->signaling->updateConnectionState($meeting, $actor, 'teleporting');
    }

    #[Test]
    public function heartbeat_updates_last_seen_at_and_marks_the_participant_connected(): void
    {
        Event::fake();

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);
        $this->signaling->updateConnectionState($meeting, $actor, 'disconnected');

        $this->signaling->heartbeat($actor);

        $participant = $this->meetings->findParticipant($meeting->id, $hostId)->fresh();
        $this->assertSame('connected', $participant->connection_state);
        $this->assertNotNull($participant->last_seen_at);
    }

    #[Test]
    public function heartbeat_does_not_broadcast_anything(): void
    {
        Event::fake([ParticipantConnectionStateChanged::class, ParticipantMediaStateChanged::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->signaling->heartbeat($actor);

        Event::assertNotDispatched(ParticipantConnectionStateChanged::class);
        Event::assertNotDispatched(ParticipantMediaStateChanged::class);
    }

    // -----------------------------------------------------------------
    // Leave (خروج متعمّد) — لمستخدم مسجّل وضيف
    // -----------------------------------------------------------------

    #[Test]
    public function leave_marks_a_registered_participant_as_left_and_broadcasts_it(): void
    {
        Event::fake([ParticipantLeftMeeting::class]);

        $hostId = $this->makeUser('Host');
        $memberId = $this->makeUser('Member');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $this->lobby->requestAccess($meeting, $memberId, []);
        $actor = $this->signaling->resolveActor($meeting, $memberId, null);

        $this->signaling->leave($meeting, $actor);

        $participant = $this->meetings->findParticipant($meeting->id, $memberId)->fresh();
        $this->assertSame('left', $participant->status);
        $this->assertSame('disconnected', $participant->connection_state);
        $this->assertNotNull($participant->left_at);

        Event::assertDispatched(ParticipantLeftMeeting::class, function ($event) use ($meeting, $memberId) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === 'user:' . $memberId
                && $event->displayName === 'Member';
        });
    }

    #[Test]
    public function leave_marks_an_admitted_guest_as_left_and_broadcasts_it(): void
    {
        Event::fake([ParticipantLeftMeeting::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => false,
        ]);
        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);
        $actor = $this->signaling->resolveActor($meeting, null, $result['guest_token']);

        $this->signaling->leave($meeting, $actor);

        $joinRequest = $result['join_request']->fresh();
        $this->assertSame('left', $joinRequest->status);
        $this->assertSame('disconnected', $joinRequest->connection_state);
        $this->assertNotNull($joinRequest->left_at);

        Event::assertDispatched(ParticipantLeftMeeting::class, function ($event) use ($meeting, $result) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === 'guest:' . $result['join_request']->id
                && $event->displayName === 'Ahmed';
        });
    }

    #[Test]
    public function a_participant_who_already_left_can_still_be_resolved_for_a_late_socket_event(): void
    {
        // resolveActor() بيقبل status 'joined' أو 'left' عمدًا (راجع
        // docblock الميثود) — عشان أحداث الـ socket المتأخرة (قطع اتصال
        // وصل بعد الـ leave الصريح) ما ترميش استثناء 403 من غير داعي.
        $hostId = $this->makeUser('Host');
        $memberId = $this->makeUser('Member');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $this->lobby->requestAccess($meeting, $memberId, []);
        $actor = $this->signaling->resolveActor($meeting, $memberId, null);
        $this->signaling->leave($meeting, $actor);

        $resolved = $this->signaling->resolveActor($meeting, $memberId, null);
        $this->assertSame('left', $resolved['model']->status);
    }

    // -----------------------------------------------------------------
    // Meeting-level status broadcast (بند 33) + تكامله مع Round 1
    // -----------------------------------------------------------------

    #[Test]
    public function broadcast_status_emits_the_meetings_current_status(): void
    {
        Event::fake([MeetingStatusBroadcast::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $this->signaling->broadcastStatus($meeting);

        Event::assertDispatched(MeetingStatusBroadcast::class, function ($event) use ($meeting) {
            return $event->meetingUuid === $meeting->uuid && $event->status === $meeting->status;
        });
    }

    #[Test]
    public function starting_a_meeting_through_the_service_broadcasts_its_new_status(): void
    {
        Event::fake([MeetingStatusBroadcast::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $started = $this->service->start($meeting);

        Event::assertDispatched(MeetingStatusBroadcast::class, function ($event) use ($started) {
            return $event->meetingUuid === $started->uuid && $event->status === 'live';
        });
    }

    #[Test]
    public function ending_and_cancelling_a_meeting_through_the_service_each_broadcast_their_status(): void
    {
        Event::fake([MeetingStatusBroadcast::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $started = $this->service->start($meeting);
        $ended = $this->service->end($started);

        Event::assertDispatched(MeetingStatusBroadcast::class, function ($event) use ($ended) {
            return $event->meetingUuid === $ended->uuid && $event->status === 'ended';
        });

        $secondMeeting = $this->service->create($hostId, ['title' => 'Another one']);
        $cancelled = $this->service->cancel($secondMeeting);

        Event::assertDispatched(MeetingStatusBroadcast::class, function ($event) use ($cancelled) {
            return $event->meetingUuid === $cancelled->uuid && $event->status === 'cancelled';
        });
    }

    #[Test]
    public function meeting_service_without_a_signaling_dependency_never_broadcasts_anything(): void
    {
        // زي MeetingFoundationTest/MeetingLobbyTest بالظبط: `new
        // MeetingService($meetings, $policy)` من غير signaling لازم
        // يفضل شغال من غير أي استثناء ومن غير أي broadcast.
        Event::fake([MeetingStatusBroadcast::class]);

        $legacyService = new MeetingService($this->meetings, $this->policy);
        $hostId = $this->makeUser('Host');
        $meeting = $legacyService->create($hostId, ['title' => 'Standup']);
        $legacyService->start($meeting);

        Event::assertNotDispatched(MeetingStatusBroadcast::class);
    }
}
