<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\KeycloakIdTokenVerifier;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * الدخول بحساب UIPE Identity (Keycloak realm "uipe").
 *  GET  /auth/keycloak/config   { enabled, client_id, issuer }   (عام، مفيش secret)
 *  POST /auth/keycloak          { id_token }
 * الفرونت بيعمل Authorization Code + PKCE على client عام (uipe-web) والسيرفر بيتحقق من الـ ID token.
 * بعد كده نفس مسار جوجل/مايكروسوفت: GoogleAuthController::handleVerified() (دخول / ربط بالإيميل / تسجيل طالب / 2FA / الجهاز / الصيانة).
 * الـ sub بيتخزن في user_social_accounts (provider = keycloak).
 */
class KeycloakAuthController extends Controller
{
    public function __construct(private KeycloakIdTokenVerifier $keycloak, private GoogleAuthController $social)
    {
    }

    public function config()
    {
        $on = $this->keycloak->enabled();
        return response()->json([
            'success' => true, 'message' => 'OK',
            'data'    => ['enabled' => $on, 'client_id' => $on ? $this->keycloak->clientId() : null, 'issuer' => $on ? $this->keycloak->issuer() : null],
            'errors'  => null, 'meta' => (object) [],
        ]);
    }

    public function submit(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $locale = $ar ? 'ar' : 'en';
        $v = Validator::make($request->all(), ['id_token' => 'required|string|max:8192']);
        if ($v->fails()) {
            return $this->fail($v->errors()->first(), 422);
        }
        if (!$this->keycloak->enabled()) {
            return $this->fail($ar ? 'الدخول بحساب UIPE Identity غير مفعّل.' : 'UIPE Identity sign-in is not enabled.', 404);
        }

        $k = $this->keycloak->verify((string) $request->input('id_token'));
        if (!$k) {
            $why = $this->keycloak->lastError;
            SecurityLog::write('Keycloak sign-in rejected', ['ip' => $request->ip(), 'reason' => $why]);
            if ($why === 'email_unverified') {
                return $this->fail($ar ? 'إيميل حساب UIPE Identity ده لسه متأكدش. أكّده الأول وجرّب تاني.' : 'The email on this UIPE Identity account is not verified yet. Verify it first, then try again.', 422, ['code' => 'email_unverified']);
            }
            if ($why === 'no_email') {
                return $this->fail($ar ? 'حساب UIPE Identity ده مفيهوش إيميل.' : 'This UIPE Identity account has no email address.', 422, ['code' => 'no_email']);
            }
            return $this->fail($ar ? 'مقدرناش نتأكد من حساب UIPE Identity. جرّب تاني.' : "We couldn't verify your UIPE Identity account. Please try again.", 401);
        }

        $k['provider'] = 'keycloak';
        return $this->social->handleVerified($request, $k, $locale);
    }

    private function fail(string $message, int $status, ?array $errors = null)
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors, 'meta' => (object) []], $status);
    }
}
