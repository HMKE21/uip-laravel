<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantAddress;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMailBrand;
use App\Services\Tenancy\TenantOidc;
use App\Services\Tenancy\TenantOidcVerifier;
use App\Services\Tenancy\TenantSecurity;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * الدخول الموحّد (SSO) بـ OIDC لمنصة معزولة — Entra ID / Okta / Keycloak / Auth0 / Google Workspace…
 *  GET  /auth/tenant-oidc/config  { enabled, label, client_id, issuer, authorization_endpoint, token_endpoint, response_types, scope }  (عام، مفيش secret)
 *  POST /auth/tenant-oidc         { id_token, nonce }
 * الفرونت بيعمل Authorization Code + PKCE (لو الـ IdP بيسمح بـ CORS على token endpoint) أو id_token flow بـ nonce،
 * والسيرفر بيتحقق من الـ ID token بنفسه. بعد كده نفس مسار جوجل (GoogleAuthController::handleVerified → finishLogin بنفس كل الحراسات).
 */
class TenantOidcController extends Controller
{
    public function __construct(private TenantOidcVerifier $verifier, private GoogleAuthController $social)
    {
    }

    private function fail(string $message, int $status, ?array $errors = null)
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors, 'meta' => (object) []], $status);
    }

    /** @return array<string,mixed>|null */
    private function oidc(): ?array
    {
        $s = TenantSecurity::current();
        $o = $s['oidc'] ?? null;
        return (is_array($o) && !empty($o['enabled']) && $o['issuer'] !== '' && $o['client_id'] !== '') ? $o : null;
    }

    public function config()
    {
        $o = $this->oidc();
        $d = $o ? TenantOidc::discover((string) $o['issuer']) : null;
        if (!$o || !$d) {
            return response()->json(['success' => true, 'message' => 'OK', 'data' => ['enabled' => false], 'errors' => null, 'meta' => (object) []]);
        }
        return response()->json([
            'success' => true, 'message' => 'OK',
            'data' => [
                'enabled' => true,
                'label' => $o['label'] !== '' ? $o['label'] : null,
                'client_id' => $o['client_id'],
                'issuer' => $o['issuer'],
                'authorization_endpoint' => $d['authorization_endpoint'],
                'token_endpoint' => $d['token_endpoint'],
                'response_types' => $d['response_types_supported'],
                'scope' => 'openid email profile',
            ],
            'errors' => null, 'meta' => (object) [],
        ]);
    }

    public function submit(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $locale = $ar ? 'ar' : 'en';
        $o = TenantContext::active() ? $this->oidc() : null;
        if (!$o) {
            return $this->fail($ar ? 'الدخول الموحّد (SSO) غير مفعّل.' : 'Single sign-on is not enabled.', 404, ['code' => 'sso_disabled']);
        }
        $v = Validator::make($request->all(), ['id_token' => 'required|string|max:8192', 'nonce' => 'nullable|string|max:200']);
        if ($v->fails()) {
            return $this->fail($v->errors()->first(), 422);
        }

        $k = $this->verifier->verify((string) $request->input('id_token'), (string) $o['issuer'], (string) $o['client_id'], $request->input('nonce'));
        if (!$k) {
            $why = $this->verifier->lastError;
            SecurityLog::write('Tenant SSO sign-in rejected', ['ip' => $request->ip(), 'reason' => $why]);
            if ($why === 'email_unverified') {
                return $this->fail($ar ? 'إيميل حساب الشركة ده لسه متأكدش عند مزوّد الدخول.' : 'The email on this account is not verified at your identity provider.', 422, ['code' => 'email_unverified']);
            }
            if ($why === 'no_email') {
                return $this->fail($ar ? 'حساب الشركة ده مفيهوش إيميل. اطلب من مسؤول الـ IdP يضيف claim الإيميل.' : 'This account has no email. Ask your identity-provider admin to include the email claim.', 422, ['code' => 'no_email']);
            }
            return $this->fail($ar ? 'مقدرناش نتأكد من حساب الدخول الموحّد. جرّب تاني.' : "We couldn't verify your single sign-on account. Please try again.", 401);
        }

        $k['provider'] = 'oidc';
        return $this->social->handleVerified($request, $k, $locale);
    }
}
