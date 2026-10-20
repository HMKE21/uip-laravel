<?php

namespace App\Http\Middleware;

use App\Repositories\ApiTokenRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * قفل تطبيق الأندرويد (APK) بـ API token صادر من صفحة /admin/mobile.
 *
 * التطبيق (src/native.js في الفرونت) بيبعت مع كل طلب:
 *   X-UIP-Client: android      ← علامة إن الطلب جاي من التطبيق مش من المتصفح
 *   X-App-Token:  <raw token>  ← التوكن اللي اتبنى جوه الـ APK (VITE_APP_TOKEN)
 *
 * السلوك:
 *  - APP_TOKEN_REQUIRED=false (الافتراضي): مفيش منع. لو التوكن وصل وصالح بس بنسجّل last_used_at
 *    (عشان تتأكد إن التوكن شغال قبل ما تفعّل القفل).
 *  - APP_TOKEN_REQUIRED=true: أي طلب معلَّم كتطبيق من غير توكن صالح (مفقود / غلط / ملغي) → 403
 *    بكود app_token_invalid، والتطبيق بيعرض شاشة "التطبيق غير مصرّح".
 *  - APP_TOKEN_BLOCK_WEBVIEW=true (اختياري): بيعتبر أي Android WebView ("; wv)" في الـ User-Agent)
 *    طلب تطبيق حتى لو مبعتش العلامة، فبيقفل نسخ APK القديمة اللي اتبنت قبل القفل. تحذير: متصفحات
 *    داخل تطبيقات تانية (فيسبوك/إنستجرام/جيميل على أندرويد) بتستخدم WebView كمان فهتتقفل معاها.
 *
 * ملحوظة أمان: التوكن جوه الـ APK ممكن يتستخرج بفك الملف. ده بيمنع النسخ اللي معندهاش توكن
 * والنسخ المعدّلة/القديمة بعد الإلغاء، مش بديل لتسجيل الدخول. الإلغاء من /admin/mobile بيقفل
 * كل APK بنفس التوكن (تأخير أقصى ~60 ثانية بسبب الـ cache).
 */
class UipAppTokenMiddleware
{
    public function __construct(private ApiTokenRepository $tokens)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request); // preflight — بيتعامل معاه UipCorsMiddleware
        }

        if ($request->is('api/v1/app-config')) {
            return $next($request); // this is where the app GETS its token, so it cannot require one
        }

        $startedAt = microtime(true);
        $raw = trim((string) $request->header('X-App-Token', ''));
        $tokenId = $raw !== '' ? $this->activeId($raw) : 0;
        $valid = $tokenId > 0;
        // توكن ملغي بيحاول يدخل: بنسجّله برضه (outcome=revoked) عشان تشوف مين لسه بيستخدمه.
        $revokedId = (!$valid && $raw !== '') ? $this->revokedId($raw) : 0;

        $response = null;
        if (config('site.app_token_required')) {
            $ua = (string) $request->userAgent();
            $isApp = $request->headers->has('X-UIP-Client')
                || (config('site.app_token_block_webview') && str_contains($ua, '; wv)'));

            if ($isApp && !$valid) {
                $response = response()->json([
                    'success' => false,
                    'message' => 'This app build is not authorized. Please install the latest official version.',
                    'data'    => null,
                    'errors'  => null,
                    'meta'    => (object) [],
                    'code'    => 'app_token_invalid',
                ], 403);
            }
        }
        $response ??= $next($request);

        // بعد الرد: وقتها uip.auth يكون خلّص وعارفين مين المستخدم.
        // تقارير التطبيق نفسها (client-report) بيسجّلها الـ controller كصف source=client، فمنكرّرهاش هنا.
        if (($tokenId || $revokedId) && !$request->is('api/v1/app/client-report')) {
            $this->logUsage($request, $response, $tokenId ?: $revokedId, $valid ? 'ok' : 'revoked', $startedAt);
        }

        return $response;
    }

    /** id التوكن الفعال (0 لو مش صالح). بيحدّث last_used_at كل 10 دقايق. */
    private function activeId(string $raw): int
    {
        $hash = hash('sha256', $raw);
        // cache قصير: مفيش DB hit في كل ريكوست، والإلغاء بيسري في أقل من دقيقة.
        $id = (int) Cache::remember('app_token:' . $hash, 60, function () use ($raw) {
            return $this->tokens->findActiveByRawToken($raw)?->id ?? 0;
        });
        if (!$id) {
            Cache::forget('app_token:' . $hash); // ما نكاشيش الرفض، عشان توكن جديد يشتغل فورًا
            return 0;
        }
        if (Cache::add('app_token_seen:' . $id, 1, 600)) {
            DB::table('api_tokens')->where('id', $id)->update(['last_used_at' => now()]);
        }
        return $id;
    }

    private function revokedId(string $raw): int
    {
        $hash = hash('sha256', $raw);
        return (int) Cache::remember('app_token_revoked:' . $hash, 60, function () use ($hash) {
            return (int) (DB::table('api_tokens')->where('token_hash', $hash)->whereNotNull('revoked_at')->value('id') ?? 0);
        });
    }

    /** صف في api_token_usages — فشله ما يوقّفش الطلب أبدًا. بدون query string (ممكن فيها أسرار). */
    private function logUsage(Request $request, $response, int $tokenId, string $outcome, float $startedAt = 0): void
    {
        try {
            $row = [
                'api_token_id' => $tokenId,
                'user_id'      => $request->attributes->get('uip_user_id') ?: null,
                'method'       => substr($request->method(), 0, 10),
                'path'         => substr('/' . ltrim($request->path(), '/'), 0, 255),
                'route'        => ($uri = $request->route()?->uri()) ? substr('/' . ltrim($uri, '/'), 0, 255) : null,
                'status'       => (int) $response->getStatusCode(),
                'outcome'      => $outcome,
                ...$this->errorInfo($response),
                'ip'           => substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent'   => substr((string) $request->userAgent(), 0, 255) ?: null,
                'client'       => substr((string) $request->header('X-UIP-Client', ''), 0, 40) ?: null,
                'device_id'    => substr((string) $request->header('X-Device-Id', ''), 0, 64) ?: null,
                'created_at'   => now(),
        ];

        // تشخيص: حجم الطلب + مدة المعالجة + نوع المحتوى (من غير الـ boundary).
            $diag = [
                'request_bytes' => ($len = $request->header('Content-Length')) !== null && ctype_digit((string) $len) ? (int) $len : null,
                'duration_ms'   => $startedAt > 0 ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                'content_type'  => ($ct = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]))) !== '' ? substr($ct, 0, 80) : null,
                'source'        => 'server',
            ];
            try {
                DB::table('api_token_usages')->insert($row + $diag);
            } catch (\Throwable $e) {
                DB::table('api_token_usages')->insert($row); // الأعمدة الجديدة لسه ما اتعملتش (php artisan migrate)
            }

            // تنبيه الأدمنز على الأعطال المهمة (5xx، أو رفع ملف/فيديو فاشل) — push على الموبايل. بيتعمل بعد ما الصف اتسجّل.
            if ($outcome === 'ok' && (int) $response->getStatusCode() >= 400) {
                $this->maybeAlert($request, $response, $tokenId, $row, $diag);
            }

            // تنضيف تلقائي: 1% من الطلبات بتمسح السجل الأقدم من المدة المحددة.
            if (random_int(1, 100) === 1) {
                $days = max(1, (int) config('site.app_token_log_days', 60));
                DB::table('api_token_usages')->where('created_at', '<', now()->subDays($days))->delete();
            }
        } catch (\Throwable $e) {
            // التسجيل ثانوي: لو الجدول لسه ما اتعملوش (migration) أو حصل خطأ، الطلب يكمل عادي.
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $diag */
    private function maybeAlert(Request $request, $response, int $tokenId, array $row, array $diag): void
    {
        try {
            $status = (int) $response->getStatusCode();
            $multipart = str_contains((string) ($diag['content_type'] ?? ''), 'multipart');
            $kind = $status >= 500 ? 'server_error' : ($multipart && !in_array($status, [401, 403, 404, 428, 429], true) ? 'upload_error' : null);
            if (!$kind) {
                return;
            }
            app(\App\Services\AppFailureAlertService::class)->alert($tokenId, $kind, [
                'status'     => $status,
                'method'     => $row['method'],
                'path'       => $row['route'] ?? $row['path'],
                'route'      => $row['route'],
                'user_id'    => $row['user_id'],
                'user_agent' => $row['user_agent'],
                'bytes'      => $diag['request_bytes'] ?? null,
                'message'    => $row['error_message'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // التنبيه ثانوي.
        }
    }

    /**
     * سبب الفشل لطلبات status >= 400: message/code من الـ JSON، وللـ 5xx اسم الـ exception ومكانه.
     * (الـ response هنا لسه ما اتشفّرتش — UipResponseEncryptionMiddleware أبعد منّنا.)
     * مفيش قيم حساسة: QueryException بنسجّل رسالة الـ driver بس (من غير الـ SQL والـ bindings).
     *
     * @return array{error_code:?string,error_message:?string,error_detail:?string}
     */
    private function errorInfo($response): array
    {
        $out = ['error_code' => null, 'error_message' => null, 'error_detail' => null];
        if ((int) $response->getStatusCode() < 400) {
            return $out;
        }
        try {
            $ct = (string) $response->headers->get('Content-Type', '');
            $raw = str_contains($ct, 'json') ? $response->getContent() : null;
            if (is_string($raw) && $raw !== '' && strlen($raw) < 100000) {
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    $out['error_code'] = isset($j['code']) && is_scalar($j['code']) ? (string) $j['code'] : null;
                    $msg = isset($j['message']) && is_scalar($j['message']) ? (string) $j['message'] : null;
                    if (!empty($j['errors']) && is_array($j['errors'])) {
                        $flat = [];
                        array_walk_recursive($j['errors'], function ($v, $k) use (&$flat) {
                            if (is_scalar($v)) {
                                $flat[] = (is_string($k) ? $k . ': ' : '') . $v;
                            }
                        });
                        if ($flat) {
                            $msg = trim(($msg ?? '') . ' — ' . implode(' | ', array_slice($flat, 0, 5)), ' —');
                        }
                    }
                    $out['error_message'] = $msg;
                }
            }

            $ex = $response->exception ?? null;
            if ($ex instanceof \Throwable) {
                $file = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $ex->getFile());
                $out['error_detail'] = get_class($ex) . ' @ ' . $file . ':' . $ex->getLine();
                $exMsg = $ex instanceof \Illuminate\Database\QueryException
                    ? (string) ($ex->errorInfo[2] ?? 'Database error')
                    : $ex->getMessage();
                if ($exMsg !== '' && (!$out['error_message'] || $out['error_message'] === 'Server Error')) {
                    $out['error_message'] = $exMsg;
                }
            }
        } catch (\Throwable $e) {
            // استخراج السبب ثانوي.
        }

        foreach (['error_code' => 60, 'error_message' => 500, 'error_detail' => 255] as $k => $max) {
            $out[$k] = ($out[$k] !== null && $out[$k] !== '') ? mb_substr((string) $out[$k], 0, $max) : null;
        }
        return $out;
    }
}
