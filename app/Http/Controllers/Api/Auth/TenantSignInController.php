<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantAuthSettings;
use App\Services\Tenancy\PlatformUsers;
use App\Services\Tenancy\TenantContext;

/** GET /auth/tenant-sign-in — شكل صفحة دخول المنصة المعزولة (القالب والنصوص). مفيش أي secret هنا. */
class TenantSignInController extends Controller
{
    private function err(string $msg, int $status = 422, string $code = 'invalid')
    {
        return response()->json(['success' => false, 'message' => $msg, 'data' => null, 'errors' => ['code' => $code], 'meta' => (object) []], $status);
    }

    private function applyPassword(object $user, string $new, string $locale)
    {
        $policy = app(\App\Services\PasswordPolicyService::class);
        try {
            $policy->assertValid($new);
            $policy->assertNotReused($user->id, $new);
        } catch (\InvalidArgumentException $e) {
            return $this->err($e->getMessage());
        }
        if (password_verify($new, (string) $user->password_hash)) {
            return $this->err($locale === 'ar' ? 'اختار كلمة مرور مختلفة عن الحالية.' : 'Choose a password different from the current one.');
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $data = ['password_hash' => $hash];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'tokens_valid_after')) {
            $data['tokens_valid_after'] = now();
        }
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update($data);
        $policy->recordPasswordChange($user->id, $hash); // بيصفّر must_change_password
        return null;
    }

    /** POST /auth/tenant-password-change — الباسورد المؤقت/المنتهي: الحالي + الجديد (جوّه المنصة بس). */
    public function changePassword(\Illuminate\Http\Request $request)
    {
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        if (!TenantContext::active()) {
            return $this->err('Not found.', 404, 'not_found');
        }
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), ['email' => 'required|email', 'current_password' => 'required|string', 'password' => 'required|string|min:6|max:100']);
        if ($v->fails()) {
            return $this->err($v->errors()->first());
        }
        $user = \App\Models\User::where('email', $request->input('email'))->first();
        if (!$user || !password_verify((string) $request->input('current_password'), (string) $user->password_hash)) {
            if ($user) {
                app(\App\Services\AccountLockoutService::class)->registerFailedAttempt($user, $request->ip(), $locale);
            }
            return $this->err($locale === 'ar' ? 'الإيميل أو كلمة المرور الحالية غلط.' : 'Invalid email or current password.');
        }
        if (!app(\App\Services\PasswordPolicyService::class)->expiryStatus($user->id)['expired']) {
            return $this->err($locale === 'ar' ? 'مفيش حاجة محتاجة تغيير.' : 'Nothing to change.');
        }
        if ($bad = $this->applyPassword($user, (string) $request->input('password'), $locale)) {
            return $bad;
        }
        return response()->json(['success' => true, 'message' => 'OK', 'data' => null, 'errors' => null, 'meta' => (object) []]);
    }

    /** GET /auth/invite?token= — بيانات صاحب الدعوة (للصفحة). */
    public function inviteInfo(\Illuminate\Http\Request $request)
    {
        $u = TenantContext::active() ? PlatformUsers::userFromInvite((string) $request->query('token', '')) : null;
        if (!$u) {
            return $this->err($request->header('X-Locale', 'en') === 'ar' ? 'اللينك ده مش شغّال أو اتستخدم قبل كده. اطلب دعوة جديدة.' : 'This link is invalid or was already used. Ask for a new invitation.', 410, 'invite_invalid');
        }
        return response()->json(['success' => true, 'message' => 'OK', 'data' => ['email' => $u->email, 'name' => $u->full_name], 'errors' => null, 'meta' => (object) []]);
    }

    /** POST /auth/invite/accept {token, password} */
    public function inviteAccept(\Illuminate\Http\Request $request)
    {
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), ['token' => 'required|string|max:2000', 'password' => 'required|string|min:6|max:100']);
        if ($v->fails()) {
            return $this->err($v->errors()->first());
        }
        $u = TenantContext::active() ? PlatformUsers::userFromInvite((string) $request->input('token')) : null;
        if (!$u) {
            return $this->err($locale === 'ar' ? 'اللينك ده مش شغّال أو اتستخدم قبل كده.' : 'This link is invalid or was already used.', 410, 'invite_invalid');
        }
        if ($bad = $this->applyPassword($u, (string) $request->input('password'), $locale)) {
            return $bad;
        }
        app(\App\Services\EmailVerificationService::class)->markVerified((int) $u->id);
        return response()->json(['success' => true, 'message' => 'OK', 'data' => ['email' => $u->email], 'errors' => null, 'meta' => (object) []]);
    }

    public function show()
    {
        $s = TenantAuthSettings::active();
        if ($s === null || !TenantContext::active()) {
            $data = ['isolated' => false];
        } else {
            $sec = $s['security'] ?? TenantAuthSettings::securityDefaults();
            $pp = app(\App\Services\PasswordPolicyService::class)->getPolicy();
            $data = [
                'isolated' => true, 'template' => $s['template'], 'headline' => $s['headline'], 'subtitle' => $s['subtitle'],
                // طرق الدخول المتاحة (الفرونت بيظهر الأزرار منها) + قيود الدومين + قواعد الباسورد لصفحات الدعوة/التغيير.
                'methods' => [
                    'magic_link' => !empty($sec['magic_link']),
                    'passkeys' => !empty($sec['passkeys']),
                    'sso' => !empty($sec['oidc']['enabled']),
                    'sso_label' => ($sec['oidc']['label'] ?? '') !== '' ? $sec['oidc']['label'] : null,
                    'saml' => !empty($sec['saml']['enabled']),
                    'saml_label' => ($sec['saml']['label'] ?? '') !== '' ? $sec['saml']['label'] : null,
                ],
                'allowed_domains' => (array) ($sec['allowed_domains'] ?? []),
                'password_rules' => [
                    'min_length' => (int) $pp['min_length'], 'require_upper' => (bool) $pp['require_upper'], 'require_lower' => (bool) $pp['require_lower'],
                    'require_number' => (bool) $pp['require_number'], 'require_special' => (bool) $pp['require_special'],
                ],
            ];
        }
        return response()->json(['success' => true, 'message' => 'OK', 'data' => $data, 'errors' => null, 'meta' => (object) []]);
    }
}
