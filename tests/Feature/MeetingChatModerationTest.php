<?php

namespace Tests\Feature;

use App\Events\Meetings\ChatCleared;
use App\Events\Meetings\ChatMessageDeleted;
use App\Events\Meetings\ChatSettingsChanged;
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
 * Meeting chat moderation: chat-history policy for late joiners, deleting messages (own / host moderation),
 * silencing a person in chat, clearing the chat. Same setup as MeetingLiveCollaborationTest.
 */
class MeetingChatModerationTest extends TestCase
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
            putenv('JWT_SECRET=test-secret-for-meeting-chat-moderation-tests');
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

    private function makeUser(string $name): int
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

    private function joinAsHost(array $settings = []): array
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, ['title' => 'Class', 'waiting_room_enabled' => false, 'settings' => $settings]);

        return [$meeting, $hostId, $this->signaling->resolveActor($meeting, $hostId, null)];
    }

    private function joinAsMember($meeting, string $name = 'Member'): array
    {
        $userId = $this->makeUser($name);
        $this->lobby->requestAccess($meeting, $userId, []);

        return [$userId, $this->signaling->resolveActor($meeting, $userId, null)];
    }

    private function bodies(array $messages): array
    {
        return array_values(array_map(fn ($m) => $m['body'], array_filter($messages, fn ($m) => $m['type'] === 'text')));
    }

    // ---- history policy ------------------------------------------------

    #[Test]
    public function with_keep_a_late_joiner_reads_the_earlier_chat(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost(['chat_history' => 'keep']);
        $this->chat->send($meeting, $hostActor, ['body' => 'before you']);

        [, $lateActor] = $this->joinAsMember($meeting, 'Late');

        $this->assertSame(['before you'], $this->bodies($this->chat->listFor($meeting, $lateActor)));
    }

    #[Test]
    public function with_hide_a_late_joiner_only_gets_messages_sent_after_they_entered(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost(['chat_history' => 'hide']);
        $this->chat->listFor($meeting, $hostActor); // the host opens the chat on entry, before anyone has said anything
        $this->chat->send($meeting, $hostActor, ['body' => 'before you']);

        [, $lateActor] = $this->joinAsMember($meeting, 'Late');
        $this->assertSame([], $this->bodies($this->chat->listFor($meeting, $lateActor)), 'first open: nothing from before');

        $this->chat->send($meeting, $hostActor, ['body' => 'after you']);

        // a refresh keeps what they already had and adds the new message, but never reveals the old one
        $this->assertSame(['after you'], $this->bodies($this->chat->listFor($meeting, $lateActor)));
        // the host (who was there from the start) still sees everything
        $this->assertSame(['before you', 'after you'], $this->bodies($this->chat->listFor($meeting, $hostActor)));
    }

    #[Test]
    public function hide_never_hides_a_persons_own_or_private_messages(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost(['chat_history' => 'hide']);
        [, $lateActor] = $this->joinAsMember($meeting, 'Late');
        $this->chat->listFor($meeting, $lateActor); // first entry

        $this->chat->send($meeting, $hostActor, ['body' => 'dm to late', 'recipient_key' => $lateActor['key']]);
        $this->chat->send($meeting, $lateActor, ['body' => 'my own']);

        $this->assertEqualsCanonicalizing(['dm to late', 'my own'], $this->bodies($this->chat->listFor($meeting, $lateActor)));
    }

    // ---- deleting ------------------------------------------------------

    #[Test]
    public function a_sender_can_delete_their_own_message_for_everyone(): void
    {
        Event::fake([ChatMessageDeleted::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $message = $this->chat->send($meeting, $memberActor, ['body' => 'oops']);
        $this->chat->deleteMessage($meeting, $memberActor, $message->id);

        $this->assertSame([], $this->bodies($this->chat->listFor($meeting, $hostActor)));
        Event::assertDispatched(ChatMessageDeleted::class, fn ($e) => $e->messageId === $message->id
            && $e->targetChannelName === 'presence-meeting.' . $meeting->uuid);
    }

    #[Test]
    public function a_participant_cannot_delete_someone_elses_message(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $message = $this->chat->send($meeting, $hostActor, ['body' => 'host says']);

        $this->expectException(\RuntimeException::class);
        $this->chat->deleteMessage($meeting, $memberActor, $message->id);
    }

    #[Test]
    public function the_host_can_delete_any_public_message(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $message = $this->chat->send($meeting, $memberActor, ['body' => 'rude message']);

        $this->chat->deleteMessage($meeting, $hostActor, $message->id);

        $this->assertSame([], $this->bodies($this->chat->listFor($meeting, $memberActor)));
    }

    #[Test]
    public function the_host_cannot_delete_a_private_message_between_two_other_people(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $a] = $this->joinAsMember($meeting, 'A');
        [, $b] = $this->joinAsMember($meeting, 'B');
        $dm = $this->chat->send($meeting, $a, ['body' => 'secret', 'recipient_key' => $b['key']]);

        $this->expectException(\RuntimeException::class);
        $this->chat->deleteMessage($meeting, $hostActor, $dm->id);
    }

    #[Test]
    public function deleting_a_private_message_notifies_only_the_recipients_inbox(): void
    {
        Event::fake([ChatMessageDeleted::class]);
        [$meeting] = $this->joinAsHost();
        [, $a] = $this->joinAsMember($meeting, 'A');
        [, $b] = $this->joinAsMember($meeting, 'B');
        $dm = $this->chat->send($meeting, $a, ['body' => 'secret', 'recipient_key' => $b['key']]);

        $this->chat->deleteMessage($meeting, $a, $dm->id);

        Event::assertDispatched(ChatMessageDeleted::class, fn ($e) => $e->targetChannelName === $this->signaling->personalChannelName($meeting, $b['key']));
        $this->assertSame([], $this->bodies($this->chat->listFor($meeting, $b)));
    }

    // ---- host controls ---------------------------------------------------

    #[Test]
    public function a_silenced_participant_cannot_post_but_can_be_allowed_again(): void
    {
        Event::fake([ChatSettingsChanged::class]);
        [$meeting, $hostId] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->hostControl->setChatMuted($meeting, $hostId, $memberActor['key'], true);
        try {
            $this->chat->send($meeting->fresh(), $memberActor, ['body' => 'let me talk']);
            $this->fail('A silenced participant must not be able to post.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('turned off your chat', $e->getMessage());
        }

        $this->hostControl->setChatMuted($meeting->fresh(), $hostId, $memberActor['key'], false);
        $this->chat->send($meeting->fresh(), $memberActor, ['body' => 'thanks']);

        Event::assertDispatched(ChatSettingsChanged::class);
    }

    #[Test]
    public function only_a_manager_can_change_chat_settings_or_clear_the_chat(): void
    {
        [$meeting, , ] = $this->joinAsHost();
        [$memberId] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->hostControl->setChatSettings($meeting, $memberId, ['chat_history' => 'hide']);
    }

    #[Test]
    public function the_host_can_switch_history_and_chat_live_and_clear_the_public_chat_only(): void
    {
        Event::fake([ChatSettingsChanged::class, ChatCleared::class]);
        [$meeting, $hostId, $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->chat->send($meeting, $hostActor, ['body' => 'public one']);
        $this->chat->send($meeting, $hostActor, ['body' => 'private one', 'recipient_key' => $memberActor['key']]);

        $state = $this->hostControl->setChatSettings($meeting, $hostId, ['chat_history' => 'hide', 'chat_enabled' => false]);
        $this->assertSame('hide', $state['chat_history']);
        $this->assertFalse($state['chat_enabled']);
        $this->hostControl->setChatSettings($meeting->fresh(), $hostId, ['chat_enabled' => true]);

        $this->hostControl->clearChat($meeting->fresh(), $hostId);

        $this->assertSame(['private one'], $this->bodies($this->chat->listFor($meeting->fresh(), $memberActor)));
        Event::assertDispatched(ChatCleared::class);
    }

    #[Test]
    public function an_invalid_history_mode_is_rejected(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();

        $this->expectException(\RuntimeException::class);
        $this->hostControl->setChatSettings($meeting, $hostId, ['chat_history' => 'banana']);
    }

    #[Test]
    public function mute_all_turns_off_everyones_mic_except_the_host(): void
    {
        [$meeting, $hostId, $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $memberActor['model']->mic_enabled = true;
        $memberActor['model']->save();

        $this->hostControl->muteAll($meeting, $hostId);

        $this->assertFalse((bool) $memberActor['model']->fresh()->mic_enabled);
    }
}
