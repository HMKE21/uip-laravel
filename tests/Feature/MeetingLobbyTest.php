<?php

namespace Tests\Feature;

use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;
use App\Services\MeetingLobbyService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingService;
use App\Services\UipJwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لـ Round 2 (Lobby & Access) بتاع موديول الاجتماعات:
 * MeetingLobbyService (pre-join info، طلب الدخول، الـ waiting room،
 * الدخول كضيف) + الأعمدة/الميثودز الجديدة في MeetingRepository
 * (join requests) وMeetingJoinRequest::displayLabel().
 *
 * ملحوظة بيئة: زي MeetingFoundationTest بالظبط — DatabaseTransactions،
 * جدول `users` بس محتاج migrate فعلي (test_support_tables)، الباقي
 * (meetings/meeting_join_requests) بيتعمله migrate عادي عن طريق
 * migrations الحقيقية بتاعة المشروع وقت تشغيل السويت.
 *
 * JWT_SECRET: UipJwtService::secret() بترمي لو مش متظبطة — الاختبارات
 * القديمة (Round 1) ما كانتش محتاجاها لأنها مبتعديش على أي JWT، ده أول
 * اختبار فعليًا بيولّد/يفكّ توكن ضيف، فبنظبطها في setUp() بقيمة تجريبية
 * ثابتة (putenv بدل تعديل .env الحقيقي) بدل ما نفترض إنها متظبطة في
 * بيئة الـ CI.
 */
class MeetingLobbyTest extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingLobbyService $lobby;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-meeting-lobby-tests');
        }

        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->service = new MeetingService($this->meetings, $this->policy);
        $this->lobby = new MeetingLobbyService($this->meetings, $this->policy);
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
    // Pre-Join Screen (بند 3)
    // -----------------------------------------------------------------

    #[Test]
    public function pre_join_info_reflects_meeting_settings_without_leaking_secrets(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Design Review',
            'password'             => 'secret123',
            'waiting_room_enabled' => true,
            'allow_guests'         => true,
        ]);

        $info = $this->lobby->preJoinInfo($meeting, null);

        $this->assertSame('Design Review', $info['meeting']['title']);
        $this->assertTrue($info['requires_password']);
        $this->assertTrue($info['waiting_room_enabled']);
        $this->assertTrue($info['allow_guests']);
        $this->assertFalse($info['is_authenticated']);
        $this->assertFalse($info['already_participant']);
        $this->assertFalse($info['bypasses_waiting_room']);
        $this->assertArrayNotHasKey('password_hash', $info['meeting']);
        $this->assertArrayNotHasKey('join_token', $info['meeting']);
    }

    #[Test]
    public function pre_join_info_marks_the_host_as_bypassing_the_waiting_room(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Standup']);

        $hostInfo = $this->lobby->preJoinInfo($meeting, $hostId);
        $strangerInfo = $this->lobby->preJoinInfo($meeting, $strangerId);

        $this->assertTrue($hostInfo['bypasses_waiting_room']);
        $this->assertTrue($hostInfo['already_participant']);
        $this->assertFalse($strangerInfo['bypasses_waiting_room']);
        $this->assertFalse($strangerInfo['already_participant']);
    }

    #[Test]
    public function verify_password_matches_round1_hashing(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Private Sync', 'password' => 'secret123']);

        $this->assertTrue($this->lobby->verifyPassword($meeting, 'secret123'));
        $this->assertFalse($this->lobby->verifyPassword($meeting, 'wrong'));
        $this->assertFalse($this->lobby->verifyPassword($meeting, null));

        $noPasswordMeeting = $this->service->create($hostId, ['title' => 'Open Meeting']);
        $this->assertTrue($this->lobby->verifyPassword($noPasswordMeeting, null));
    }

    // -----------------------------------------------------------------
    // Waiting Room (بند 22)
    // -----------------------------------------------------------------

    #[Test]
    public function host_and_co_host_are_admitted_immediately_never_queued(): void
    {
        $hostId = $this->makeUser('Host');
        $coHostId = $this->makeUser('Co-Host');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review', 'waiting_room_enabled' => true]);

        $this->meetings->addParticipant([
            'meeting_id' => $meeting->id,
            'user_id'    => $coHostId,
            'role'       => 'co_host',
            'status'     => 'joined',
            'joined_at'  => now(),
        ]);

        $hostResult = $this->lobby->requestAccess($meeting, $hostId, []);
        $this->assertSame('admitted', $hostResult['status']);

        $coHostResult = $this->lobby->requestAccess($meeting, $coHostId, []);
        $this->assertSame('admitted', $coHostResult['status']);

        $this->assertSame(0, count($this->meetings->pendingJoinRequestsFor($meeting->id)));
    }

    #[Test]
    public function an_authenticated_stranger_is_queued_when_the_waiting_room_is_enabled(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review', 'waiting_room_enabled' => true]);

        $result = $this->lobby->requestAccess($meeting, $strangerId, []);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('pending', $result['join_request']->status);

        $participant = $this->meetings->findParticipant($meeting->id, $strangerId);
        $this->assertNull($participant, 'A queued user should not become a participant before being admitted.');
    }

    #[Test]
    public function an_authenticated_stranger_is_admitted_directly_when_the_waiting_room_is_disabled(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Open Standup', 'waiting_room_enabled' => false]);

        $result = $this->lobby->requestAccess($meeting, $strangerId, []);

        $this->assertSame('admitted', $result['status']);
        $participant = $this->meetings->findParticipant($meeting->id, $strangerId);
        $this->assertNotNull($participant);
        $this->assertSame('joined', $participant->status);
    }

    #[Test]
    public function re_requesting_access_reuses_the_existing_pending_request(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review', 'waiting_room_enabled' => true]);

        $first = $this->lobby->requestAccess($meeting, $strangerId, []);
        $second = $this->lobby->requestAccess($meeting, $strangerId, []);

        $this->assertSame($first['join_request']->id, $second['join_request']->id);
        $this->assertSame(1, count($this->meetings->pendingJoinRequestsFor($meeting->id)));
    }

    #[Test]
    public function the_host_can_accept_a_pending_request_which_admits_the_participant(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review', 'waiting_room_enabled' => true]);

        $result = $this->lobby->requestAccess($meeting, $strangerId, []);
        $joinRequest = $this->lobby->decide($meeting, $result['join_request'], true, $hostId);

        $this->assertSame('admitted', $joinRequest->status);
        $this->assertNotNull($joinRequest->decided_at);
        $this->assertSame($hostId, $joinRequest->decided_by_user_id);

        $participant = $this->meetings->findParticipant($meeting->id, $strangerId);
        $this->assertNotNull($participant);
        $this->assertSame('joined', $participant->status);
    }

    #[Test]
    public function the_host_can_reject_a_pending_request_without_creating_a_participant(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review', 'waiting_room_enabled' => true]);

        $result = $this->lobby->requestAccess($meeting, $strangerId, []);
        $joinRequest = $this->lobby->decide($meeting, $result['join_request'], false, $hostId);

        $this->assertSame('rejected', $joinRequest->status);
        $this->assertNull($this->meetings->findParticipant($meeting->id, $strangerId));
    }

    #[Test]
    public function deciding_an_already_decided_request_is_rejected(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review', 'waiting_room_enabled' => true]);

        $result = $this->lobby->requestAccess($meeting, $strangerId, []);
        $this->lobby->decide($meeting, $result['join_request'], true, $hostId);

        $this->expectException(\RuntimeException::class);
        $this->lobby->decide($meeting, $result['join_request']->fresh(), true, $hostId);
    }

    #[Test]
    public function accept_all_and_reject_all_decide_every_pending_request(): void
    {
        $hostId = $this->makeUser('Host');
        $userA = $this->makeUser('A');
        $userB = $this->makeUser('B');
        $userC = $this->makeUser('C');
        $meeting = $this->service->create($hostId, ['title' => 'Town Hall', 'waiting_room_enabled' => true]);

        $this->lobby->requestAccess($meeting, $userA, []);
        $this->lobby->requestAccess($meeting, $userB, []);
        $acceptedCount = $this->lobby->decideAll($meeting, true, $hostId);
        $this->assertSame(2, $acceptedCount);
        $this->assertSame('joined', $this->meetings->findParticipant($meeting->id, $userA)->status);
        $this->assertSame('joined', $this->meetings->findParticipant($meeting->id, $userB)->status);

        $this->lobby->requestAccess($meeting, $userC, []);
        $rejectedCount = $this->lobby->decideAll($meeting, false, $hostId);
        $this->assertSame(1, $rejectedCount);
        $this->assertNull($this->meetings->findParticipant($meeting->id, $userC));

        $this->assertSame(0, count($this->meetings->pendingJoinRequestsFor($meeting->id)));
    }

    #[Test]
    public function a_meeting_that_has_ended_can_no_longer_be_joined(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $meeting = $this->service->start($meeting);
        $meeting = $this->service->end($meeting);

        $this->expectException(\RuntimeException::class);
        $this->lobby->requestAccess($meeting, $strangerId, []);
    }

    #[Test]
    public function requesting_access_with_the_wrong_password_is_rejected(): void
    {
        $hostId = $this->makeUser('Host');
        $strangerId = $this->makeUser('Stranger');
        $meeting = $this->service->create($hostId, ['title' => 'Private Sync', 'password' => 'secret123']);

        $this->expectException(\InvalidArgumentException::class);
        $this->lobby->requestAccess($meeting, $strangerId, ['password' => 'wrong']);
    }

    #[Test]
    public function the_meeting_capacity_is_enforced_across_joined_participants_and_admitted_guests(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Tiny Room',
            'max_participants'     => 2,
            'waiting_room_enabled' => false,
            'allow_guests'         => true,
        ]);
        // الهوست نفسه بيتعد ضمن الـ 2 (host participant اتعمل وقت create()).

        $secondUser = $this->makeUser('Second');
        $this->lobby->requestAccess($meeting, $secondUser, []);

        $this->expectException(\RuntimeException::class);
        $this->lobby->requestAccess($meeting, null, ['display_name' => 'Overflow Guest']);
    }

    // -----------------------------------------------------------------
    // Guest Access (بند 23)
    // -----------------------------------------------------------------

    #[Test]
    public function guests_are_rejected_when_guest_access_is_disabled(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Internal Only', 'allow_guests' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);
    }

    #[Test]
    public function guests_must_provide_a_display_name(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Open Talk', 'allow_guests' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->lobby->requestAccess($meeting, null, []);
    }

    #[Test]
    public function a_guest_is_queued_in_the_waiting_room_and_receives_a_usable_guest_token(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => true,
        ]);

        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);

        $this->assertSame('pending', $result['status']);
        $this->assertTrue($result['join_request']->isGuest());
        $this->assertSame('Guest — Ahmed', $result['join_request']->displayLabel());
        $this->assertArrayHasKey('guest_token', $result);

        $resolved = $this->lobby->resolveGuestToken($result['guest_token'], $meeting->id);
        $this->assertNotNull($resolved);
        $this->assertSame($result['join_request']->id, $resolved->id);

        // توكن ضيف تاني (على اجتماع تاني مثلًا) مايفتحش نفس الطلب.
        $bogus = UipJwtService::encode(['typ' => 'meeting_guest', 'join_request_id' => $result['join_request']->id, 'meeting_id' => $meeting->id + 999], 3600);
        $this->assertNull($this->lobby->resolveGuestToken($bogus, $meeting->id));
    }

    #[Test]
    public function a_guest_is_admitted_directly_when_the_waiting_room_is_disabled(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Open Webinar',
            'allow_guests'         => true,
            'waiting_room_enabled' => false,
        ]);

        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Sara']);

        $this->assertSame('admitted', $result['status']);
        $this->assertSame('admitted', $result['join_request']->status);
        $this->assertContains($result['join_request']->id, array_map(
            fn ($g) => $g->id,
            $this->meetings->admittedGuestsFor($meeting->id)
        ));
    }

    #[Test]
    public function the_host_can_admit_a_queued_guest_from_the_waiting_room(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Public Seminar',
            'allow_guests'         => true,
            'waiting_room_enabled' => true,
        ]);

        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => 'Ahmed']);
        $joinRequest = $this->lobby->decide($meeting, $result['join_request'], true, $hostId);

        $this->assertSame('admitted', $joinRequest->status);
        // ضيف مقبول لسه مفيش صف موازي ليه في meeting_participants — هو
        // نفسه سجل حضوره (راجع docblock MeetingJoinRequest).
        $this->assertNull($this->meetings->findParticipant($meeting->id, 0));
        $this->assertCount(1, $this->meetings->admittedGuestsFor($meeting->id));
    }
}
