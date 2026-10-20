<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AcademicRankRepository;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StaffAssignmentRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Crypt;

/**
 * منقولة من app/Services/AcademicStaffManagementService.php القديمة —
 * بند 10 (Academic Staff + Faculty portal)، جوهر صفحة "Academic Staff
 * Management" بتاعة الجامعة/الكلية. نفس نمط دعوة الحساب الحقيقي بتاع
 * SupervisorManagementService/StudentManagementService بالظبط: كل دعوة
 * بتعمل صف users حقيقي (login-capable، باسورد مؤقت، role='academic_staff'
 * عبر user_roles، migration 104) + صف academic_staff يحمل الرتبة/الكلية/
 * القسم (migration 101). assignLeadership()/endLeadership() عملية منفصلة
 * محفوظة بالتاريخ (Dean/Head of Department/University President...) عبر
 * StaffAssignmentRepository::assign() اللي عمرها ما بتكتب فوق صف قديم —
 * بتقفله وتفتح صف جديد.
 *
 * بورتال الكلية (Faculty) بيعيد استخدام نفس الميثودز دي بالظبط —
 * resendInvite()/activate()/deactivate()/delete()/updateAssignment() كلهم
 * بياخدوا $facultyId اختياري في الآخر (نفس نمط StudentManagementService
 * لبورتال الكلية بتاع الطلاب) عشان الكلية تقدر تدير أعضاء هيئة التدريس
 * بتاعتها بس، عمرها ما تلمس عضو تابع لكلية تانية. invite() أصلًا بتاخد
 * $facultyId صريح، فبورتال الكلية بيمرر رقمها الثابت هي بس.
 *
 * فرق شكلي فقط عن القديمة: Core\Model الثابتة -> حقن الـ Repositories في
 * الـ constructor (نفس تصميم بند 6/8/9)؛ date()/strtotime() -> now()/
 * ->addDays() Carbon.
 */
class AcademicStaffManagementService
{
    /** نطاقات القيادة اللي الجامعة تقدر تمنحها عبر assignLeadership(). */
    public const LEADERSHIP_SCOPES = ['university', 'faculty', 'department'];

    private const INVITE_EXPIRY_DAYS = 7;

    public function __construct(
        private AcademicStaffRepository $staff,
        private StaffAssignmentRepository $assignments,
        private AcademicRankRepository $ranks,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private UniversityRepository $universities,
        private UserRepository $users,
        private MailService $mail,
        private AuditLogService $auditLog
    ) {
    }

    /** أقل طول مسموح لكلمة مرور مخصصة (setPassword()/invite()/resendInvite()) — نفس min:8 المستخدم في *SettingsApiController::updatePassword(). */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * بتدعو دكتور/محاضر/معيد، محكومة بهيكل الجامعة دي نفسها (Faculty ->
     * Department) — نفس فحص الملكية اللي StudentManagementService::invite()
     * بيعمله للطلاب، عشان قسم من جامعة تانية عمره ما يتربط.
     *
     * $password اختياري: لو فاضي (null/'') بيتولد عشوائي زي القديم بالظبط.
     * لو الجامعة/الكلية حددت كلمة مرور بنفسها بدل العشوائية، بتتحقق من
     * الطول الأدنى وتستخدم هي. في الحالتين، النتيجة بترجع 'password'
     * (نص صريح) عشان الواجهة تقدر تعرضها للمستخدم مرة واحدة وقت الإنشاء —
     * زي بالظبط اللي اتبعت بالإيميل، من غير ما تحتاج تستنى reveal لاحق.
     * @return array{success:bool, message:string, password?:string}
     */
    public function invite(
        $universityId,
        $actingUserId,
        string $fullName,
        string $email,
        ?int $facultyId,
        ?int $departmentId,
        ?int $academicRankId,
        ?string $staffNumber,
        ?string $bio,
        ?string $password = null,
        string $locale = 'ar',
        ?string $nameAr = null,
        ?string $nameEn = null
    ): array {
        $names = \App\Support\BilingualName::resolveOrLegacy($fullName, $nameAr, $nameEn, $locale);
        if (!$names['ok']) {
            return ['success' => false, 'message' => reset($names['errors']), 'errors' => $names['errors']];
        }
        $fullName = $names['full_name'];
        $email = mb_strtolower(trim($email));

        if ($password !== null && trim($password) !== '' && mb_strlen(trim($password)) < self::MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة المرور يجب ألا تقل عن 8 أحرف.'
                : 'Password must be at least 8 characters.'];
        }

        if ($why = \App\Support\DisposableEmail::problem($email, $locale)) {
            return ['success' => false, 'message' => $why];
        }

        if ($this->users->emailExists($email)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا البريد الإلكتروني مسجل بالفعل على المنصة بحساب آخر.'
                : 'This email already has an account elsewhere on the platform.'];
        }

        if ($facultyId !== null) {
            $faculty = $this->faculties->findOwned($facultyId, $universityId);
            if (!$faculty) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الكلية المختارة غير موجودة في جامعتك.'
                    : 'The selected faculty does not belong to your university.'];
            }
        }

        if ($departmentId !== null) {
            if ($facultyId === null) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'اختر الكلية أولًا قبل اختيار القسم.'
                    : 'Select a faculty before choosing a department.'];
            }
            $department = $this->departments->find($departmentId);
            if (!$department || (int) $department->faculty_id !== (int) $facultyId) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'القسم المختار لا يتبع الكلية المختارة.'
                    : 'The selected department does not belong to the selected faculty.'];
            }
        }

        if ($academicRankId !== null) {
            $rank = $this->ranks->find($academicRankId);
            if (!$rank || (!$rank->isPlatformDefault() && (int) $rank->university_id !== (int) $universityId)) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الرتبة الأكاديمية غير صالحة.'
                    : 'Invalid academic rank.'];
            }
        }

        if ($staffNumber !== null && trim($staffNumber) !== '' && $this->staff->findByStaffNumber($universityId, trim($staffNumber))) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'الرقم الوظيفي مستخدم بالفعل في جامعتك.'
                : 'This staff number is already used at your university.'];
        }

        $tempPassword = $password !== null && trim($password) !== '' ? trim($password) : $this->generateTempPassword();

        $user = $this->users->createBareWithRole([
            'full_name'     => $names['full_name'],
            'name_ar'       => $names['name_ar'],
            'name_en'       => $names['name_en'],
            'email'         => $email,
            'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
            'status'        => 'active',
        ], 'academic_staff');

        $staff = $this->staff->create([
            'user_id'            => $user->id,
            'university_id'      => $universityId,
            'faculty_id'         => $facultyId,
            'department_id'      => $departmentId,
            'academic_rank_id'   => $academicRankId,
            'staff_number'       => $staffNumber !== null && trim($staffNumber) !== '' ? trim($staffNumber) : null,
            'bio'                => $bio !== null && trim($bio) !== '' ? trim($bio) : null,
            'status'             => 'active',
            'invitation_status'  => 'pending',
            'invited_at'         => now(),
            'expires_at'         => now()->addDays(self::INVITE_EXPIRY_DAYS),
            'password_encrypted' => Crypt::encryptString($tempPassword),
        ]);

        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';

        $this->mail->sendAcademicStaffInvite($email, trim($fullName), $universityName, $tempPassword, $locale);

        $this->auditLog->record($actingUserId, 'university.academic_staff_invite', 'AcademicStaff', $staff->id, null, [
            'email' => $email, 'faculty_id' => $facultyId, 'department_id' => $departmentId, 'academic_rank_id' => $academicRankId,
            'password_source' => $password !== null && trim($password) !== '' ? 'custom' : 'generated',
        ]);

        return ['success' => true, 'message' => $locale === 'ar'
            ? 'تمت دعوة عضو هيئة التدريس وإرسال بيانات الدخول له بالبريد الإلكتروني.'
            : 'Academic staff member invited — login details were emailed to them.',
            'password' => $tempPassword];
    }

    /**
     * @param string|null $password اختياري — نفس منطق invite() (فاضي =
     * عشوائي، محدد = مخصص بشرط الطول الأدنى). راجع docblock invite().
     * @param int|null $facultyId نطاق اختياري — بورتال الكلية بيمرره عشان يعيد الإرسال لأعضائه بس
     * @return array{success:bool, message:string, password?:string}
     */
    public function resendInvite($id, $universityId, $actingUserId, ?string $password = null, string $locale = 'ar', $facultyId = null): array
    {
        $staff = $this->staff->findOwnedByUniversity($id, $universityId, $facultyId);
        if (!$staff || $staff->invitation_status === 'accepted' || !$staff->invited_at) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'غير متاح لهذا العضو.' : 'Not available for this staff member.'];
        }

        if ($password !== null && trim($password) !== '' && mb_strlen(trim($password)) < self::MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة المرور يجب ألا تقل عن 8 أحرف.'
                : 'Password must be at least 8 characters.'];
        }

        $tempPassword = $password !== null && trim($password) !== '' ? trim($password) : $this->generateTempPassword();
        $user = $staff->user_id ? User::find($staff->user_id) : null;
        if ($user) {
            $user->fill(['password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
            $user->save();
        }

        $staff->fill([
            'invitation_status'  => 'pending',
            'expires_at'         => now()->addDays(self::INVITE_EXPIRY_DAYS),
            'password_encrypted' => Crypt::encryptString($tempPassword),
        ]);
        $staff->save();

        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';
        $email = $user->email ?? '';
        $fullName = $user->full_name ?? '';

        $sent = $this->mail->sendAcademicStaffInvite($email, $fullName, $universityName, $tempPassword, $locale);
        $this->auditLog->record($actingUserId, 'university.academic_staff_resend_invite', 'AcademicStaff', $staff->id, null, [
            'password_source' => $password !== null && trim($password) !== '' ? 'custom' : 'generated',
        ]);

        return ['success' => $sent, 'message' => $locale === 'ar' ? 'تم إرسال دعوة جديدة.' : 'A new invite was sent.',
            'password' => $tempPassword];
    }

    /**
     * تحديد/تغيير كلمة مرور عضو هيئة تدريس مباشرة — بعكس resendInvite()،
     * شغالة حتى لو الدعوة اتقبلت خلاص (invitation_status = accepted)،
     * عشان "عايز اشوف الباسورد بتاعه وأعدله" ينفع في أي وقت مش بس وقت
     * الدعوة. $password فاضي = بيتولد عشوائي جديد.
     * @return array{success:bool, message:string, password?:string}
     */
    public function setPassword($id, $universityId, $actingUserId, ?string $password, string $locale = 'ar', $facultyId = null): array
    {
        $staff = $this->staff->findOwnedByUniversity($id, $universityId, $facultyId);
        if (!$staff) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'العضو غير موجود.' : 'Staff member not found.'];
        }

        if ($password !== null && trim($password) !== '' && mb_strlen(trim($password)) < self::MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'كلمة المرور يجب ألا تقل عن 8 أحرف.'
                : 'Password must be at least 8 characters.'];
        }

        if (!$staff->invited_at) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا حساب مرتبط يملكه صاحبه — لا يمكن تغيير كلمة سره من هنا.'
                : 'This is a linked account owned by its user — its password cannot be changed from here.'];
        }

        $newPassword = $password !== null && trim($password) !== '' ? trim($password) : $this->generateTempPassword();

        $user = $staff->user_id ? User::find($staff->user_id) : null;
        if ($user) {
            $user->fill(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)]);
            $user->save();
        }

        $staff->fill(['password_encrypted' => Crypt::encryptString($newPassword)]);
        $staff->save();

        $this->auditLog->record($actingUserId, 'university.academic_staff_set_password', 'AcademicStaff', $staff->id, null, [
            'password_source' => $password !== null && trim($password) !== '' ? 'custom' : 'generated',
        ]);

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث كلمة المرور.' : 'Password updated.',
            'password' => $newPassword];
    }

    /**
     * فك تشفير كلمة المرور المخزّنة الحالية لعضو (لزرار "عرض" في الواجهة).
     * عملية حساسة — بتتسجل في الـ audit log كل مرة، حتى لو كانت مجرد
     * قراءة (نفس أهمية أي عملية عليها تسجيل هنا).
     * @return array{success:bool, message:string, password?:string}
     */
    public function revealPassword($id, $universityId, $actingUserId, string $locale = 'ar', $facultyId = null): array
    {
        $staff = $this->staff->findOwnedByUniversity($id, $universityId, $facultyId);
        if (!$staff) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'العضو غير موجود.' : 'Staff member not found.'];
        }

        if (!$staff->password_encrypted) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا توجد كلمة مرور محفوظة لهذا العضو — حدد واحدة أولًا.'
                : 'No stored password for this member yet — set one first.'];
        }

        try {
            $plain = Crypt::decryptString($staff->password_encrypted);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'تعذر فك تشفير كلمة المرور.' : 'Could not decrypt the stored password.'];
        }

        $this->auditLog->record($actingUserId, 'university.academic_staff_reveal_password', 'AcademicStaff', $staff->id, null, null);

        return ['success' => true, 'message' => '', 'password' => $plain];
    }

    /**
     * استيراد أعضاء هيئة تدريس بالجملة من صفوف CSV/XLSX (راجع
     * App\Support\TabularFileReader و AcademicStaffApiController::import()).
     * كل صف بيتعامل معاه بنفس فحوصات invite() بالظبط (نفس الميثود
     * بتتنادى per-row) — صف فيه خطأ (إيميل مكرر، قسم مش تابع للكلية...)
     * ميوقفش باقي الصفوف، بس بيترجع في summary.results عشان الواجهة تعرض
     * جدول نتائج (نجح/فشل + السبب) بدل all-or-nothing.
     *
     * أعمدة الصف المتوقعة (lowercase، من TabularFileReader): name_ar و
     * name_en (الاتنين إجباري)، email، department (اسم أو id)، rank (اسم أو id)،
     * staff_number، bio، password (اختياري لكل صف).
     *
     * @param array<int,array<string,string>> $rows
     * @return array{total:int, success_count:int, results:array<int,array{row:int,email:string,success:bool,message:string}>}
     */
    public function importRows(array $rows, $universityId, $actingUserId, ?int $facultyId, string $locale = 'ar'): array
    {
        $results = [];
        $successCount = 0;

        foreach (array_values($rows) as $i => $row) {
            $rowNumber = $i + 2; // +1 لصف الهيدر +1 لأول index

            $fullName = trim((string) ($row['full_name'] ?? $row['name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '') {
                $results[] = ['row' => $rowNumber, 'email' => $email, 'success' => false, 'message' => $locale === 'ar'
                    ? 'البريد الإلكتروني ناقص.'
                    : 'Missing email.'];
                continue;
            }
            $rowNames = \App\Support\BilingualName::resolve($row['name_ar'] ?? $row['arabic_name'] ?? '', $row['name_en'] ?? $row['english_name'] ?? '', $locale);
            if (!$rowNames['ok']) {
                $results[] = ['row' => $rowNumber, 'email' => $email, 'success' => false, 'message' => ($locale === 'ar'
                    ? 'الاسم بالعربي والإنجليزي مطلوبين (أعمدة name_ar و name_en): '
                    : 'Arabic and English names are required (columns name_ar and name_en): ') . implode(' ', $rowNames['errors'])];
                continue;
            }

            $departmentId = $this->resolveDepartmentCell($row['department'] ?? $row['department_id'] ?? null, $facultyId);
            $rankId = $this->resolveRankCell($row['rank'] ?? $row['academic_rank'] ?? $row['academic_rank_id'] ?? null, $universityId);
            $staffNumber = trim((string) ($row['staff_number'] ?? '')) ?: null;
            $bio = trim((string) ($row['bio'] ?? '')) ?: null;
            $password = trim((string) ($row['password'] ?? '')) ?: null;

            $result = $this->invite($universityId, $actingUserId, $rowNames['full_name'], $email, $facultyId, $departmentId, $rankId, $staffNumber, $bio, $password, $locale, $rowNames['name_ar'], $rowNames['name_en']);

            $results[] = ['row' => $rowNumber, 'email' => $email, 'success' => $result['success'], 'message' => $result['message']];
            if ($result['success']) {
                $successCount++;
            }
        }

        return ['total' => count($rows), 'success_count' => $successCount, 'results' => $results];
    }

    /** خلية "department" في صف استيراد: رقم -> id مباشرة، نص -> بحث بالاسم جوه نفس الكلية (لو محددة). */
    private function resolveDepartmentCell(?string $cell, ?int $facultyId): ?int
    {
        $cell = trim((string) $cell);
        if ($cell === '') {
            return null;
        }
        if (ctype_digit($cell)) {
            return (int) $cell;
        }
        if ($facultyId === null) {
            return null; // مفيش نطاق كلية نقدر نبحث فيه بالاسم من غيره
        }
        foreach ($this->departments->forFaculty($facultyId) as $department) {
            if (mb_strtolower($department->name_en ?? '') === mb_strtolower($cell) || mb_strtolower($department->name_ar ?? '') === mb_strtolower($cell)) {
                return (int) $department->id;
            }
        }
        return null;
    }

    /** خلية "rank" في صف استيراد: رقم -> id مباشرة، نص -> بحث بالاسم جوه رتب الجامعة المتاحة. */
    private function resolveRankCell(?string $cell, $universityId): ?int
    {
        $cell = trim((string) $cell);
        if ($cell === '') {
            return null;
        }
        if (ctype_digit($cell)) {
            return (int) $cell;
        }
        foreach ($this->ranks->availableFor($universityId) as $rank) {
            if (mb_strtolower($rank->name_en ?? '') === mb_strtolower($cell) || mb_strtolower($rank->name_ar ?? '') === mb_strtolower($cell)) {
                return (int) $rank->id;
            }
        }
        return null;
    }

    /** بتعدّل faculty/department/rank/bio — نفس فحوصات الملكية بتاعة invite(). */
    public function updateAssignment($id, $universityId, $actingUserId, ?int $facultyId, ?int $departmentId, ?int $academicRankId, ?string $bio, string $locale = 'ar', ?int $scopeFacultyId = null): array
    {
        $staff = $this->staff->findOwnedByUniversity($id, $universityId, $scopeFacultyId);
        if (!$staff) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'العضو غير موجود.' : 'Staff member not found.'];
        }

        if ($facultyId !== null && !$this->faculties->findOwned($facultyId, $universityId)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الكلية غير موجودة في جامعتك.' : 'Faculty not found in your university.'];
        }
        if ($departmentId !== null) {
            $department = $this->departments->find($departmentId);
            if (!$department || (int) $department->faculty_id !== (int) $facultyId) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'القسم لا يتبع الكلية المختارة.' : 'Department does not belong to the selected faculty.'];
            }
        }
        if ($academicRankId !== null) {
            $rank = $this->ranks->find($academicRankId);
            if (!$rank || (!$rank->isPlatformDefault() && (int) $rank->university_id !== (int) $universityId)) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'الرتبة الأكاديمية غير صالحة.' : 'Invalid academic rank.'];
            }
        }

        $before = $staff->toArray();
        $staff->fill([
            'faculty_id'       => $facultyId,
            'department_id'    => $departmentId,
            'academic_rank_id' => $academicRankId,
            'bio'              => $bio !== null && trim($bio) !== '' ? trim($bio) : null,
        ]);
        $staff->save();

        $this->auditLog->record($actingUserId, 'university.academic_staff_update', 'AcademicStaff', $staff->id, $before, $staff->toArray());
        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث بيانات العضو.' : 'Staff assignment updated.'];
    }

    /** @param int|null $facultyId نطاق اختياري — بورتال الكلية بيقدر يفعّل أعضاءه بس */
    public function activate($id, $universityId, $actingUserId, $facultyId = null): bool
    {
        return $this->setAccountStatus($id, $universityId, $actingUserId, 'active', 'university.academic_staff_activate', $facultyId);
    }

    /** @param int|null $facultyId نطاق اختياري — بورتال الكلية بيقدر يعطّل أعضاءه بس */
    public function deactivate($id, $universityId, $actingUserId, $facultyId = null): bool
    {
        return $this->setAccountStatus($id, $universityId, $actingUserId, 'inactive', 'university.academic_staff_deactivate', $facultyId);
    }

    private function setAccountStatus($id, $universityId, $actingUserId, string $status, string $auditAction, $facultyId = null): bool
    {
        $staff = $this->staff->findOwnedByUniversity($id, $universityId, $facultyId);
        if (!$staff) {
            return false;
        }

        $before = $staff->toArray();
        $staff->fill(['status' => $status]);
        $staff->save();

        if ($staff->user_id) {
            $user = User::find($staff->user_id);
            if ($user) {
                $user->fill(['status' => $status === 'active' ? 'active' : 'suspended']);
                $user->save();
            }
        }

        $this->auditLog->record($actingUserId, $auditAction, 'AcademicStaff', $staff->id, $before, $staff->toArray());
        return true;
    }

    /** @param int|null $facultyId نطاق اختياري — بورتال الكلية بيقدر يشيل أعضاءه بس */
    public function delete($id, $universityId, $actingUserId, $facultyId = null): bool
    {
        $staff = $this->staff->findOwnedByUniversity($id, $universityId, $facultyId);
        if (!$staff) {
            return false;
        }

        $before = $staff->toArray();

        if ($staff->user_id) {
            $user = User::find($staff->user_id);
            if ($user) {
                $user->fill(['status' => 'suspended']);
                $user->save();
            }
        }

        $deleted = (bool) $staff->delete();
        if ($deleted) {
            $this->auditLog->record($actingUserId, 'university.academic_staff_delete', 'AcademicStaff', $id, $before, null);
        }
        return $deleted;
    }

    /**
     * بتمنح لقب قيادي (Dean/Vice Dean/Head of Department/University
     * President/...) على نطاق، عبر StaffAssignmentRepository::assign() —
     * محفوظة بالتاريخ، عمرها ما بتتكتب فوقها (نفس قرار بند 9). $scopeId
     * لازم يكون id كيان الجامعة دي فعلًا مالكاه.
     * @return array{success:bool, message:string}
     */
    public function assignLeadership($academicStaffId, $universityId, $actingUserId, string $scopeType, $scopeId, $academicRankId, string $locale = 'ar'): array
    {
        $staff = $this->staff->findOwnedByUniversity($academicStaffId, $universityId);
        if (!$staff) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'العضو غير موجود.' : 'Staff member not found.'];
        }
        if (!in_array($scopeType, self::LEADERSHIP_SCOPES, true)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'نوع النطاق غير صالح.' : 'Invalid scope type.'];
        }

        if ($scopeType === 'university' && (int) $scopeId !== (int) $universityId) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'نطاق غير صالح.' : 'Invalid scope.'];
        }
        if ($scopeType === 'faculty' && !$this->faculties->findOwned($scopeId, $universityId)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الكلية غير موجودة في جامعتك.' : 'Faculty not found in your university.'];
        }
        if ($scopeType === 'department') {
            $department = $this->departments->find($scopeId);
            $faculty = $department ? $this->faculties->find($department->faculty_id) : null;
            if (!$department || !$faculty || (int) $faculty->university_id !== (int) $universityId) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'القسم غير موجود في جامعتك.' : 'Department not found in your university.'];
            }
        }

        $rank = $this->ranks->find($academicRankId);
        if (!$rank || (!$rank->isPlatformDefault() && (int) $rank->university_id !== (int) $universityId)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الرتبة غير صالحة.' : 'Invalid rank.'];
        }

        $this->assignments->assign($staff->id, $academicRankId, $scopeType, $scopeId, null, $actingUserId);

        $this->auditLog->record($actingUserId, 'university.academic_staff_assign_leadership', 'AcademicStaff', $staff->id, null, [
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'academic_rank_id' => $academicRankId,
        ]);

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم إسناد المنصب.' : 'Leadership title assigned.'];
    }

    /** بتنهي إسناد قيادي واحد (استقالة مثلًا) من غير ما تمسح تاريخه. */
    public function endLeadership($assignmentId, $academicStaffId, $universityId, $actingUserId): bool
    {
        $staff = $this->staff->findOwnedByUniversity($academicStaffId, $universityId);
        if (!$staff) {
            return false;
        }

        $assignment = $this->assignments->find($assignmentId);
        if (!$assignment || (int) $assignment->academic_staff_id !== (int) $staff->id) {
            return false;
        }

        $ended = $this->assignments->endAssignment($assignmentId);
        if ($ended) {
            $this->auditLog->record($actingUserId, 'university.academic_staff_end_leadership', 'AcademicStaff', $staff->id, null, ['assignment_id' => $assignmentId]);
        }
        return $ended;
    }

    /** محسوبة، مش مخزّنة: دعوة لسه pending وعدّت مدتها بتترجع Expired من غير الحاجة لـ cron. */
    public static function effectiveInvitationStatus(array $staff): string
    {
        if (($staff['invitation_status'] ?? null) === 'pending' && !empty($staff['expires_at']) && strtotime((string) $staff['expires_at']) < time()) {
            return 'expired';
        }
        return $staff['invitation_status'] ?? 'n/a';
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
