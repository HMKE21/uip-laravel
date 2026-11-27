<?php

namespace App\Http\Middleware;

use App\Services\SecurityDetectionService;
use Closure;
use Illuminate\Http\Request;

/**
 * يغذّي محرك الكشف بأحداث "تحميل ملف" (قاعدة mass_download). بيراقب بس — مبيغيّرش الرد أبدًا،
 * والتسجيل بيتم بعد إرسال الرد (terminating) فمفيش أثر على السرعة، وأي فشل بيتبلع.
 *
 * بيعدّ GET ناجح على روت uri بتاعه بينتهي بـ /download أو /download-url، من مستخدم مسجّل دخول،
 * ومبيعدّش صور البروفايل/البوستات/الشات/الـ AI (بتتحمّل تلقائيًا كصور فتبوّظ العدّ).
 */
class UipDetectionTapMiddleware
{
    private const EXCLUDE = ['profile-photos', 'posts', 'messaging', 'ai-assistant', 'stories', 'avatars'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            if (!$request->isMethod('GET')) {
                return $response;
            }
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            if ($status < 200 || $status >= 400) {
                return $response;
            }
            $route = $request->route();
            $uri = $route && method_exists($route, 'uri') ? (string) $route->uri() : '';
            if ($uri === '' || !preg_match('#/download(-url)?(/|$)#', '/' . $uri)) {
                return $response;
            }
            foreach (self::EXCLUDE as $frag) {
                if (str_contains($uri, $frag)) {
                    return $response;
                }
            }
            $userId = (int) $request->attributes->get('uip_user_id');
            if (!$userId) {
                return $response;
            }

            $bytes = null;
            if (isset($response->headers) && $response->headers->has('Content-Length')) {
                $bytes = (int) $response->headers->get('Content-Length');
            }
            $payload = [
                'user_id' => $userId,
                'ip'      => $request->ip(),
                'bytes'   => $bytes,
                'meta'    => ['route' => $uri],
            ];
            app()->terminating(function () use ($payload) {
                try {
                    app(SecurityDetectionService::class)->record('file_download', $payload);
                } catch (\Throwable) {
                }
            });
        } catch (\Throwable) {
            // مراقبة بس
        }

        return $response;
    }
}
