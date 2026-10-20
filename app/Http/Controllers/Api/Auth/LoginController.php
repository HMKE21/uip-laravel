<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Services\AccountLockoutService;
use App\Services\RoleService;
use App\Services\TrustedDeviceService;
use App\Services\UipJwtService;
use App\Support\AuthCookies;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * يطابق LoginController::submit() + AuthService::attemptLogin() القديمين
 * — الجزء الأساسي + Account Lockout + Two-Factor بالكامل دلوقتي.
 *
 * ⚠️ لسه متعمّد مش موجود (بند منفصل — Security Portal، بند 25 في خطة
 * الهجرة): IP / Country / Device restriction policies، Concurrent Session
 * Limits. دول واجهة إدارة كاملة
 * (allow/deny lists، GeoIP) هتتنقل مع باقي الـ Security Portal مرة واحدة.
 *
 * Maintenance mode enforcement (AdminSettingsApiController::toggleMaintenance()):
 * كان بيتخزن في settings بس من غير أي إنفاذ فعلي — أي حد (admin أو لأ)
 * كان لسه قادر يعمل submit() عادي وهو ON. هنا بنفحصه بعد ما نعرف الـ role
 * (عشان admin يقدر يدخل دايمًا يطفيه) وقبل أي إصدار توكن/تحدي 2FA.
 */
class LoginController extends Controller
{
    /**
     * أدوار الستاف: بتدخل من بوابة الدخول المخفية بس (portal=staff، الفرونت بيبعتها
     * من صفحة /staff-access)، ومبتدخلش من /auth/login العادية. والعكس: أي دور تاني
     * مبيدخلش من بوابة الستاف. الرفض بنفس رسالة "بيانات غلط" عشان مفيش حد يعرف
     * إن الحساب ده موجود أو إنه ستاف.
     */
    private const STAFF_ROLES = ['admin', 'data_analyst', 'security_admin', 'security_officer'];

    /**
     * أنواع الحسابات في اللوجين العادي. الفرونت بيسأل "إنت مين؟" قبل الدخول وبيبعت
     * الاختيار في login_as؛ لو الحساب مش من النوع ده الدخول بيترفض حتى بإيميل
     * وباسورد صح (وبرضه لو login_as ناقص — مفيش دخول من غير اختيار).
     */
    private const PUBLIC_ROLE_LABELS = [
        'student'        => ['en' => 'Student',         'ar' => 'طالب'],
        'university'     => ['en' => 'University',      'ar' => 'جامعة'],
        'faculty'        => ['en' => 'Faculty',         'ar' => 'كلية'],
        'academic_staff' => ['en' => 'Academic staff',  'ar' => 'دكتور / عضو هيئة تدريس'],
        'supervisor'     => ['en' => 'Supervisor',      'ar' => 'مشرف'],
    ];

    public function __construct(
        private RoleService $roles,
        private AccountLockoutService $lockout,
        private TrustedDeviceService $trustedDevice,
        private SettingRepository $settings,
        private \App\Services\SecurityAlertService $alerts,
        private \App\Services\DeviceRestrictionPolicyService $deviceRestriction
    ) {
    }

    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';

        // Staff gate: the hidden staff sign-in only accepts a request that carries a ticket from POST /auth/staff-gate
        // (a correct access code). Checked before any account lookup, so nothing about accounts leaks without it.
        // The ticket also fixes which staff roles may sign in with it (checked again below, once the role is known).
        $gateRoles = null;
        if ($request->input('portal') === 'staff' && \App\Support\StaffGate::enabled()) {
            $gateRoles = \App\Support\StaffGate::rolesFromTicket($request->input('gate_token'));
            if ($gateRoles === null) {
                SecurityLog::write('Staff login refused - no valid gate ticket', ['ip' => $request->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => $locale === 'ar' ? 'أدخل كود الدخول أولًا.' : 'Enter the access code first.',
                    'data'    => null,
                    'errors'  => ['code' => 'staff_gate_required'],
                    'meta'    => (object) [],
                ], 403);
            }
        }

        // Device Restrictions Policy — قاعدة على مستوى المنصة (مش مرتبطة بحساب بعينه)،
        // فبتتفحص قبل أي بحث عن الحساب أو تحقق من كلمة السر.
        if (!$this->deviceRestriction->isAllowed($request->userAgent())) {
            SecurityLog::write('Login blocked - device type restricted', [
                'device' => $this->deviceRestriction->classify($request->userAgent()), 'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $this->deviceRestriction->blockedMessage($locale),
                'data'    => ['device_blocked' => true],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403);
        }

        $user = User::where('email', $data['email'])->first();

        // Account Lockout policy — يتفحص قبل كلمة السر بالظبط زي القديم،
        // عشان مستخدم مقفول ياخد نفس الرسالة أيًا كان اللي كتبه.
        if ($user) {
            $lockStatus = $this->lockout->checkStatus($user, $locale);
            if ($lockStatus['locked']) {
                SecurityLog::write('Login blocked - account locked', ['user_id' => $user->id, 'ip' => $request->ip()]);
                return response()->json(['success' => false, 'message' => $lockStatus['message']], 422);
            }
        }

        if (!$user || !password_verify($data['password'], $user->password_hash)) {
            if ($user) {
                $attempt = $this->lockout->registerFailedAttempt($user, $request->ip(), $locale);
                if ($attempt['just_locked']) {
                    return response()->json(['success' => false, 'message' => $attempt['message']], 422);
                }
            }
            SecurityLog::write('Failed login attempt', ['email' => $data['email'], 'ip' => $request->ip()]);
            $this->alerts->failedLogin($data['email'], $request->ip(), $user?->id);
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 422);
        }

        if (in_array($user->status, ['suspended', 'banned'], true)) {
            SecurityLog::write('Blocked login for ' . $user->status . ' account', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => 'This account has been ' . $user->status . '. Contact support.',
            ], 422);
        }

        // حساب اتسجّل بالباسورد ولسه إيميله متأكدش ومحدش فعّله: مبيدخلش. (بعد التحقق من الباسورد
        // عشان مفيش حد يعرف إن الإيميل ده مسجّل من غير ما يعرف باسورده.)
        if (\App\Services\EmailVerificationService::blocksLogin($user)) {
            SecurityLog::write('Login blocked - email not verified', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => $locale === 'ar'
                    ? 'لازم تأكّد بريدك الإلكتروني الأول. افتح الرسالة اللي وصلتك واضغط على رابط التأكيد، أو اطلب رسالة جديدة. ممكن كمان مدير المنصة يفعّل حسابك.'
                    : 'Please confirm your email first. Open the message we sent you and click the confirmation link, or request a new one. A platform admin can also activate your account.',
                'data'    => ['email_not_verified' => true, 'email' => $user->email, 'activation_fallback' => \App\Http\Controllers\Api\Auth\ActivateWithoutEmailController::mode() !== 'off'],
                'errors'  => ['code' => 'email_not_verified'],
                'meta'    => (object) [],
            ], 403);
        }

        $role = $this->roles->primaryRoleFor($user->id);

        // Joker account (admin-granted, holds every role): enters from either portal and
        // starts as the role picked on the login screen (if held), else its primary role.
        $isJoker = $this->roles->isJoker((int) $user->id);
        $viaStaffPortal = $request->input('portal') === 'staff';
        if ($isJoker) {
            $held = $this->roles->allRolesFor((int) $user->id);
            $pick = (string) $request->input('login_as', '');
            if ($pick !== '' && in_array($pick, $held, true)) {
                $role = $pick;
            }
            // The entry portal decides which portal opens: a joker signing in from the staff
            // portal must land in a staff role (admin first), from the public page in a public
            // role — never in the other portal's role just because it is the primary one.
            if (!$viaStaffPortal === in_array($role, self::STAFF_ROLES, true)) {
                $candidates = $viaStaffPortal
                    ? array_values(array_filter(['admin', 'security_admin', 'data_analyst', 'security_officer'], fn ($r) => in_array($r, $held, true)))
                    : array_values(array_filter(['student', 'university', 'faculty', 'academic_staff', 'supervisor'], fn ($r) => in_array($r, $held, true)));
                if ($candidates) {
                    $role = $candidates[0];
                }
            }
        }
        // The code that opened the gate decides which staff accounts may enter: anything else gets the same
        // "wrong e-mail or password" answer (a joker account falls back to a role the code does allow).
        if ($gateRoles !== null && !in_array($role, $gateRoles, true)) {
            $held = $isJoker ? $this->roles->allRolesFor((int) $user->id) : [];
            $fallback = array_values(array_intersect($gateRoles, $held));
            if ($fallback) {
                $role = $fallback[0];
            } else {
                SecurityLog::write('Staff login refused - role not covered by the access code', [
                    'user_id' => $user->id, 'role' => $role, 'ip' => $request->ip(),
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 422);
            }
        }
        if (!$isJoker && in_array($role, self::STAFF_ROLES, true) !== $viaStaffPortal) {
            SecurityLog::write('Login refused - wrong entry portal', [
                'user_id' => $user->id, 'role' => $role, 'portal' => $viaStaffPortal ? 'staff' : 'public', 'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 422);
        }

        if (!$viaStaffPortal && !$isJoker) {
            $loginAs = (string) $request->input('login_as', '');
            if (!isset(self::PUBLIC_ROLE_LABELS[$loginAs])) {
                return response()->json([
                    'success' => false,
                    'message' => $locale === 'ar' ? 'اختار نوع حسابك الأول.' : 'Please choose your account type first.',
                ], 422);
            }
            if ($loginAs !== $role) {
                SecurityLog::write('Login refused - wrong account type chosen', [
                    'user_id' => $user->id, 'role' => $role, 'chosen' => $loginAs, 'ip' => $request->ip(),
                ]);
                $label = self::PUBLIC_ROLE_LABELS[$loginAs][$locale];
                return response()->json([
                    'success' => false,
                    'message' => $locale === 'ar'
                        ? "الحساب ده مش حساب «{$label}». اختار نوع الحساب الصح وجرّب تاني."
                        : "This isn't a {$label} account. Pick the right account type and try again.",
                ], 422);
            }
        }

        // Password expiration policy: كلمة السر انتهت => الدخول بيترفض لحد ما تتغيّر (عن طريق "نسيت كلمة السر").
        $expiry = app(\App\Services\PasswordPolicyService::class)->expiryStatus($user->id);
        if ($expiry['expired']) {
            SecurityLog::write('Login blocked - password expired', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => $locale === 'ar'
                    ? 'انتهت صلاحية كلمة المرور. استخدم "نسيت كلمة السر" لتعيين كلمة جديدة.'
                    : 'Your password has expired. Use "Forgot password" to set a new one.',
                'data'    => ['password_expired' => true, 'email' => $user->email],
                'errors'  => ['code' => 'password_expired'],
                'meta'    => (object) [],
            ], 403);
        }
        $request->attributes->set('uip_password_expiry', $expiry);

        return $this->finishLogin($request, $user, $role, $locale);
    }

    /**
     * كل اللي بعد التحقق من هوية الحساب (باسورد أو جوجل): قيود نوع الجهاز حسب الدور، تصفير
     * محاولات الفشل، وضع الصيانة، الجلسة الواحدة، الـ 2FA، وإصدار التوكنز. اتفصل عن submit()
     * عشان GoogleAuthController يعدّي من نفس الحراسات بالظبط بدل ما نكرر الكود.
     */
    public function finishLogin(Request $request, User $user, string $role, string $locale)
    {
        // Device Restrictions Policy (وضع by_role) — الدور اتعرف دلوقتي والحساب اتأكد منه،
        // فنطبّق قاعدة الدور ده على نوع الجهاز (مثلًا الطالب ممنوع موبايل والأدمن لأ).
        if (!$this->deviceRestriction->isAllowed($request->userAgent(), $role)) {
            SecurityLog::write('Login blocked - device type restricted for role', [
                'user_id' => $user->id, 'role' => $role,
                'device' => $this->deviceRestriction->classify($request->userAgent()), 'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $this->deviceRestriction->blockedMessage($locale, $role),
                'data'    => [
                    'device_blocked' => true,
                    'device_type'    => $this->deviceRestriction->classify($request->userAgent()),
                    'role'           => $role,
                ],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403);
        }

        $this->lockout->registerSuccessfulLogin($user);

        if ($role !== 'admin' && $this->settings->get('maintenance_mode', 'global', null, '0') === '1') {
            SecurityLog::write('Login blocked - maintenance mode', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => $locale === 'ar' ? 'المنصة تحت الصيانة حالياً' : 'The platform is under maintenance',
                'data'    => ['maintenance_mode' => true],
                'errors'  => null,
                'meta'    => (object) [],
            ], 503);
        }

        // جلسة واحدة نشطة لكل حساب: لو الحساب مفتوح على جهاز/متصفح تاني الدخول بيترفض.
        if ($block = app(\App\Services\UserSessionService::class)->loginBlock((int) $user->id, $locale, $role)) {
            SecurityLog::write('Login blocked - account already open elsewhere', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => $block[0],
                'data'    => $block[1],
                'errors'  => null,
                'meta'    => (object) [],
            ], 409);
        }

        if ($user->two_factor_enabled) {
            $trustedHit = $this->trustedDevice->isCurrentDeviceTrusted(
                $user->id,
                $request->ip(),
                $request->cookie(TrustedDeviceService::COOKIE_NAME)
            );

            if (!$trustedHit) {
                $challengeToken = UipJwtService::encode(
                    ['typ' => '2fa_challenge', 'sub' => $user->id, 'role' => $role],
                    300 // 5 دقايق — مدة كافية تدخل التطبيق وتكتب الكود
                );

                SecurityLog::write('Password verified, awaiting 2FA code', ['user_id' => $user->id, 'ip' => $request->ip()]);

                return response()->json([
                    'success' => true,
                    'message' => 'Two-factor verification required.',
                    'data'    => [
                        'requires_2fa'    => true,
                        'redirect'        => '/auth/two-factor',
                        'challenge_token' => $challengeToken,
                    ],
                    'errors' => null,
                    'meta'   => (object) [],
                ]);
            }

            SecurityLog::write('2FA skipped - trusted device', ['user_id' => $user->id, 'ip' => $request->ip()]);
        }

        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $tokens = UipJwtService::issueTokenPair($user->id, $role);

        // Joker: remember which role this fresh session started as (see RoleService::effectiveRoleFor).
        if ($this->roles->isJoker((int) $user->id)) {
            $sid = \Illuminate\Support\Facades\DB::table('user_sessions')->where('user_id', $user->id)->max('id');
            if ($sid) {
                \Illuminate\Support\Facades\DB::table('user_sessions')->where('id', $sid)->update(['active_role' => $role]);
            }
        }

        SecurityLog::write('Successful login', ['user_id' => $user->id, 'ip' => $request->ip()]);

        // MFA Requirements Policy: لو الدور ملزم بـ 2FA ومفعّلهوش، بنبدأ عدّاد المهلة (لو لسه)،
        // ولو المهلة خلصت (أو 0 = فورًا) بنوديه مباشرة لصفحة التفعيل. التوكن بيتصدر عادي
        // (محتاجه علشان يفعّل) واليوزر مقيّد في UipAuthMiddleware لحد ما يخلّص.
        $mfa = app(\App\Services\MfaPolicyService::class)->status((int) $user->id, $role);
        $redirect = $mfa['restricted'] ? '/auth/mfa-setup' : $this->roles->homeRouteFor($role);
        if ($mfa['restricted']) {
            SecurityLog::write('Login restricted - MFA enrollment required', ['user_id' => $user->id, 'role' => $role, 'ip' => $request->ip()]);
        }

        return AuthCookies::attach(response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => array_merge(['redirect' => $redirect, 'mfa' => $mfa, 'password_expiry' => $request->attributes->get('uip_password_expiry')], $tokens),
            'errors'  => null,
            'meta'    => (object) [],
        ]), $request);

    }
}
