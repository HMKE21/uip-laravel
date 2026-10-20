<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\University;
use App\Models\User;
use App\Repositories\FacultyRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * منقولة من app/Services/FacultyAccountService.php القديمة. نفس منطق
 * توليد الباسورد المؤقت وربط الحساب بالكلية والـ audit log بالظبط.
 *
 * ⚠️ فرق متعمّد — sendCredentials() هنا بترسل log entry + TODO بدل
 * إيميل حقيقي، بنفس بالظبط اتفاقية ForgotPasswordController::submit()
 * الموجودة بالفعل من بند 1 (إرسال الإيميلات الحقيقي لسه TODO في كل
 * الموديول، مش حاجة جديدة اتأجلت هنا).
 */
class FacultyAccountService
{
    public function __construct(
        private FacultyRepository $faculties,
        private UniversityRepository $universities,
        private AuditLogService $auditLog,
        private PasswordPolicyService $passwordPolicy
    ) {
    }

    /**
     * $password: لو اتبعتت، الجامعة هي اللي مختارة كلمة السر (لازم تعدي
     * PasswordPolicyService زي أي تسجيل تاني)؛ لو null بيرجع لسلوك التوليد
     * التلقائي القديم بالظبط.
     * @return array{success:bool, message:string}
     */
    public function provisionLogin($facultyId, $universityId, $actingUserId, string $email, string $locale = 'ar', ?string $password = null): array
    {
        $faculty = $this->faculties->findOwned($facultyId, $universityId);
        if (!$faculty) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الكلية غير موجودة.' : 'Faculty not found.'];
        }
        if ($faculty->user_id) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'الكلية دي عندها حساب دخول بالفعل.'
                : 'This faculty already has a login account.'];
        }

        $email = mb_strtolower(trim($email));
        if (User::where('email', $email)->exists()) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا البريد الإلكتروني مسجل بالفعل على المنصة بحساب آخر.'
                : 'This email already has an account elsewhere on the platform.'];
        }

        $isCustomPassword = $password !== null && $password !== '';
        if ($isCustomPassword) {
            try {
                $this->passwordPolicy->assertValid($password);
            } catch (\InvalidArgumentException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }
        $finalPassword = $isCustomPassword ? $password : $this->generateTempPassword();

        $user = User::create([
            'uuid'          => (string) Str::uuid(),
            'full_name'     => $faculty->name($locale) ?: $faculty->name('en'),
            'name_ar'       => $faculty->name_ar ?: $faculty->name('ar'),
            'name_en'       => $faculty->name_en ?: $faculty->name('en'),
            'email'         => $email,
            'password_hash' => password_hash($finalPassword, PASSWORD_DEFAULT),
            'status'        => 'active',
        ]);

        // نفس RegisterController::submit() بالظبط — role_id مش role string.
        $roleId = \Illuminate\Support\Facades\DB::table('roles')->where('slug', 'faculty')->value('id');
        if ($roleId) {
            \Illuminate\Support\Facades\DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
        }

        $faculty->fill(['user_id' => $user->id]);
        $faculty->save();

        // لو الجامعة كتبت كلمة السر بنفسها هي عارفاها أصلاً — الإرسال هنا
        // بيفيد بس في حالة التوليد التلقائي (زي القديم بالظبط).
        if (!$isCustomPassword) {
            $this->sendCredentials($faculty, $universityId, $email, $finalPassword, $locale);
        }

        $this->auditLog->record($actingUserId, 'university.faculty_login_provision', 'Faculty', $faculty->id, null, ['email' => $email]);

        if ($isCustomPassword) {
            return ['success' => true, 'message' => $locale === 'ar'
                ? 'تم إنشاء حساب الكلية بالبريد الإلكتروني وكلمة المرور اللي حددتها.'
                : 'Faculty account created with the email and password you set.'];
        }

        return ['success' => true, 'message' => $locale === 'ar'
            ? 'تم إنشاء حساب الكلية وإرسال بيانات الدخول بالبريد الإلكتروني.'
            : 'Faculty account created — login details were emailed to it.'];
    }

    /** @return array{success:bool, message:string} */
    public function resetLogin($facultyId, $universityId, $actingUserId, string $locale = 'ar'): array
    {
        $faculty = $this->faculties->findOwned($facultyId, $universityId);
        if (!$faculty || !$faculty->user_id) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'مفيش حساب دخول لهذه الكلية بعد.' : 'This faculty has no login account yet.'];
        }

        $user = User::find($faculty->user_id);
        if (!$user) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الحساب غير موجود.' : 'Account not found.'];
        }

        $tempPassword = $this->generateTempPassword();
        $user->fill(['password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
        $user->save();

        $this->sendCredentials($faculty, $universityId, $user->email, $tempPassword, $locale);

        $this->auditLog->record($actingUserId, 'university.faculty_login_reset', 'Faculty', $faculty->id, null, null);

        return ['success' => true, 'message' => $locale === 'ar'
            ? 'تم إنشاء كلمة مرور جديدة وإرسالها بالبريد الإلكتروني.'
            : 'A new password was generated and emailed.'];
    }

    /**
     * تغيير إيميل حساب دخول الكلية يدويًا (مش Reset). الجامعة بتحط
     * الإيميل الجديد مباشرة. @return array{success:bool, message:string}
     */
    public function updateLoginEmail($facultyId, $universityId, $actingUserId, string $newEmail, string $locale = 'ar'): array
    {
        $faculty = $this->faculties->findOwned($facultyId, $universityId);
        if (!$faculty || !$faculty->user_id) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'مفيش حساب دخول لهذه الكلية بعد.' : 'This faculty has no login account yet.'];
        }

        $user = User::find($faculty->user_id);
        if (!$user) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الحساب غير موجود.' : 'Account not found.'];
        }

        $newEmail = mb_strtolower(trim($newEmail));

        if ($newEmail === mb_strtolower((string) $user->email)) {
            return ['success' => true, 'message' => $locale === 'ar'
                ? 'تم تحديث البريد الإلكتروني لحساب الكلية.'
                : "The faculty's login email was updated."];
        }

        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'هذا البريد الإلكتروني مسجل بالفعل على المنصة بحساب آخر.'
                : 'This email already has an account elsewhere on the platform.'];
        }

        $oldEmail = $user->email;
        $user->fill(['email' => $newEmail]);
        $user->save();

        $this->auditLog->record($actingUserId, 'university.faculty_login_email_update', 'Faculty', $faculty->id, ['email' => $oldEmail], ['email' => $newEmail]);

        return ['success' => true, 'message' => $locale === 'ar'
            ? 'تم تحديث البريد الإلكتروني لحساب الكلية.'
            : "The faculty's login email was updated."];
    }

    /**
     * تغيير كلمة مرور حساب دخول الكلية يدويًا (مش توليد عشوائي زي
     * resetLogin) — الجامعة بتحدد كلمة المرور الجديدة بنفسها، لازم تعدي
     * PasswordPolicyService زي أي كلمة سر تانية. مفيش إرسال إيميل هنا،
     * نفس منطق provisionLogin() مع custom password.
     * @return array{success:bool, message:string}
     */
    public function updateLoginPassword($facultyId, $universityId, $actingUserId, string $newPassword, string $locale = 'ar'): array
    {
        $faculty = $this->faculties->findOwned($facultyId, $universityId);
        if (!$faculty || !$faculty->user_id) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'مفيش حساب دخول لهذه الكلية بعد.' : 'This faculty has no login account yet.'];
        }

        $user = User::find($faculty->user_id);
        if (!$user) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'الحساب غير موجود.' : 'Account not found.'];
        }

        try {
            $this->passwordPolicy->assertValid($newPassword);
            $this->passwordPolicy->assertNotReused($user->id, $newPassword);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->fill(['password_hash' => $newHash]);
        $user->save();
        $this->passwordPolicy->recordPasswordChange($user->id, $newHash);

        $this->auditLog->record($actingUserId, 'university.faculty_login_password_update', 'Faculty', $faculty->id, null, null);

        return ['success' => true, 'message' => $locale === 'ar'
            ? 'تم تحديث كلمة المرور لحساب الكلية.'
            : "The faculty's login password was updated."];
    }

    private function sendCredentials(Faculty $faculty, $universityId, string $email, string $tempPassword, string $locale): void
    {
        $university = $this->universities->find($universityId);
        $universityName = $university ? ($university->name($locale) ?: $university->name('en')) : '';
        $facultyName = $faculty->name($locale) ?: $faculty->name('en');

        // TODO: استبدل السطر ده بإرسال إيميل حقيقي عبر Mail::to($email)->send(...)
        // — نفس اتفاقية ForgotPasswordController::submit() (بند 1)، مش فرق جديد.
        Log::info('Faculty login credentials generated', [
            'faculty_id'  => $faculty->id,
            'email'       => $email,
            'university'  => $universityName,
            'faculty'     => $facultyName,
        ]);
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
