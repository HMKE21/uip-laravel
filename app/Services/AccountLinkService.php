<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AcademicRankRepository;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * Link an account that ALREADY exists on the platform (it has its own email + password) to a
 * university / faculty, instead of inviting a brand-new account:
 *   - student        → students.university_id/faculty/department/... on the existing students row
 *   - academic_staff → a new academic_staff row + the academic_staff role on the same login
 *   - supervisor     → a new supervisors row + the supervisor role on the same login
 *
 * A login already attached to ANOTHER university is never taken over — it has to be released
 * there first. Admin / university / faculty logins can't be linked as people.
 *
 * lookup() statuses: not_found | available | in_this_university | other_university | ineligible
 */
class AccountLinkService
{
    private const BLOCKED_ROLES = ['admin', 'security_admin', 'security_officer', 'data_analyst', 'university', 'faculty'];
    public const TYPES = ['student', 'academic_staff', 'supervisor'];

    public function __construct(
        private UserRepository $users,
        private StudentRepository $students,
        private AcademicStaffRepository $staff,
        private SupervisorRepository $supervisors,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private StudentGroupRepository $groups,
        private AcademicRankRepository $ranks,
        private UniversityRepository $universities,
        private NotificationService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    private function roles(int $userId): array
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->pluck('r.slug')->all();
    }

    private function addRole(int $userId, string $slug): void
    {
        $roleId = DB::table('roles')->where('slug', $slug)->value('id');
        if ($roleId && !DB::table('user_roles')->where('user_id', $userId)->where('role_id', $roleId)->exists()) {
            DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    /** @return array{status:string, user?:array, message:string} */
    public function lookup(string $type, string $email, $universityId, ?int $facultyId, string $locale = 'ar'): array
    {
        $ar = $locale === 'ar';
        $email = mb_strtolower(trim($email));
        if (!in_array($type, self::TYPES, true) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'not_found', 'message' => $ar ? 'أدخل بريدًا إلكترونيًا صحيحًا.' : 'Enter a valid email address.'];
        }

        $user = $this->users->findByEmail($email);
        if (!$user) {
            return ['status' => 'not_found', 'message' => $ar
                ? 'لا يوجد حساب بهذا البريد على المنصة. يمكنك إنشاء حساب جديد بدلًا من ذلك.'
                : 'No account with this email exists on the platform. You can create a new account instead.'];
        }

        $info = ['id' => (int) $user->id, 'full_name' => $user->full_name, 'email' => $user->email];
        $roles = $this->roles((int) $user->id);

        if (array_intersect($roles, self::BLOCKED_ROLES)) {
            return ['status' => 'ineligible', 'user' => $info, 'message' => $ar
                ? 'هذا الحساب من نوع لا يمكن ربطه (إدارة/جامعة/كلية).'
                : 'This account type (admin / university / faculty) cannot be linked.'];
        }

        $affiliation = null; // null = free, otherwise the university id it belongs to
        $student = null;
        if ($type === 'student') {
            if (!in_array('student', $roles, true)) {
                return ['status' => 'ineligible', 'user' => $info, 'message' => $ar
                    ? 'هذا الحساب ليس حساب طالب.'
                    : 'This is not a student account.'];
            }
            $student = $this->students->getOrCreate((int) $user->id);
            $affiliation = $student->university_id ? (int) $student->university_id : null;
        } elseif ($type === 'academic_staff') {
            $row = $this->staff->findByUserId($user->id);
            $affiliation = $row ? (int) $row->university_id : null;
        } else {
            $row = $this->supervisors->findByUserId($user->id);
            $affiliation = $row ? (int) $row->university_id : null;
        }

        if ($affiliation === null) {
            return ['status' => 'available', 'user' => $info, 'message' => $ar
                ? 'الحساب موجود ويمكن ربطه الآن.'
                : 'Account found — it can be linked now.'];
        }
        if ($affiliation === (int) $universityId) {
            return ['status' => 'in_this_university', 'user' => $info, 'message' => $ar
                ? 'هذا الحساب مرتبط بالفعل بجامعتك.'
                : 'This account is already linked to your university.'];
        }

        return ['status' => 'other_university', 'user' => $info, 'message' => $ar
            ? 'هذا الحساب مرتبط بجامعة أخرى. يجب فكّ ارتباطه هناك أولًا.'
            : 'This account is linked to another university. It must be released there first.'];
    }

    /** Shared eligibility gate: returns [User|null, errorResult|null]. */
    private function eligible(string $type, string $email, $universityId, ?int $facultyId, string $locale): array
    {
        $found = $this->lookup($type, $email, $universityId, $facultyId, $locale);
        if ($found['status'] !== 'available') {
            return [null, ['success' => false, 'message' => $found['message']]];
        }
        return [User::find($found['user']['id']), null];
    }

    private function fail(string $locale, string $ar, string $en): array
    {
        return ['success' => false, 'message' => $locale === 'ar' ? $ar : $en];
    }

    private function universityName($universityId, string $locale): string
    {
        $u = $this->universities->find($universityId);
        return $u ? ($u->name($locale) ?: $u->name('en')) : '';
    }

    public function linkStudent($universityId, $actingUserId, string $email, ?int $facultyId, ?int $departmentId, ?int $groupId, ?int $academicYear, ?int $currentSemester, ?string $studentNumber, string $locale = 'ar', ?int $scopeFacultyId = null): array
    {
        if ($scopeFacultyId !== null) {
            $facultyId = $scopeFacultyId;
        }
        [$user, $err] = $this->eligible('student', $email, $universityId, $scopeFacultyId, $locale);
        if ($err) {
            return $err;
        }

        $faculty = $facultyId ? $this->faculties->findOwned($facultyId, $universityId) : null;
        if ($facultyId && !$faculty) {
            return $this->fail($locale, 'الكلية المختارة غير صحيحة.', 'The selected faculty is invalid.');
        }
        $department = null;
        if ($departmentId) {
            $department = $faculty ? $this->departments->findOwnedByFaculty($departmentId, $faculty->id) : null;
            if (!$department) {
                return $this->fail($locale, 'القسم المختار غير صحيح أو لا يتبع الكلية.', 'The selected department is invalid for that faculty.');
            }
        }
        if ($groupId && !$this->groups->findOwned($groupId, $universityId)) {
            $groupId = null;
        }
        $studentNumber = $studentNumber !== null ? trim($studentNumber) : null;
        if ($studentNumber && $this->students->findByStudentNumber($universityId, $studentNumber)) {
            return $this->fail($locale, 'الرقم الجامعي مستخدم بالفعل لطالب آخر.', 'This student ID is already in use at your university.');
        }

        $student = $this->students->getOrCreate((int) $user->id);
        $student->fill([
            'university_id'  => $universityId,
            'student_number' => $studentNumber ?: $student->student_number,
            'faculty_id'     => $faculty?->id,
            'department_id'  => $department?->id,
            'faculty'        => $faculty ? $faculty->name($locale) : $student->faculty,
            'department'     => $department ? $department->name($locale) : $student->department,
            'academic_year'  => $academicYear ?? $student->academic_year,
            'current_semester' => $currentSemester ?? $student->current_semester,
            'group_id'       => $groupId,
        ]);
        $student->save();

        // Any pending join request of this student to this university is now fulfilled.
        DB::table('student_university_requests')
            ->where('student_id', $student->id)->where('university_id', $universityId)->where('status', 'pending')
            ->update(['status' => 'approved', 'reviewed_by' => $actingUserId, 'reviewed_at' => date('Y-m-d H:i:s')]);

        $this->done((int) $user->id, $actingUserId, 'student', $student->id, $universityId, $locale);

        return ['success' => true, 'id' => $student->id, 'message' => $locale === 'ar' ? 'تم ربط الطالب بجامعتك.' : 'Student linked to your university.'];
    }

    public function linkAcademicStaff($universityId, $actingUserId, string $email, ?int $facultyId, ?int $departmentId, ?int $rankId, ?string $staffNumber, ?string $bio, string $locale = 'ar', ?int $scopeFacultyId = null): array
    {
        if ($scopeFacultyId !== null) {
            $facultyId = $scopeFacultyId;
        }
        [$user, $err] = $this->eligible('academic_staff', $email, $universityId, $scopeFacultyId, $locale);
        if ($err) {
            return $err;
        }

        if ($facultyId && !$this->faculties->findOwned($facultyId, $universityId)) {
            return $this->fail($locale, 'الكلية المختارة غير موجودة في جامعتك.', 'The selected faculty does not belong to your university.');
        }
        if ($departmentId) {
            $dept = $facultyId ? $this->departments->findOwnedByFaculty($departmentId, $facultyId) : null;
            if (!$dept) {
                return $this->fail($locale, 'القسم المختار لا يتبع الكلية.', 'The selected department does not belong to that faculty.');
            }
        }
        if ($rankId) {
            $rank = $this->ranks->find($rankId);
            if (!$rank || (!$rank->isPlatformDefault() && (int) $rank->university_id !== (int) $universityId)) {
                return $this->fail($locale, 'الرتبة الأكاديمية غير صالحة.', 'Invalid academic rank.');
            }
        }
        $staffNumber = $staffNumber !== null ? trim($staffNumber) : null;
        if ($staffNumber && $this->staff->findByStaffNumber($universityId, $staffNumber)) {
            return $this->fail($locale, 'الرقم الوظيفي مستخدم بالفعل.', 'This staff number is already used at your university.');
        }

        $row = DB::transaction(function () use ($user, $universityId, $facultyId, $departmentId, $rankId, $staffNumber, $bio) {
            $this->addRole((int) $user->id, 'academic_staff');
            return $this->staff->create([
                'user_id'           => $user->id,
                'university_id'     => $universityId,
                'faculty_id'        => $facultyId,
                'department_id'     => $departmentId,
                'academic_rank_id'  => $rankId,
                'staff_number'      => $staffNumber ?: null,
                'bio'               => $bio !== null && trim($bio) !== '' ? trim($bio) : null,
                'status'            => 'active',
                'invitation_status' => null,
            ]);
        });

        $this->done((int) $user->id, $actingUserId, 'academic_staff', $row->id, $universityId, $locale);

        return ['success' => true, 'id' => $row->id, 'message' => $locale === 'ar' ? 'تم ربط عضو هيئة التدريس بجامعتك.' : 'Staff member linked to your university.'];
    }

    public function linkSupervisor($universityId, $actingUserId, string $email, ?string $department, ?string $title, array $permissions, string $locale = 'ar'): array
    {
        [$user, $err] = $this->eligible('supervisor', $email, $universityId, null, $locale);
        if ($err) {
            return $err;
        }
        $permissions = array_values(array_intersect($permissions, array_keys(SupervisorManagementService::PERMISSIONS)));

        $row = DB::transaction(function () use ($user, $universityId, $department, $title, $permissions) {
            $this->addRole((int) $user->id, 'supervisor');
            return $this->supervisors->create([
                'university_id'     => $universityId,
                'user_id'           => $user->id,
                'full_name'         => $user->full_name,
                'email'             => $user->email,
                'department'        => $department ? trim($department) : null,
                'title'             => $title ? trim($title) : null,
                'permissions'       => implode(',', $permissions),
                'invitation_status' => 'accepted',
                'status'            => 'active',
                'accepted_at'       => now(),
            ]);
        });

        $this->done((int) $user->id, $actingUserId, 'supervisor', $row->id, $universityId, $locale);

        return ['success' => true, 'id' => $row->id, 'message' => $locale === 'ar' ? 'تم ربط المشرف بجامعتك.' : 'Supervisor linked to your university.'];
    }

    private function done(int $userId, $actingUserId, string $type, $entityId, $universityId, string $locale): void
    {
        $name = $this->universityName($universityId, $locale);
        $this->notifications->notify(
            $userId,
            'university_account_linked',
            $locale === 'ar' ? 'تم ربط حسابك بجامعة' : 'Your account was linked to a university',
            $locale === 'ar' ? "تم ربط حسابك بـ {$name}." : "Your account is now linked to {$name}.",
            '/'
        );
        $this->auditLog->record($actingUserId, 'university.link_existing_' . $type, ucfirst($type), $entityId, null, ['user_id' => $userId]);
    }
}
