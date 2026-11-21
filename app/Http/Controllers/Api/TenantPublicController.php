<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantBranding;
use App\Services\Tenancy\TenantDomainService;
use Illuminate\Http\Request;

/**
 * نقاط عامة (من غير اعتماد) للـ White Label:
 *  GET /api/dev/v1/tenant/branding[?domain=meet.company.com] — هوية الجهة اللي بتخدم الدومين (Host الطلب أو ?domain). من غير أي بيانات داخلية.
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
