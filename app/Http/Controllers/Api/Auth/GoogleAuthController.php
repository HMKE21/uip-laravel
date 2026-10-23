<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\FileUploadService;
use App\Services\GoogleIdTokenVerifier;
use App\Services\RoleService;
use App\Services\StudentJoinRequestService;
use App\Services\UipJwtService;
use App\Support\BilingualName;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * الدخول / إنشاء الحساب بجوجل (ويب + تطبيق الأندرويد بنفس الـ endpoints).
 *
 *  POST /auth/google           { id_token }
 *      - الإيميل عنده حساب (أو جوجل مربوط بحساب) -> دخول عادي، بنفس كل حراسات اللوجين (الجهاز، الصيانة،
 *        الجلسة الواحدة، الـ 2FA...) عن طريق LoginController::finishLogin().
 *      - مفيش حساب -> { needs_registration, registration_token, profile, missing } والفرونت يفتح صفحة
 *        "كمّل بياناتك". مفيش حاجة بتتسجل لحد ما الطالب يكمّل.
 *  POST /auth/google/complete  { registration_token, name_ar, name_en, university_id?... }
 *      - بينشئ حساب الطالب باسم/إيميل/صورة جوجل + البيانات اللي كمّلها، ويدخّله.
 *
 * التسجيل الذاتي للطالب بس (زي RegisterController)، وأدوار الستاف مبتدخلش بجوجل أبدًا.
 */
class GoogleAuthController extends Controller
{
    private const SIGNUP_TYP = 'google_signup';
    private const SIGNUP_TTL = 900; // 15 دقيقة لتكملة البيانات

    public function __construct(
        private GoogleIdTokenVerifier $google,
        private LoginController $login,
        private RoleService $roles,
        private AccountLockoutService $lockout,
        private StudentJoinRequestService $joinRequests,
        private FileUploadService $uploads,
        private \App\Services\DeviceRestrictionPolicyService $deviceRestriction
    ) {
    }

    /** يعرّف الفرونت لو الميزة مفعّلة (GOOGLE_CLIENT_IDS) وإيه الـ client ID — بدل ما يتحط في كود الفرونت. */
    public function config()
    {
        $ids = $this->google->clientIds();
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data'    => ['enabled' => $ids !== [], 'client_id' => $ids[0] ?? null],
            'errors'  => null,
            'meta'    => (object) [],
        ]);
    }

    public function submit(Request $request)
    {
        $locale = $this->locale($request);
        $v = Validator::make($request->all(), ['id_token' => 'required|string|max:4096']);
        if ($v->fails()) {
            return $this->fail($v->errors()->first(), 422);
        }

        $g = $this->google->verify((string) $request->input('id_token'));
        if (!$g) {
            SecurityLog::write('Google sign-in rejected - invalid token', ['ip' => $request->ip()]);
            return $this->fail($locale === 'ar'
                ? 'مقدرناش نتأكد من حساب جوجل. جرّب تاني.'
                : "We couldn't verify your Google account. Please try again.", 401);
        }

        return $this->handleVerified($request, $g, $locale);
    }

    /**
     * كل اللي بعد التحقق من هوية الشخص عند مزوّد الدخول (جوجل أو مايكروسوفت): دخول لو عنده حساب، وإلا بداية تسجيل.
     * $g = ['sub','email','name','picture', 'provider' => 'google'|'microsoft'] — الإيميل لازم يكون متأكَّد منه عند المزوّد.
     */
    public function handleVerified(Request $request, array $g, string $locale)
    {
        $g['provider'] = $g['provider'] ?? 'google';
        $user = $this->findUser($g);
        if ($user) {
            return $this->signIn($request, $user, $g, $locale);
        }

        // مفيش حساب: التسجيل ممكن يكون مقفول من Admin → Platform Policy.
        if (!\App\Support\PlatformPolicy::registrationOpen()) {
            return $this->fail(\App\Support\PlatformPolicy::registrationClosedMessage($locale), 403, ['code' => 'registration_closed']);
        }

        // إنشاء ذاتي للطالب بس، ومن جهاز مسموح.
        if (!$this->deviceRestriction->isAllowed($request->userAgent(), 'student')) {
            return $this->deviceBlocked($request, $locale, 'student');
        }

        $deletedState = app(\App\Services\AccountRecoveryService::class)->state($g['email']);
        $suggest = $this->suggestNames($g);
        $token = UipJwtService::encode([
            'typ'     => self::SIGNUP_TYP,
            'prov'    => $g['provider'],
            'gsub'    => $g['sub'],
            'email'   => $g['email'],
            'name'    => $g['name'],
            'picture' => $g['picture'],
        ], self::SIGNUP_TTL);

        return response()->json([
            'success' => true,
            'message' => 'Additional details required.',
            'data'    => [
                'needs_registration' => true,
                'registration_token' => $token,
                // الإيميل ده كان لحساب اتحذف: الفرونت يعرض "استرجع حسابك" (POST /auth/google/restore) أو يكمّل حساب جديد.
                'deleted_account'    => $deletedState['state'] === 'none' ? null : [
                    'can_restore' => $deletedState['state'] === 'restorable',
                    'reason'      => $deletedState['code'],
                ],
                'profile'            => [
                    'provider' => $g['provider'],
                    'email'   => $g['email'],
                    'name'    => $g['name'],
                    'picture' => $g['picture'],
                    'name_ar' => $suggest['name_ar'],
                    'name_en' => $suggest['name_en'],
                ],
                // الحقول الإجبارية اللي جوجل مقدرش يديها — الفرونت يطلبها من الطالب.
                'missing'            => array_keys(array_filter(
                    ['name_ar' => $suggest['name_ar'] === '', 'name_en' => $suggest['name_en'] === '']
                )),
            ],
            'errors'  => null,
            'meta'    => (object) [],
        ]);
    }

    public function complete(Request $request)
    {
        $locale = $this->locale($request);
        $claims = UipJwtService::decode((string) $request->input('registration_token', ''));
        if (!$claims || ($claims['typ'] ?? null) !== self::SIGNUP_TYP || empty($claims['email']) || empty($claims['gsub'])) {
            return $this->fail($locale === 'ar'
                ? 'انتهت مهلة التسجيل. ارجع واختار حساب جوجل تاني.'
                : 'Your sign-up session expired. Please choose your Google account again.', 422, ['code' => 'signup_expired']);
        }
        $g = [
            'provider' => in_array($claims['prov'] ?? 'google', ['google', 'microsoft'], true) ? (string) ($claims['prov'] ?? 'google') : 'google',
            'sub' => (string) $claims['gsub'], 'email' => strtolower((string) $claims['email']),
            'name' => (string) ($claims['name'] ?? ''), 'picture' => $claims['picture'] ?? null,
            'given_name' => '', 'family_name' => '', 'locale' => null,
        ];

        // اتسجّل قبل كده (ضغط مرتين مثلًا) -> ندخّله بس.
        if ($existing = $this->findUser($g)) {
            return $this->signIn($request, $existing, $g, $locale);
        }

        if (!\App\Support\PlatformPolicy::registrationOpen()) {
            return $this->fail(\App\Support\PlatformPolicy::registrationClosedMessage($locale), 403, ['code' => 'registration_closed']);
        }

        if (!$this->deviceRestriction->isAllowed($request->userAgent(), 'student')) {
            return $this->deviceBlocked($request, $locale, 'student');
        }

        $v = Validator::make($request->all(), [
            'phone'              => 'nullable|string|max:30',
            'preferred_language' => 'nullable|in:ar,en',
            'university_id'      => 'nullable|integer',
            'faculty_id'         => 'nullable|integer',
            'department_id'      => 'nullable|integer',
        ]);
        $suggest = $this->suggestNames($g);
        $names = BilingualName::resolve(
            $request->input('name_ar', $suggest['name_ar']),
            $request->input('name_en', $suggest['name_en']),
            $locale
        );
        if ($v->fails() || !$names['ok']) {
            $errors = $names['errors'];
            foreach ($v->errors()->toArray() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            return response()->json([
                'success' => false,
                'message' => $names['errors'] ? reset($names['errors']) : $v->errors()->first(),
                'errors'  => $errors,
            ], 422);
        }

        $avatarUrl = $g['picture'];
        $user = DB::transaction(function () use ($request, $g, $names) {
            $user = User::create([
                'uuid'               => (string) Str::uuid(),
                'full_name'          => $names['full_name'],
                'name_ar'            => $names['name_ar'],
                'name_en'            => $names['name_en'],
                'email'              => $g['email'],
                'phone'              => $request->input('phone') ?: null,
                // مفيش باسورد: العمود إجباري فبنحط هاش عشوائي ملهوش حد يعرفه. الطالب يقدر يعمل لنفسه
                // باسورد من "نسيت كلمة السر" لو حب يدخل بالإيميل كمان.
                'password_hash'      => password_hash(Str::random(64), PASSWORD_BCRYPT),
                'preferred_language' => $request->input('preferred_language', $this->locale($request)),
                'status'             => 'active',
                'email_verified_at'  => now(),
            ]);

            $roleId = DB::table('roles')->where('slug', 'student')->value('id');
            if ($roleId) {
                DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
            }
            DB::table('students')->insert(['user_id' => $user->id]);
            $this->link($user, $g);

            if ($uid = $request->input('university_id')) {
                try {
                    $this->joinRequests->submitRequest(
                        $user->id, (int) $uid,
                        $request->input('faculty_id') ? (int) $request->input('faculty_id') : null,
                        $request->input('department_id') ? (int) $request->input('department_id') : null
                    );
                } catch (\Throwable $e) {
                    Log::warning('Join request on Google registration failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
            return $user;
        });

        $this->importAvatar($user, $avatarUrl);
        SecurityLog::write('User registered with Google', ['user_id' => $user->id, 'email' => $user->email, 'role' => 'student']);

        return $this->login->finishLogin($request, $user->fresh(), 'student', $locale);
    }

    /**
     * POST /auth/google/restore { registration_token }
     * جوجل أثبت ملكية الإيميل، فالحساب المحذوف بيرجع بكل بياناته من غير OTP (بنفس شروط الاسترجاع الذاتي)
     * ويدخل فورًا بنفس حراسات اللوجين.
     */
    public function restoreDeleted(Request $request)
    {
        $locale = $this->locale($request);
        $claims = UipJwtService::decode((string) $request->input('registration_token', ''));
        if (!$claims || ($claims['typ'] ?? null) !== self::SIGNUP_TYP || empty($claims['email']) || empty($claims['gsub'])) {
            return $this->fail($locale === 'ar'
                ? 'انتهت مهلة التسجيل. ارجع واختار حساب جوجل تاني.'
                : 'Your sign-up session expired. Please choose your Google account again.', 422, ['code' => 'signup_expired']);
        }
        $g = [
            'provider' => in_array($claims['prov'] ?? 'google', ['google', 'microsoft'], true) ? (string) ($claims['prov'] ?? 'google') : 'google',
            'sub' => (string) $claims['gsub'], 'email' => strtolower((string) $claims['email']),
            'name' => (string) ($claims['name'] ?? ''), 'picture' => $claims['picture'] ?? null,
            'given_name' => '', 'family_name' => '', 'locale' => null,
        ];

        $recovery = app(\App\Services\AccountRecoveryService::class);
        $st = $recovery->state($g['email']);
        $squatter = $st['active'] && $recovery->isSquatter($st['active']) ? $st['active'] : null;
        if ($st['state'] === 'none' || ($st['state'] === 'needs_login_merge')) {
            // مفيش محذوف، أو فيه حساب شغّال: دخول عادي لو موجود.
            if ($existing = $this->findUser($g)) {
                return $this->signIn($request, $existing, $g, $locale);
            }
            return $this->fail($locale === 'ar' ? 'مفيش حساب محذوف نقدر نسترجعه.' : 'There is no deleted account to restore.', 404, ['code' => 'not_found']);
        }
        if ($st['state'] !== 'restorable') {
            return $this->fail($locale === 'ar'
                ? 'استرجاع الحساب ده محتاج موافقة الإدارة. تواصل مع الدعم.'
                : 'Restoring this account requires an administrator. Contact support.', 403, ['code' => (string) $st['code']]);
        }
        if (!$this->deviceRestriction->isAllowed($request->userAgent(), 'student')) {
            return $this->deviceBlocked($request, $locale, 'student');
        }

        $res = $recovery->restoreVerified($st['deleted'], $squatter, null);
        if (!$res['ok']) {
            return $this->fail((string) $res['error'], 422, ['code' => 'restore_failed']);
        }
        SecurityLog::write('Deleted account restored with Google', ['user_id' => $st['deleted']->id, 'ip' => $request->ip()]);

        $user = User::find((int) $st['deleted']->id);

        return $this->signIn($request, $user, $g, $locale);
    }

    // ------------------------------------------------------------------ helpers

    /** المربوط بجوجل الأول (sub)، وبعدين بالإيميل. */
    private function findUser(array $g): ?User
    {
        $uid = DB::table('user_social_accounts')->where('provider', $g['provider'] ?? 'google')->where('provider_user_id', $g['sub'])->value('user_id');
        $user = $uid ? User::find($uid) : null;
        return $user ?: User::whereRaw('LOWER(email) = ?', [$g['email']])->first();
    }

    private function signIn(Request $request, User $user, array $g, string $locale)
    {
        // نفس رسالة "بيانات غلط" عشان محدش يعرف إن الإيميل ده ستاف.
        $role = $this->roles->primaryRoleFor($user->id);
        if (in_array($role, ['admin', 'data_analyst', 'security_admin', 'security_officer'], true)) {
            SecurityLog::write('Google sign-in refused - staff account', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return $this->fail($locale === 'ar' ? 'مينفعش الدخول بجوجل للحساب ده.' : "This account can't sign in with Google.", 422);
        }

        $lock = $this->lockout->checkStatus($user, $locale);
        if ($lock['locked']) {
            return $this->fail($lock['message'], 422);
        }
        if (in_array($user->status, ['suspended', 'banned'], true)) {
            return $this->fail('This account has been ' . $user->status . '. Contact support.', 422);
        }

        // أول مرة يدخل بجوجل: نربط الحساب، ونأكّد الإيميل (جوجل أكدته)، ونجيب صورته لو معندوش.
        $linked = DB::table('user_social_accounts')->where('provider', $g['provider'] ?? 'google')->where('provider_user_id', $g['sub'])->exists();
        if (!$linked) {
            // حساب اتسجّل بالباسورد ومتأكدش إيميله قبل كده: ممكن حد تاني يكون سجّل بإيميل الشخص ده بباسورد
            // من عنده (pre-hijacking). جوجل أثبت إن صاحب الإيميل هو اللي بيدخل دلوقتي، فنبوّظ الباسورد القديم
            // ونقفل أي جلسة قديمة؛ صاحب الإيميل يعمل باسورد جديد من "نسيت كلمة السر" لو حب.
            if (!$user->email_verified_at) {
                $user->forceFill(['password_hash' => password_hash(Str::random(64), PASSWORD_BCRYPT)])->save();
                DB::table('refresh_tokens')->where('user_id', $user->id)->delete();
                // أي لينك تأكيد/استرجاع كان اتطلب من اللي حجز الإيميل يتبطل.
                DB::table('email_verification_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
                DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            }
            $this->link($user, $g);
            SecurityLog::write('Google account linked', ['user_id' => $user->id, 'ip' => $request->ip()]);
        }
        if (!$user->email_verified_at || $user->status === 'pending') {
            // جوجل أثبت ملكية الإيميل -> الحساب يتفعّل (pending -> active).
            app(\App\Services\EmailVerificationService::class)->markVerified((int) $user->id);
            $user->refresh();
        }
        if (!$user->avatar_path) {
            $this->importAvatar($user, $g['picture'] ?? null);
        }

        return $this->login->finishLogin($request, $user, $role, $locale);
    }

    private function link(User $user, array $g): void
    {
        DB::table('user_social_accounts')->updateOrInsert(
            ['provider' => $g['provider'] ?? 'google', 'provider_user_id' => $g['sub']],
            ['user_id' => $user->id, 'email' => $g['email'], 'avatar_url' => $g['picture'] ?? null, 'updated_at' => now()]
        );
    }

    /** اسم جوجل بيتحط في الخانة اللي بتطابق حروفه؛ الخانة التانية لازم الطالب يكتبها. */
    private function suggestNames(array $g): array
    {
        $name = BilingualName::clean($g['name'] ?? '');
        return [
            'name_ar' => ($name !== '' && BilingualName::hasArabic($name)) ? $name : '',
            'name_en' => ($name !== '' && !BilingualName::hasArabic($name) && BilingualName::hasLatin($name)) ? $name : '',
        ];
    }

    /** ينزّل صورة جوجل ويحفظها عندنا (best-effort — فشلها ميوقفش التسجيل). */
    private function importAvatar(User $user, ?string $url): void
    {
        $tmp = null;
        try {
            $parts = $url ? parse_url($url) : null;
            // لينك صورة جوجل بس (https على *.googleusercontent.com) — يمنع SSRF لأي عنوان تاني.
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)googleusercontent\.com$/i', $parts['host'] ?? '')) {
                return;
            }
            $url = preg_replace('/=s\d+(-c)?$/', '=s400-c', $url);
            $res = Http::timeout(8)->withOptions(['allow_redirects' => false])->get($url);
            $body = $res->successful() ? $res->body() : '';
            if ($body === '' || strlen($body) > 2 * 1024 * 1024) {
                return;
            }
            $ext = match (strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]))) {
                'image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg', 'image/jpg' => 'jpg', default => null,
            };
            if (!$ext) {
                return;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'gav');
            file_put_contents($tmp, $body);
            $stored = $this->uploads->store(new UploadedFile($tmp, 'google-avatar.' . $ext, null, null, true), 'avatars', (string) $user->id);
            $user->forceFill(['avatar_path' => $stored['stored_path']])->save();
        } catch (\Throwable $e) {
            Log::info('Google avatar import skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function deviceBlocked(Request $request, string $locale, string $role)
    {
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

    private function locale(Request $request): string
    {
        return $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
    }

    private function fail(string $message, int $status, ?array $errors = null)
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors, 'meta' => (object) []], $status);
    }
}
