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

        $middleware->alias([
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
        ]);

        // المكالمات: الـ SDP لازم ينتهي بـ \r\n. TrimStrings الافتراضي كان بيشيله فالمستلم يرفض الـ offer
        // والمكالمة تفشل. بنستثني حقل payload بتاع /messaging/calls/{id}/signal (الفرونت كمان بيصلّح الـ SDP).
        $middleware->trimStrings(except: ['payload', 'payload.*', 'payload.sdp']);

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
