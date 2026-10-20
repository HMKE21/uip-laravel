<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;

/**
 * منقولة من app/Services/StudentManagementService.php القديمة (1089 سطر) —
 * الميثودز اللي StudentsApiController محتاجاها لـ /api/v1/students:
 * invite/resendInvite/activate/deactivate/updateAffiliation/
 * updateAcademicDetails/updateStudyDates/updateProfile/delete +
 * effectiveInvitationStatus (static)، بالإضافة لميثودز المجموعات (بند 16):
 * createGroup/updateGroup/deleteGroup/moveStudents اللي GroupsApiController
 * محتاجاها.
 *
 * عمدًا مش منقول: importCsv() (استيراد CSV — راوت /bulk/students/import،
 * موديول تاني). القديمة كانت بتحقن StudentGroupChatService كمان — مستخدمة
 * بس جوه moveStudents/updateAffiliation (group-chat sync)؛ هنا invite()/
 * moveStudents() بتمرروا group_id لو موجود ومتحقق منه بس من غير ما تعملوا
 * chat sync (Group Chat نفسه — StudentGroupChatService — لسه مش بند اتعمل،
 * منفصل عن CRUD المجموعات نفسه)، وupdateAffiliation() منقولة من غير جزئية
 * الـ groupChat->syncMembership() لنفس السبب — موثقة تحت كل ميثود.
 */
class StudentManagementService
{
    private const INVITE_EXPIRY_DAYS = 7;
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private StudentRepository $students,
        private StudentGroupRepository $groups,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private UserRepository $users,
        private MailService $mail,
        private NotificationService $notifications,
        private AuditLogService $auditLog,
        private FileUploadService $uploads
    ) {
    }

    /**
     * @param int|null $facultyId    لازم يكون صف faculties حقيقي تابع لـ $universityId
     * @param int|null $departmentId لازم يكون صف departments حقيقي تابع لـ $facultyId
     * @return array{success:bool, message:string}
     */
    public function invite(
        $universityId,
        $actingUserId,
        string $fullName,
        string $email,
        ?string $studentNumber,
        ?int $facultyId,
        ?int $departmentId,
        ?int $academicYear,
        ?int $groupId,
        string $locale = 'ar',
        ?int $currentSemester = null,
        ?string $studyStartDate = null,
        ?string $password = null,
        bool $sendEmail = true,
        ?string $nameAr = null,
        ?string $nameEn = null
    ): array {
        $names = \App\Support\BilingualName::resolveOrLegacy($fullName, $nameAr, $nameEn, $locale);
        if (!$names['ok']) {
            return ['success' => false, 'message' => reset($names['errors']), 'errors' => $names['errors']];
        }
        $fullName = $names['full_name'];
        $email = mb_strtolower(trim($email));
        $password = $password !== null ? trim($password) : null;
        $manualPassword = $password !== null && $password !== '';
        if ($manualPassword && mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة السر يجب ألا تقل عن 8 أحرف.'
                : 'The password must be at least 8 characters.'];
        }

        if ($why = \App\Support\DisposableEmail::problem($email, $locale)) {
            return ['success' => false, 'message' => $why];
        }

        if ($this->users->emailExists($email)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا البريد الإلكتروني مسجل بالفعل على المنصة بحساب آخر.'
                : 'This email already has an account elsewhere on the platform.'];
        }
        if ($studentNumber && $this->students->findByStudentNumber($universityId, $studentNumber)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'الرقم الجامعي مستخدم بالفعل لطالب آخر في جامعتك.'
                : 'This student ID is already in use at your university.'];
        }
        if ($groupId && !$this->groups->findOwned($groupId, $universityId)) {
            $groupId = null;
        }

        $faculty = null;
        if ($facultyId) {
            $faculty = $this->faculties->findOwned($facultyId, $universityId);
            if (!$faculty) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الكلية المختارة غير صحيحة أو لا تنتمي لجامعتك.'
                    : 'The selected faculty is invalid or does not belong to your university.'];
            }
        }

        $department = null;
        if ($departmentId) {
            if (!$faculty) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'يجب اختيار الكلية أولًا قبل اختيار القسم.'
                    : 'A faculty must be selected before a department.'];
            }
            $department = $this->departments->findOwnedByFaculty($departmentId, $faculty->id);
            if (!$department) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'القسم المختار غير صحيح أو لا ينتمي لهذه الكلية.'
                    : 'The selected department is invalid or does not belong to that faculty.'];
            }
        }

        $tempPassword = $manualPassword ? $password : $this->generateTempPassword();

        $user = $this->users->createWithRole([
            'full_name'     => $names['full_name'],
            'name_ar'       => $names['name_ar'],
            'name_en'       => $names['name_en'],
            'email'         => $email,
            'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
            'status'        => 'active',
        ], 'student');

        $studyStartDate = $studyStartDate !== null ? trim($studyStartDate) : null;
        $studyStartDate = ($studyStartDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $studyStartDate)) ? $studyStartDate : null;
        $student = $this->students->getOrCreate($user->id);
        $student->fill([
            'university_id'             => $universityId,
            'student_number'            => $studentNumber ? trim($studentNumber) : null,
            'faculty_id'                => $faculty?->id,
            'department_id'             => $department?->id,
            'faculty'                   => $faculty ? $faculty->name($locale) : null,
            'department'                => $department ? $department->name($locale) : null,
            'academic_year'             => $academicYear,
            'current_semester'          => $currentSemester,
            'study_start_date'          => $studyStartDate,
            'group_id'                  => $groupId,
            // A manually-set password means the admin hands the credentials over themselves:
            // no invitation flow (no "Invite pending/expired" badge, nothing to resend).
            'invitation_status'         => $manualPassword ? null : 'pending',
            // invited_at doubles as "this login was created by the university" — only such logins
            // may have their password managed by the university (linked/self-registered ones may not).
            'invited_at'                => date('Y-m-d H:i:s'),
            'expires_at'                => $manualPassword ? null : date('Y-m-d H:i:s', strtotime('+' . self::INVITE_EXPIRY_DAYS . ' days')),
        ]);
        $student->save();

        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';

        $emailSent = $sendEmail
            ? (bool) $this->mail->sendStudentInvite($email, trim($fullName), $universityName, $tempPassword, $locale)
            : false;

        $this->auditLog->record($actingUserId, 'university.student_invite', 'Student', $student->id, null, [
            'email' => $email, 'faculty_id' => $faculty?->id, 'department_id' => $department?->id,
            'group_id' => $groupId,
            'manual_password' => $manualPassword, 'email_sent' => $emailSent,
        ]);

        $message = match (true) {
            $emailSent => $locale === 'ar'
                ? 'تمت إضافة الطالب وإرسال بيانات الدخول له بالبريد الإلكتروني.'
                : 'Student added — login details were emailed to them.',
            $sendEmail => $locale === 'ar'
                ? 'تمت إضافة الطالب لكن تعذر إرسال البريد الإلكتروني — سلّمه بيانات الدخول يدويًا.'
                : 'Student added, but the email could not be sent — hand over the login details manually.',
            default => $locale === 'ar' ? 'تمت إضافة الطالب.' : 'Student added.',
        };

        // The password is returned to the (university/faculty) admin so they can hand it over
        // when email delivery is off or fails.
        return ['success' => true, 'message' => $message, 'password' => $tempPassword,
            'email_sent' => $emailSent, 'student_id' => $student->id];
    }

    /**
     * Admin sets (or regenerates, when $password is empty) a student's login password.
     * @return array{success:bool, message:string, password?:string}
     */
    public function setPassword($id, $universityId, $actingUserId, ?string $password, string $locale = 'ar', $facultyId = null): array
    {
        $student = $this->students->findOwned($id, $universityId, $facultyId);
        $user = $student ? User::find($student->user_id) : null;
        if (!$student || !$user) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الطالب غير موجود.' : 'Student not found.'];
        }

        if (!$student->invited_at) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا حساب مرتبط يملكه صاحبه — لا يمكن تغيير كلمة سره من هنا.'
                : 'This is a linked account owned by its user — its password cannot be changed from here.'];
        }

        $password = $password !== null ? trim($password) : '';
        if ($password !== '' && mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة السر يجب ألا تقل عن 8 أحرف.'
                : 'The password must be at least 8 characters.'];
        }
        $new = $password !== '' ? $password : $this->generateTempPassword();

        $user->fill(['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        $user->save();

        $this->auditLog->record($actingUserId, 'university.student_set_password', 'Student', $student->id, null, [
            'source' => $password !== '' ? 'custom' : 'generated',
        ]);

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث كلمة السر.' : 'Password updated.', 'password' => $new];
    }

    /**
     * Bulk import (CSV/XLSX rows keyed by lower-cased header): name_ar, name_en (both required), email, student_number,
     * faculty, department, academic_year, current_semester, group, password (optional).
     * faculty/department/group accept an id or an exact Arabic/English name. A faculty-scoped
     * admin ($forcedFacultyId) can only import into their own faculty.
     * @return array{imported:int, skipped:int, errors:string[], results:array}
     */
    public function importRows(array $rows, $universityId, $actingUserId, ?int $forcedFacultyId = null, string $locale = 'ar', bool $sendEmail = false): array
    {
        $faculties = $this->faculties->forUniversity($universityId);
        $groups = $this->groups->forUniversityWithCounts($universityId);

        $match = function ($cell, array $items, callable $names) {
            $cell = trim((string) $cell);
            if ($cell === '') {
                return null;
            }
            foreach ($items as $it) {
                $id = is_array($it) ? ($it['id'] ?? null) : $it->id;
                if (ctype_digit($cell) && (int) $cell === (int) $id) {
                    return (int) $id;
                }
                foreach ($names($it) as $n) {
                    if ($n !== null && mb_strtolower(trim((string) $n)) === mb_strtolower($cell)) {
                        return (int) $id;
                    }
                }
            }
            return false; // given but not found
        };

        $results = [];
        $errors = [];
        $imported = 0;

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 2;
            $fullName = trim((string) ($row['full_name'] ?? $row['name'] ?? ''));
            $rowNameAr = trim((string) ($row['name_ar'] ?? $row['arabic_name'] ?? ''));
            $rowNameEn = trim((string) ($row['name_en'] ?? $row['english_name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $fail = function (string $msg) use (&$results, &$errors, $n, $email) {
                $results[] = ['row' => $n, 'email' => $email, 'success' => false, 'message' => $msg];
                $errors[] = "Row {$n} ({$email}): {$msg}";
            };

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $fail($locale === 'ar' ? 'البريد الإلكتروني ناقص أو غير صالح.' : 'Missing or invalid email.');
                continue;
            }
            if ($why = \App\Support\DisposableEmail::problem($email, $locale)) {
                $fail($why);
                continue;
            }
            $rowNames = \App\Support\BilingualName::resolve($rowNameAr, $rowNameEn, $locale);
            if (!$rowNames['ok']) {
                $fail(($locale === 'ar' ? 'الاسم بالعربي والإنجليزي مطلوبين (أعمدة name_ar و name_en): ' : 'Arabic and English names are required (columns name_ar and name_en): ') . implode(' ', $rowNames['errors']));
                continue;
            }

            $facultyId = $forcedFacultyId;
            if ($facultyId === null) {
                $f = $match($row['faculty'] ?? $row['faculty_id'] ?? '', $faculties, fn ($x) => [$x->name_en, $x->name_ar]);
                if ($f === false) {
                    $fail($locale === 'ar' ? 'الكلية غير موجودة.' : 'Faculty not found.');
                    continue;
                }
                $facultyId = $f;
            }

            $departmentId = null;
            $dCell = trim((string) ($row['department'] ?? $row['department_id'] ?? ''));
            if ($dCell !== '') {
                if (!$facultyId) {
                    $fail($locale === 'ar' ? 'حدد الكلية قبل القسم.' : 'A faculty is required before a department.');
                    continue;
                }
                $d = $match($dCell, $this->departments->forFaculty($facultyId), fn ($x) => [$x->name_en, $x->name_ar]);
                if ($d === false || $d === null) {
                    $fail($locale === 'ar' ? 'القسم غير موجود في هذه الكلية.' : 'Department not found in that faculty.');
                    continue;
                }
                $departmentId = $d;
            }

            $groupId = null;
            $gCell = trim((string) ($row['group'] ?? $row['group_id'] ?? ''));
            if ($gCell !== '') {
                $g = $match($gCell, $groups, fn ($x) => [$x['name'] ?? null]);
                if ($g === false || $g === null) {
                    $fail($locale === 'ar' ? 'المجموعة غير موجودة.' : 'Group not found.');
                    continue;
                }
                $groupId = $g;
            }

            $int = fn ($v) => (($v = trim((string) $v)) !== '' && ctype_digit($v)) ? (int) $v : null;
            $res = $this->invite(
                $universityId, $actingUserId, $fullName, $email,
                trim((string) ($row['student_number'] ?? '')) ?: null,
                $facultyId, $departmentId,
                $int($row['academic_year'] ?? ''), $groupId, $locale, null,
                $int($row['current_semester'] ?? ''), null,
                trim((string) ($row['password'] ?? '')) ?: null,
                $sendEmail,
                $rowNames['name_ar'], $rowNames['name_en']
            );

            $results[] = ['row' => $n, 'email' => $email, 'success' => $res['success'], 'message' => $res['message'],
                'password' => $res['success'] ? ($res['password'] ?? null) : null];
            if ($res['success']) {
                $imported++;
            } else {
                $errors[] = "Row {$n} ({$email}): {$res['message']}";
            }
        }

        return ['imported' => $imported, 'skipped' => count($rows) - $imported, 'errors' => $errors, 'results' => $results];
    }

    public function resendInvite($id, $universityId, $actingUserId, string $locale = 'ar', $facultyId = null): array
    {
        $student = $this->students->findOwned($id, $universityId, $facultyId);
        if (!$student || $student->invitation_status === null) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'غير متاح لهذا الطالب.' : 'Not available for this student.'];
        }

        $tempPassword = $this->generateTempPassword();
        $user = User::find($student->user_id);
        if ($user) {
            $user->fill(['password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
            $user->save();
        }

        $student->fill([
            'invitation_status' => 'pending',
            'expires_at'        => date('Y-m-d H:i:s', strtotime('+' . self::INVITE_EXPIRY_DAYS . ' days')),
        ]);
        $student->save();

        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';

        $sent = $user ? $this->mail->sendStudentInvite($user->email, $user->full_name, $universityName, $tempPassword, $locale) : false;
        $this->auditLog->record($actingUserId, 'university.student_resend_invite', 'Student', $student->id, null, null);

        return ['success' => $sent, 'message' => $locale === 'ar' ? 'تم إرسال دعوة جديدة.' : 'A new invite was sent.'];
    }

    public function activate($id, $universityId, $actingUserId, $facultyId = null): bool
    {
        return $this->setAccountStatus($id, $universityId, $actingUserId, 'active', 'university.student_activate', $facultyId);
    }

    public function deactivate($id, $universityId, $actingUserId, $facultyId = null): bool
    {
        return $this->setAccountStatus($id, $universityId, $actingUserId, 'inactive', 'university.student_deactivate', $facultyId);
    }

    /**
     * إعادة تعيين faculty_id/department_id الحقيقية لطالب
     * موجود. $scopeFacultyId (migration 106): لو موجودة يبقى الكولر حساب
     * كلية، ومينفعش ينقل طالب لكلية تانية غير كليته (الجامعة بس اللي تقدر).
     *
     * فجوة متعمدة موثقة: القديمة كانت بتمسح group_id لو الكلية/القسم
     * اتغيرت فعليًا وبعدين تنادي StudentGroupChatService::syncMembership()
     * عشان تزامن عضوية شات المجموعة القديمة. Group Chat نفسه لسه مش بند
     * اتعمل (هييجي مع بند Groups)، فالنسخة دي بتمسح group_id (نفس الأثر
     * على صف الطالب) بس من غير نداء الـ chat sync — مفيش تأثير ظاهر
     * للفرونت دلوقتي لأن مفيش Group Chat UI أصلًا يستهلكها.
     */
    public function updateAffiliation(
        $id,
        $universityId,
        $actingUserId,
        ?int $facultyId,
        ?int $departmentId,
        string $locale = 'ar',
        $scopeFacultyId = null
    ): array {
        $student = $this->students->findOwned($id, $universityId, $scopeFacultyId);
        if (!$student) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الطالب غير موجود.' : 'Student not found.'];
        }

        if ($scopeFacultyId !== null && $facultyId !== null && (int) $facultyId !== (int) $scopeFacultyId) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'حساب الكلية لا يقدر ينقل طالب لكلية تانية — ده متاح للجامعة بس.'
                : 'A faculty account cannot move a student to a different faculty — only the university can.'];
        }

        // منطقة الإصلاح: لو الكولر ماحددش faculty_id صراحة (زي فورم تعديل
        // الطالب في بورتال الكلية — أصلًا معندهاش selector للكلية، الصفحة
        // كلها محصورة في كلية واحدة أصلًا)، كان $faculty بيفضل null فيرجع
        // خطأ "يجب اختيار الكلية أولًا" حتى لو بس department_id هو اللي
        // اتغيّر، وكمان كان هيمسح faculty_id/department_id الحاليين للطالب
        // فعليًا لو اتخطى الخطأ ده بطريقة تانية (لأن $faculty?->id بيتكتب
        // على الصف تحت). الحل: نرجع لـ scopeFacultyId (كلية الكولر نفسه)
        // ولو مفيش أصلًا نرجع لـ faculty_id الحالي المسجل على الطالب —
        // بس لما فعلاً محددش حاجة صراحة.
        $effectiveFacultyId = $facultyId ?? $scopeFacultyId ?? $student->faculty_id;

        $faculty = null;
        if ($effectiveFacultyId) {
            $faculty = $this->faculties->findOwned($effectiveFacultyId, $universityId);
            if (!$faculty) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الكلية المختارة غير صحيحة أو لا تنتمي لجامعتك.'
                    : 'The selected faculty is invalid or does not belong to your university.'];
            }
        }

        $department = null;
        if ($departmentId) {
            if (!$faculty) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'يجب اختيار الكلية أولًا قبل اختيار القسم.'
                    : 'A faculty must be selected before a department.'];
            }
            $department = $this->departments->findOwnedByFaculty($departmentId, $faculty->id);
            if (!$department) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'القسم المختار غير صحيح أو لا ينتمي لهذه الكلية.'
                    : 'The selected department is invalid or does not belong to that faculty.'];
            }
        }

        $before = [
            'faculty_id' => $student->faculty_id, 'department_id' => $student->department_id,
            'faculty' => $student->faculty, 'department' => $student->department,
            'expected_graduation_date' => $student->expected_graduation_date,
            'group_id' => $student->group_id,
        ];

        $facultyChanged    = (int) ($student->faculty_id ?? 0) !== (int) ($faculty?->id ?? 0);
        $departmentChanged = (int) ($student->department_id ?? 0) !== (int) ($department?->id ?? 0);
        $oldGroupId = $student->group_id;
        $shouldClearGroup = ($facultyChanged || $departmentChanged) && $oldGroupId !== null;

        $studentUpdate = [
            'faculty_id'                => $faculty?->id,
            'department_id'             => $department?->id,
            'faculty'                   => $faculty ? $faculty->name($locale) : null,
            'department'                => $department ? $department->name($locale) : null,
        ];
        if ($shouldClearGroup) {
            $studentUpdate['group_id'] = null;
        }
        $student->fill($studentUpdate);
        $student->save();

        $this->auditLog->record($actingUserId, 'university.student_reassign_faculty', 'Student', $student->id, $before, [
            'faculty_id' => $faculty?->id, 'department_id' => $department?->id,
            'group_id' => $student->group_id,
        ]);

        $this->notifications->notify(
            $student->user_id,
            'student_affiliation_updated',
            $locale === 'ar' ? 'تم تحديث بياناتك الأكاديمية' : 'Your academic affiliation was updated',
            $shouldClearGroup
                ? ($locale === 'ar'
                    ? 'قامت إدارة جامعتك بتحديث الكلية/القسم المسجّل بهم، وتمت إزالتك من مجموعتك السابقة نتيجة لذلك.'
                    : 'Your university has updated the faculty/department on your record, and you were removed from your previous group as a result.')
                : ($locale === 'ar'
                    ? 'قامت إدارة جامعتك بتحديث الكلية/القسم المسجّل بهم.'
                    : 'Your university has updated the faculty/department on your record.'),
            '/student/profile'
        );

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث تبعية الطالب.' : 'Student affiliation updated.'];
    }

    public function updateAcademicDetails(
        $id,
        $universityId,
        $actingUserId,
        ?string $studentNumber,
        ?int $academicYear,
        string $locale = 'ar',
        $scopeFacultyId = null,
        ?int $currentSemester = null
    ): array {
        $student = $this->students->findOwned($id, $universityId, $scopeFacultyId);
        if (!$student) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الطالب غير موجود.' : 'Student not found.'];
        }

        $studentNumber = $studentNumber !== null ? trim($studentNumber) : null;
        $studentNumber = $studentNumber === '' ? null : $studentNumber;

        if ($studentNumber !== null) {
            $existing = $this->students->findByStudentNumber($universityId, $studentNumber);
            if ($existing && (int) $existing->id !== (int) $student->id) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الرقم الجامعي مستخدم بالفعل لطالب آخر في جامعتك.'
                    : 'This student ID is already in use at your university.'];
            }
        }

        $before = [
            'student_number'   => $student->student_number,
            'academic_year'    => $student->academic_year,
            'current_semester' => $student->current_semester,
        ];

        $student->fill([
            'student_number'   => $studentNumber,
            'academic_year'    => $academicYear,
            'current_semester' => $currentSemester,
        ]);
        $student->save();

        $this->auditLog->record($actingUserId, 'university.student_academic_details_update', 'Student', $student->id, $before, [
            'student_number' => $studentNumber, 'academic_year' => $academicYear, 'current_semester' => $currentSemester,
        ]);

        $this->notifications->notify(
            $student->user_id,
            'student_academic_details_updated',
            $locale === 'ar' ? 'تم تحديث بياناتك الأكاديمية' : 'Your academic details were updated',
            $locale === 'ar'
                ? 'قامت إدارة جامعتك بتحديث الرقم الجامعي/السنة الدراسية/الترم الحالي المسجّلين لك.'
                : 'Your university has updated your student number/academic year/current semester on file.',
            '/student/profile'
        );

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث البيانات الأكاديمية.' : 'Academic details updated.'];
    }

    public function updateStudyDates(
        $id,
        $universityId,
        $actingUserId,
        ?string $studyStartDate,
        ?string $expectedGraduationOverride,
        string $locale = 'ar',
        $scopeFacultyId = null
    ): array {
        $student = $this->students->findOwned($id, $universityId, $scopeFacultyId);
        if (!$student) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الطالب غير موجود.' : 'Student not found.'];
        }

        $studyStartDate = $studyStartDate !== null ? trim($studyStartDate) : null;
        if ($studyStartDate !== null && $studyStartDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyStartDate)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'تاريخ بداية الدراسة غير صالح.' : 'Invalid study start date.'];
        }
        $studyStartDate = ($studyStartDate === '') ? null : $studyStartDate;

        $expectedGraduationOverride = $expectedGraduationOverride !== null ? trim($expectedGraduationOverride) : null;
        if ($expectedGraduationOverride !== null && $expectedGraduationOverride !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedGraduationOverride)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'تاريخ التخرج المتوقع غير صالح.' : 'Invalid expected graduation date.'];
        }
        $expectedGraduationOverride = ($expectedGraduationOverride === '') ? null : $expectedGraduationOverride;

        $expectedGraduationDate = $expectedGraduationOverride;

        $before = [
            'study_start_date'         => $student->study_start_date,
            'expected_graduation_date' => $student->expected_graduation_date,
        ];

        $student->fill([
            'study_start_date'         => $studyStartDate,
            'expected_graduation_date' => $expectedGraduationDate,
        ]);
        $student->save();

        $this->auditLog->record($actingUserId, 'university.student_study_dates_update', 'Student', $student->id, $before, [
            'study_start_date' => $studyStartDate, 'expected_graduation_date' => $expectedGraduationDate,
            'manual_override' => $expectedGraduationOverride !== null,
        ]);

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث تواريخ الدراسة.' : 'Study dates updated.'];
    }

    public function updateProfile(
        $id,
        $universityId,
        $actingUserId,
        ?string $fullName,
        ?array $avatarFile,
        string $locale = 'ar',
        $scopeFacultyId = null,
        ?string $nameAr = null,
        ?string $nameEn = null
    ): array {
        $student = $this->students->findOwned($id, $universityId, $scopeFacultyId);
        if (!$student) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الطالب غير موجود.' : 'Student not found.'];
        }

        $user = User::find($student->user_id);
        if (!$user) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الطالب غير موجود.' : 'Student not found.'];
        }

        $before = ['full_name' => $user->full_name, 'name_ar' => $user->name_ar, 'name_en' => $user->name_en, 'avatar_path' => $user->avatar_path];
        $changes = [];

        if ($nameAr !== null || $nameEn !== null) {
            // Both names are always edited together so neither can be left blank/stale.
            $names = \App\Support\BilingualName::resolve($nameAr ?? $user->name_ar, $nameEn ?? $user->name_en, $locale);
            if (!$names['ok']) {
                return ['success' => false, 'message' => reset($names['errors']), 'errors' => $names['errors']];
            }
            $changes += \App\Support\BilingualName::columns($names);
        } else {
            $fullName = $fullName !== null ? trim($fullName) : null;
            if ($fullName !== null && $fullName !== '') {
                $changes['full_name'] = $fullName;
            }
        }

        if ($avatarFile && (int) ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = $this->uploads->store($avatarFile, 'avatars', (string) $user->id);
                if ($user->avatar_path) {
                    $this->uploads->delete($user->avatar_path);
                }
                $changes['avatar_path'] = $stored['stored_path'];
            } catch (\RuntimeException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (!$changes) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'مفيش حاجة اتغيرت.' : 'Nothing to update.'];
        }

        $user->fill($changes);
        $user->save();

        $this->auditLog->record($actingUserId, 'university.student_profile_update', 'Student', $student->id, $before, $changes);

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث بيانات الطالب.' : "Student's profile updated."];
    }

    private function setAccountStatus($id, $universityId, $actingUserId, string $status, string $auditAction, $facultyId = null): bool
    {
        $student = $this->students->findOwned($id, $universityId, $facultyId);
        if (!$student) {
            return false;
        }

        $user = User::find($student->user_id);
        if (!$user) {
            return false;
        }

        $before = ['status' => $user->status];
        $user->fill(['status' => $status === 'active' ? 'active' : 'suspended']);
        $user->save();

        $this->auditLog->record($actingUserId, $auditAction, 'Student', $student->id, $before, ['status' => $user->status]);
        return true;
    }

    /**
     * بيشيل الطالب من روستر الجامعة دي وبيوقف الدخول بتاعه — مش حذف
     * فعلي (حسابه ومشاريعه وسجل الأودت فاضلين زي ما هم). $facultyId
     * (migration 106): لو الكولر حساب كلية، "حذف" معناها "شيله من كليتي
     * بس" — الطالب فاضل طالب في الجامعة الأوسع (university_id متغيرش،
     * الدخول مش بيتوقف). الجامعة بس (facultyId === null) بتعمل الشيل
     * الكامل + توقيف الدخول.
     */
    public function delete($id, $universityId, $actingUserId, $facultyId = null): bool
    {
        $student = $this->students->findOwned($id, $universityId, $facultyId);
        if (!$student) {
            return false;
        }

        $before = $student->toArray();

        if ($facultyId === null) {
            $user = User::find($student->user_id);
            if ($user) {
                $user->fill(['status' => 'suspended']);
                $user->save();
            }
            $student->fill(['university_id' => null]);
        }

        $student->fill([
            'faculty_id'    => null,
            'department_id' => null,
            'faculty'       => null,
            'department'    => null,
            'group_id'      => null,
        ]);
        $student->save();

        $auditAction = $facultyId === null ? 'university.student_delete' : 'faculty.student_remove';
        $this->auditLog->record($actingUserId, $auditAction, 'Student', $id, $before, $student->toArray());
        return true;
    }

    // -- Groups (بند 16) -----------------------------------------------------

    /** @return array{success:bool, message:string} */
    public function createGroup($universityId, $actingUserId, string $name, ?string $description, string $locale = 'ar', ?int $maxMembers = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => $locale === 'ar' ? 'اسم المجموعة مطلوب.' : 'Group name is required.'];
        }
        if ($this->groups->findByName($universityId, $name)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'يوجد بالفعل مجموعة بهذا الاسم.' : 'A group with this name already exists.'];
        }

        $group = $this->groups->create([
            'university_id' => $universityId,
            'name'          => $name,
            'description'   => $description ? trim($description) : null,
            'max_members'   => $maxMembers,
        ]);

        $this->auditLog->record($actingUserId, 'university.group_create', 'StudentGroup', $group->id, null, ['name' => $name]);
        return ['success' => true, 'message' => $locale === 'ar' ? 'تم إنشاء المجموعة.' : 'Group created.'];
    }

    /** @return array{success:bool, message:string} */
    public function updateGroup($id, $universityId, $actingUserId, string $name, ?string $description, string $locale = 'ar', ?int $maxMembers = null): array
    {
        $group = $this->groups->findOwned($id, $universityId);
        if (!$group) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'المجموعة غير موجودة.' : 'Group not found.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => $locale === 'ar' ? 'اسم المجموعة مطلوب.' : 'Group name is required.'];
        }

        $before = $group->toArray();
        $group->fill(['name' => $name, 'description' => $description ? trim($description) : null, 'max_members' => $maxMembers]);
        $group->save();

        $this->auditLog->record($actingUserId, 'university.group_update', 'StudentGroup', $group->id, $before, $group->toArray());
        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث المجموعة.' : 'Group updated.'];
    }

    /** حذف مجموعة أبدًا مبيمسحش طلابها — بيرجعوا لـ "بدون مجموعة" (ON DELETE SET NULL, migration 099). */
    public function deleteGroup($id, $universityId, $actingUserId): bool
    {
        $group = $this->groups->findOwned($id, $universityId);
        if (!$group) {
            return false;
        }
        $before = $group->toArray();
        $deleted = (bool) $group->delete();
        if ($deleted) {
            $this->auditLog->record($actingUserId, 'university.group_delete', 'StudentGroup', $id, $before, null);
        }
        return $deleted;
    }

    /**
     * @return array{success:bool, message:string}
     *
     * فجوة متعمدة موثقة (نفس اللي updateAffiliation() فوق عاملاها): القديمة
     * كانت بتنادي StudentGroupChatService::syncMembership()/getOrCreateForGroup()
     * هنا عشان تزامن عضوية شات المجموعة. Group Chat نفسه لسه مش بند اتعمل،
     * فالنسخة دي بتنقل الطلاب فعليًا (نفس الأثر على student.group_id) بس من
     * غير نداء الـ chat sync — مفيش تأثير ظاهر للفرونت دلوقتي لأن مفيش Group
     * Chat UI أصلًا يستهلكها.
     */
    public function moveStudents(array $studentIds, $universityId, $actingUserId, ?int $groupId, string $locale = 'ar'): array
    {
        $targetGroup = null;
        if ($groupId !== null) {
            $targetGroup = $this->groups->findOwned($groupId, $universityId);
            if (!$targetGroup) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'المجموعة غير موجودة.' : 'Group not found.'];
            }
        }

        // عدد الطلاب المنقولين اللي أصلًا داخل المجموعة الهدف — لازم نطرحه
        // عشان نحسب max_members من غير double-count.
        $alreadyInTargetCount = 0;
        if ($groupId !== null) {
            foreach ($studentIds as $sid) {
                $existingStudent = $this->students->find($sid);
                if ($existingStudent && (int) $existingStudent->group_id === (int) $groupId) {
                    $alreadyInTargetCount++;
                }
            }
        }

        // Cap اختياري بالحد الأقصى للأعضاء (migration 122) — nullable،
        // فمجموعة من غير حد بتتصرف زي الأول بالظبط.
        if ($targetGroup && $targetGroup->max_members !== null) {
            $resultingCount = $this->groups->memberCount($groupId) - $alreadyInTargetCount + count($studentIds);
            if ($resultingCount > (int) $targetGroup->max_members) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? "هذه المجموعة وصلت للحد الأقصى ({$targetGroup->max_members} عضو)."
                    : "This group is capped at {$targetGroup->max_members} member(s)."];
            }
        }

        $moved = $this->students->bulkAssignGroup($studentIds, $universityId, $groupId);
        if ($moved > 0) {
            $this->auditLog->record($actingUserId, 'university.students_move_group', 'StudentGroup', $groupId, null, [
                'student_ids' => $studentIds, 'moved' => $moved,
            ]);
        }

        return ['success' => $moved > 0, 'message' => $locale === 'ar'
            ? "تم نقل {$moved} طالب/طلاب."
            : "Moved {$moved} student(s)."];
    }

    /** محسوبة، مش متخزنة: دعوة pending وتاريخها فات بترجع Expired من غير ما نحتاج cron. */
    public static function effectiveInvitationStatus(array $student): string
    {
        if ($student['invitation_status'] === null) {
            return 'n/a';
        }
        if ($student['invitation_status'] === 'pending' && !empty($student['expires_at']) && strtotime($student['expires_at']) < time()) {
            return 'expired';
        }
        return $student['invitation_status'];
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
