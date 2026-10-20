<?php

namespace Tests\Feature;

use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\MeetingRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\SettingRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;
use App\Services\MeetingAttachableService;
use App\Services\MeetingInvitationService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round 8 (Invitations & Calendar) بتاع موديول الاجتماعات: بند 13 (Bulk
 * Invitations بالأدوار/المجموعات/المشاريع)، بند 14 (Calendar view)، بند
 * 26 (Project Integration — attachable entities). الاختبارات هنا بتنادي
 * MeetingInvitationService/MeetingAttachableService/MeetingRepository
 * مباشرة (زي MeetingFoundationTest بالظبط)، من غير HTTP layer.
 *
 * الجداول المحتاجة لأنواع الـ target دي (projects/project_team_members/
 * supervisors + أعمدة إضافية على
 * universities/faculties/academic_staff) مش موجودة في شيم
 * 2020_01_01_000000_create_test_support_tables.php الأصلي (كان مبني
 * لاحتياجات exam-system بس) — اتضافت في migration منفصلة additive:
 * 2026_08_31_095000_add_round8_meeting_invitation_test_support_tables.php.
 */
class MeetingInvitationsRound8Test extends TestCase
{
    use DatabaseTransactions;

    private MeetingRepository $meetings;
    private MeetingPolicyService $policy;
    private MeetingService $service;
    private MeetingInvitationService $bulkInvites;
    private MeetingAttachableService $attachables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meetings = new MeetingRepository();
        $this->policy = new MeetingPolicyService(new SettingRepository());
        $this->service = new MeetingService($this->meetings, $this->policy);

        $students = new StudentRepository();
        $academicStaff = new AcademicStaffRepository();
        $supervisors = new SupervisorRepository();
        $universities = new UniversityRepository();
        $faculties = new FacultyRepository();
        $groups = new StudentGroupRepository();
        $projectTeam = new ProjectTeamMemberRepository();

        $this->bulkInvites = new MeetingInvitationService(
            $this->meetings,
            $this->service,
            $students,
            $academicStaff,
            $supervisors,
            $universities,
            $faculties,
            $groups,
            $projectTeam
        );

        $this->attachables = new MeetingAttachableService(
            $projectTeam,
            $faculties,
            $universities,
            $groups,
            $students,
            $academicStaff,
            $supervisors
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

    private function makeUniversity(?int $userId = null): int
    {
        return DB::table('universities')->insertGetId([
            'user_id'          => $userId,
            'name'             => 'Test University',
            'official_name_en' => 'Test University',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function makeFaculty(int $universityId, ?int $userId = null): int
    {
        return DB::table('faculties')->insertGetId([
            'university_id' => $universityId,
            'user_id'       => $userId,
            'name'          => 'Faculty of Engineering',
            'name_en'       => 'Faculty of Engineering',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function makeStudent(int $universityId, int $userId, ?int $facultyId = null, ?int $groupId = null): int
    {
        return DB::table('students')->insertGetId([
            'user_id'       => $userId,
            'university_id' => $universityId,
            'faculty_id'    => $facultyId,
            'group_id'      => $groupId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function makeAcademicStaff(int $universityId, int $userId, ?int $facultyId = null): int
    {
        return DB::table('academic_staff')->insertGetId([
            'user_id'       => $userId,
            'university_id' => $universityId,
            'faculty_id'    => $facultyId,
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // بند 13 — Bulk Invitations.
    // -----------------------------------------------------------------

    #[Test]
    public function bulk_invite_resolves_explicit_user_ids_and_skips_the_host(): void
    {
        $hostId = $this->makeUser('Host');
        $userA = $this->makeUser('A');
        $userB = $this->makeUser('B');
        $meeting = $this->service->create($hostId, ['title' => 'Kickoff']);

        $result = $this->bulkInvites->bulkInvite($meeting, $hostId, 'student', [
            ['type' => 'users', 'user_ids' => [$userA, $userB, $hostId]],
        ]);

        $this->assertSame(2, $result['invited_count']);
        $this->assertSame(1, $result['skipped_self_or_host']);
        $invitedIds = array_map(fn ($i) => $i->invited_user_id, $result['invitations']);
        $this->assertContains($userA, $invitedIds);
        $this->assertContains($userB, $invitedIds);
    }

    #[Test]
    public function bulk_invite_resolves_students_of_hosts_own_university(): void
    {
        $hostUserId = $this->makeUser('University Host');
        $universityId = $this->makeUniversity($hostUserId);
        $studentUser1 = $this->makeUser('Student One');
        $studentUser2 = $this->makeUser('Student Two');
        $this->makeStudent($universityId, $studentUser1);
        $this->makeStudent($universityId, $studentUser2);

        $meeting = $this->service->create($hostUserId, ['title' => 'All Students Briefing']);

        $result = $this->bulkInvites->bulkInvite($meeting, $hostUserId, 'university', [
            ['type' => 'students', 'university_id' => $universityId],
        ]);

        $this->assertSame(2, $result['invited_count']);
    }

    #[Test]
    public function bulk_invite_rejects_students_target_from_another_university(): void
    {
        $hostUserId = $this->makeUser('University Host');
        $ownUniversityId = $this->makeUniversity($hostUserId);
        $otherUniversityId = $this->makeUniversity($this->makeUser('Other University Owner'));

        $meeting = $this->service->create($hostUserId, ['title' => 'Briefing']);

        $this->expectException(\RuntimeException::class);
        $this->bulkInvites->bulkInvite($meeting, $hostUserId, 'university', [
            ['type' => 'students', 'university_id' => $otherUniversityId],
        ]);

        unset($ownUniversityId);
    }

    #[Test]
    public function bulk_invite_admin_bypasses_the_own_university_scope_check(): void
    {
        $adminUserId = $this->makeUser('Admin');
        $otherUniversityId = $this->makeUniversity($this->makeUser('Some University Owner'));
        $studentUserId = $this->makeUser('Student');
        $this->makeStudent($otherUniversityId, $studentUserId);

        $meeting = $this->service->create($adminUserId, ['title' => 'Admin Broadcast']);

        $result = $this->bulkInvites->bulkInvite($meeting, $adminUserId, 'admin', [
            ['type' => 'students', 'university_id' => $otherUniversityId],
        ]);

        $this->assertSame(1, $result['invited_count']);
    }

    #[Test]
    public function bulk_invite_resolves_a_student_group_members_target(): void
    {
        $hostUserId = $this->makeUser('University Host');
        $universityId = $this->makeUniversity($hostUserId);
        $groupId = DB::table('student_groups')->insertGetId([
            'name' => 'Robotics Club', 'university_id' => $universityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $memberUserId = $this->makeUser('Member');
        $this->makeStudent($universityId, $memberUserId, null, $groupId);

        $meeting = $this->service->create($hostUserId, ['title' => 'Club Meeting']);

        $result = $this->bulkInvites->bulkInvite($meeting, $hostUserId, 'university', [
            ['type' => 'group', 'group_id' => $groupId],
        ]);

        $this->assertSame(1, $result['invited_count']);
        $this->assertSame($memberUserId, $result['invitations'][0]->invited_user_id);
    }

    #[Test]
    public function bulk_invite_resolves_a_project_owner_and_accepted_team_members(): void
    {
        $ownerUserId = $this->makeUser('Project Owner');
        $acceptedMemberUserId = $this->makeUser('Accepted Member');
        $pendingMemberUserId = $this->makeUser('Pending Member');

        $projectId = DB::table('projects')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'owner_id' => $ownerUserId,
            'title_en' => 'Graduation Project', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('project_team_members')->insert([
            ['project_id' => $projectId, 'user_id' => $acceptedMemberUserId, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $projectId, 'user_id' => $pendingMemberUserId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $hostUserId = $this->makeUser('Some Host');
        $meeting = $this->service->create($hostUserId, ['title' => 'Project Sync']);

        $result = $this->bulkInvites->bulkInvite($meeting, $hostUserId, 'student', [
            ['type' => 'project', 'project_id' => $projectId],
        ]);

        $invitedIds = array_map(fn ($i) => $i->invited_user_id, $result['invitations']);
        $this->assertContains($ownerUserId, $invitedIds);
        $this->assertContains($acceptedMemberUserId, $invitedIds);
        $this->assertNotContains($pendingMemberUserId, $invitedIds);
    }

    #[Test]
    public function bulk_invite_rejects_an_unknown_target_type(): void
    {
        $hostUserId = $this->makeUser('Host');
        $meeting = $this->service->create($hostUserId, ['title' => 'Meeting']);

        $this->expectException(\InvalidArgumentException::class);
        $this->bulkInvites->bulkInvite($meeting, $hostUserId, 'student', [
            ['type' => 'not_a_real_type'],
        ]);
    }

    #[Test]
    public function bulk_invite_requires_admin_for_a_universities_target(): void
    {
        $hostUserId = $this->makeUser('Regular Host');
        $meeting = $this->service->create($hostUserId, ['title' => 'Meeting']);

        $this->expectException(\RuntimeException::class);
        $this->bulkInvites->bulkInvite($meeting, $hostUserId, 'student', [
            ['type' => 'universities'],
        ]);
    }

    // -----------------------------------------------------------------
    // بند 26 — Attachable Entities.
    // -----------------------------------------------------------------

    #[Test]
    public function attachable_resolve_returns_nulls_when_no_type_is_given(): void
    {
        $result = $this->attachables->resolve($this->makeUser(), 'student', []);
        $this->assertNull($result['attachable_type']);
        $this->assertNull($result['attachable_id']);
    }

    #[Test]
    public function attachable_resolve_accepts_a_label_only_type_without_existence_check(): void
    {
        $result = $this->attachables->resolve($this->makeUser(), 'student', ['attachable_type' => 'course']);
        $this->assertSame('course', $result['attachable_type']);
        $this->assertNull($result['attachable_id']);
    }

    #[Test]
    public function attachable_resolve_rejects_an_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->attachables->resolve($this->makeUser(), 'student', ['attachable_type' => 'not_a_real_type']);
    }

    #[Test]
    public function attachable_resolve_allows_the_project_owner_to_attach_their_own_project(): void
    {
        $ownerUserId = $this->makeUser('Owner');
        $projectId = DB::table('projects')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'owner_id' => $ownerUserId,
            'title_en' => 'My Project', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->attachables->resolve($ownerUserId, 'student', [
            'attachable_type' => 'project', 'attachable_id' => $projectId,
        ]);

        $this->assertSame('project', $result['attachable_type']);
        $this->assertSame($projectId, $result['attachable_id']);
    }

    #[Test]
    public function attachable_resolve_rejects_a_project_the_host_does_not_own_or_belong_to(): void
    {
        $ownerUserId = $this->makeUser('Owner');
        $strangerUserId = $this->makeUser('Stranger');
        $projectId = DB::table('projects')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'owner_id' => $ownerUserId,
            'title_en' => 'My Project', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->attachables->resolve($strangerUserId, 'student', [
            'attachable_type' => 'project', 'attachable_id' => $projectId,
        ]);
    }

    #[Test]
    public function attachable_resolve_requires_attachable_id_for_a_validated_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->attachables->resolve($this->makeUser(), 'student', ['attachable_type' => 'project']);
    }

    #[Test]
    public function attachable_resolve_rejects_a_nonexistent_project_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->attachables->resolve($this->makeUser(), 'student', [
            'attachable_type' => 'project', 'attachable_id' => 999999,
        ]);
    }

    #[Test]
    public function attachable_resolve_allows_own_university_account_to_attach_a_student_group(): void
    {
        $hostUserId = $this->makeUser('University Host');
        $universityId = $this->makeUniversity($hostUserId);
        $groupId = DB::table('student_groups')->insertGetId([
            'name' => 'Robotics Club', 'university_id' => $universityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->attachables->resolve($hostUserId, 'university', [
            'attachable_type' => 'student_group', 'attachable_id' => $groupId,
        ]);

        $this->assertSame('student_group', $result['attachable_type']);
    }

    #[Test]
    public function attachable_resolve_rejects_a_student_group_from_another_university(): void
    {
        $hostUserId = $this->makeUser('University Host');
        $this->makeUniversity($hostUserId);
        $otherUniversityId = $this->makeUniversity($this->makeUser('Other Owner'));
        $groupId = DB::table('student_groups')->insertGetId([
            'name' => 'Other Group', 'university_id' => $otherUniversityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->attachables->resolve($hostUserId, 'university', [
            'attachable_type' => 'student_group', 'attachable_id' => $groupId,
        ]);
    }

    // -----------------------------------------------------------------
    // بند 14 — Calendar & بند 26 — forAttachable().
    // -----------------------------------------------------------------

    #[Test]
    public function calendar_for_user_returns_only_meetings_in_the_requested_date_range(): void
    {
        $hostId = $this->makeUser('Host');

        $inRange = $this->service->create($hostId, [
            'title' => 'In Range', 'type' => 'scheduled', 'scheduled_start_at' => '2026-09-10 10:00:00',
        ]);
        $outOfRange = $this->service->create($hostId, [
            'title' => 'Out Of Range', 'type' => 'scheduled', 'scheduled_start_at' => '2026-10-10 10:00:00',
        ]);

        $results = $this->meetings->calendarForUser($hostId, '2026-09-01 00:00:00', '2026-09-30 23:59:59');

        $ids = array_map(fn ($m) => $m->id, $results);
        $this->assertContains($inRange->id, $ids);
        $this->assertNotContains($outOfRange->id, $ids);
    }

    #[Test]
    public function calendar_for_user_includes_meetings_where_the_user_is_a_participant_not_just_host(): void
    {
        $hostId = $this->makeUser('Host');
        $participantId = $this->makeUser('Participant');

        $meeting = $this->service->create($hostId, [
            'title' => 'Shared Meeting', 'type' => 'scheduled', 'scheduled_start_at' => '2026-09-15 09:00:00',
        ]);
        $this->meetings->addParticipant([
            'meeting_id' => $meeting->id, 'user_id' => $participantId,
            'role' => 'participant', 'status' => 'joined', 'joined_at' => now(),
        ]);

        $results = $this->meetings->calendarForUser($participantId, '2026-09-01 00:00:00', '2026-09-30 23:59:59');

        $this->assertContains($meeting->id, array_map(fn ($m) => $m->id, $results));
    }

    #[Test]
    public function for_attachable_returns_all_meetings_linked_to_one_entity(): void
    {
        $hostId = $this->makeUser('Host');
        $ownerUserId = $this->makeUser('Project Owner');
        $projectId = DB::table('projects')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'owner_id' => $ownerUserId,
            'title_en' => 'Project', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $attached = $this->service->create($hostId, [
            'title' => 'Attached', 'attachable_type' => 'project', 'attachable_id' => $projectId,
        ]);
        $unattached = $this->service->create($hostId, ['title' => 'Unattached']);

        $results = $this->meetings->forAttachable('project', $projectId);

        $ids = array_map(fn ($m) => $m->id, $results);
        $this->assertContains($attached->id, $ids);
        $this->assertNotContains($unattached->id, $ids);
        $this->assertSame('project', $attached->attachable_type);
        $this->assertSame($projectId, $attached->attachable_id);
    }
}
