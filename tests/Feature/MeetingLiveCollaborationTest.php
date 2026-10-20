<?php

namespace Tests\Feature;

use App\Events\Meetings\ChatMessageReactionChanged;
use App\Events\Meetings\ChatMessageSent;
use App\Events\Meetings\ParticipantHandRaised;
use App\Events\Meetings\ParticipantReactionSent;
use App\Events\Meetings\ParticipantScreenShareForceStopped;
use App\Events\Meetings\ScreenShareParticipantPolicyChanged;
use App\Events\Meetings\ScreenSharePolicyChanged;
use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;
use App\Services\MeetingChatService;
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
 * Round 5 (Live Collaboration) بتاع موديول الاجتماعات: بند 7 (Meeting
 * Chat)، بند 8 (Private Chat)، بند 9 (Screen Sharing host controls)،
 * بند 11 (Raise Hand & Reactions). نفس نمط إعداد MeetingSignalingTest
 * بالظبط (JWT_SECRET/reverb config في setUp، DatabaseTransactions)،
 * بس هنا بنبني $signaling **مع** $chat (بعكس signaling test اللي
 * بيسيب $chat=null عمدًا عشان يتأكد إن Round 3/4 مش متأثرة).
 */
class MeetingLiveCollaborationTest extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingLobbyService $lobby;
    private MeetingChatService $chat;
    private MeetingSignalingService $signaling;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-meeting-live-collaboration-tests');
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

    /**
     * waiting_room_enabled=false عمدًا (زي MeetingWebrtcCoreTest بالظبط)
     * — عشان joinAsMember() تحت يدخل الأعضاء مباشرة (status=joined) من
     * غير ما يحتاج قرار هوست صريح، وده مش محور الاختبارات هنا أصلًا.
     */
    private function joinAsHost(string $name = 'Host'): array
    {
        $hostId = $this->makeUser($name);
        $meeting = $this->service->create($hostId, ['title' => 'Standup', 'waiting_room_enabled' => false]);
        $actor = $this->signaling->resolveActor($meeting, $hostId, null);

        return [$meeting, $hostId, $actor];
    }

    private function joinAsMember($meeting, string $name = 'Member'): array
    {
        $userId = $this->makeUser($name);
        $this->lobby->requestAccess($meeting, $userId, []);
        $actor = $this->signaling->resolveActor($meeting, $userId, null);

        return [$userId, $actor];
    }

    // -----------------------------------------------------------------
    // Chat (بند 7 — رسائل عامة) + system messages
    // -----------------------------------------------------------------

    #[Test]
    public function a_public_message_is_visible_to_every_active_participant(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $message = $this->chat->send($meeting, $hostActor, ['body' => 'Hello everyone']);

        $this->assertNull($message->recipient_key);
        $this->assertSame('text', $message->type);

        $visibleToMember = $this->chat->listFor($meeting, $memberActor);
        $this->assertCount(1, $visibleToMember);
        $this->assertSame('Hello everyone', $visibleToMember[0]['body']);

        Event::assertDispatched(ChatMessageSent::class, function ($event) use ($meeting) {
            return $event->targetChannelName === 'presence-meeting.' . $meeting->uuid;
        });
    }

    #[Test]
    public function chat_is_rejected_when_disabled_in_meeting_settings(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $meeting->settings = array_merge($meeting->settings ?? [], ['allow_chat' => false]);
        $meeting->save();

        $this->expectException(\RuntimeException::class);
        $this->chat->send($meeting->fresh(), $hostActor, ['body' => 'Should fail']);
    }

    #[Test]
    public function joining_the_presence_channel_posts_a_system_join_message_only_once(): void
    {
        [$meeting, $hostId, $hostActor] = $this->joinAsHost();
        $channelName = $this->signaling->expectedChannelName($meeting);

        $this->signaling->authorizeChannel($meeting, $hostId, null, 'socket-1', $channelName);
        $this->signaling->authorizeChannel($meeting, $hostId, null, 'socket-2', $channelName);

        $messages = $this->chat->listFor($meeting, $hostActor);
        $systemJoinMessages = array_filter($messages, fn ($m) => $m['type'] === 'system' && str_contains($m['body'], 'joined the meeting'));
        $this->assertCount(1, $systemJoinMessages);
    }

    // -----------------------------------------------------------------
    // Private Chat (بند 8)
    // -----------------------------------------------------------------

    #[Test]
    public function a_private_message_is_only_visible_to_sender_and_recipient(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        [, $outsiderActor] = $this->joinAsMember($meeting, 'Outsider');

        $message = $this->chat->send($meeting, $hostActor, [
            'body'          => 'Just for you',
            'recipient_key' => $memberActor['key'],
        ]);

        $this->assertSame($memberActor['key'], $message->recipient_key);

        $visibleToMember = $this->chat->listFor($meeting, $memberActor);
        $this->assertCount(1, $visibleToMember);

        $visibleToOutsider = $this->chat->listFor($meeting, $outsiderActor);
        $this->assertCount(0, $visibleToOutsider);

        Event::assertDispatched(ChatMessageSent::class, function ($event) use ($meeting, $memberActor) {
            return $event->targetChannelName === $this->signaling->personalChannelName($meeting, $memberActor['key']);
        });
    }

    #[Test]
    public function a_private_message_cannot_be_sent_to_someone_not_active_in_the_meeting(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->expectException(\RuntimeException::class);
        $this->chat->send($meeting, $hostActor, ['body' => 'Hi', 'recipient_key' => 'user:999999']);
    }

    #[Test]
    public function a_private_message_cannot_be_sent_to_yourself(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->expectException(\RuntimeException::class);
        $this->chat->send($meeting, $hostActor, ['body' => 'Hi', 'recipient_key' => $hostActor['key']]);
    }

    // -----------------------------------------------------------------
    // Message reactions (بند 7)
    // -----------------------------------------------------------------

    #[Test]
    public function reacting_to_a_message_toggles_it_on_and_off(): void
    {
        Event::fake([ChatMessageReactionChanged::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $message = $this->chat->send($meeting, $hostActor, ['body' => 'React to this']);

        $added = $this->chat->toggleReaction($meeting, $memberActor, $message->id, '👍');
        $this->assertTrue($added['added']);

        $present = $this->chat->present($message->fresh());
        $this->assertSame(1, $present['reactions'][0]['count']);

        $removed = $this->chat->toggleReaction($meeting, $memberActor, $message->id, '👍');
        $this->assertFalse($removed['added']);

        $presentAfter = $this->chat->present($message->fresh());
        $this->assertCount(0, $presentAfter['reactions']);

        Event::assertDispatchedTimes(ChatMessageReactionChanged::class, 2);
    }

    #[Test]
    public function a_stranger_cannot_react_to_a_private_message_they_are_not_part_of(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        [, $outsiderActor] = $this->joinAsMember($meeting, 'Outsider');

        $message = $this->chat->send($meeting, $hostActor, [
            'body'          => 'Secret',
            'recipient_key' => $memberActor['key'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->chat->toggleReaction($meeting, $outsiderActor, $message->id, '👍');
    }

    // -----------------------------------------------------------------
    // Raise Hand (بند 11)
    // -----------------------------------------------------------------

    #[Test]
    public function raising_a_hand_persists_state_broadcasts_it_and_posts_a_system_message(): void
    {
        Event::fake([ParticipantHandRaised::class]);

        [$meeting, $hostId, ] = $this->joinAsHost();
        [$memberId, $memberActor] = $this->joinAsMember($meeting);

        $result = $this->signaling->raiseHand($meeting, $memberActor, true);

        $this->assertTrue($result['hand_raised']);
        $this->assertNotNull($result['hand_raised_at']);

        $participant = $this->meetings->findParticipant($meeting->id, $memberId)->fresh();
        $this->assertTrue((bool) $participant->hand_raised);

        Event::assertDispatched(ParticipantHandRaised::class, function ($event) use ($meeting, $memberActor) {
            return $event->meetingUuid === $meeting->uuid && $event->participantKey === $memberActor['key'] && $event->raised === true;
        });

        $systemMessages = array_filter(
            $this->chat->listFor($meeting, $memberActor),
            fn ($m) => $m['type'] === 'system' && str_contains($m['body'], 'raised their hand')
        );
        $this->assertCount(1, $systemMessages);
    }

    #[Test]
    public function lowering_a_hand_clears_state_and_does_not_post_a_system_message(): void
    {
        Event::fake([ParticipantHandRaised::class]);

        [$meeting, , ] = $this->joinAsHost();
        [$memberId, $memberActor] = $this->joinAsMember($meeting);
        $this->signaling->raiseHand($meeting, $memberActor, true);

        $result = $this->signaling->raiseHand($meeting, $memberActor, false);

        $this->assertFalse($result['hand_raised']);
        $this->assertNull($result['hand_raised_at']);

        $systemMessages = array_filter(
            $this->chat->listFor($meeting, $memberActor),
            fn ($m) => $m['type'] === 'system' && str_contains($m['body'], 'raised their hand')
        );
        $this->assertCount(1, $systemMessages); // بس واحدة من الرفع، مفيش واحدة تانية من الخفض.
    }

    #[Test]
    public function leaving_the_meeting_automatically_lowers_a_raised_hand(): void
    {
        [$meeting, , ] = $this->joinAsHost();
        [$memberId, $memberActor] = $this->joinAsMember($meeting);
        $this->signaling->raiseHand($meeting, $memberActor, true);

        $this->signaling->leave($meeting, $memberActor);

        $participant = $this->meetings->findParticipant($meeting->id, $memberId)->fresh();
        $this->assertFalse((bool) $participant->hand_raised);
    }

    // -----------------------------------------------------------------
    // Reactions (بند 11 — ephemeral)
    // -----------------------------------------------------------------

    #[Test]
    public function sending_a_known_reaction_type_broadcasts_it(): void
    {
        Event::fake([ParticipantReactionSent::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->signaling->sendReaction($meeting, $hostActor, 'applause');

        Event::assertDispatched(ParticipantReactionSent::class, function ($event) use ($meeting, $hostActor) {
            return $event->meetingUuid === $meeting->uuid && $event->participantKey === $hostActor['key'] && $event->type === 'applause';
        });
    }

    #[Test]
    public function an_unknown_reaction_type_is_rejected(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->expectException(\InvalidArgumentException::class);
        $this->signaling->sendReaction($meeting, $hostActor, 'confetti_cannon');
    }

    #[Test]
    public function the_other_reaction_type_requires_a_short_custom_emoji(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->expectException(\InvalidArgumentException::class);
        $this->signaling->sendReaction($meeting, $hostActor, 'other', null);
    }

    // -----------------------------------------------------------------
    // Screen Sharing host controls (بند 9)
    // -----------------------------------------------------------------

    #[Test]
    public function the_host_can_lock_screen_sharing_for_the_whole_meeting(): void
    {
        Event::fake([ScreenSharePolicyChanged::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->signaling->setScreenSharingLock($meeting, $hostActor, true);

        $this->assertTrue($meeting->fresh()->screen_sharing_locked);
        $this->assertFalse($this->signaling->canShareScreen($meeting->fresh(), $memberActor));
        $this->assertTrue($this->signaling->canShareScreen($meeting->fresh(), $hostActor));

        Event::assertDispatched(ScreenSharePolicyChanged::class, function ($event) use ($meeting) {
            return $event->meetingUuid === $meeting->uuid && $event->locked === true;
        });
    }

    #[Test]
    public function a_non_host_cannot_lock_screen_sharing(): void
    {
        [$meeting, , ] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->signaling->setScreenSharingLock($meeting, $memberActor, true);
    }

    #[Test]
    public function a_per_participant_exception_overrides_the_meeting_wide_lock(): void
    {
        Event::fake([ScreenShareParticipantPolicyChanged::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $this->signaling->setScreenSharingLock($meeting, $hostActor, true);

        $this->signaling->setParticipantScreenSharePolicy($meeting->fresh(), $hostActor, $memberActor['key'], true);

        $this->assertTrue($this->signaling->canShareScreen($meeting->fresh(), $memberActor));

        Event::assertDispatched(ScreenShareParticipantPolicyChanged::class, function ($event) use ($meeting, $memberActor) {
            return $event->meetingUuid === $meeting->uuid && $event->participantKey === $memberActor['key'] && $event->allowed === true;
        });
    }

    #[Test]
    public function updating_media_state_rejects_screen_sharing_when_locked_and_not_exempted(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $this->signaling->setScreenSharingLock($meeting, $hostActor, true);

        $this->expectException(\RuntimeException::class);
        $this->signaling->updateMediaState($meeting->fresh(), $memberActor, ['screen_sharing' => true]);
    }

    #[Test]
    public function the_host_can_force_stop_another_participants_screen_share(): void
    {
        Event::fake([ParticipantScreenShareForceStopped::class]);

        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $this->signaling->updateMediaState($meeting, $memberActor, ['screen_sharing' => true]);

        $this->signaling->stopParticipantScreenShare($meeting, $hostActor, $memberActor['key']);

        $participant = $this->meetings->findParticipant($meeting->id, (int) substr($memberActor['key'], strlen('user:')))->fresh();
        $this->assertFalse((bool) $participant->screen_sharing);

        Event::assertDispatched(ParticipantScreenShareForceStopped::class, function ($event) use ($meeting, $memberActor, $hostActor) {
            return $event->meetingUuid === $meeting->uuid
                && $event->participantKey === $memberActor['key']
                && $event->stoppedByKey === $hostActor['key'];
        });
    }

    #[Test]
    public function force_stopping_a_participant_who_is_not_sharing_fails(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->signaling->stopParticipantScreenShare($meeting, $hostActor, $memberActor['key']);
    }

    #[Test]
    public function a_non_host_cannot_force_stop_another_participants_screen_share(): void
    {
        [$meeting, , ] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        [, $anotherMemberActor] = $this->joinAsMember($meeting, 'Another');
        $this->signaling->updateMediaState($meeting, $memberActor, ['screen_sharing' => true]);

        $this->expectException(\RuntimeException::class);
        $this->signaling->stopParticipantScreenShare($meeting, $anotherMemberActor, $memberActor['key']);
    }

    #[Test]
    public function starting_and_stopping_screen_share_posts_matching_system_messages(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->signaling->updateMediaState($meeting, $hostActor, ['screen_sharing' => true]);
        $this->signaling->updateMediaState($meeting, $hostActor, ['screen_sharing' => false]);

        $messages = $this->chat->listFor($meeting, $hostActor);
        $started = array_filter($messages, fn ($m) => str_contains($m['body'], 'started sharing their screen'));
        $stopped = array_filter($messages, fn ($m) => str_contains($m['body'], 'stopped sharing their screen'));

        $this->assertCount(1, $started);
        $this->assertCount(1, $stopped);
    }

    // -----------------------------------------------------------------
    // Roster reflects Round 5 state (hand/screen-share) for the grid
    // -----------------------------------------------------------------

    #[Test]
    public function the_roster_includes_hand_raised_state_for_the_grid(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $this->signaling->raiseHand($meeting, $memberActor, true);

        $roster = $this->signaling->roster($meeting);
        $memberEntry = array_values(array_filter($roster, fn ($r) => $r['key'] === $memberActor['key']))[0];

        $this->assertTrue($memberEntry['hand_raised']);
        $this->assertNotNull($memberEntry['hand_raised_at']);
    }
}
