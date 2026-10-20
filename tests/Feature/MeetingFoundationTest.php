<?php

namespace Tests\Feature;

use App\Repositories\MeetingRepository;
use App\Services\MeetingPolicyService;
use App\Services\MeetingService;
use App\Repositories\SettingRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لـ Round 1 (Foundation) بتاع موديول الاجتماعات:
 * MeetingRepository (توليد uuid/meeting_code/join_token فريدة)،
 * MeetingService (create/update/invite/respondToInvitation)، و
 * MeetingPolicyService (isHost/canManage/canView).
 *
 * ملحوظة بيئة: زي ExamTargetEligibilityTest بالظبط — DatabaseTransactions
 * بدل RefreshDatabase عشان الجدول الوحيد المحتاج هنا فعليًا هو `users`
 * (موجود من 0001_01_01_000000 + أعمدة test_support_tables في بيئة
 * الاختبار)، ومفيش داعي لعمل migrate لباقي جداول الموديولات التانية.
 */
class MeetingFoundationTest extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->service = new MeetingService($this->meetings, $this->policy);
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

    #[Test]
    public function it_generates_unique_meeting_identifiers(): void
    {
        $uuid1 = $this->meetings->generateUniqueUuid();
        $uuid2 = $this->meetings->generateUniqueUuid();
        $this->assertNotEquals($uuid1, $uuid2);

        $code1 = $this->meetings->generateUniqueMeetingCode();
        $code2 = $this->meetings->generateUniqueMeetingCode();
        $this->assertNotEquals($code1, $code2);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $code1);

        $token1 = $this->meetings->generateUniqueJoinToken();
        $token2 = $this->meetings->generateUniqueJoinToken();
        $this->assertNotEquals($token1, $token2);
        $this->assertSame(32, strlen($token1));
    }

    #[Test]
    public function creating_a_meeting_registers_the_host_as_a_joined_participant(): void
    {
        $hostId = $this->makeUser('Host');

        $meeting = $this->service->create($hostId, ['title' => 'Sprint Planning']);

        $this->assertSame('scheduled', $meeting->status);
        $this->assertSame('instant', $meeting->type);
        $this->assertNotEmpty($meeting->uuid);
        $this->assertNotEmpty($meeting->meeting_code);
        $this->assertNotEmpty($meeting->join_token);
        $this->assertNull($meeting->password_hash);

        $participant = $this->meetings->findParticipant($meeting->id, $hostId);
        $this->assertNotNull($participant);
        $this->assertSame('host', $participant->role);
        $this->assertSame('joined', $participant->status);
    }

    #[Test]
    public function scheduled_meetings_require_a_start_time_but_instant_ones_do_not(): void
    {
        $hostId = $this->makeUser('Host');

        $scheduled = $this->service->create($hostId, [
            'title'              => 'Weekly Sync',
            'type'               => 'scheduled',
            'scheduled_start_at' => now()->addDay(),
        ]);
        $this->assertSame('scheduled', $scheduled->type);
        $this->assertNotNull($scheduled->scheduled_start_at);

        $instant = $this->service->create($hostId, ['title' => 'Quick Call']);
        $this->assertNull($instant->scheduled_start_at);
    }

    #[Test]
    public function max_participants_is_capped_to_the_mesh_limit(): void
    {
        $hostId = $this->makeUser('Host');

        $meeting = $this->service->create($hostId, [
            'title'            => 'Big Meeting',
            'max_participants' => 500,
        ]);

        $this->assertSame($this->policy->maxMeshParticipants(), $meeting->max_participants);
    }

    #[Test]
    public function a_meeting_password_is_hashed_and_can_be_changed(): void
    {
        $hostId = $this->makeUser('Host');

        $meeting = $this->service->create($hostId, [
            'title'    => 'Private Sync',
            'password' => 'secret123',
        ]);
        $this->assertTrue($meeting->hasPassword());
        $this->assertTrue(Hash::check('secret123', $meeting->password_hash));

        $meeting = $this->service->update($meeting, ['password' => 'newpass456']);
        $this->assertTrue(Hash::check('newpass456', $meeting->password_hash));

        $meeting = $this->service->update($meeting, ['password' => null]);
        $this->assertFalse($meeting->hasPassword());
    }

    #[Test]
    public function inviting_a_user_creates_a_pending_invitation_and_an_invited_participant(): void
    {
        $hostId = $this->makeUser('Host');
        $inviteeId = $this->makeUser('Invitee');

        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $invitation = $this->service->invite($meeting, $hostId, $inviteeId, 'Please join us');

        $this->assertSame('pending', $invitation->status);
        $this->assertSame('Please join us', $invitation->message);
        $this->assertNotEmpty($invitation->token);

        $participant = $this->meetings->findParticipant($meeting->id, $inviteeId);
        $this->assertNotNull($participant);
        $this->assertSame('invited', $participant->status);
        $this->assertSame($hostId, $participant->invited_by_user_id);
    }

    #[Test]
    public function accepting_an_invitation_marks_the_participant_as_joined(): void
    {
        $hostId = $this->makeUser('Host');
        $inviteeId = $this->makeUser('Invitee');

        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $invitation = $this->service->invite($meeting, $hostId, $inviteeId);

        $invitation = $this->service->respondToInvitation($invitation, $inviteeId, true);

        $this->assertSame('accepted', $invitation->status);
        $this->assertNotNull($invitation->responded_at);

        $participant = $this->meetings->findParticipant($meeting->id, $inviteeId);
        $this->assertSame('joined', $participant->status);
        $this->assertNotNull($participant->joined_at);
    }

    #[Test]
    public function declining_an_invitation_and_re_inviting_resets_it_to_pending(): void
    {
        $hostId = $this->makeUser('Host');
        $inviteeId = $this->makeUser('Invitee');

        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $invitation = $this->service->invite($meeting, $hostId, $inviteeId);
        $invitation = $this->service->respondToInvitation($invitation, $inviteeId, false);

        $this->assertSame('declined', $invitation->status);
        $participant = $this->meetings->findParticipant($meeting->id, $inviteeId);
        $this->assertSame('declined', $participant->status);

        // إعادة الدعوة بعد الرفض لازم تعيد استخدام نفس صف الدعوة (unique
        // constraint على meeting_id+invited_user_id) بدل ما تحاول تدرج
        // صف جديد وتفشل.
        $reInvitation = $this->service->invite($meeting, $hostId, $inviteeId);
        $this->assertSame($invitation->id, $reInvitation->id);
        $this->assertSame('pending', $reInvitation->status);

        $participant = $this->meetings->findParticipant($meeting->id, $inviteeId);
        $this->assertSame('invited', $participant->status);
    }

    #[Test]
    public function responding_twice_to_the_same_invitation_is_rejected(): void
    {
        $hostId = $this->makeUser('Host');
        $inviteeId = $this->makeUser('Invitee');

        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $invitation = $this->service->invite($meeting, $hostId, $inviteeId);
        $this->service->respondToInvitation($invitation, $inviteeId, true);

        $this->expectException(\RuntimeException::class);
        $this->service->respondToInvitation($invitation->fresh(), $inviteeId, true);
    }

    #[Test]
    public function a_user_cannot_respond_to_someone_elses_invitation(): void
    {
        $hostId = $this->makeUser('Host');
        $inviteeId = $this->makeUser('Invitee');
        $strangerId = $this->makeUser('Stranger');

        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $invitation = $this->service->invite($meeting, $hostId, $inviteeId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->respondToInvitation($invitation, $strangerId, true);
    }

    #[Test]
    public function policy_authorization_distinguishes_host_participant_and_stranger(): void
    {
        $hostId = $this->makeUser('Host');
        $participantId = $this->makeUser('Participant');
        $strangerId = $this->makeUser('Stranger');

        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);
        $this->service->invite($meeting, $hostId, $participantId);

        $this->assertTrue($this->policy->isHost($meeting, $hostId));
        $this->assertFalse($this->policy->isHost($meeting, $participantId));

        $this->assertTrue($this->policy->canManage($meeting, $hostId, $this->meetings));
        $this->assertFalse($this->policy->canManage($meeting, $participantId, $this->meetings));

        $this->assertTrue($this->policy->canView($meeting, $hostId, $this->meetings));
        $this->assertTrue($this->policy->canView($meeting, $participantId, $this->meetings));
        $this->assertFalse($this->policy->canView($meeting, $strangerId, $this->meetings));
    }

    #[Test]
    public function cancelling_and_ending_a_meeting_updates_status_and_timestamps(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Design Review']);

        $started = $this->service->start($meeting);
        $this->assertSame('live', $started->status);
        $this->assertNotNull($started->started_at);

        $ended = $this->service->end($started);
        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ended_at);

        $meeting2 = $this->service->create($hostId, ['title' => 'Another Meeting']);
        $cancelled = $this->service->cancel($meeting2);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    #[Test]
    public function forUser_returns_meetings_the_user_hosts_or_participates_in(): void
    {
        $hostId = $this->makeUser('Host');
        $participantId = $this->makeUser('Participant');
        $strangerId = $this->makeUser('Stranger');

        $hosted = $this->service->create($hostId, ['title' => 'Hosted Meeting']);
        $invitedTo = $this->service->create($hostId, ['title' => 'Invited Meeting']);
        $this->service->invite($invitedTo, $hostId, $participantId);

        $participantMeetings = $this->meetings->forUser($participantId);
        $participantMeetingIds = array_map(fn ($m) => $m->id, $participantMeetings);

        $this->assertContains($invitedTo->id, $participantMeetingIds);
        $this->assertNotContains($hosted->id, $participantMeetingIds);

        $strangerMeetings = $this->meetings->forUser($strangerId);
        $this->assertCount(0, $strangerMeetings);
    }
}
