<?php

namespace Tests\Feature;

use App\Events\Meetings\MeetingRecordingReady;
use App\Events\Meetings\MeetingRecordingStarted;
use App\Events\Meetings\MeetingRecordingStopped;
use App\Jobs\ProcessMeetingRecordingJob;
use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;
use App\Services\FileUploadPolicyService;
use App\Services\FileUploadService;
use App\Services\MeetingLobbyService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingRecordingService;
use App\Services\MeetingService;
use App\Services\MeetingSignalingService;
use App\Services\MeetingChatService;
use App\Services\MeetingAttendanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round 9 (Recording) بتاع موديول الاجتماعات: بند 18 ("Meeting
 * Recording" — Client-Side recording, Recording status, Recording
 * processing، Recording access permissions). نفس نمط إعداد
 * MeetingCollaborationExtrasTest بالظبط (JWT_SECRET/reverb config في
 * setUp، DatabaseTransactions، الاختبارات بتنادي الـ services مباشرة
 * مش HTTP).
 */
class MeetingRecordingRound9Test extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingLobbyService $lobby;
    private MeetingSignalingService $signaling;
    private MeetingRecordingService $recordings;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('JWT_SECRET')) {
            putenv('JWT_SECRET=test-secret-for-meeting-recording-tests');
        }

        config([
            'broadcasting.connections.reverb.key'    => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
        ]);

        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->lobby = new MeetingLobbyService($this->meetings, $this->policy);
        $chat = new MeetingChatService($this->meetings);
        $attendance = new MeetingAttendanceService($this->meetings);
        $this->signaling = new MeetingSignalingService($this->meetings, $this->lobby, $this->policy, $chat, $attendance);
        $this->service = new MeetingService($this->meetings, $this->policy, null, $this->signaling, $attendance);
        $this->recordings = new MeetingRecordingService(
            $this->meetings,
            new FileUploadService(new FileUploadPolicyService()),
            $this->policy
        );
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
    // بند 18 — Recording status / lifecycle
    // -----------------------------------------------------------------

    #[Test]
    public function the_host_can_start_a_recording_and_all_participants_are_notified(): void
    {
        Event::fake([MeetingRecordingStarted::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();

        $recording = $this->recordings->start($meeting, $hostActor, 'video');

        $this->assertSame('recording', $recording->status);
        $this->assertSame('video', $recording->kind);
        $this->assertTrue($recording->isActive());
        Event::assertDispatched(MeetingRecordingStarted::class);
    }

    #[Test]
    public function a_regular_participant_cannot_start_a_recording(): void
    {
        [$meeting] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);

        $this->expectException(\RuntimeException::class);
        $this->recordings->start($meeting, $memberActor, 'video');
    }

    #[Test]
    public function starting_a_recording_fails_when_recording_is_disabled_for_the_meeting(): void
    {
        $hostId = $this->makeUser('Host');
        $meeting = $this->service->create($hostId, [
            'title'                => 'Standup',
            'waiting_room_enabled' => false,
            'settings'             => ['recording_enabled' => false],
        ]);
        $hostActor = $this->signaling->resolveActor($meeting, $hostId, null);

        $this->expectException(\RuntimeException::class);
        $this->recordings->start($meeting, $hostActor, 'video');
    }

    #[Test]
    public function an_invalid_kind_is_rejected(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();

        $this->expectException(\InvalidArgumentException::class);
        $this->recordings->start($meeting, $hostActor, 'holographic');
    }

    #[Test]
    public function cannot_start_a_second_recording_of_the_same_kind_while_one_is_active(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $this->recordings->start($meeting, $hostActor, 'video');

        $this->expectException(\RuntimeException::class);
        $this->recordings->start($meeting, $hostActor, 'video');
    }

    #[Test]
    public function two_different_kinds_can_record_at_the_same_time(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $video = $this->recordings->start($meeting, $hostActor, 'video');
        $audio = $this->recordings->start($meeting, $hostActor, 'audio');

        $this->assertNotSame($video->id, $audio->id);
        $this->assertCount(2, $this->recordings->listFor($meeting));
    }

    #[Test]
    public function a_co_host_can_also_start_and_stop_a_recording(): void
    {
        [$meeting, , ] = $this->joinAsHost();
        [$memberId, ] = $this->joinAsMember($meeting);
        $participant = $meeting->participants()->where('user_id', $memberId)->first();
        $participant->role = 'co_host';
        $participant->save();
        $coHostActor = $this->signaling->resolveActor($meeting, $memberId, null);

        $recording = $this->recordings->start($meeting, $coHostActor, 'video');
        $this->assertSame('recording', $recording->status);

        $upload = UploadedFile::fake()->create('clip.webm', 500, 'video/webm');
        $stopped = $this->recordings->stop($meeting, $coHostActor, $recording, $upload);
        $this->assertSame('processing', $stopped->status);
    }

    // -----------------------------------------------------------------
    // بند 18 — Recording processing (stop + async finalize)
    // -----------------------------------------------------------------

    #[Test]
    public function stopping_a_recording_uploads_the_file_and_dispatches_the_processing_job(): void
    {
        Event::fake([MeetingRecordingStopped::class]);
        Queue::fake();
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');

        $upload = UploadedFile::fake()->create('meeting.webm', 2048, 'video/webm');
        $stopped = $this->recordings->stop($meeting, $hostActor, $recording, $upload);

        $this->assertSame('processing', $stopped->status);
        $this->assertNotNull($stopped->stored_path);
        $this->assertSame('meeting.webm', $stopped->original_name);
        $this->assertNotNull($stopped->duration_seconds);
        Event::assertDispatched(MeetingRecordingStopped::class);
        Queue::assertPushed(ProcessMeetingRecordingJob::class);
    }

    #[Test]
    public function stopping_an_already_stopped_recording_fails(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');
        $this->recordings->stop($meeting, $hostActor, $recording, UploadedFile::fake()->create('a.webm', 100, 'video/webm'));

        $this->expectException(\RuntimeException::class);
        $this->recordings->stop($meeting, $hostActor, $recording->fresh(), UploadedFile::fake()->create('b.webm', 100, 'video/webm'));
    }

    #[Test]
    public function stopping_with_a_disallowed_file_type_marks_the_recording_failed(): void
    {
        Event::fake([MeetingRecordingStopped::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');

        $bad = UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream');

        try {
            $this->recordings->stop($meeting, $hostActor, $recording, $bad);
            $this->fail('Expected a RuntimeException for a disallowed extension.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $recording->refresh();
        $this->assertSame('failed', $recording->status);
        $this->assertNotNull($recording->failure_reason);
        Event::assertDispatched(MeetingRecordingStopped::class);
    }

    #[Test]
    public function finalize_marks_a_processing_recording_with_a_stored_file_as_completed(): void
    {
        Event::fake([MeetingRecordingReady::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');
        $recording = $this->recordings->stop($meeting, $hostActor, $recording, UploadedFile::fake()->create('c.webm', 300, 'video/webm'));

        $this->recordings->finalize($recording->fresh());

        $recording->refresh();
        $this->assertSame('completed', $recording->status);
        Event::assertDispatched(MeetingRecordingReady::class);
    }

    #[Test]
    public function finalize_is_a_no_op_for_a_recording_that_is_not_processing(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');

        $this->recordings->finalize($recording);

        $this->assertSame('recording', $recording->fresh()->status);
    }

    #[Test]
    public function the_job_finalizes_a_recording_by_id(): void
    {
        Event::fake([MeetingRecordingReady::class]);
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');
        $recording = $this->recordings->stop($meeting, $hostActor, $recording, UploadedFile::fake()->create('d.webm', 300, 'video/webm'));

        (new ProcessMeetingRecordingJob($recording->id))->handle($this->recordings);

        $this->assertSame('completed', $recording->fresh()->status);
    }

    #[Test]
    public function the_job_silently_does_nothing_if_the_recording_was_deleted_before_it_ran(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');
        $recording = $this->recordings->stop($meeting, $hostActor, $recording, UploadedFile::fake()->create('e.webm', 300, 'video/webm'));
        $recordingId = $recording->id;
        $recording->forceDelete();

        // ماينفعش يرمي استثناء (race condition documented on the job).
        (new ProcessMeetingRecordingJob($recordingId))->handle($this->recordings);
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------
    // بند 18 — Recording access permissions
    // -----------------------------------------------------------------

    #[Test]
    public function a_regular_participant_can_list_recordings_but_cannot_delete_them(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        [, $memberActor] = $this->joinAsMember($meeting);
        $recording = $this->recordings->start($meeting, $hostActor, 'video');

        $this->assertCount(1, $this->recordings->listFor($meeting));

        $this->expectException(\RuntimeException::class);
        $this->recordings->delete($meeting, $memberActor, $recording);
    }

    #[Test]
    public function the_host_can_delete_a_recording_and_it_is_removed_from_the_list(): void
    {
        [$meeting, , $hostActor] = $this->joinAsHost();
        $recording = $this->recordings->start($meeting, $hostActor, 'video');
        $recording = $this->recordings->stop($meeting, $hostActor, $recording, UploadedFile::fake()->create('f.webm', 100, 'video/webm'));

        $this->recordings->delete($meeting, $hostActor, $recording);

        $this->assertCount(0, $this->recordings->listFor($meeting));
    }

    #[Test]
    public function recording_is_enabled_by_default(): void
    {
        [$meeting] = $this->joinAsHost();

        $this->assertTrue($this->recordings->isRecordingEnabled($meeting));
    }
}
