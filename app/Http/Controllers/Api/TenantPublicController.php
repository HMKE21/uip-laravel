<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantAddress;
use App\Services\Tenancy\TenantBranding;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantResolver;
use App\Services\Tenancy\TenantSiteService;
use App\Services\Tenancy\TenantDomainService;
use Illuminate\Http\Request;

/**
 * نقاط عامة (من غير اعتماد) للـ White Label:
 *  GET /api/dev/v1/tenant/branding[?domain=meet.company.com] — هوية الجهة اللي بتخدم الدومين (Host الطلب أو ?domain). من غير أي بيانات داخلية.
 *  GET /api/dev/v1/tenant/site?slug=<slug>|domain=<host>[&preview=<token>] — الموقع المنشور (أو المسودة لحامل توكن المعاينة) + الهوية. من غير بيانات داخلية.
 *  GET /api/dev/v1/tenant/site/seo?slug=|domain=&path=… · GET …/site/sitemap · GET …/site/robots · POST …/site/contact — SEO وخريطة الموقع وrobots ونموذج التواصل.
 *  GET /api/dev/v1/tenant/domain-check?domain=…&token=…      — لـ Caddy on-demand TLS (`ask`): 200 لو الدومين active، وإلا 404. مقفولة لو TENANT_ASK_SECRET فاضي.
 * الاتنين خارج الـ OpenAPI (من غير dev.auth).
 */
class TenantPublicController extends Controller
{
    public function branding(Request $r)
    {
        $host = (string) ($r->query('domain') ?: $r->getHost());
        $t = TenantDomainService::resolve($host);
        if (!$t) {
            return $this->apiError('No workspace uses this domain.', null, 404);
        }
        return response()->json(['success' => true, 'message' => 'OK', 'data' => ['branding' => TenantBranding::publicPayload($t)], 'errors' => null, 'meta' => (object) []], 200,
            ['Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
    }

    /** العميل من slug أو domain (query أو body). لازم يتنادى جوه TenantContext::runCentral. */
    private function locate(Request $r): ?object
    {
        $slug = strtolower(trim((string) $r->input('slug', '')));
        if ($slug !== '') {
            if (TenantAddress::enabled() && preg_match(TenantAddress::PATTERN, $slug) === 1) {
                $x = TenantResolver::lookupSlug($slug);
                return ($x && $x->tenant_status === 'active') ? \Illuminate\Support\Facades\DB::table('tenants')->where('id', $x->id)->first() : null;
            }
            return null;
        }
        return TenantDomainService::resolve((string) ($r->input('domain') ?: $r->getHost()));
    }

    private function json(array $data, array $headers = [])
    {
        return response()->json(['success' => true, 'message' => 'OK', 'data' => $data, 'errors' => null, 'meta' => (object) []], 200, $headers + ['Access-Control-Allow-Origin' => '*']);
    }

    public function site(Request $r, TenantSiteService $site)
    {
        // كل القراءة من قاعدة المنصة الأم حتى لو ResolveTenant حوّل الـ connection لقاعدة العميل (X-Tenant-Slug / X-Tenant-Domain).
        return TenantContext::runCentral(function () use ($r, $site) {
            $t = $this->locate($r);
            if (!$t) {
                return $this->apiError('No workspace uses this address.', null, 404);
            }
            $preview = (string) $r->query('preview', '');
            $data = $preview !== '' ? $site->previewPayload($t, $preview) : $site->publicPayload($t);
            if ($data === null) {
                return $this->apiError('This preview link is not valid.', null, 404);
            }
            return $this->json($data, ['Cache-Control' => $preview !== '' ? 'no-store' : 'public, max-age=60']);
        });
    }

    /** GET /tenant/site/seo?slug=|domain=&path=/blog/my-post[&preview=…] — عنوان/وصف/صورة/canonical/robots للمسار (عربي + إنجليزي). */
    public function seo(Request $r, TenantSiteService $site)
    {
        return TenantContext::runCentral(function () use ($r, $site) {
            $t = $this->locate($r);
            if (!$t) {
                return $this->apiError('No workspace uses this address.', null, 404);
            }
            $preview = (string) $r->query('preview', '');
            $seo = $site->publicSeo($t, (string) $r->query('path', '/'), $preview);
            if ($seo === null) {
                return $this->apiError('This preview link is not valid.', null, 404);
            }
            return $this->json(['seo' => $seo], ['Cache-Control' => $preview !== '' ? 'no-store' : 'public, max-age=60']);
        });
    }

    /** GET /tenant/site/sitemap?slug=|domain= — XML. */
    public function sitemap(Request $r, TenantSiteService $site)
    {
        return TenantContext::runCentral(function () use ($r, $site) {
            $t = $this->locate($r);
            if (!$t) {
                return response('', 404);
            }
            return response($site->publicSitemap($t), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
        });
    }

    /** GET /tenant/site/robots?slug=|domain= — نص robots.txt. */
    public function robots(Request $r, TenantSiteService $site)
    {
        return TenantContext::runCentral(function () use ($r, $site) {
            $t = $this->locate($r);
            if (!$t) {
                return response('', 404);
            }
            return response($site->publicRobots($t), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
        });
    }

    /** POST /tenant/site/contact {slug|domain, name, email, subject?, message, website(honeypot: سيبه فاضي)} — من نموذج التواصل. */
    public function contact(Request $r, TenantSiteService $site)
    {
        $in = $r->validate(['name' => 'required|string|max:200', 'email' => 'required|string|max:300', 'subject' => 'nullable|string|max:300', 'message' => 'required|string|max:5000', 'website' => 'nullable|string|max:200']);
        return TenantContext::runCentral(function () use ($r, $site, $in) {
            $t = $this->locate($r);
            if (!$t) {
                return $this->apiError('No workspace uses this address.', null, 404);
            }
            try {
                return $this->json($site->contact($t, $in, (string) $r->ip()), ['Cache-Control' => 'no-store']);
            } catch (\DomainException $e) {
                return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 422);
            }
        });
    }

    public function domainCheck(Request $r)
    {
        $secret = (string) config('tenancy.domains.ask_secret', '');
        if ($secret === '') {
            return response('', 404);
        }
        if (!hash_equals($secret, (string) $r->query('token', ''))) {
            return response('', 403);
        }
        $d = strtolower(trim((string) $r->query('domain', '')));
        return response('', $d !== '' && TenantDomainService::resolve($d) ? 200 : 404, ['Cache-Control' => 'no-store']);
    }
}
