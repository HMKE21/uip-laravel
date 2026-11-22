<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMailBrand;
use App\Services\Tenancy\TenantSaml;
use App\Services\Tenancy\TenantSecurity;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * الدخول الموحّد (SSO) بـ SAML 2.0 لمنصة معزولة (Entra ID / Okta / ADFS / OneLogin / Google Workspace / Keycloak…). SP-initiated بس.
 *
 *  GET  /auth/tenant-saml/config           { enabled, label }                      (عام)
 *  POST /auth/tenant-saml/start            → { redirect_url, state }               الفرونت يحوّل المتصفح لـ redirect_url ويحتفظ بـ state (sessionStorage)
 *  POST /auth/tenant-saml/acs/{tenantId}   ← الـ IdP بيعمل POST لهنا من المتصفح (form: SAMLResponse). بيتحقق ويحوّل المتصفح لـ
 *                                            {front}/sso/saml?code=…  (أو ?error=<code>)
 *  POST /auth/tenant-saml/exchange         { code, state } → نفس رد الدخول بالجوجل/OIDC (handleVerified → finishLogin بكل الحراسات)
 *  GET  /auth/tenant-saml/metadata/{id}    SP metadata (XML) للصقها عند الـ IdP
 *
 * ليه code + state؟ الـ ACS بيوصله POST من الـ IdP من غير هيدر X-Tenant-Domain، فبيحدد المنصة من الـ id في الرابط،
 * وبعد التحقق بنسلّم الهوية للفرونت بكود لمرة واحدة (دقيقتين) مربوط بـ state اللي طلبه نفس المتصفح (يمنع login-CSRF).
 */
class TenantSamlController extends Controller
{
    private const REQ_TTL = 600;
    private const CODE_TTL = 120;

    public function __construct(private TenantSaml $saml, private GoogleAuthController $social, private LoginController $login)
    {
    }

    private function fail(string $message, int $status, ?array $errors = null)
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors, 'meta' => (object) []], $status);
    }

    /** @return array<string,mixed>|null */
    private function cfg(?array $sec): ?array
    {
        $s = $sec['saml'] ?? null;
        return (is_array($s) && !empty($s['enabled']) && ($s['idp_entity_id'] ?? '') !== '' && ($s['sso_url'] ?? '') !== '' && ($s['certificate'] ?? '') !== '') ? $s : null;
    }

    public function config()
    {
        $s = TenantContext::active() ? $this->cfg(TenantSecurity::current()) : null;
        return response()->json([
            'success' => true, 'message' => 'OK',
            'data' => $s ? ['enabled' => true, 'label' => ($s['label'] ?? '') !== '' ? $s['label'] : null] : ['enabled' => false],
            'errors' => null, 'meta' => (object) [],
        ]);
    }

    public function start(Request $request)
    {
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        $ar = $locale === 'ar';
        $s = TenantContext::active() ? $this->cfg(TenantSecurity::current()) : null;
        if (!$s) {
            return $this->fail($ar ? 'الدخول الموحّد (SAML) غير مفعّل.' : 'SAML single sign-on is not enabled.', 404, ['code' => 'sso_disabled']);
        }
        if (TenantSecurity::ipBlocked($request->ip(), null, true)) {
            return $this->login->tenantBlock($request, null, 'ip_not_allowed', $locale);
        }
        $tid = (int) TenantContext::id();
        [$id, $url] = TenantSaml::authnRequest((string) $s['sso_url'], TenantSaml::acsUrl($tid), TenantSaml::entityId($tid));
        $state = Str::random(40);
        Cache::put('saml:req:' . $id, ['state' => hash('sha256', $state)], self::REQ_TTL);
        return response()->json(['success' => true, 'message' => 'OK', 'data' => ['redirect_url' => $url, 'state' => $state], 'errors' => null, 'meta' => (object) []]);
    }

    /** الـ IdP بيعمل POST لهنا من متصفح المستخدم. مفيش tenant header: المنصة من الـ id في الرابط. */
    public function acs(Request $request, int $tenantId)
    {
        $t = TenantContext::findIsolated($tenantId);
        if (!$t) {
            return response('Not found', 404)->header('Cache-Control', 'no-store');
        }
        return TenantContext::run($t, fn () => $this->handleAcs($request, $tenantId));
    }

    private function back(string $front, array $q)
    {
        return redirect()->away($front . '/sso/saml?' . http_build_query($q), 303)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function handleAcs(Request $request, int $tid)
    {
        $front = TenantMailBrand::frontendUrl($tid);
        if (!$front) {
            return response('Workspace has no address yet.', 404)->header('Cache-Control', 'no-store');
        }
        $s = $this->cfg(TenantSecurity::forTenant($tid));
        if (!$s) {
            return $this->back($front, ['error' => 'sso_disabled']);
        }
        $raw = $request->input('SAMLResponse');
        $res = is_string($raw) ? $this->saml->verify($raw, $s, TenantSaml::acsUrl($tid), TenantSaml::entityId($tid)) : null;
        if (!$res) {
            $why = $this->saml->lastError ?? 'invalid';
            SecurityLog::write('Tenant SAML sign-in rejected', ['ip' => $request->ip(), 'tenant' => $tid, 'reason' => $why, 'detail' => $this->saml->lastDetail]);
            return $this->back($front, ['error' => in_array($why, ['idp_denied', 'no_email', 'time'], true) ? $why : 'invalid']);
        }
        // لازم نكون إحنا اللي طلبنا الدخول ده (SP-initiated بس) ومرة واحدة.
        $req = Cache::pull('saml:req:' . $res['in_response_to']);
        if (!is_array($req) || empty($req['state'])) {
            SecurityLog::write('Tenant SAML sign-in rejected', ['ip' => $request->ip(), 'tenant' => $tid, 'reason' => 'unsolicited_or_expired']);
            return $this->back($front, ['error' => 'expired']);
        }
        // نفس الـ Assertion متتقبلش مرتين.
        $ttl = max(300, min(86400, (int) $res['expires'] - time() + 300));
        if (!Cache::add('saml:asrt:' . hash('sha256', $res['assertion_id']), 1, $ttl)) {
            SecurityLog::write('Tenant SAML replay rejected', ['ip' => $request->ip(), 'tenant' => $tid]);
            return $this->back($front, ['error' => 'invalid']);
        }
        $code = Str::random(48);
        Cache::put('saml:code:' . hash('sha256', $code), [
            'state' => $req['state'],
            'k' => ['sub' => $res['sub'], 'email' => $res['email'], 'name' => $res['name'], 'picture' => null],
        ], self::CODE_TTL);
        return $this->back($front, ['code' => $code]);
    }

    public function exchange(Request $request)
    {
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        $ar = $locale === 'ar';
        $s = TenantContext::active() ? $this->cfg(TenantSecurity::current()) : null;
        if (!$s) {
            return $this->fail($ar ? 'الدخول الموحّد (SAML) غير مفعّل.' : 'SAML single sign-on is not enabled.', 404, ['code' => 'sso_disabled']);
        }
        $v = Validator::make($request->all(), ['code' => 'required|string|min:20|max:100', 'state' => 'required|string|min:20|max:100']);
        if ($v->fails()) {
            return $this->fail($v->errors()->first(), 422);
        }
        $d = Cache::pull('saml:code:' . hash('sha256', (string) $request->input('code')));
        if (!is_array($d) || !is_array($d['k'] ?? null) || !hash_equals((string) ($d['state'] ?? ''), hash('sha256', (string) $request->input('state')))) {
            SecurityLog::write('Tenant SAML exchange rejected', ['ip' => $request->ip()]);
            return $this->fail($ar ? 'انتهت صلاحية محاولة الدخول. جرّب تاني.' : 'This sign-in attempt expired. Please try again.', 401, ['code' => 'expired']);
        }
        $k = $d['k'];
        $k['provider'] = 'saml';
        return $this->social->handleVerified($request, $k, $locale);
    }

    /** SP metadata (عام، مفيش أسرار). */
    public function metadata(int $tenantId)
    {
        if (!TenantContext::findIsolated($tenantId)) {
            return response('Not found', 404);
        }
        return response(TenantSaml::spMetadata(TenantSaml::entityId($tenantId), TenantSaml::acsUrl($tenantId)), 200)
            ->header('Content-Type', 'application/samlmetadata+xml; charset=UTF-8');
    }
}
