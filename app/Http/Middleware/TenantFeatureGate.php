<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * يقفل أي route لميزة مش مفعّلة للعميل. بيشتغل على العملاء المعزولين بس (على المنصة الأم مبيعملش حاجة).
 * بيجي بعد ResolveTenant في الـ global stack. المسارات غير الـ /api (health, assets) وطلبات OPTIONS (preflight) بتعدّي.
 */
class TenantFeatureGate
{
    public function handle(Request $request, Closure $next)
    {
        if (!TenantContext::active() || $request->isMethod('OPTIONS')) {
            return $next($request);
        }
        $p = TenantFeatures::normalizePath($request->path());
        if ($p === null) {
            return $next($request);
        }

        $owner = TenantFeatures::classify($p);
        if ($owner === 'core') {
            return $next($request);
        }
        if ($owner === null) {
            if (config('tenant_features.unmapped', 'deny') === 'allow') {
                return $next($request);
            }
            Log::notice('Tenant gate: unmapped path denied', ['tenant' => TenantContext::id(), 'path' => $request->path()]);
            return $this->deny($request, 'feature_not_available', 'This service is not available for this workspace.', null);
        }
        if (TenantFeatures::isEnabled((int) TenantContext::id(), $owner)) {
            return $next($request);
        }
        return $this->deny($request, 'feature_not_enabled', 'This feature is not enabled for this workspace.', $owner);
    }

    private function deny(Request $request, string $code, string $message, ?string $feature)
    {
        $respond = fn () => response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => ['code' => $code, 'feature' => $feature],
            'meta'    => (object) [],
        ], 403);

        // بنلفّ الرد بـ CORS عشان الفرونت يقدر يقرا الخطأ (الـ gate بيجي قبل UipCorsMiddleware).
        return app(UipCorsMiddleware::class)->handle($request, $respond);
    }
}
