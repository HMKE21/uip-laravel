<?php

namespace App\Services;

use App\Models\ProjectTeamMember;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة بالكامل من app/Services/StudentTeamService.php القديمة — بند
 * 11. طريقتين لإضافة عضو فريق (قرار منتج، الاتنين مدعومين):
 *   - inviteMember(): دعوة بالإيميل، بتتحل بس مع حساب منصة موجود فعلًا
 *     (نفس سلوك ResearchProjectService::inviteMember() — status بتبدأ
 *     'pending' لحد ما المدعو يقبل).
 *   - addManualMember(): اسم + سنة دراسية + رقم طالب، من غير الحاجة
 *     لحساب — بتتسجل 'accepted' فورًا لأنه مفيش حد تاني محتاج يأكّدها.
 *
 * نفس شكل ProjectPublishingService/ProjectLinkService بالظبط ("الكنترولر
 * عمره ما يلمس الـ repository/الموديل مباشرة").
 */
class StudentTeamService
{
    /** بيخلي قائمة فريق المشروع مقروءة — نفس منطق حد اللينكات في ProjectLinkService. */
    private const MAX_TEAM_MEMBERS = 10;

    /**
     * الأدوار اللي الطالب يقدر يختارها لعضو مضاف يدويًا (migration
     * 131/135). 'supervisor' مستبعد عمدًا هنا — الدكتور/المشرف الوحيد
     * فاضل على projects.supervisor_name، عبر حقله المخصص، عشان يفضل
     * مكان واحد بس بيقود خطوة مراجعة المشرف. القايمة دي للزملاء وباقي
     * المعارف الأكاديمية (معيد/TA، متعاونين، ...) المسجلين جنبهم.
     */
    public const MANUAL_ROLES = [
        'student_member', 'teaching_assistant', 'principal_investigator',
        'professor', 'external_collaborator', 'collaborator',
    ];

    public function __construct(
        private ProjectRepository $projects,
        private ProjectTeamMemberRepository $team,
        private UserRepository $users,
        private NotificationService $notifications,
        private MailService $mail,
        private AuditLogService $auditLog
    ) {
    }

    /** @return ProjectTeamMember[] */
    public function listForOwner(string $projectUuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->team->forProject($project->id);
    }

    /**
     * بتدعو مستخدم منصة موجود (بالإيميل) لفريق المشروع.
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    public function inviteMember(string $projectUuid, $ownerId, string $email, string $locale = 'ar'): ProjectTeamMember
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }

        $this->assertRoom($project->id);

        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException($locale === 'ar' ? 'بريد إلكتروني غير صالح.' : 'Invalid email address.');
        }
        if ($why = \App\Support\DisposableEmail::problem($email, $locale)) {
            throw new \RuntimeException($why);
        }

        if ($this->team->findByProjectAndEmail($project->id, $email)) {
            throw new \RuntimeException($locale === 'ar'
                ? 'هذا البريد الإلكتروني مضاف بالفعل على هذا المشروع.'
                : 'This email is already on this project\'s team.');
        }

        $invitedUser = $this->users->findByEmail($email);
        if (!$invitedUser) {
            throw new \RuntimeException($locale === 'ar'
                ? 'لا يوجد حساب مسجّل بهذا البريد الإلكتروني على المنصة. اطلب منه/منها إنشاء حساب، أو أضفه/أضفها ببياناته كعضو بدون حساب.'
                : 'No platform account is registered with this email yet. Ask them to sign up, or add them as a member without an account.');
        }

        $member = $this->team->create([
            'project_id'    => $project->id,
            'user_id'       => $invitedUser->id,
            'invited_email' => $email,
            'role'          => 'student_member',
            'status'        => 'pending',
            'invited_by'    => $ownerId,
            'invited_at'    => now(),
        ]);

        $projectTitle = $project->title_en ?: $project->title_ar;
        $title = $locale === 'ar' ? 'دعوة للانضمام لفريق مشروع' : 'Invitation to join a project team';
        $body = $locale === 'ar'
            ? "دعاك زميلك للانضمام كعضو فريق على مشروع \"{$projectTitle}\"."
            : "You've been invited as a team member on the project \"{$projectTitle}\".";
        $linkUrl = '/team-invites/' . $member->id;

        $this->notifications->notify($invitedUser->id, 'team_invite', $title, $body, $linkUrl);
        $this->mail->sendNotificationEmail($invitedUser->email, $invitedUser->full_name ?? '', $title, $body, $linkUrl, $locale);

        $this->auditLog->record($ownerId, 'student.team_invite', 'Project', $project->id, null, ['email' => $email]);

        return $member;
    }

    /**
     * تسجيل زميل معندوش حساب منصة: اسم + سنة دراسية + رقم طالب. بتتقبل
     * فورًا — مفيش حد تاني محتاج يأكّد سجل نصي عادي.
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    public function addManualMember(
        string $projectUuid,
        $ownerId,
        string $name,
        ?int $academicYear = null,
        ?string $studentNumber = null,
        string $locale = 'ar',
        string $role = 'student_member'
    ): ProjectTeamMember {
        $project = $this->findManageable($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }

        $this->assertRoom($project->id);

        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException($locale === 'ar' ? 'اسم العضو مطلوب.' : "Member's name is required.");
        }
        if (mb_strlen($name) > 190) {
            $name = mb_substr($name, 0, 190);
        }

        if (!in_array($role, self::MANUAL_ROLES, true)) {
            $role = 'student_member';
        }
        // غير المالك (طالب عضو فريق) يضيف أدوار الطلبة والمتعاونين بس — مش دكتور/معيد.
        if ((string) $project->owner_id !== (string) $ownerId
            && !in_array($role, ['student_member', 'collaborator', 'external_collaborator'], true)) {
            $role = 'student_member';
        }

        $studentNumber = $studentNumber !== null ? trim($studentNumber) : null;
        $studentNumber = ($studentNumber !== null && $studentNumber !== '') ? mb_substr($studentNumber, 0, 50) : null;

        if ($academicYear !== null && ($academicYear < 1 || $academicYear > 10)) {
            $academicYear = null;
        }

        $member = $this->team->create([
            'project_id'     => $project->id,
            'member_name'    => $name,
            'academic_year'  => $academicYear,
            'student_number' => $studentNumber,
            'role'           => $role,
            'status'         => 'accepted',
            'invited_by'     => $ownerId,
            'invited_at'     => now(),
            'responded_at'   => now(),
        ]);

        $this->auditLog->record($ownerId, 'student.team_add_manual', 'Project', $project->id, null, ['name' => $name]);

        Log::info('Manual team member added', ['project_id' => $project->id, 'member_id' => $member->id]);

        return $member;
    }


    /** الأدوار المسموحة حسب نوع الحساب اللي بيتضاف (طالب / دكتور-معيد). */
    private const ROLES_FOR_STUDENT_USER = ['student_member', 'collaborator'];
    private const ROLES_FOR_STAFF_USER   = ['supervisor', 'professor', 'principal_investigator', 'teaching_assistant'];

    /**
     * بحث عن حسابات (طلبة + دكاترة/معيدين) من نفس جامعة المشروع بالاسم أو الإيميل
     * أو الكود (الرقم الجامعي / رقم عضو هيئة التدريس). بيستبعد المالك وأي حد مضاف بالفعل.
     * @return array<int,array<string,mixed>>
     */
    public function searchCandidates(string $projectUuid, $ownerId, string $query, int $limit = 10): array
    {
        $project = $this->findManageable($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $uniId = $this->universityOf($project, $project->owner_id);
        // البحث بكلمات: كل كلمة لازم تظهر في الاسم أو الإيميل أو الكود. الألقاب (د./دكتور) بتتشال،
        // وفروق الكتابة (أ/ا، ى/ي، ة/ه) متتحسبش فرق، عشان "دكتور احمد" يلاقي "أ.د. أحمد".
        $fold = fn (string $v) => strtr(mb_strtolower($v), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $esc = fn (string $v) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
        $titles = ['دكتور', 'الدكتور', 'دكتوره', 'د', 'استاذ', 'الاستاذ', 'اد', 'ا', 'م', 'مهندس', 'dr', 'prof', 'eng'];
        $tokens = array_values(array_filter(
            preg_split('/[\s\.\/,،]+/u', $fold($query), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn ($t) => !in_array($t, $titles, true)
        ));
        if (!$tokens) {
            return [];
        }
        $uniId = $this->universityOf($project, $project->owner_id);
        $already = \Illuminate\Support\Facades\DB::table('project_team_members')
            ->where('project_id', $project->id)->whereNotNull('user_id')->whereNotIn('status', ['rejected', 'removed'])
            ->pluck('user_id')->all();
        $exclude = array_merge($already, [(int) $project->owner_id, (int) $ownerId]);
        $foldCol = fn (string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,'أ','ا'),'إ','ا'),'آ','ا'),'ى','ي'),'ة','ه'),'ـ','')";

        $rows = \Illuminate\Support\Facades\DB::table('users as u')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('academic_staff as a', 'a.user_id', '=', 'u.id')
            ->whereNull('u.deleted_at')->whereIn('u.status', ['active', 'pending'])
            ->whereNotIn('u.id', $exclude)
            ->where(function ($w) use ($uniId) {
                $w->where('s.university_id', $uniId)->orWhere(function ($x) use ($uniId) {
                    $x->where('a.university_id', $uniId)->where('a.status', 'active');
                });
            });
        foreach ($tokens as $tok) {
            $like = '%' . $esc($tok) . '%';
            $rows->where(function ($w) use ($like, $foldCol) {
                $w->whereRaw($foldCol('LOWER(u.full_name)') . ' LIKE ?', [$like])
                  ->orWhere('u.email', 'like', $like)
                  ->orWhere('s.student_number', 'like', $like)
                  ->orWhere('a.staff_number', 'like', $like);
            });
        }
        $rows = $rows
            ->selectRaw('u.id, u.full_name, u.email, s.student_number, a.staff_number, s.faculty AS student_faculty, (a.id IS NOT NULL) AS is_staff, (s.id IS NOT NULL) AS is_student')
            ->orderBy('u.full_name')->limit(max(1, min(25, $limit)))->get();

        return $rows->map(fn ($r) => [
            'user_id'   => (int) $r->id,
            'full_name' => $r->full_name,
            'email'     => $r->email,
            'code'      => $r->is_staff ? $r->staff_number : $r->student_number,
            'kind'      => $r->is_staff ? 'academic_staff' : 'student',
            'faculty'   => $r->student_faculty,
            'allowed_roles' => $r->is_staff ? self::ROLES_FOR_STAFF_USER : self::ROLES_FOR_STUDENT_USER,
        ])->all();
    }

    /**
     * إضافة مباشرة لحساب موجود اختاره الطالب من نتيجة البحث. بتتسجل accepted فورًا
     * (فالمشروع بيظهر في بورتال الشخص ده على طول) + إشعار. الدكتور/المعيد بيشوفه في
     * "مشاريع الطلبة" لأن AcademicStaffProjectService بيربط عبر project_team_members.user_id.
     */
    public function addUserMember(string $projectUuid, $ownerId, int $targetUserId, string $role, string $locale = 'ar'): ProjectTeamMember
    {
        $project = $this->findManageable($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        $this->assertRoom($project->id);

        $target = $this->users->findById($targetUserId);
        if (!$target || (int) $target->id === (int) $project->owner_id) {
            throw new \RuntimeException($locale === 'ar' ? 'المستخدم غير موجود.' : 'User not found.');
        }
        $db = \Illuminate\Support\Facades\DB::class;
        $uniId = $this->universityOf($project, $project->owner_id);
        $student = $db::table('students')->where('user_id', $target->id)->where('university_id', $uniId)->first();
        $staff = $db::table('academic_staff')->where('user_id', $target->id)->where('university_id', $uniId)->where('status', 'active')->first();
        if (!$student && !$staff) {
            throw new \RuntimeException($locale === 'ar' ? 'هذا الحساب لا يتبع جامعة المشروع.' : "This account doesn't belong to the project's university.");
        }
        $allowed = $staff ? self::ROLES_FOR_STAFF_USER : self::ROLES_FOR_STUDENT_USER;
        if (!in_array($role, $allowed, true)) {
            $role = $staff ? 'supervisor' : 'student_member';
        }

        $existing = $db::table('project_team_members')->where('project_id', $project->id)->where('user_id', $target->id)->first();
        if ($existing && $existing->status !== 'rejected' && $existing->status !== 'removed') {
            throw new \RuntimeException($locale === 'ar' ? 'هذا الشخص مضاف بالفعل على المشروع.' : 'This person is already on the project.');
        }
        $email = mb_strtolower((string) $target->email);
        if ($existing) {
            $db::table('project_team_members')->where('id', $existing->id)->delete();
        }

        $member = $this->team->create([
            'project_id'     => $project->id,
            'user_id'        => $target->id,
            'invited_email'  => $email,
            'member_name'    => null,
            'role'           => $role,
            'status'         => 'accepted',
            'invited_by'     => $ownerId,
            'invited_at'     => now(),
            'responded_at'   => now(),
            'student_number' => $student->student_number ?? null,
            'academic_year'  => $student->academic_year ?? null,
        ]);

        // الدكتور المشرف الأساسي: لو الحقل فاضي نملاه باسمه عشان مسار موافقة المشرف يشتغل.
        if ($role === 'supervisor' && empty($project->supervisor_name)) {
            $db::table('projects')->where('id', $project->id)->update(['supervisor_name' => $target->full_name]);
        }

        $title = $project->title_ar ?: $project->title_en;
        $link = $staff ? '/academic-staff/projects/' . $project->uuid : '/student/projects/' . $project->uuid;
        $this->notifications->notify(
            $target->id, 'team_added',
            $locale === 'ar' ? 'تمت إضافتك لمشروع تخرج' : 'You were added to a graduation project',
            $locale === 'ar' ? "تمت إضافتك على مشروع \"{$title}\"." : "You were added to the project \"{$title}\".",
            $link
        );
        $this->auditLog->record($ownerId, 'student.team_add_user', 'Project', $project->id, null, ['user_id' => $target->id, 'role' => $role]);

        return $member;
    }

    public function removeMember(string $projectUuid, $ownerId, $memberId): bool
    {
        $project = $this->findManageable($projectUuid, $ownerId);
        if (!$project) {
            return false;
        }
        $member = $this->team->findForProject($memberId, $project->id);
        if (!$member) {
            return false;
        }
        // المالك يشيل أي حد. العضو يشيل بس اللي هو ضافه من الطلبة/المتعاونين، ومش المالك ولا هيئة التدريس.
        if ((string) $project->owner_id !== (string) $ownerId) {
            $staffRoles = self::ROLES_FOR_STAFF_USER;
            if ((string) $member->invited_by !== (string) $ownerId
                || in_array($member->role, $staffRoles, true)
                || (string) $member->user_id === (string) $project->owner_id) {
                return false;
            }
        }
        $deleted = $this->team->deleteForProject($memberId, $project->id);
        if ($deleted) {
            $this->auditLog->record($ownerId, 'student.team_remove', 'Project', $project->id, null, ['member_id' => $memberId]);
        }
        return $deleted;
    }

    /** المالك، أو طالب عضو فريق مقبول (student_member) — بيقدر يضيف زملاء. غير كده null. */
    private function findManageable(string $uuid, $userId)
    {
        $project = $this->projects->findByUuid($uuid);
        if (!$project) {
            return null;
        }
        if ((string) $project->owner_id === (string) $userId) {
            return $project;
        }
        $m = $this->team->findAcceptedMember($project->id, $userId);
        return ($m && $m->role === 'student_member') ? $project : null;
    }

    /** جامعة المشروع، أو جامعة المالك الطالب لو المشروع نفسه معندوش university_id. */
    private function universityOf($project, $ownerId)
    {
        return $project->university_id
            ?: \Illuminate\Support\Facades\DB::table('students')->where('user_id', $ownerId)->value('university_id');
    }

    private function assertRoom(int $projectId): void
    {
        if (count($this->team->forProject($projectId)) >= self::MAX_TEAM_MEMBERS) {
            throw new \RuntimeException('A project can have at most ' . self::MAX_TEAM_MEMBERS . ' team members.');
        }
    }
}
