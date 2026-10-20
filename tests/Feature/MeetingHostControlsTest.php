<?php

namespace Tests\Feature;

use App\Events\Meetings\MeetingHostTransferred;
use App\Events\Meetings\MeetingLockChanged;
use App\Events\Meetings\ParticipantCameraDisabledByHost;
use App\Events\Meetings\ParticipantMediaStateChanged;
use App\Events\Meetings\ParticipantMutedByHost;
use App\Events\Meetings\ParticipantRemovedFromMeeting;
use App\Events\Meetings\ParticipantRoleChanged;
use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;
use App\Services\MeetingChatService;
use App\Services\MeetingHostControlService;
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
 * Round 6 (Host Controls) بتاع موديول الاجتماعات: بند 5 (الجزء الخاص
 * بأوامر الهوست/moderator — Mute/Remove/Disable camera/Make host/
 * Promote/Remove moderator/Lock meeting)، بند 10 (Participant
 * Management panel). نفس نمط إعداد MeetingLiveCollaborationTest
 * بالظبط (JWT_SECRET/reverb config في setUp، DatabaseTransactions،
 * الاختبارات بتنادي الـ services مباشرة مش HTTP، زي باقي اختبارات
 * الموديول ده كله).
 */
class MeetingHostControlsTest extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingLobbyService $lobby;
    private MeetingChatService $chat;
    private MeetingSignalingService $signaling;
    private MeetingHostControlService $hostControl;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-meeting-host-controls-tests');
        }

        config([
            'broadcasting.connections.reverb.key'    => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
        ]);

        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->lobby = new MeetingLobbyService($this->meetings, $this->policy);
        $this->chat = new MeetingChatService($this->meetings);
        $this->signaling = new MeetingSignalingService($this->meetings, $this->lobby, $this->policy, $this->chat);
        $this->service = new MeetingService($this->meetings, $this->policy, null, $this->signaling);
        $this->hostControl = new MeetingHostControlService($this->meetings, $this->policy, $this->signaling, $this->chat);
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

    /** waiting_room_enabled=false عمدًا — راجع docblock نفس الميثود في MeetingLiveCollaborationTest. */
    private function joinAsHost(string $name = 'Host'): array
    {
        $hostId = $this->makeUser($name);
        $meeting = $this->service->create($hostId, ['title' => 'Standup', 'waiting_room_enabled' => false]);

        return [$meeting, $hostId];
    }

    private function joinAsMember($meeting, string $name = 'Member'): int
    {
        $userId = $this->makeUser($name);
        $this->lobby->requestAccess($meeting, $userId, []);

        return $userId;
    }

    private function joinAsCoHost($meeting, string $name = 'Co-Host'): int
    {
        $userId = $this->makeUser($name);
        $this->meetings->addParticipant([
            'meeting_id' => $meeting->id,
            'user_id'    => $userId,
            'role'       => 'co_host',
            'status'     => 'joined',
            'joined_at'  => now(),
        ]);

        return $userId;
    }

    /** بيفترض إن الاجتماع اتعمل بـ 'allow_guests' => true — راجع joinAsHostAllowingGuests(). */
    private function joinAsGuest($meeting, string $name = 'Guest'): array
    {
        $result = $this->lobby->requestAccess($meeting, null, ['display_name' => $name]);

        return [$result['join_request'], $result['guest_token']];
    }

    /** زي joinAsHost() بس بـ allow_guests=true — للاختبارات اللي محتاجة ضيف. */
    private function joinAsHostAllowingGuests(string $name = 'Host'): array
    {
        $hostId = $this->makeUser($name);
        $meeting = $this->service->create($hostId, [
            'title'                => 'Standup',
            'waiting_room_enabled' => false,
            'allow_guests'         => true,
        ]);

        return [$meeting, $hostId];
    }

    // -----------------------------------------------------------------
    // بند 5 — "Lock meeting" / "Unlock meeting"
    // -----------------------------------------------------------------

    #[Test]
    public function host_can_lock_and_unlock_the_meeting(): void
    {
        Event::fake([MeetingLockChanged::class]);

        [$meeting, $hostId] = $this->joinAsHost();

        $locked = $this->hostControl->setLock($meeting, $hostId, true);
        $this->assertTrue($locked);
        $this->assertTrue($meeting->fresh()->locked);

        $unlocked = $this->hostControl->setLock($meeting, $hostId, false);
        $this->assertFalse($unlocked);
        $this->assertFalse($meeting->fresh()->locked);

        Event::assertDispatched(MeetingLockChanged::class, 2);
    }

    #[Test]
    public function co_host_can_also_lock_the_meeting(): void
    {
        [$meeting, ] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting);

        $locked = $this->hostControl->setLock($meeting, $coHostId, true);
        $this->assertTrue($locked);
    }

    #[Test]
    public function regular_participant_cannot_lock_the_meeting(): void
    {
        [$meeting, ] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->setLock($meeting, $memberId, true);
    }

    #[Test]
    public function a_locked_meeting_rejects_new_join_requests_but_the_host_still_gets_in(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $this->hostControl->setLock($meeting, $hostId, true);

        $newUserId = $this->makeUser('Latecomer');
        $this->expectException(\RuntimeException::class);
        $this->lobby->requestAccess($meeting->fresh(), $newUserId, []);
    }

    #[Test]
    public function the_host_can_still_reenter_a_meeting_locked_by_themselves(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $this->hostControl->setLock($meeting, $hostId, true);

        $result = $this->lobby->requestAccess($meeting->fresh(), $hostId, []);
        $this->assertSame('admitted', $result['status']);
    }

    // -----------------------------------------------------------------
    // بند 5/10 — "Mute participant" / "Disable participant camera"
    // -----------------------------------------------------------------

    #[Test]
    public function host_can_mute_a_participant(): void
    {
        Event::fake([ParticipantMutedByHost::class, ParticipantMediaStateChanged::class]);

        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $state = $this->hostControl->mute($meeting, $hostId, 'user:' . $memberId);

        $this->assertFalse($state['mic_enabled']);
        $this->assertFalse($this->meetings->findParticipant($meeting->id, $memberId)->mic_enabled);

        Event::assertDispatched(ParticipantMutedByHost::class, function ($event) use ($memberId) {
            return $event->participantKey === 'user:' . $memberId;
        });
        Event::assertDispatched(ParticipantMediaStateChanged::class);
    }

    #[Test]
    public function host_can_disable_a_participants_camera(): void
    {
        Event::fake([ParticipantCameraDisabledByHost::class, ParticipantMediaStateChanged::class]);

        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $state = $this->hostControl->disableCamera($meeting, $hostId, 'user:' . $memberId);

        $this->assertFalse($state['camera_enabled']);
        Event::assertDispatched(ParticipantCameraDisabledByHost::class);
    }

    #[Test]
    public function host_can_mute_an_admitted_guest(): void
    {
        [$meeting, $hostId] = $this->joinAsHostAllowingGuests();
        [$joinRequest, ] = $this->joinAsGuest($meeting);

        $this->hostControl->mute($meeting, $hostId, 'guest:' . $joinRequest->id);

        $this->assertFalse($joinRequest->fresh()->mic_enabled);
    }

    #[Test]
    public function a_regular_participant_cannot_mute_another_participant(): void
    {
        [$meeting, ] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);
        $otherId = $this->joinAsMember($meeting, 'Other Member');

        $this->expectException(\RuntimeException::class);
        $this->hostControl->mute($meeting, $memberId, 'user:' . $otherId);
    }

    // -----------------------------------------------------------------
    // بند 5/10 — "Remove participant"
    // -----------------------------------------------------------------

    #[Test]
    public function host_can_remove_a_participant(): void
    {
        Event::fake([ParticipantRemovedFromMeeting::class]);

        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $this->hostControl->remove($meeting, $hostId, 'user:' . $memberId);

        $participant = $this->meetings->findParticipant($meeting->id, $memberId);
        $this->assertSame('removed', $participant->status);
        $this->assertSame($hostId, $participant->removed_by_user_id);
        $this->assertNotNull($participant->removed_at);
        $this->assertFalse($participant->hand_raised);

        Event::assertDispatched(ParticipantRemovedFromMeeting::class);
    }

    #[Test]
    public function host_can_remove_an_admitted_guest(): void
    {
        [$meeting, $hostId] = $this->joinAsHostAllowingGuests();
        [$joinRequest, ] = $this->joinAsGuest($meeting);

        $this->hostControl->remove($meeting, $hostId, 'guest:' . $joinRequest->id);

        $fresh = $joinRequest->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($hostId, $fresh->removed_by_user_id);
        $this->assertNotNull($fresh->removed_at);
    }

    #[Test]
    public function the_host_cannot_be_removed_from_their_own_meeting(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->remove($meeting, $coHostId, 'user:' . $hostId);
    }

    #[Test]
    public function a_co_host_cannot_remove_another_co_host(): void
    {
        [$meeting, ] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting, 'Co-Host A');
        $otherCoHostId = $this->joinAsCoHost($meeting, 'Co-Host B');

        $this->expectException(\RuntimeException::class);
        $this->hostControl->remove($meeting, $coHostId, 'user:' . $otherCoHostId);
    }

    #[Test]
    public function the_primary_host_can_remove_a_co_host(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting);

        $this->hostControl->remove($meeting, $hostId, 'user:' . $coHostId);

        $this->assertSame('removed', $this->meetings->findParticipant($meeting->id, $coHostId)->status);
    }

    #[Test]
    public function a_removed_participant_cannot_be_muted_again(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);
        $this->hostControl->remove($meeting, $hostId, 'user:' . $memberId);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->mute($meeting, $hostId, 'user:' . $memberId);
    }

    // -----------------------------------------------------------------
    // بند 5/10 — "Promote moderator" / "Remove moderator" (Demote)
    // -----------------------------------------------------------------

    #[Test]
    public function the_host_can_promote_a_participant_to_co_host(): void
    {
        Event::fake([ParticipantRoleChanged::class]);

        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $this->hostControl->promote($meeting, $hostId, 'user:' . $memberId);

        $this->assertSame('co_host', $this->meetings->findParticipant($meeting->id, $memberId)->role);
        Event::assertDispatched(ParticipantRoleChanged::class, function ($event) use ($memberId) {
            return $event->participantKey === 'user:' . $memberId && $event->newRole === 'co_host';
        });
    }

    #[Test]
    public function the_host_can_demote_a_co_host_back_to_participant(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting);

        $this->hostControl->demote($meeting, $hostId, 'user:' . $coHostId);

        $this->assertSame('participant', $this->meetings->findParticipant($meeting->id, $coHostId)->role);
    }

    #[Test]
    public function a_co_host_cannot_promote_another_participant(): void
    {
        [$meeting, ] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting);
        $memberId = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->promote($meeting, $coHostId, 'user:' . $memberId);
    }

    #[Test]
    public function promoting_a_guest_is_rejected(): void
    {
        [$meeting, $hostId] = $this->joinAsHostAllowingGuests();
        [$joinRequest, ] = $this->joinAsGuest($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->promote($meeting, $hostId, 'guest:' . $joinRequest->id);
    }

    #[Test]
    public function demoting_a_participant_who_is_not_a_co_host_is_rejected(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->demote($meeting, $hostId, 'user:' . $memberId);
    }

    // -----------------------------------------------------------------
    // بند 5 — "Make participant host"
    // -----------------------------------------------------------------

    #[Test]
    public function the_host_can_transfer_host_role_to_a_participant(): void
    {
        Event::fake([MeetingHostTransferred::class]);

        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);

        $this->hostControl->transferHost($meeting, $hostId, 'user:' . $memberId);

        $freshMeeting = $meeting->fresh();
        $this->assertSame($memberId, $freshMeeting->host_user_id);
        $this->assertSame('host', $this->meetings->findParticipant($meeting->id, $memberId)->role);
        // الهوست القديم بيتحوّل co_host مش participant عادي — راجع docblock transferHost().
        $this->assertSame('co_host', $this->meetings->findParticipant($meeting->id, $hostId)->role);

        Event::assertDispatched(MeetingHostTransferred::class, function ($event) use ($memberId) {
            return $event->newHostKey === 'user:' . $memberId;
        });
    }

    #[Test]
    public function the_new_host_can_now_manage_the_meeting_and_the_old_host_still_can_as_co_host(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $memberId = $this->joinAsMember($meeting);
        $this->hostControl->transferHost($meeting, $hostId, 'user:' . $memberId);

        $freshMeeting = $meeting->fresh();
        // الهوست الجديد يقدر يقفل الاجتماع.
        $this->hostControl->setLock($freshMeeting, $memberId, true);
        $this->assertTrue($freshMeeting->fresh()->locked);

        // الهوست القديم (دلوقتي co_host) لسه يقدر يدير (mute) بس مش
        // يرقّي/ينقل الهوست تاني (دي للهوست الأساسي بس).
        $anotherMemberId = $this->joinAsMember($freshMeeting, 'Another Member');
        $this->hostControl->mute($freshMeeting, $hostId, 'user:' . $anotherMemberId);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->transferHost($freshMeeting, $hostId, 'user:' . $anotherMemberId);
    }

    #[Test]
    public function transferring_host_to_the_current_host_is_rejected(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();

        $this->expectException(\RuntimeException::class);
        $this->hostControl->transferHost($meeting, $hostId, 'user:' . $hostId);
    }

    #[Test]
    public function a_co_host_cannot_transfer_the_host_role(): void
    {
        [$meeting, ] = $this->joinAsHost();
        $coHostId = $this->joinAsCoHost($meeting);
        $memberId = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->transferHost($meeting, $coHostId, 'user:' . $memberId);
    }
}
