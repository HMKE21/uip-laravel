<?php

namespace App\Http\Middleware;

use App\Services\Developer\DevAuthException;
use App\Services\Developer\DevLogService;
use App\Services\Developer\DeveloperAuthService;
use Closure;
use Illuminate\Http\Request;

/**
 * Developer API (/api/dev/v1/*): Bearer بمفتاح API أو OAuth access token. بيحط في الريكوست:
 *   dev_ctx (سياق الاعتماد) و uip_user_id (صاحب الاعتماد). اختياريًا scope مطلوب: ->middleware('dev.auth:mail.read').
 * بيسجّل كل طلب في سجل التكاملات، وبيضيف هيدرز الـ rate limit.
 */
class DevAuthMiddleware
{
    public function __construct(private DeveloperAuthService $auth, private DevLogService $logs)
    {
    }

    private function error(DevAuthException $e)
    {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
        return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null, 'errors' => ['code' => $e->errorCode], 'meta' => (object) []], $status, $e->headers + ['Cache-Control' => 'no-store']);
    }

    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $t0 = microtime(true);
        try {
            $ctx = $this->auth->authenticate($request);
        } catch (DevAuthException $e) {
            return $this->error($e);
        }
        try {
            if ($scope) {
                $this->auth->requireScope($ctx, $scope);
            }
        } catch (DevAuthException $e) {
            $this->logs->fromRequest($request, $e->getCode() ?: 403, $ctx, (int) round((microtime(true) - $t0) * 1000), $e->errorCode, $scope);
            return $this->error($e)->withHeaders(['X-RateLimit-Limit' => (string) $ctx['limit'], 'X-RateLimit-Remaining' => (string) $ctx['remaining']]);
        }

        $request->attributes->set('dev_ctx', $ctx);
        $request->attributes->set('uip_user_id', $ctx['user_id']);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $ctx['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $ctx['remaining']);
        $response->headers->set('X-UIPE-Mode', $ctx['mode']);
        $response->headers->set('Cache-Control', 'no-store');

        $status = $response->getStatusCode();
        $this->logs->fromRequest($request, $status, $ctx, (int) round((microtime(true) - $t0) * 1000), $status >= 400 ? (string) (json_decode((string) $response->getContent(), true)['errors']['code'] ?? 'error') : null, $scope);
        return $response;
    }
}
