<?php

namespace App\Http\Middleware;

use App\Services\ContentBlockedException;
use App\Services\ContentSafetyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * خط الدفاع المركزي: أي كتابة (POST/PUT/PATCH) على /api بتتفحص قبل ما توصل لأي controller.
 *
 *  - كل النصوص في الـ body/query (JSON + form): قاموس إباحي/مخلّ — الأسماء والعناوين القصيرة بطبقة أشد.
 *  - كل الملفات المرفوعة: اسم الملف + المحتوى (صورة/فيديو/GIF عبر مزوّد الفحص).
 *  - أي data:image مدفوعة جوه نص (محرر الوورد، الستوري…).
 *
 * المخالفة بترجّع 422 { errors.code = 'content_not_allowed' } برسالة عربي/إنجليزي، وبتتسجّل للأدمن.
 * مستثنى: المسارات في config('content_safety.skip_paths') (مصادقة، إشارات مكالمات، إجابات الامتحان، تشغيل كود…).
 * الرفع بالأجزاء (chunked) بيتفحص عند التجميع في ChunkedUploadService::open().
 */
class UipContentSafetyMiddleware
{
    private const MAX_FIELDS = 4000;
    private const MAX_DEPTH = 8;

    public function __construct(private ContentSafetyService $safety)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->safety->enabled() || $request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return $next($request);
        }
        $path = trim($request->path(), '/');
        foreach ((array) config('content_safety.skip_paths', []) as $pattern) {
            if (fnmatch($pattern, $path)) {
                return $next($request);
            }
        }

        try {
            $count = 0;
            $this->scanValue($request->except(array_keys($request->allFiles())), '', 0, $count);

            $scanMedia = true;
            foreach ((array) config('content_safety.skip_image_paths', []) as $pattern) {
                if (fnmatch($pattern, $path)) {
                    $scanMedia = false;
                    break;
                }
            }
            foreach ($this->flattenFiles($request->allFiles()) as $file) {
                $this->safety->assertUploadSafe($file, $scanMedia);
            }
        } catch (ContentBlockedException $e) {
            // نفس الشكل في كل مكان: errors = { code, category, kind, reason } والـ message فيها سبب الحظر.
            return $e->render($request);
        }

        return $next($request);
    }

    private function scanValue($value, string $key, int $depth, int &$count): void
    {
        if ($depth > self::MAX_DEPTH || $count > self::MAX_FIELDS) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $name = is_string($k) ? $k : $key;
                if (is_string($k) && preg_match((string) config('content_safety.skip_fields'), $k)) {
                    continue;
                }
                $this->scanValue($v, $name, $depth + 1, $count);
            }

            return;
        }
        if (!is_string($value) || strlen($value) < 2) {
            return;
        }
        $count++;
        if (stripos($value, 'data:image/') !== false) {
            $this->safety->assertDataImagesIn($value);
        }
        // blob base64 طويل (مش كلام). ⚠️ لازم يكون من غير مسافات/تاب (الـ base64 بيتقسّم بس بأسطر جديدة) — قبل كده أي رسالة
        // طويلة حروف وأرقام ومسافات بس كانت بتعدّي من الفحص بالكامل (تحايل: نص +2000 حرف من غير علامات ترقيم).
        if (strlen($value) > 2000 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', substr($value, 0, 4000))) {
            return;
        }
        $strict = $key !== '' && preg_match((string) config('content_safety.strict_fields'), $key) === 1;
        $this->safety->assertText($value, $strict, 'text');
    }

    /** @return UploadedFile[] */
    private function flattenFiles(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            if ($f instanceof UploadedFile) {
                $out[] = $f;
            } elseif (is_array($f)) {
                $out = array_merge($out, $this->flattenFiles($f));
            }
        }

        return $out;
    }
}
