<?php

namespace App\Http\Middleware;

use App\Services\SecurityHealthService;
use Closure;
use Illuminate\Http\Request;

/**
 * صحة السيرفر (بند 2): بيعدّ أخطاء 5xx (لكل دقيقة/route/status) عشان قاعدة http_5xx وصفحة Server Health.
 * بيراقب بس — مبيغيّرش الرد، والتسجيل بعد إرسال الرد (terminating)، وأي فشل بيتبلع. الطلب الناجح مبيكلّفش أي استعلام.
 */
class UipHealthTapMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            if ($status >= 500 && $status <= 599) {
                $route = $request->route();
                $uri = $route && method_exists($route, 'uri') ? '/' . ltrim((string) $route->uri(), '/') : null;
                $method = $request->method();
                app()->terminating(function () use ($status, $uri, $method) {
                    try {
                        app(SecurityHealthService::class)->recordError($status, $uri === null ? null : $method . ' ' . $uri);
                    } catch (\Throwable) {
                    }
                });
            }
        } catch (\Throwable) {
            // مراقبة بس
        }

        return $response;
    }
}
