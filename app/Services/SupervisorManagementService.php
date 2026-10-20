<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;

/**
 * منقولة من app/Services/SupervisorManagementService.php القديمة — بند 9
 * (Supervisors)، جوهر صفحة "Supervisor Management" بتاعة الجامعة. كل
 * دعوة بتعمل صف users حقيقي (login-capable، باسورد مؤقت، role='supervisor'
 * عبر user_roles) + صف supervisors روستر يحمل صلاحيات RBAC مقيّدة —
 * (migration 095
 * بتعكس نفس شكل migration 052). assignScope()/unassignScope() بيديروا
 * صفوف supervisor_assignments (migration 096) اللي كل ظهور المشرف في
 * موديول Supervisors كله (بند 9) مبني عليها — شوف
 * SupervisorAssignmentRepository::scopedStudents()/scopedProjects().
 *
 * فرق شكلي فقط عن القديمة: Core\Model الثابتة (Supervisor::find/...) ->
 * حقن الـ Repositories المُمررة بالفعل في الـ constructor (نفس تصميم
 * بند 6/8)؛ \App\Models\User::find/Project::find فضلوا زي ما هم لأن
 * مفيش UserRepository::find()/ProjectRepository::find() مباشرة في هذا
 * الشكل.
 */
class SupervisorManagementService
{
    /** كتالوج الصلاحيات — مخزّنة CSV على supervisors.permissions. اتفحص عبر SupervisorRepository::permissionsForUser(). */
    public const PERMISSIONS = [
        'view_students'   => ['en' => 'View Assigned Students', 'ar' => 'عرض الطلاب المُسندين'],
        'manage_projects' => ['en' => 'Review & Approve Projects', 'ar' => 'مراجعة واعتماد المشاريع'],
        'manage_messages' => ['en' => 'Messages', 'ar' => 'الرسائل'],
        'view_reports'    => ['en' => 'Reports', 'ar' => 'التقارير'],
    ];

    /** أنواع النطاقات اللي الجامعة تقدر تمنحها. */
    public const SCOPE_TYPES = ['faculty', 'department', 'academic_year', 'group', 'project'];

    private const INVITE_EXPIRY_DAYS = 7;

    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private StudentGroupRepository $groups,
        private UserRepository $users,
        private MailService $mail,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{success:bool, message:string} */
    public function invite($universityId, $actingUserId, string $fullName, string $email, ?string $department, ?string $title, array $permissions, string $locale = 'ar', ?string $password = null, ?string $nameAr = null, ?string $nameEn = null): array
    {
        $names = \App\Support\BilingualName::resolveOrLegacy($fullName, $nameAr, $nameEn, $locale);
        if (!$names['ok']) {
            return ['success' => false, 'message' => reset($names['errors']), 'errors' => $names['errors']];
        }
        $fullName = $names['full_name'];
        $email = mb_strtolower(trim($email));
        $password = $password !== null ? trim($password) : '';
        if ($password !== '' && mb_strlen($password) < 8) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة السر يجب ألا تقل عن 8 أحرف.'
                : 'The password must be at least 8 characters.'];
        }
        $permissions = array_values(array_intersect($permissions, array_keys(self::PERMISSIONS)));

        if ($this->supervisors->findByEmail($universityId, $email)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا البريد الإلكتروني مضاف بالفعل ضمن قائمة المشرفين.'
                : 'This email is already on your supervisor roster.'];
        }

        if ($why = \App\Support\DisposableEmail::problem($email, $locale)) {
            return ['success' => false, 'message' => $why];
        }

        if ($this->users->emailExists($email)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا البريد الإلكتروني مسجل بالفعل على المنصة بحساب آخر.'
                : 'This email already has an account elsewhere on the platform.'];
        }

        $tempPassword = $password !== '' ? $password : $this->generateTempPassword();

        $user = $this->users->createBareWithRole([
            'full_name'     => $names['full_name'],
            'name_ar'       => $names['name_ar'],
            'name_en'       => $names['name_en'],
            'email'         => $email,
            'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
            'status'        => 'active',
        ], 'supervisor');

        $supervisor = $this->supervisors->create([
            'university_id'      => $universityId,
            'user_id'            => $user->id,
            'full_name'          => $names['full_name'],
            'name_ar'            => $names['name_ar'],
            'name_en'            => $names['name_en'],
            'email'              => $email,
            'department'         => $department ? trim($department) : null,
            'title'              => $title ? trim($title) : null,
            'permissions'        => implode(',', $permissions),
            'invitation_status'  => 'pending',
            'status'             => 'active',
            'invited_at'         => now(),
            'expires_at'         => now()->addDays(self::INVITE_EXPIRY_DAYS),
        ]);

        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';

        $emailSent = (bool) $this->mail->sendSupervisorInvite($email, $supervisor->full_name, $universityName, $tempPassword, $locale);

        $this->auditLog->record($actingUserId, 'university.supervisor_invite', 'Supervisor', $supervisor->id, null, [
            'email' => $email, 'department' => $department, 'permissions' => $permissions,
        ]);

        return ['success' => true, 'password' => $tempPassword, 'email_sent' => $emailSent, 'message' => $emailSent
            ? ($locale === 'ar'
                ? 'تمت دعوة المشرف وإرسال بيانات الدخول له بالبريد الإلكتروني.'
                : 'Supervisor invited — login details were emailed to them.')
            : ($locale === 'ar'
                ? 'تمت إضافة المشرف لكن تعذر إرسال البريد — سلّمه بيانات الدخول يدويًا.'
                : 'Supervisor added, but the email could not be sent — hand over the login details manually.')];
    }

    /** Admin sets (or regenerates, when empty) a supervisor's login password. */
    public function setPassword($id, $universityId, $actingUserId, ?string $password, string $locale = 'ar'): array
    {
        $supervisor = $this->supervisors->findOwned($id, $universityId);
        $user = $supervisor && $supervisor->user_id ? User::find($supervisor->user_id) : null;
        if (!$supervisor || !$user) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'المشرف غير موجود.' : 'Supervisor not found.'];
        }
        if (!$supervisor->invited_at) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا حساب مرتبط يملكه صاحبه — لا يمكن تغيير كلمة سره من هنا.'
                : 'This is a linked account owned by its user — its password cannot be changed from here.'];
        }
        $password = $password !== null ? trim($password) : '';
        if ($password !== '' && mb_strlen($password) < 8) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة السر يجب ألا تقل عن 8 أحرف.'
                : 'The password must be at least 8 characters.'];
        }
        $new = $password !== '' ? $password : $this->generateTempPassword();
        $user->fill(['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        $user->save();
        $this->auditLog->record($actingUserId, 'university.supervisor_set_password', 'Supervisor', $supervisor->id, null, [
            'source' => $password !== '' ? 'custom' : 'generated',
        ]);

        return ['success' => true, 'password' => $new, 'message' => $locale === 'ar' ? 'تم تحديث كلمة السر.' : 'Password updated.'];
    }

    public function resendInvite($id, $universityId, $actingUserId, string $locale = 'ar'): array
    {
        $supervisor = $this->supervisors->findOwned($id, $universityId);
        if (!$supervisor || $supervisor->invitation_status === 'accepted') {
            return ['success' => false, 'message' => $locale === 'ar' ? 'غير متاح لهذا المشرف.' : 'Not available for this supervisor.'];
        }

        $tempPassword = $this->generateTempPassword();
        $user = $supervisor->user_id ? User::find($supervisor->user_id) : null;
        if ($user) {
            $user->fill(['password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
            $user->save();
        }

        $supervisor->fill([
            'invitation_status' => 'pending',
            'expires_at'        => now()->addDays(self::INVITE_EXPIRY_DAYS),
        ]);
        $supervisor->save();

        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';

        $sent = $this->mail->sendSupervisorInvite($supervisor->email, $supervisor->full_name, $universityName, $tempPassword, $locale);
        $this->auditLog->record($actingUserId, 'university.supervisor_resend_invite', 'Supervisor', $supervisor->id, null, null);

        return ['success' => $sent, 'message' => $locale === 'ar' ? 'تم إرسال دعوة جديدة.' : 'A new invite was sent.'];
    }

    /** بتعدّل department/title + مجموعة صلاحيات RBAC. */
    public function updateRole($id, $universityId, $actingUserId, ?string $department, ?string $title, array $permissions): bool
    {
        $supervisor = $this->supervisors->findOwned($id, $universityId);
        if (!$supervisor) {
            return false;
        }

        $permissions = array_values(array_intersect($permissions, array_keys(self::PERMISSIONS)));
        $before = $supervisor->toArray();
        $supervisor->fill([
            'department'  => $department ? trim($department) : null,
            'title'       => $title ? trim($title) : null,
            'permissions' => implode(',', $permissions),
        ]);
        $supervisor->save();

        $this->auditLog->record($actingUserId, 'university.supervisor_update_role', 'Supervisor', $supervisor->id, $before, $supervisor->toArray());
        return true;
    }

    public function activate($id, $universityId, $actingUserId): bool
    {
        return $this->setAccountStatus($id, $universityId, $actingUserId, 'active', 'university.supervisor_activate');
    }

    public function deactivate($id, $universityId, $actingUserId): bool
    {
        return $this->setAccountStatus($id, $universityId, $actingUserId, 'inactive', 'university.supervisor_deactivate');
    }

    /** بتقلب حالة الروستر وحالة اللوجين المرتبط بيه سوا، عشان "تعطيل" فعلًا يمنع الدخول مش بس تسمية شكلية. */
    private function setAccountStatus($id, $universityId, $actingUserId, string $status, string $auditAction): bool
    {
        $supervisor = $this->supervisors->findOwned($id, $universityId);
        if (!$supervisor) {
            return false;
        }

        $before = $supervisor->toArray();
        $supervisor->fill(['status' => $status]);
        $supervisor->save();

        if ($supervisor->user_id) {
            $user = User::find($supervisor->user_id);
            if ($user) {
                $user->fill(['status' => $status === 'active' ? 'active' : 'suspended']);
                $user->save();
            }
        }

        $this->auditLog->record($actingUserId, $auditAction, 'Supervisor', $supervisor->id, $before, $supervisor->toArray());
        return true;
    }

    /** بتشيل صف الروستر ونطاقاته، وتمنع اللوجين المرتبط (suspended، مش حذف نهائي). */
    public function delete($id, $universityId, $actingUserId): bool
    {
        $supervisor = $this->supervisors->findOwned($id, $universityId);
        if (!$supervisor) {
            return false;
        }

        $before = $supervisor->toArray();

        if ($supervisor->user_id) {
            $user = User::find($supervisor->user_id);
            if ($user) {
                $user->fill(['status' => 'suspended']);
                $user->save();
            }
        }

        $this->assignments->deleteAllForSupervisor($supervisor->id);
        $deleted = (bool) $supervisor->delete();
        if ($deleted) {
            $this->auditLog->record($actingUserId, 'university.supervisor_delete', 'Supervisor', $id, $before, null);
        }
        return $deleted;
    }

    /**
     * بتمنح صف نطاق واحد. $scopeValue مطلوب لـ
     * faculty/department/academic_year/group؛ $projectId مطلوب (ولازم
     * ينتمي للجامعة دي) لـ scope_type='project'.
     *
     * لـ faculty/department/group، $scopeValue هو الـ id الحقيقي (مش اسم
     * نصي) واتفحص مقابل هيكل الجامعة الفعلي — faculty لازم ينتمي للجامعة
     * دي، department لازم ينتمي لأي كلية من كليات الجامعة دي، group لازم
     * ينتمي للجامعة دي. ده اللي بيخلي
     * SupervisorAssignmentRepository::scopeWhereClause() يطابق على
     * students.faculty_id/department_id/group_id الحقيقية مش نص.
     * @return array{success:bool, message:string}
     */
    public function assignScope($supervisorId, $universityId, $actingUserId, string $scopeType, $scopeValue, ?int $projectId, string $locale = 'ar'): array
    {
        $supervisor = $this->supervisors->findOwned($supervisorId, $universityId);
        if (!$supervisor) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'المشرف غير موجود.' : 'Supervisor not found.'];
        }
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'نوع النطاق غير صالح.' : 'Invalid scope type.'];
        }

        if ($scopeType === 'project') {
            if (!$projectId) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'اختر مشروعًا.' : 'Choose a project.'];
            }
            $project = Project::find($projectId);
            if (!$project || (int) $project->university_id !== (int) $universityId) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'المشروع غير موجود في جامعتك.' : 'Project not found in your university.'];
            }
            $scopeValue = null;
        } elseif ($scopeType === 'academic_year') {
            $scopeValue = trim((string) $scopeValue);
            if ($scopeValue === '') {
                return ['success' => false, 'message' => $locale === 'ar' ? 'اختر قيمة للنطاق.' : 'Choose a value for the scope.'];
            }
            $projectId = null;
        } elseif ($scopeType === 'faculty') {
            $facultyId = (int) $scopeValue;
            $faculty = $facultyId ? $this->faculties->findOwned($facultyId, $universityId) : null;
            if (!$faculty) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الكلية المختارة غير صحيحة أو لا تنتمي لجامعتك.'
                    : 'The selected faculty is invalid or does not belong to your university.'];
            }
            $scopeValue = (string) $faculty->id;
            $projectId = null;
        } elseif ($scopeType === 'department') {
            $departmentId = (int) $scopeValue;
            $department = $departmentId ? $this->departments->findOwnedByUniversity($departmentId, $universityId) : null;
            if (!$department) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'القسم المختار غير صحيح أو لا ينتمي لجامعتك.'
                    : 'The selected department is invalid or does not belong to your university.'];
            }
            $scopeValue = (string) $department->id;
            $projectId = null;
        } else { // group
            $groupId = (int) $scopeValue;
            $group = $groupId ? $this->groups->findOwned($groupId, $universityId) : null;
            if (!$group) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'المجموعة المختارة غير صحيحة أو لا تنتمي لجامعتك.'
                    : 'The selected group is invalid or does not belong to your university.'];
            }
            $scopeValue = (string) $group->id;
            $projectId = null;
        }

        if ($this->assignments->exists($supervisorId, $scopeType, $scopeValue, $projectId)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'هذا النطاق مُسند بالفعل لهذا المشرف.' : 'This scope is already assigned to this supervisor.'];
        }

        $this->assignments->create([
            'supervisor_id' => $supervisorId,
            'university_id' => $universityId,
            'scope_type'    => $scopeType,
            'scope_value'   => $scopeValue,
            'project_id'    => $projectId,
        ]);

        $this->auditLog->record($actingUserId, 'university.supervisor_assign', 'Supervisor', $supervisorId, null, [
            'scope_type' => $scopeType, 'scope_value' => $scopeValue, 'project_id' => $projectId,
        ]);

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم إسناد النطاق للمشرف.' : 'Scope assigned to the supervisor.'];
    }

    public function unassignScope($assignmentId, $supervisorId, $universityId, $actingUserId): bool
    {
        $supervisor = $this->supervisors->findOwned($supervisorId, $universityId);
        if (!$supervisor) {
            return false;
        }

        $deleted = $this->assignments->deleteOwned($assignmentId, $supervisorId);
        if ($deleted) {
            $this->auditLog->record($actingUserId, 'university.supervisor_unassign', 'Supervisor', $supervisorId, null, ['assignment_id' => $assignmentId]);
        }
        return $deleted;
    }

    /** محسوبة، مش مخزّنة: دعوة لسه pending وعدّت مدتها بتترجع Expired من غير الحاجة لـ cron. */
    public static function effectiveInvitationStatus(array $supervisor): string
    {
        if ($supervisor['invitation_status'] === 'pending' && !empty($supervisor['expires_at']) && strtotime($supervisor['expires_at']) < time()) {
            return 'expired';
        }
        return $supervisor['invitation_status'];
    }

    private function generateTempPassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }
}
