<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * uip.scope:tenant   => الـ route شغال جوه عميل بس (وإلا 404).
 * uip.scope:platform => الـ route شغال على المنصة الأم بس (أدمن العميل مينفعش يوصله).
 */
class UipTenantScopeMiddleware
{
    public function handle(Request $request, Closure $next, string $scope = 'platform')
    {
        $ok = $scope === 'tenant' ? TenantContext::active() : !TenantContext::active();
        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'Not found.', 'data' => null, 'errors' => null, 'meta' => (object) []], 404);
        }
        return $next($request);
    }
}
