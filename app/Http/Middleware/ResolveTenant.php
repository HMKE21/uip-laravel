<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;

/**
 * يحدد العميل من الدومين ويحوّل التطبيق لقاعدته.
 *
 * مصدر الدومين: هيدر X-Tenant-Domain لو موجود (الفرونت على Vercel بيبعته لأن الـ Host اللي بيوصل لارافيل مش دومين العميل)،
 * وإلا الـ Host نفسه (لو الدومين بيشاور مباشرة على السيرفر).
 *
 *  - هيدر بدومين مجهول/مش active  => رفض (مش بنرجّع لقاعدة المنصة عشان عميل ما يشوفش دخول UIPE بالغلط).
 *  - دومين المنصة الأصلي أو IP أو Host مجهول من غير هيدر => المنصة الأم من غير أي تبديل (زي ما كان).
 *  - tenant بـ mode=shared (الـ workspaces بتاعة الـ Developer API القديمة) => من غير تبديل.
 *  - tenant بـ mode=isolated => قاعدة بيانات خاصة.
 *
 * بيتسجّل global (append) عشان يغطي web + api + broadcasting، وبعد TrustProxies.
 * التبديل بيفضل لحد نهاية الـ request (بعد terminate) عشان UipActivityLogMiddleware بيكتب بعد الرد.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('tenancy.isolation.enabled', true)) {
            return $next($request);
        }

        $header = trim((string) $request->header('X-Tenant-Domain', ''));
        $fromHeader = $header !== '';
        $host = TenantResolver::normalizeHost($fromHeader ? $header : $request->getHost());

        if ($host === null) {
            return $fromHeader ? $this->reject($request, 400, 'invalid_workspace', 'Invalid workspace domain.') : $next($request);
        }
        if (TenantResolver::isPlatformHost($host)) {
            return $next($request);
        }

        $t = TenantResolver::lookup($host);
        if (!$t) {
            return $fromHeader ? $this->reject($request, 404, 'unknown_workspace', 'This workspace does not exist.') : $next($request);
        }
        if ($t->tenant_status !== 'active' || $t->domain_status !== 'active') {
            return $this->reject($request, 403, 'workspace_unavailable', 'This workspace is not available.');
        }
        if (($t->mode ?? 'shared') !== 'isolated') {
            return $next($request);
        }
        if ($t->db_status !== 'ready' || empty($t->db_name)) {
            return $this->reject($request, 503, 'workspace_not_ready', 'This workspace is not ready yet.');
        }

        TenantContext::activate($t);
        app()->terminating(static fn () => TenantContext::deactivate());
        $request->attributes->set('tenant_id', (int) $t->id);

        return $next($request);
    }

    private function reject(Request $request, int $status, string $code, string $message)
    {
        $respond = fn () => response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => ['code' => $code],
            'meta'    => (object) [],
        ], $status);

        // ResolveTenant بيجي قبل UipCorsMiddleware فبنلفّ الرد بيه عشان المتصفح يقدر يقرا الخطأ.
        return app(UipCorsMiddleware::class)->handle($request, $respond);
    }
}
