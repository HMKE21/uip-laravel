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

    public function site(Request $r, TenantSiteService $site)
    {
        // كل القراءة من قاعدة المنصة الأم حتى لو ResolveTenant حوّل الـ connection لقاعدة العميل (X-Tenant-Slug / X-Tenant-Domain).
        return TenantContext::runCentral(function () use ($r, $site) {
            $slug = strtolower(trim((string) $r->query('slug', '')));
            $t = null;
            if ($slug !== '') {
                if (TenantAddress::enabled() && preg_match(TenantAddress::PATTERN, $slug) === 1) {
                    $x = TenantResolver::lookupSlug($slug);
                    $t = ($x && $x->tenant_status === 'active') ? \Illuminate\Support\Facades\DB::table('tenants')->where('id', $x->id)->first() : null;
                }
            } else {
                $t = TenantDomainService::resolve((string) ($r->query('domain') ?: $r->getHost()));
            }
            if (!$t) {
                return $this->apiError('No workspace uses this address.', null, 404);
            }
            $preview = (string) $r->query('preview', '');
            $data = $preview !== '' ? $site->previewPayload($t, $preview) : $site->publicPayload($t);
            if ($data === null) {
                return $this->apiError('This preview link is not valid.', null, 404);
            }
            return response()->json(['success' => true, 'message' => 'OK', 'data' => $data, 'errors' => null, 'meta' => (object) []], 200,
                ['Cache-Control' => $preview !== '' ? 'no-store' : 'public, max-age=60', 'Access-Control-Allow-Origin' => '*']);
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
