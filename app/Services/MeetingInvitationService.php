<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\Project;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\MeetingRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;

/**
 * Meetings & Collaboration Platform — Round 8 (Invitations &
 * Calendar): بند 13 ("The host should be able to invite: Specific
 * users, Students, Academic staff, Supervisors,
 * Faculties, Universities, Groups, Project members" +
 * "must respect UIP roles and permissions").
 *
 * كل نوع من الأنواع دي هنا بيتحل لمصفوفة user_id، وبعدين كل
 * user_id بيتبعت لـ MeetingService::invite() الموجود من Round 1 (نفس
 * الدعوة الفردية بالظبط، من غير ما نعيد اختراع منطق الدعوة نفسه) —
 * الإضافة الوحيدة هنا هي **الحل الجماعي** (bulk resolution) +
 * **فحص النطاق** (scope check) قبل ما نوصلها.
 *
 * "must respect UIP roles and permissions" اتفسّرت هنا: الهوست مايقدرش
 * يدعو "كل طلاب الجامعة" لجامعة غير جامعته هو (نفس مبدأ findOwned()/
 * forUniversity() المستخدم في كل المنصة للـ scoping — مفيش جدول
 * صلاحيات جديد اتعمل، ده استخدام لنفس بنية الأدوار/النطاقات
 * الموجودة). عضو أدمن (uip_role='admin') بس هو اللي بيتخطى فحص
 * النطاق ده — نفس قاعدة PermissionService::currentUserCan() "أدمن
 * دايمًا true".
 *
 * أنواع الـ target المدعومة (كل عنصر في مصفوفة targets[]):
 *   - {type: 'user', user_id}                                   — بند 13 "Specific users"
 *   - {type: 'users', user_ids: int[]}                          — دفعة يوزرات صراحةً
 *   - {type: 'students', university_id, faculty_id?, department_id?}
 *   - {type: 'academic_staff', university_id, faculty_id?, department_id?}
 *   - {type: 'supervisors', university_id}
 *   - {type: 'faculties', university_id}                        — حسابات الكليات (Faculty portal) التابعة لجامعة
 *   - {type: 'universities', university_ids?: int[]}            — حسابات جامعات محددة (أدمن بس، راجع requireAdmin تحت)
 *   - {type: 'group', group_id}                                 — أعضاء مجموعة طلاب (بند 16 القديم)
 *   - {type: 'project', project_id}                             — صاحب المشروع + أعضاء الفريق المقبولين (بند 11)
 */
class MeetingInvitationService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingService $meetingService,
        private StudentRepository $students,
        private AcademicStaffRepository $academicStaff,
        private SupervisorRepository $supervisors,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private StudentGroupRepository $groups,
        private ProjectTeamMemberRepository $projectTeam
    ) {
    }

    /**
     * جامعة/كلية/قسم الهوست نفسه (لو حسابه من نوع بيحمل نطاق) — أساس
     * فحص النطاق لباقي targets. null لدور مالوش نطاق جامعة (admin،
     * ...) — يقدر يستخدم بس user/users.
     *
     * @return array{university_id:?int, faculty_id:?int}
     */
    private function hostScope(int $hostUserId, string $hostRole): array
    {
        switch ($hostRole) {
            case 'university':
                $university = $this->universities->findByUserId($hostUserId);
                return ['university_id' => $university?->id, 'faculty_id' => null];
            case 'faculty':
                $faculty = $this->faculties->findByUserId($hostUserId);
                return ['university_id' => $faculty?->university_id, 'faculty_id' => $faculty?->id];
            case 'academic_staff':
                $staff = $this->academicStaff->findByUserId($hostUserId);
                return ['university_id' => $staff?->university_id, 'faculty_id' => $staff?->faculty_id];
            case 'supervisor':
                $supervisor = $this->supervisors->findByUserId($hostUserId);
                return ['university_id' => $supervisor?->university_id, 'faculty_id' => null];
            case 'student':
                $student = $this->students->findByUserId($hostUserId);
                return ['university_id' => $student?->university_id, 'faculty_id' => $student?->faculty_id];
            default:
                return ['university_id' => null, 'faculty_id' => null];
        }
    }

    /** @throws \RuntimeException الهوست مش أدمن، ومحاول يدعو نطاق جامعة مش بتاعته (أو حسابه مالوش نطاق أصلًا). */
    private function requireOwnUniversity(int $hostUserId, string $hostRole, $requestedUniversityId): void
    {
        if ($hostRole === 'admin') {
            return;
        }
        $scope = $this->hostScope($hostUserId, $hostRole);
        if ($scope['university_id'] === null || (int) $scope['university_id'] !== (int) $requestedUniversityId) {
            throw new \RuntimeException('You can only invite members of your own university.');
        }
    }

    /** @throws \RuntimeException بند 25 — "universities" (bulk، عبر جامعات مختلفة) أدمن بس. */
    private function requireAdmin(string $hostRole): void
    {
        if ($hostRole !== 'admin') {
            throw new \RuntimeException('Only an admin can bulk-invite university accounts.');
        }
    }

    /**
     * @param array $target عنصر واحد من targets[] (شكل type-specific، راجع docblock الكلاس)
     * @return int[] user_id مُوحّدة (ممكن تبقى فاضية)
     * @throws \InvalidArgumentException target غير معروف/ناقص حقول مطلوبة.
     * @throws \RuntimeException فحص النطاق فشل (بند 13 "must respect UIP roles and permissions").
     */
    private function resolveTarget(array $target, int $hostUserId, string $hostRole): array
    {
        $type = $target['type'] ?? null;

        switch ($type) {
            case 'user':
                if (empty($target['user_id'])) {
                    throw new \InvalidArgumentException('user_id is required for a "user" target.');
                }
                return [(int) $target['user_id']];

            case 'users':
                if (empty($target['user_ids']) || !is_array($target['user_ids'])) {
                    throw new \InvalidArgumentException('user_ids is required for a "users" target.');
                }
                return array_map('intval', $target['user_ids']);

            case 'students':
                if (empty($target['university_id'])) {
                    throw new \InvalidArgumentException('university_id is required for a "students" target.');
                }
                $this->requireOwnUniversity($hostUserId, $hostRole, $target['university_id']);
                $collection = !empty($target['faculty_id'])
                    ? $this->students->forFaculty($target['faculty_id'])
                    : $this->students->forUniversity($target['university_id']);
                if (!empty($target['department_id'])) {
                    $collection = $collection->where('department_id', $target['department_id']);
                }
                return $collection->pluck('user_id')->filter()->map('intval')->values()->all();

            case 'academic_staff':
                if (empty($target['university_id'])) {
                    throw new \InvalidArgumentException('university_id is required for an "academic_staff" target.');
                }
                $this->requireOwnUniversity($hostUserId, $hostRole, $target['university_id']);
                if (!empty($target['department_id'])) {
                    $rows = $this->academicStaff->forDepartment($target['department_id'], 'active');
                } elseif (!empty($target['faculty_id'])) {
                    $rows = $this->academicStaff->forFaculty($target['faculty_id'], 'active');
                } else {
                    $rows = $this->academicStaff->forUniversity($target['university_id'], 'active');
                }
                return array_values(array_filter(array_map(fn ($r) => (int) $r->user_id, $rows)));

            case 'supervisors':
                if (empty($target['university_id'])) {
                    throw new \InvalidArgumentException('university_id is required for a "supervisors" target.');
                }
                $this->requireOwnUniversity($hostUserId, $hostRole, $target['university_id']);
                return array_values(array_filter(array_map(
                    fn ($s) => $s->user_id !== null ? (int) $s->user_id : null,
                    $this->supervisors->forUniversity($target['university_id'])
                )));

            case 'faculties':
                if (empty($target['university_id'])) {
                    throw new \InvalidArgumentException('university_id is required for a "faculties" target.');
                }
                $this->requireOwnUniversity($hostUserId, $hostRole, $target['university_id']);
                return array_values(array_filter(array_map(
                    fn ($f) => $f->user_id !== null ? (int) $f->user_id : null,
                    $this->faculties->forUniversity($target['university_id'])
                )));

            case 'universities':
                $this->requireAdmin($hostRole);
                $query = \App\Models\University::query();
                if (!empty($target['university_ids'])) {
                    $query->whereIn('id', array_map('intval', $target['university_ids']));
                }
                return $query->pluck('user_id')->filter()->map('intval')->values()->all();

            case 'group':
                if (empty($target['group_id'])) {
                    throw new \InvalidArgumentException('group_id is required for a "group" target.');
                }
                $group = $this->groups->find($target['group_id']);
                if (!$group) {
                    throw new \InvalidArgumentException('Student group not found.');
                }
                $this->requireOwnUniversity($hostUserId, $hostRole, $group->university_id);
                return array_map(fn ($m) => (int) $m['user_id'], $this->groups->members($group->id));

            case 'project':
                if (empty($target['project_id'])) {
                    throw new \InvalidArgumentException('project_id is required for a "project" target.');
                }
                $project = Project::find($target['project_id']);
                if (!$project) {
                    throw new \InvalidArgumentException('Project not found.');
                }
                $userIds = [(int) $project->owner_id];
                foreach ($this->projectTeam->forProject($project->id) as $member) {
                    if ($member->user_id !== null && $member->status === 'accepted') {
                        $userIds[] = (int) $member->user_id;
                    }
                }
                return $userIds;

            default:
                throw new \InvalidArgumentException('Unknown invitation target type: ' . (string) $type);
        }
    }

    /**
     * @param array[] $targets راجع docblock الكلاس لشكل كل عنصر
     * @return array{invitations: MeetingInvitation[], invited_count: int, skipped_self_or_host: int}
     * @throws \InvalidArgumentException target غير صالح.
     * @throws \RuntimeException فحص نطاق فشل لأي target.
     */
    public function bulkInvite(Meeting $meeting, int $hostUserId, string $hostRole, array $targets, ?string $message = null): array
    {
        if (empty($targets)) {
            throw new \InvalidArgumentException('At least one invitation target is required.');
        }

        $userIds = [];
        foreach ($targets as $target) {
            $userIds = array_merge($userIds, $this->resolveTarget($target, $hostUserId, $hostRole));
        }
        $userIds = array_values(array_unique(array_filter($userIds, fn ($id) => $id > 0)));

        $skipped = 0;
        $invitations = [];
        foreach ($userIds as $userId) {
            if ($userId === $hostUserId) {
                $skipped++;
                continue;
            }
            $invitations[] = $this->meetingService->invite($meeting, $hostUserId, $userId, $message);
        }

        return [
            'invitations'           => $invitations,
            'invited_count'         => count($invitations),
            'skipped_self_or_host'  => $skipped,
        ];
    }
}
