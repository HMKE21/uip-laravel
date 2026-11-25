<?php

/**
 * ⚠️ ده مش ملف bootstrap/app.php كامل — ده الجزء اللي لازم تضيفه/تدمجه
 * جوه ملف bootstrap/app.php بتاع مشروعك (اللي composer create-project
 * لارافيل بيعمله تلقائيًا). متستبدلش الملف بالكامل بيه، لأنه هيبوظ باقي
 * إعدادات الـ providers/exceptions بتاعة لارافيل.
 *
 * الترتيب هنا مهم ويطابق ترتيب القديم بالظبط
 * (app/Middleware/... + routes/api.php سطر 156):
 *   SecurityHeaders -> Cors -> RateLimit -> Locale
 * السبب: SecurityHeaders وCors لازم يلفوا حتى ريسبونس الـ 429 اللي
 * RateLimit بيرجعه (عشان الهيدرز الأمنية وCORS يتحطوا عليه برضو، مش بس
 * على الريسبونسات الناجحة) — فلازم يتسجلوا قبله في السلسلة.
 *
 * uip.auth بيتسجل كـ alias عادي (route-level، زي ما هو)، مش global —
 * ده يطابق AuthMiddleware القديمة اللي كانت بس على المجموعات المحمية.
 */

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Self-healing guard: bootstrap/cache/{packages,services}.php are machine-generated. If a deploy (git pull / reset / upload)
 * brings back copies built on a dev machine that list dev-only packages (e.g. Laravel Pail) missing from this server's vendor/,
 * every request dies with 'Class "Laravel\Pail\PailServiceProvider" not found'. Delete such stale files; Laravel rebuilds them.
 */
(static function (): void {
    $root = dirname(__DIR__);
    foreach (['laravel/pail' => 'Laravel\\\\Pail\\\\'] as $package => $needle) {
        if (is_dir($root . '/vendor/' . $package)) {
            continue; // installed here: the cache is fine
        }
        foreach (['packages', 'services'] as $name) {
            $file = $root . '/bootstrap/cache/' . $name . '.php';
            if (is_file($file) && strpos((string) @file_get_contents($file), $needle) !== false) {
                (new \Illuminate\Filesystem\Filesystem())->delete($file);
            }
        }
    }
})();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // خلف Railway/Vercel/أي reverse proxy: من غير ده $request->ip() بيرجّع
        // IP الـ proxy الداخلي لكل المستخدمين، فصفحة Sessions بتعرض نفس الـ IP
        // للكل، والـ geo-location وعدّاد الـ brute-force والـ IP block كلهم
        // بيشتغلوا على IP غلط. TRUSTED_PROXIES في .env: '*' (الافتراضي، مناسب
        // لما السيرفر مش متاح للإنترنت إلا عبر الـ proxy) أو قائمة IPs/CIDRs
        // مفصولة بفاصلة.
        $trusted = trim((string) env('TRUSTED_PROXIES', '*'));
        $middleware->trustProxies(
            at: $trusted === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trusted))))
        );

        // Multi-tenancy: يحدد العميل من الدومين ويبدّل قاعدة البيانات. global (بعد TrustProxies) عشان يسبق كل middleware بيقرا الـ DB.
        $middleware->append(\App\Http\Middleware\ResolveTenant::class);
        // الخطوة 4: يقفل الميزات اللي مش مفعّلة للعميل (403). لازم بعد ResolveTenant مباشرة.
        $middleware->append(\App\Http\Middleware\TenantFeatureGate::class);

        $middleware->alias([
            // يستبدل throttle الافتراضي: عدّاد مستقل لكل روت/شخص + بيحترم إيقاف الـ Rate Limit من داشبورد الأدمن.
            'throttle'  => \App\Http\Middleware\UipThrottleRequests::class,
            'uip.auth'  => \App\Http\Middleware\UipAuthMiddleware::class,
            // بند 3 — يطابق app/Middleware/AdminMiddleware.php القديمة.
            // لازم يتحط بعد uip.auth في نفس المجموعة (محتاج uip_role
            // اللي uip.auth بيحطها في $request->attributes).
            'uip.admin' => \App\Http\Middleware\UipAdminMiddleware::class,
            'uip.drive' => \App\Http\Middleware\UipDriveAccessMiddleware::class,
            // يقفل الـ route حسب صلاحيات الأدوار (صفحة /admin/roles). لازم يتحط بعد
            // uip.auth. شوف الـ docblock في UipPermissionMiddleware للصيغة.
            'uip.can' => \App\Http\Middleware\UipPermissionMiddleware::class,
            // قفل الامتحان: يمنع الـ AI Assistant طول ما الطالب جوّه محاولة شغالة.
            'uip.exam_lock' => \App\Http\Middleware\UipExamLockMiddleware::class,
            // Realtime: pushes a ping to the affected people after every successful write (messaging / drive / notifications).
            'uip.realtime' => \App\Http\Middleware\UipRealtimeMiddleware::class,
            // المشرف/المعيد يستخدم سطح الدكتور (امتحانات/بنوك/مواد) — شوف docblock الميدلوير.
            'uip.staff_alias' => \App\Http\Middleware\UipSupervisorAsStaffMiddleware::class,

            // بند 17 — يطابق app/Middleware/FeedCommentRateLimitMiddleware.php
            // القديمة. غطا إضافي فوق UipRateLimitMiddleware العام (اللي
            // على مستوى الـ IP/user لكل الـ API)، مقيّد بس على
            // POST /feed/{id}/comments. لازم يتحط بعد uip.auth في نفس
            // المجموعة (محتاج uip_user_id اللي uip.auth بيحطها).
            'feed.comment_rate_limit' => \App\Http\Middleware\FeedCommentRateLimitMiddleware::class,

            // Meetings Round 2 (Lobby & Access, بند 23 — Guest Access).
            // نسخة اختيارية من uip.auth: بتحط uip_user_id لو فيه Bearer
            // صالح، وبتكمل عادي من غير فشل لو مفيش (ضيف من غير حساب UIP).
            // مقصورة على prefix('meetings/join') بس، مش global.
            'uip.auth.optional' => \App\Http\Middleware\UipAuthOptionalMiddleware::class,
            // Developer API (المرحلة 6): Bearer بمفتاح API (uipk_…) أو OAuth access token (uipo_…) + scope اختياري: dev.auth:mail.read
            'dev.auth' => \App\Http\Middleware\DevAuthMiddleware::class,
            // UIPE Mail (المرحلة 7): قواعد IP (block/allow) على /api/v1/mail/*. بعد uip.auth.
            'mail.ip' => \App\Http\Middleware\UipMailIpMiddleware::class,
            'mail.access' => \App\Http\Middleware\UipMailAccessMiddleware::class,
            // uip.scope:tenant (جوه عميل بس) | uip.scope:platform (المنصة الأم بس) — شوف docblock الميدلوير.
            'uip.scope' => \App\Http\Middleware\UipTenantScopeMiddleware::class,
        ]);

        // المكالمات: الـ SDP لازم ينتهي بـ \r\n. TrimStrings الافتراضي كان بيشيله فالمستلم يرفض الـ offer
        // والمكالمة تفشل. بنستثني حقل payload بتاع /messaging/calls/{id}/signal (الفرونت كمان بيصلّح الـ SDP).
        $middleware->trimStrings(except: ['payload', 'payload.*', 'payload.sdp', 'sessionDescription', 'sessionDescription.*', 'sessionDescription.sdp']);

        // بند 2 — نفس ترتيب القديم بالظبط. prepend() يحطهم أول الـ 'api'
        // middleware group (اللي routes/api.php محمّل عليها تلقائيًا في
        // لارافيل 11)، فبيغطوا كل ريكوست تحت /api/* — بما فيه أي OPTIONS
        // preflight لروت مش مسجّل أصلًا (شوف ملحوظة UipCorsMiddleware).
        // UipTrustedClientIpMiddleware لازم يبقى الأول: بيصلّح $request->ip() (الـ IP الحقيقي
        // خلف Vercel، بتوقيع HMAC) قبل ما الـ rate limit وغيره يقراه.
        $middleware->api(prepend: [
            \App\Http\Middleware\UipTrustedClientIpMiddleware::class,
            \App\Http\Middleware\UipResponseEncryptionMiddleware::class,
            \App\Http\Middleware\UipSecurityHeadersMiddleware::class,
            \App\Http\Middleware\UipCorsMiddleware::class,
            // قفل APK بـ API token (مطفي افتراضيًا — APP_TOKEN_REQUIRED). بعد Cors عشان رد 403 يطلع بهيدرز CORS.
            \App\Http\Middleware\UipAppTokenMiddleware::class,
            \App\Http\Middleware\UipRateLimitMiddleware::class,
            \App\Http\Middleware\UipLocaleMiddleware::class,
            // Device Restrictions Policy على كل الـ API (register / forgot-password / public…)
            // مش بس login — بعد Cors عشان رد الـ 403 يطلع بهيدرز CORS.
            \App\Http\Middleware\UipDeviceRestrictionMiddleware::class,
            // IP + Country Restrictions على كل الـ API (قبل كده كانوا متخزّنين بس ومش متطبقين).
            \App\Http\Middleware\UipNetworkRestrictionMiddleware::class,
            // فحص المحتوى: أي نص/ملف/صورة غير لائق أو مخلّ بيتحجب 422 قبل ما يوصل لأي controller (شوف config/content_safety.php).
            \App\Http\Middleware\UipContentSafetyMiddleware::class,
        ]);
        // Per-user activity timeline (server half): every write + every failed request of a signed-in user. Appended so it sits
        // INSIDE the response-encryption middleware (the JSON answer is still readable) and writes after the response is sent.
        $middleware->api(append: [
            \App\Http\Middleware\UipActivityLogMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
