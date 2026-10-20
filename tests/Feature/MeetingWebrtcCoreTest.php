<?php

namespace Tests\Feature;

use App\Events\Meetings\ParticipantConnectionQualityChanged;
use App\Events\Meetings\ParticipantMediaFailure;
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
 * Regression tests لـ Round 4 (WebRTC Core) بتاع موديول الاجتماعات:
 * MeetingSignalingService::iceServers()/updateConnectionQuality()/
 * reportFailure()/roster()/meshCapacity() — بند 4 (Participant Grid)،
 * بند 6 (STUN/TURN)، بند 34 (Connection Quality).
 *
 * تبادل SDP/ICE الفعلي نفسه مش موجود هنا عمدًا (بيتم عبر client events
 * على presence channel مباشرة، راجع docblock config/webrtc.php) —
 * الاختبارات دي بتغطي الجزء اللي فعلًا جوه لارافيل بس.
 */
class MeetingWebrtcCoreTest extends TestCase
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
            putenv('JWT_SECRET=test-secret-for-meeting-webrtc-tests');
        }

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
    // ICE servers (بند 6)
    // -----------------------------------------------------------------

    #[Test]
    public function ice_servers_always_include_the_configured_stun_urls(): void
    {
        config([
            'webrtc.stun_urls'   => ['stun:stun.example.com:19302'],
            'webrtc.turn_urls'   => [],
            'webrtc.turn_secret' => null,
        ]);

        $servers = $this->signaling->iceServers();

        $this->assertCount(1, $servers);
        $this->assertSame(['stun:stun.example.com:19302'], $servers[0]['urls']);
        $this->assertArrayNotHasKey('username', $servers[0]);
    }

    #[Test]
    public function ice_servers_generate_a_time_limited_turn_credential_when_turn_is_configured(): void
    {
        config([
            'webrtc.stun_urls'                => ['stun:stun.example.com:19302'],
            'webrtc.turn_urls'                => ['turn:turn.example.com:3478'],
            'webrtc.turn_secret'              => 'super-secret',
            'webrtc.turn_credential_ttl_seconds' => 600,
        ]);

        $before = time();
        $servers = $this->signaling->iceServers();
        $after = time();

        $this->assertCount(2, $servers);
        $turnServer = $servers[1];
        $this->assertSame(['turn:turn.example.com:3478'], $turnServer['urls']);
        $this->assertArrayHasKey('username', $turnServer);
        $this->assertArrayHasKey('credential', $turnServer);

        // الـ username لازم يكون timestamp انتهاء صالح ضمن نافذة الـ TTL.
        $expiry = (int) $turnServer['username'];
        $this->assertGreaterThanOrEqual($before + 600, $expiry);
        $this->assertLessThanOrEqual($after + 600, $expiry);

        // نتأكد إن الـ credential فعلًا HMAC-SHA1(secret, username) base64
        // (بروتوكول coturn REST API القياسي).
        $expectedCredential = base64_encode(hash_hmac('sha1', $turnServer['username'], 'super-secret', true));
        $this->assertSame($expectedCredential, $turnServer['credential']);
    }

    #[Test]
    public function ice_servers_omit_turn_when_urls_are_configured_but_the_secret_is_missing(): void
    {
        config([
            'webrtc.stun_urls'   => ['stun:stun.example.com:19302'],
            'webrtc.turn_urls'   => ['turn:turn.example.com:3478'],
            'webrtc.turn_secret' => '',
        ]);

        $servers = $this->signaling->iceServers();

        $this->assertCount(1, $servers);
    }

    // -----------------------------------------------------------------
    // Connection quality (بند 34)
    // -----------------------------------------------------------------

    #[Test]
    public function update_connection_quality_persists_and_broadcasts_a_valid_value(): void
    {
        Event::fake([ParticipantConnectionQualityChanged::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $quality = $this->signaling->updateConnectionQuality($meeting, $actor, 'poor');

        $this->assertSame('poor', $quality);
        $participant = $this->meetings->findParticipant($meeting->id, $hostId);
        $this->assertSame('poor', $participant->connection_quality);

        Event::assertDispatched(ParticipantConnectionQualityChanged::class, function ($event) use ($meeting, $hostId) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === 'user:' . $hostId
                && $event->connectionQuality === 'poor';
        });
    }

    #[Test]
    public function update_connection_quality_rejects_an_unknown_value(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->signaling->updateConnectionQuality($meeting, $actor, 'amazing');
    }

    // -----------------------------------------------------------------
    // Failure feedback (بند 34)
    // -----------------------------------------------------------------

    #[Test]
    public function report_failure_broadcasts_the_failure_with_no_state_change(): void
    {
        Event::fake([ParticipantMediaFailure::class]);

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->signaling->reportFailure($meeting, $actor, 'camera', 'NotAllowedError');

        $participant = $this->meetings->findParticipant($meeting->id, $hostId);
        $this->assertFalse((bool) $participant->camera_enabled);

        Event::assertDispatched(ParticipantMediaFailure::class, function ($event) use ($meeting, $hostId) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === 'user:' . $hostId
                && $event->failureType === 'camera'
                && $event->message === 'NotAllowedError';
        });
    }

    #[Test]
    public function report_failure_rejects_an_unknown_failure_type(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->signaling->reportFailure($meeting, $actor, 'teleporter');
    }

    // -----------------------------------------------------------------
    // Roster / grid (بند 4) + mesh capacity
    // -----------------------------------------------------------------

    #[Test]
    public function roster_includes_joined_participants_and_admitted_guests_but_not_left_ones(): void
    {
        $hostId = $this->makeUser('Host');
        $memberId = $this->makeUser('Member');
        $leftMemberId = $this->makeUser('Gone');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Open Standup',
            'allow_guests'         => true,
            'waiting_room_enabled' => false,
        ]);

        $this->lobby->requestAccess($meeting, $memberId, []);

        $this->lobby->requestAccess($meeting, $leftMemberId, []);
        $leftActor = $this->signaling->resolveActor($meeting, $leftMemberId, null);
        $this->signaling->leave($meeting, $leftActor);

        $guestResult = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);

        $roster = $this->signaling->roster($meeting);
        $keys = array_column($roster, 'key');

        $this->assertContains('user:' . $hostId, $keys);
        $this->assertContains('user:' . $memberId, $keys);
        $this->assertContains('guest:' . $guestResult['join_request']->id, $keys);
        $this->assertNotContains('user:' . $leftMemberId, $keys, 'A participant who left should not appear in the live grid.');
    }

    #[Test]
    public function roster_excludes_a_guest_still_waiting_to_be_admitted(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => true,
        ]);

        $pendingGuestResult = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Sara']);

        $roster = $this->signaling->roster($meeting);

        $this->assertNotContains(
            'guest:' . $pendingGuestResult['join_request']->id,
            array_column($roster, 'key'),
            'A guest still in the waiting room should not appear in the live grid.'
        );
    }

    #[Test]
    public function roster_flags_the_host_and_reflects_media_state(): void
    {
        Event::fake();

        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);
        $this->signaling->updateMediaState($meeting, $actor, ['mic_enabled' => false, 'camera_enabled' => true]);

        $roster = $this->signaling->roster($meeting);
        $hostRow = collect($roster)->firstWhere('key', 'user:' . $hostId);

        $this->assertNotNull($hostRow);
        $this->assertTrue($hostRow['is_host']);
        $this->assertFalse($hostRow['mic_enabled']);
        $this->assertTrue($hostRow['camera_enabled']);
    }

    #[Test]
    public function mesh_capacity_reports_the_policy_limit_and_current_headcount(): void
    {
        $hostId = $this->makeUser('Host');
        $memberId = $this->makeUser('Member');
        $meeting = $this->service->create($hostId, ['title' => 'Standup', 'waiting_room_enabled' => false]);
        $this->lobby->requestAccess($meeting, $memberId, []);

        $capacity = $this->signaling->meshCapacity($meeting);

        $this->assertSame($this->policy->maxMeshParticipants(), $capacity['limit']);
        $this->assertSame(2, $capacity['current']);
        $this->assertFalse($capacity['exceeds_recommended']);
    }

    #[Test]
    public function mesh_capacity_flags_when_the_headcount_exceeds_the_configured_limit(): void
    {
        config(['meetings.max_mesh_participants' => 2]);
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->signaling = new MeetingSignalingService($this->meetings, $this->lobby, $this->policy);

        $hostId = $this->makeUser('Host');
        $memberA = $this->makeUser('A');
        $memberB = $this->makeUser('B');
        $meeting = $this->service->create($hostId, ['title' => 'Crowded Standup', 'waiting_room_enabled' => false]);
        $this->lobby->requestAccess($meeting, $memberA, []);
        $this->lobby->requestAccess($meeting, $memberB, []);

        $capacity = $this->signaling->meshCapacity($meeting);

        $this->assertSame(3, $capacity['current']);
        $this->assertTrue($capacity['exceeds_recommended']);
    }
}
