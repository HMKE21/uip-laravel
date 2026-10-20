<?php

namespace Tests\Feature;

use App\Events\Meetings\MeetingActionItemChanged;
use App\Events\Meetings\MeetingFileRemoved;
use App\Events\Meetings\MeetingFileShared;
use App\Events\Meetings\MeetingNotesUpdated;
use App\Events\Meetings\MeetingPollClosed;
use App\Events\Meetings\MeetingPollCreated;
use App\Events\Meetings\MeetingPollResultsUpdated;
use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;
use App\Services\MeetingAttendanceService;
use App\Services\MeetingChatService;
use App\Services\MeetingFileService;
use App\Services\MeetingLobbyService;
use App\Services\MeetingNotesService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingPollService;
use App\Services\MeetingService;
use App\Services\MeetingSignalingService;
use App\Services\FileUploadPolicyService;
use App\Services\FileUploadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round 7 (Collaboration Extras) بتاع موديول الاجتماعات: بند 17
 * (Attendance Tracking)، بند 19 (File Sharing)، بند 20 (Meeting
 * Notes)، بند 21 (Polls). نفس نمط إعداد MeetingHostControlsTest بالظبط
 * (JWT_SECRET/reverb config في setUp، DatabaseTransactions، الاختبارات
 * بتنادي الـ services مباشرة مش HTTP)، بالإضافة لـ $attendance المحقون
 * جوه $signaling/$service (نفس نمط $chat في Round 5).
 */
class MeetingCollaborationExtrasTest extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingLobbyService $lobby;
    private MeetingChatService $chat;
    private MeetingSignalingService $signaling;
    private MeetingAttendanceService $attendance;
    private MeetingFileService $files;
    private MeetingNotesService $notes;
    private MeetingPollService $polls;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-meeting-collaboration-extras-tests');
        }

        config([
            'broadcasting.connections.reverb.key'    => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
        ]);

        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->lobby = new MeetingLobbyService($this->meetings, $this->policy);
        $this->chat = new MeetingChatService($this->meetings);
        $this->attendance = new MeetingAttendanceService($this->meetings);
        $this->signaling = new MeetingSignalingService($this->meetings, $this->lobby, $this->policy, $this->chat, $this->attendance);
        $this->service = new MeetingService($this->meetings, $this->policy, null, $this->signaling, $this->attendance);
        $this->files = new MeetingFileService($this->meetings, new FileUploadService(new FileUploadPolicyService()));
        $this->notes = new MeetingNotesService($this->meetings, $this->policy);
        $this->polls = new MeetingPollService($this->meetings, $this->policy);
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

    /** waiting_room_enabled=false عمدًا — راجع docblock نفس الميثود في MeetingHostControlsTest. */
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

    private function enterRoom($meeting, ?int $userId, ?string $guestToken = null): void
    {
        $this->signaling->authorizeChannel($meeting, $userId, $guestToken, 'socket-' . uniqid(), $this->signaling->expectedChannelName($meeting));
    }

    // -----------------------------------------------------------------
    // بند 17 — Attendance Tracking
    // -----------------------------------------------------------------

    #[Test]
    public function joining_and_leaving_the_room_records_an_attendance_session(): void
    {
        [$meeting, $hostId, $hostActor] = $this->joinAsHost();
        $this->enterRoom($meeting, $hostId);

        $report = $this->attendance->report($meeting);
        $this->assertCount(1, $report);
        $this->assertSame($hostActor['key'], $report[0]['participant_key']);
        $this->assertSame(1, $report[0]['joins']);
        $this->assertSame(0, $report[0]['leaves']);
        $this->assertNull($report[0]['left_at']);

        $this->signaling->leave($meeting, $hostActor);

        $report = $this->attendance->report($meeting);
        $this->assertSame(1, $report[0]['leaves']);
        $this->assertNotNull($report[0]['left_at']);
    }

    #[Test]
    public function rejoining_the_room_creates_a_second_session_and_counts_both_joins(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        [$memberId, $memberActor] = $this->joinAsMember($meeting);

        $this->enterRoom($meeting, $memberId);
        $this->signaling->leave($meeting, $this->signaling->resolveActor($meeting, $memberId, null));
        $this->enterRoom($meeting, $memberId);

        $report = $this->attendance->report($meeting);
        $row = collect($report)->firstWhere('participant_key', $memberActor['key']);
        $this->assertSame(2, $row['joins']);
        $this->assertSame(1, $row['leaves']);
    }

    #[Test]
    public function ending_the_meeting_closes_any_still_open_attendance_session(): void
    {
        [$meeting, $hostId, $hostActor] = $this->joinAsHost();
        $this->service->start($meeting, $hostId);
        $this->enterRoom($meeting, $hostId);

        $this->service->end($meeting->fresh(), $hostId);

        $report = $this->attendance->report($meeting);
        $this->assertNotNull($report[0]['left_at']);
    }

    #[Test]
    public function attendance_export_produces_a_csv_with_a_row_per_participant(): void
    {
        [$meeting, $hostId] = $this->joinAsHost();
        $this->enterRoom($meeting, $hostId);

        $csv = $this->attendance->exportCsv($meeting);

        $this->assertStringContainsString('Participant,Joined,Left,Duration (min),Joins,Leaves,Attendance %', $csv);
        $this->assertStringContainsString('Host', $csv);
    }

    // -----------------------------------------------------------------
    // بند 19 — File Sharing
    // -----------------------------------------------------------------

    #[Test]
    public function a_participant_can_upload_a_file_and_it_appears_in_the_list(): void
    {
        Event::fake([MeetingFileShared::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();

        $upload = UploadedFile::fake()->create('agenda.pdf', 50, 'application/pdf');
        $file = $this->files->upload($meeting, $hostActor, $upload);

        $this->assertSame('agenda.pdf', $file->original_name);
        $this->assertCount(1, $this->files->listFor($meeting));
        Event::assertDispatched(MeetingFileShared::class);
    }

    #[Test]
    public function file_sharing_can_be_disabled_for_the_meeting(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Standup',
            'waiting_room_enabled' => false,
            'settings'             => ['allow_file_sharing' => false],
        ]);
        $hostActor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->expectException(\RuntimeException::class);
        $this->files->upload($meeting, $hostActor, UploadedFile::fake()->create('x.pdf', 10));
    }

    #[Test]
    public function the_uploader_can_delete_their_own_file(): void
    {
        Event::fake([MeetingFileShared::class, MeetingFileRemoved::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $file = $this->files->upload($meeting, $memberActor, UploadedFile::fake()->create('notes.docx', 20));

        $this->files->delete($meeting, $memberActor, $file, $this->policy);

        $this->assertCount(0, $this->files->listFor($meeting));
        Event::assertDispatched(MeetingFileRemoved::class);
    }

    #[Test]
    public function a_regular_member_cannot_delete_another_participants_file(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        [, $otherMemberActor] = $this->joinAsMember($meeting, 'Other Member');

        $file = $this->files->upload($meeting, $memberActor, UploadedFile::fake()->create('x.pdf', 10));

        $this->expectException(\RuntimeException::class);
        $this->files->delete($meeting, $otherMemberActor, $file, $this->policy);
    }

    #[Test]
    public function the_host_can_delete_any_participants_file(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $file = $this->files->upload($meeting, $memberActor, UploadedFile::fake()->create('x.pdf', 10));

        $this->files->delete($meeting, $hostActor, $file, $this->policy);

        $this->assertCount(0, $this->files->listFor($meeting));
    }

    // -----------------------------------------------------------------
    // بند 20 — Meeting Notes & Action Items
    // -----------------------------------------------------------------

    #[Test]
    public function host_can_update_the_shared_notes_document(): void
    {
        Event::fake([MeetingNotesUpdated::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();

        $note = $this->notes->updateBody($meeting, $hostActor, 'Agenda: review proposal.');

        $this->assertSame('Agenda: review proposal.', $note->body);
        $this->assertSame($hostActor['key'], $note->last_edited_by_key);
        Event::assertDispatched(MeetingNotesUpdated::class);
    }

    #[Test]
    public function a_regular_member_cannot_edit_the_notes(): void
    {
        [$meeting, ,] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->notes->updateBody($meeting, $memberActor, 'Sneaky edit');
    }

    #[Test]
    public function everyone_can_read_the_notes_even_if_only_the_host_can_edit(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $this->notes->updateBody($meeting, $hostActor, 'Shared agenda');

        $note = $this->notes->get($meeting);

        $this->assertSame('Shared agenda', $note->body);
    }

    #[Test]
    public function host_can_create_and_update_a_decision_action_item(): void
    {
        Event::fake([MeetingActionItemChanged::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();

        $item = $this->notes->createItem($meeting, $hostActor, [
            'type'                  => 'action_item',
            'title'                 => 'Ahmed will submit the updated proposal.',
            'assignee_display_name' => 'Ahmed',
            'due_at'                => '2026-09-05',
        ]);

        $this->assertSame('action_item', $item->type);
        $this->assertCount(1, $this->notes->listItems($meeting));

        $updated = $this->notes->updateItem($meeting, $hostActor, $item, ['status' => 'done']);
        $this->assertSame('done', $updated->status);

        Event::assertDispatched(MeetingActionItemChanged::class, 2);
    }

    #[Test]
    public function host_can_delete_an_action_item(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $item = $this->notes->createItem($meeting, $hostActor, ['type' => 'task', 'title' => 'Follow up']);

        $this->notes->deleteItem($meeting, $hostActor, $item);

        $this->assertCount(0, $this->notes->listItems($meeting));
    }

    #[Test]
    public function a_regular_member_cannot_create_action_items(): void
    {
        [$meeting, ,] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->notes->createItem($meeting, $memberActor, ['type' => 'task', 'title' => 'Not allowed']);
    }

    // -----------------------------------------------------------------
    // بند 21 — Polls
    // -----------------------------------------------------------------

    #[Test]
    public function host_can_create_a_single_choice_poll(): void
    {
        Event::fake([MeetingPollCreated::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();

        $poll = $this->polls->create($meeting, $hostActor, [
            'question' => 'Approve the proposal?',
            'options'  => ['Yes', 'No'],
        ]);

        $this->assertSame('single_choice', $poll->poll_type);
        $this->assertCount(2, $poll->options);
        Event::assertDispatched(MeetingPollCreated::class);
    }

    #[Test]
    public function creating_a_poll_with_fewer_than_two_options_fails(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->expectException(\InvalidArgumentException::class);
        $this->polls->create($meeting, $hostActor, ['question' => 'Q?', 'options' => ['Only one']]);
    }

    #[Test]
    public function a_regular_member_cannot_create_a_poll(): void
    {
        [$meeting, ,] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->polls->create($meeting, $memberActor, ['question' => 'Q?', 'options' => ['A', 'B']]);
    }

    #[Test]
    public function a_member_can_vote_in_a_single_choice_poll_and_results_update_live(): void
    {
        Event::fake([MeetingPollResultsUpdated::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $poll = $this->polls->create($meeting, $hostActor, ['question' => 'Approve?', 'options' => ['Yes', 'No']]);
        $yesOptionId = $poll->options[0]->id;

        $results = $this->polls->vote($meeting, $memberActor, $poll, [$yesOptionId]);

        $this->assertSame(1, $results['total_votes']);
        $this->assertSame(1, $results['options'][0]['vote_count']);
        $this->assertSame(0, $results['options'][1]['vote_count']);
        Event::assertDispatched(MeetingPollResultsUpdated::class);
    }

    #[Test]
    public function revoting_replaces_the_members_previous_choice_in_a_single_choice_poll(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $poll = $this->polls->create($meeting, $hostActor, ['question' => 'Approve?', 'options' => ['Yes', 'No']]);
        [$yesId, $noId] = $poll->options->pluck('id')->all();

        $this->polls->vote($meeting, $memberActor, $poll, [$yesId]);
        $results = $this->polls->vote($meeting, $memberActor, $poll, [$noId]);

        $this->assertSame(1, $results['total_votes']);
        $this->assertSame(0, $results['options'][0]['vote_count']);
        $this->assertSame(1, $results['options'][1]['vote_count']);
    }

    #[Test]
    public function a_single_choice_poll_rejects_more_than_one_selected_option(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $poll = $this->polls->create($meeting, $hostActor, ['question' => 'Approve?', 'options' => ['Yes', 'No']]);
        [$yesId, $noId] = $poll->options->pluck('id')->all();

        $this->expectException(\InvalidArgumentException::class);
        $this->polls->vote($meeting, $memberActor, $poll, [$yesId, $noId]);
    }

    #[Test]
    public function a_multiple_choice_poll_accepts_several_options(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $poll = $this->polls->create($meeting, $hostActor, [
            'question'  => 'Which days work?',
            'poll_type' => 'multiple_choice',
            'options'   => ['Mon', 'Tue', 'Wed'],
        ]);
        $optionIds = $poll->options->pluck('id')->take(2)->all();

        $results = $this->polls->vote($meeting, $memberActor, $poll, $optionIds);

        $this->assertSame(2, $results['total_votes']);
    }

    #[Test]
    public function anonymous_polls_never_reveal_voter_names(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $poll = $this->polls->create($meeting, $hostActor, [
            'question'     => 'Secret vote?',
            'is_anonymous' => true,
            'options'      => ['Yes', 'No'],
        ]);

        $results = $this->polls->vote($meeting, $memberActor, $poll, [$poll->options[0]->id]);

        $this->assertNull($results['options'][0]['voters']);
    }

    #[Test]
    public function voting_on_a_closed_poll_fails(): void
    {
        Event::fake([MeetingPollClosed::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $poll = $this->polls->create($meeting, $hostActor, ['question' => 'Q?', 'options' => ['A', 'B']]);

        $this->polls->close($meeting, $hostActor, $poll);

        $this->expectException(\RuntimeException::class);
        $this->polls->vote($meeting, $memberActor, $poll->fresh(), [$poll->options[0]->id]);
    }

    #[Test]
    public function only_the_host_can_close_a_poll(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $poll = $this->polls->create($meeting, $hostActor, ['question' => 'Q?', 'options' => ['A', 'B']]);

        $this->expectException(\RuntimeException::class);
        $this->polls->close($meeting, $memberActor, $poll);
    }
}
