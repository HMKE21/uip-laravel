<?php

namespace App\Services;

use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;

/**
 * Meetings & Collaboration Platform — Round 8 (Invitations & Calendar):
 * بند 26 ("Project Integration" — "Meetings should be attachable to UIP
 * entities"). يتستخدم من MeetingsApiController وقت store()/update() بس
 * (زي MeetingInvitationService بالظبط، بند 13) — مش موديل/migration
 * جديد، مجرد تحقق قبل ما نمرر attachable_type/attachable_id لـ
 * MeetingService::create()/update() (راجع docblock migration
 * 2026_08_31_090000 لتفصيل النوعين).
 *
 * النوعين المدعومين في attachable_type:
 *   1) VALIDATED_TYPES: ليها موديول Eloquent فعلي، بيتحقق من الوجود +
 *      إن الهوست فعلاً "يملك"/عضو في الكيان ده وقت الإنشاء.
 *   2) LABEL_ONLY_TYPES: مذكورة في نص المواصفة (بند 26) بس مفيش لها
 *      موديول مستقل دلوقتي — بتتقبل كـ label حر من غير فحص وجود.
 */
class MeetingAttachableService
{
    public const VALIDATED_TYPES = ['project', 'faculty', 'university', 'student_group'];

    public const LABEL_ONLY_TYPES = ['research', 'course', 'supervisor_relationship'];

    public function __construct(
        private ProjectTeamMemberRepository $projectTeam,
        private FacultyRepository $faculties,
        private UniversityRepository $universities,
        private StudentGroupRepository $groups,
        private StudentRepository $students,
        private AcademicStaffRepository $academicStaff,
        private SupervisorRepository $supervisors
    ) {
    }

    /**
     * جامعة الهوست نفسه (لو حسابه من نوع بيحمل نطاق جامعة) — نفس مبدأ
     * MeetingInvitationService::hostScope()، بس مبسّطة لـ university_id
     * بس (مش محتاجين faculty_id هنا). null لدور مالوش نطاق جامعة.
     */
    private function hostUniversityId(int $hostUserId, string $hostRole): ?int
    {
        switch ($hostRole) {
            case 'university':
                return $this->universities->findByUserId($hostUserId)?->id;
            case 'faculty':
                return $this->faculties->findByUserId($hostUserId)?->university_id;
            case 'academic_staff':
                return $this->academicStaff->findByUserId($hostUserId)?->university_id;
            case 'supervisor':
                return $this->supervisors->findByUserId($hostUserId)?->university_id;
            case 'student':
                return $this->students->findByUserId($hostUserId)?->university_id;
            default:
                return null;
        }
    }

    /**
     * @param array $data قد تحمل attachable_type/attachable_id (الاتنين
     *                     اختياريين — لو attachable_type مش موجود، مفيش
     *                     ربط، بيرجع النوعين null).
     * @return array{attachable_type: ?string, attachable_id: ?int}
     * @throws \InvalidArgumentException type مش معروف، أو VALIDATED_TYPES من غير attachable_id، أو الكيان نفسه مش موجود.
     * @throws \RuntimeException الهوست مش مالك/عضو الكيان المطلوب الربط بيه.
     */
    public function resolve(int $hostUserId, string $hostRole, array $data): array
    {
        $type = $data['attachable_type'] ?? null;
        if ($type === null || $type === '') {
            return ['attachable_type' => null, 'attachable_id' => null];
        }

        if (in_array($type, self::LABEL_ONLY_TYPES, true)) {
            // مفيش موديول نتحقق منه — attachable_id (لو موجود) بيتسجل
            // زي ما هو، مجرد label إضافي للفرونت.
            return [
                'attachable_type' => $type,
                'attachable_id'   => !empty($data['attachable_id']) ? (int) $data['attachable_id'] : null,
            ];
        }

        if (!in_array($type, self::VALIDATED_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown attachable_type: ' . (string) $type);
        }

        if (empty($data['attachable_id'])) {
            throw new \InvalidArgumentException('attachable_id is required for attachable_type "' . $type . '".');
        }
        $id = (int) $data['attachable_id'];

        switch ($type) {
            case 'project':
                $project = \App\Models\Project::find($id);
                if (!$project) {
                    throw new \InvalidArgumentException('Project not found.');
                }
                $isOwner = (int) $project->owner_id === $hostUserId;
                $isMember = $this->projectTeam->isAcceptedMember($project->id, $hostUserId);
                if (!$isOwner && !$isMember && $hostRole !== 'admin') {
                    throw new \RuntimeException('You can only attach meetings to your own projects.');
                }
                break;

            case 'faculty':
                $faculty = $this->faculties->find($id);
                if (!$faculty) {
                    throw new \InvalidArgumentException('Faculty not found.');
                }
                if ($hostRole !== 'admin' && (int) ($faculty->user_id ?? 0) !== $hostUserId) {
                    throw new \RuntimeException('You can only attach meetings to your own faculty account.');
                }
                break;

            case 'university':
                $university = $this->universities->find($id);
                if (!$university) {
                    throw new \InvalidArgumentException('University not found.');
                }
                if ($hostRole !== 'admin' && (int) ($university->user_id ?? 0) !== $hostUserId) {
                    throw new \RuntimeException('You can only attach meetings to your own university account.');
                }
                break;

            case 'student_group':
                $group = $this->groups->find($id);
                if (!$group) {
                    throw new \InvalidArgumentException('Student group not found.');
                }
                if ($hostRole !== 'admin') {
                    $universityId = $this->hostUniversityId($hostUserId, $hostRole);
                    if ($universityId === null || (int) $universityId !== (int) $group->university_id) {
                        throw new \RuntimeException('You can only attach meetings to a student group in your own university.');
                    }
                }
                break;
        }

        return ['attachable_type' => $type, 'attachable_id' => $id];
    }
}
