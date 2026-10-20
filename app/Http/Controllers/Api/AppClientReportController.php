<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ApiTokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/app/client-report — التطبيق (الـ APK) بيبلّغ عن حاجة فشلت عنده *قبل ما توصل للسيرفر*
 * (رفع فيديو مات، المحرر فشل في التصدير...). الطلبات اللي بتوصل بيسجّلها UipAppTokenMiddleware لوحدها؛
 * أما اللي بتموت في الموبايل فمالهاش أي أثر عندنا — ده اللي بيسدّ الفجوة وبيظهر في /admin/mobile/{id}
 * كصف source=client (فلتر "App-side failures").
 *
 * - لازم X-App-Token صالح (غير كده بنتجاهل بهدوء). الـ user بيتحدد لو فيه Bearer (uip.auth.optional).
 * - مفيش بيانات حساسة: اسم/نوع/حجم الملف ورسالة الخطأ وأرقام تشخيص بس. الـ meta محدود الحجم.
 */
class AppClientReportController extends Controller
{
    public function __construct(private ApiTokenRepository $tokens)
    {
    }

    public function store(Request $request)
    {
        $raw = trim((string) $request->header('X-App-Token', ''));
        $token = $raw !== '' ? $this->tokens->findActiveByRawToken($raw) : null;
        if (!$token) {
            return $this->apiSuccess(null, 'Ignored.');
        }

        $kind = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $request->input('kind', 'client_error'))) ?: 'client_error';
        $method = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $request->input('method', 'POST'))) ?: 'POST';
        $path = '/' . ltrim(explode('?', (string) $request->input('path', ''))[0], '/');

        $meta = $request->input('meta');
        $metaJson = is_array($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if (is_string($metaJson) && strlen($metaJson) > 4000) {
            $metaJson = substr($metaJson, 0, 4000);
        }

        $bytes = $request->input('bytes');
        $dur = $request->input('duration_ms');

        try {
            DB::table('api_token_usages')->insert([
                'api_token_id'  => $token->id,
                'user_id'       => $request->attributes->get('uip_user_id') ?: null,
                'method'        => substr($method, 0, 10),
                'path'          => substr($path, 0, 255),
                'route'         => null,
                'status'        => max(0, min(65535, (int) $request->input('status', 0))),
                'request_bytes' => is_numeric($bytes) && $bytes >= 0 ? (int) $bytes : null,
                'duration_ms'   => is_numeric($dur) && $dur >= 0 ? (int) min($dur, 4294967295) : null,
                'content_type'  => null,
                'outcome'       => 'ok',
                'source'        => 'client',
                'error_code'    => substr($kind, 0, 60),
                'error_message' => mb_substr((string) $request->input('message', ''), 0, 500) ?: null,
                'error_detail'  => null,
                'meta'          => $metaJson,
                'ip'            => substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent'    => substr((string) $request->userAgent(), 0, 255) ?: null,
                'client'        => substr((string) $request->header('X-UIP-Client', ''), 0, 40) ?: null,
                'device_id'     => substr((string) $request->header('X-Device-Id', ''), 0, 64) ?: null,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // الأعمدة الجديدة لسه ما اتعملتش (php artisan migrate): نتجاهل بهدوء.
            return $this->apiSuccess(null, 'Reported.');
        }

        // تنبيه الأدمنز (push) بعطل التطبيق نفسه — بنفس منع التكرار.
        app(\App\Services\AppFailureAlertService::class)->alert((int) $token->id, 'app_failure', [
            'failure_kind' => $kind,
            'status'       => (int) $request->input('status', 0),
            'method'       => $method,
            'path'         => $path,
            'user_id'      => $request->attributes->get('uip_user_id') ?: null,
            'user_agent'   => (string) $request->userAgent(),
            'bytes'        => is_numeric($bytes) ? (int) $bytes : null,
            'message'      => (string) $request->input('message', ''),
            'meta_hint'    => is_array($meta) ? (($meta['isVideo'] ?? false) ? 'video' : (string) ($meta['type'] ?? '')) : '',
        ]);

        return $this->apiSuccess(null, 'Reported.');
    }
}
