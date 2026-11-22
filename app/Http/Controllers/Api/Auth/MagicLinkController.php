<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\Identity\LoginHistoryService;
use App\Services\MailService;
use App\Services\RoleService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMailBrand;
use App\Services\Tenancy\TenantSecurity;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Magic link لكل منصة معزولة (المالك بيفعّله من "الدخول والأمان"):
 *  POST /auth/magic-link/request {email}  → رد واحد ثابت (مفيش تسريب لوجود الحساب)؛ لو الحساب موجود ومسموح بيتبعت لينك.
 *  POST /auth/magic-link/verify  {token}  → بيدخّل المستخدم عن طريق LoginController::finishLogin (نفس الحراسات: IP / دومين / قفل الحساب / 2FA / الصيانة…).
 * اللينك مرة واحدة وصالح 15 دقيقة. بيتخزن الـ hash بس في كاش المنصة. الـ verify بـ POST (مش GET) عشان فاحصات الإيميل مش بتحرق اللينك.
 */
class MagicLinkController extends Controller
{
    private const TTL = 900;
    private const RESEND_COOLDOWN = 60;

    public function __construct(
        private LoginController $login,
        private RoleService $roles,
        private AccountLockoutService $lockout,
        private LoginHistoryService $history,
        private MailService $mail
    ) {
    }

    private function locale(Request $r): string
    {
        return $r->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
    }

    private function err(string $msg, int $status, string $code)
    {
        return response()->json(['success' => false, 'message' => $msg, 'data' => null, 'errors' => ['code' => $code], 'meta' => (object) []], $status);
    }

    private function enabled(): bool
    {
        $s = TenantSecurity::current();
        return $s !== null && !empty($s['magic_link']);
    }

    public function request(Request $request)
    {
        $locale = $this->locale($request);
        $ar = $locale === 'ar';
        if (!$this->enabled()) {
            return $this->err($ar ? 'الدخول برابط سحري غير مفعّل.' : 'Magic-link sign-in is not enabled.', 404, 'magic_link_disabled');
        }
        $v = Validator::make($request->all(), ['email' => 'required|email|max:190']);
        if ($v->fails()) {
            return $this->err($v->errors()->first(), 422, 'invalid');
        }
        if (TenantSecurity::ipBlocked($request->ip(), null, true)) {
            return $this->login->tenantBlock($request, null, 'ip_not_allowed', $locale);
        }
        $email = strtolower(trim((string) $request->input('email')));
        if (TenantSecurity::emailBlockedHere($email)) {
            return $this->login->tenantBlock($request, null, 'email_domain_not_allowed', $locale);
        }

        $ok = response()->json(['success' => true, 'message' => 'OK', 'data' => ['sent' => true, 'minutes' => intdiv(self::TTL, 60)], 'errors' => null, 'meta' => (object) []]);

        // مرة كل دقيقة لكل إيميل (يمنع إغراق صندوق حد بالرسائل) — بنفس الرد.
        if (!Cache::add('ml:rl:' . sha1($email), 1, self::RESEND_COOLDOWN)) {
            return $ok;
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user || in_array($user->status, ['suspended', 'banned'], true) || !empty($user->deleted_at)) {
            return $ok;
        }
        if ($this->lockout->checkStatus($user, $locale)['locked']) {
            return $ok;
        }

        $token = Str::random(48);
        Cache::put('ml:' . hash('sha256', $token), ['uid' => (int) $user->id], self::TTL);

        $base = TenantMailBrand::frontendUrl((int) TenantContext::id());
        $link = $base ? $base . '/magic-link?token=' . $token : null;
        if (!$link) {
            Log::warning('Magic link: no frontend url for tenant', ['tenant' => TenantContext::id()]);
            return $ok;
        }
        try {
            $this->mail->sendNotificationEmail(
                (string) $user->email,
                (string) $user->full_name,
                $ar ? 'رابط الدخول' : 'Your sign-in link',
                $ar ? 'اضغط على الزرار عشان تدخل. اللينك شغّال 15 دقيقة ومرة واحدة بس. لو ماطلبتش ده، تجاهل الرسالة.'
                    : 'Use the button to sign in. The link works once and expires in 15 minutes. If you did not ask for it, ignore this email.',
                $link,
                $locale
            );
        } catch (\Throwable $e) {
            Log::warning('Magic link email failed', ['error' => $e->getMessage()]);
        }
        SecurityLog::write('Magic link requested', ['user_id' => $user->id, 'ip' => $request->ip()]);
        return $ok;
    }

    public function verify(Request $request)
    {
        $locale = $this->locale($request);
        $ar = $locale === 'ar';
        if (!$this->enabled()) {
            return $this->err($ar ? 'الدخول برابط سحري غير مفعّل.' : 'Magic-link sign-in is not enabled.', 404, 'magic_link_disabled');
        }
        $v = Validator::make($request->all(), ['token' => 'required|string|min:20|max:200']);
        if ($v->fails()) {
            return $this->err($v->errors()->first(), 422, 'invalid');
        }
        $bad = $this->err($ar ? 'اللينك ده مش شغّال أو اتستخدم قبل كده. اطلب لينك جديد.' : 'This link is invalid or was already used. Request a new one.', 410, 'magic_link_invalid');

        $hit = Cache::pull('ml:' . hash('sha256', (string) $request->input('token'))); // مرة واحدة
        if (!is_array($hit) || empty($hit['uid'])) {
            $this->history->record($request, null, false, 'magic_link', 'magic_link_invalid');
            return $bad;
        }
        $user = User::find((int) $hit['uid']);
        if (!$user || !empty($user->deleted_at)) {
            return $bad;
        }
        $role = $this->roles->primaryRoleFor((int) $user->id);

        $lock = $this->lockout->checkStatus($user, $locale);
        if ($lock['locked']) {
            $this->history->record($request, (int) $user->id, false, 'magic_link', 'locked');
            return $this->err($lock['message'], 422, 'locked');
        }
        if (in_array($user->status, ['suspended', 'banned'], true)) {
            $this->history->record($request, (int) $user->id, false, 'magic_link', 'suspended');
            return $this->err('This account has been ' . $user->status . '. Contact support.', 422, 'suspended');
        }

        // اللينك اتبعت على إيميل الحساب، فاستلامه = إثبات ملكية الإيميل (زي جوجل). حساب كان محجوز بباسورد من حد تاني
        // (pre-hijacking) بنبوّظ باسورده القديم ونقفل جلساته.
        if (!$user->email_verified_at) {
            $user->forceFill(['password_hash' => password_hash(Str::random(64), PASSWORD_BCRYPT)])->save();
            DB::table('refresh_tokens')->where('user_id', $user->id)->delete();
        }
        if (!$user->email_verified_at || $user->status === 'pending') {
            app(\App\Services\EmailVerificationService::class)->markVerified((int) $user->id);
            $user->refresh();
        }

        return $this->login->finishLogin($request, $user, $role, $locale);
    }
}
