<?php

/**
 * فحص المحتوى (Content Safety) — ممنوع أي محتوى إباحي / مخلّ / حسّاس في كل المنصة.
 *
 * الطبقات:
 *  1) نصوص: قاموس عربي/إنجليزي/عربيزي بيتطبّق على كل كتابة (رسائل، منشورات، تعليقات، بروفايل، مشاريع…) + أي اسم ملف.
 *  2) GIF/ملصقات: بحث + عنوان + تصنيف Giphy (G / PG بس افتراضيًا) + إعادة تحقق وقت الإرسال.
 *  3) صور/فيديو/GIF: فحص بالمحتوى عبر مزوّد خارجي (OpenAI Moderation مجاني، أو Sightengine) — لازم مفتاح في .env.
 *  4) data:image المدفوعة جوه نصوص (محرر الوورد، الستوري…) بتتفحص كمان.
 *
 * كل حاجة بتتسجّل في content_safety_events ويظهر للأدمن في Admin → Content Safety.
 */
return [
    'enabled' => (bool) env('CONTENT_SAFETY_ENABLED', true),

    // auto | openai | sightengine | none   (auto = أول مزوّد مفتاحه موجود)
    'provider' => env('CONTENT_SAFETY_PROVIDER', 'auto'),

    // لو المزوّد متظبط وفشل (timeout/خطأ): false = نرفض الملف (الأحوط)، true = نسمح ونسجّل تحذير.
    'fail_open' => (bool) env('CONTENT_SAFETY_FAIL_OPEN', false),

    // true = لو مفيش مزوّد صور متظبط، نرفض رفع أي صورة/فيديو/GIF (مش مستحسن إلا لو عايز أقصى تشدد).
    'require_image_scanner' => (bool) env('CONTENT_SAFETY_REQUIRE_IMAGE_SCANNER', false),

    'openai' => [
        'key'   => env('CONTENT_SAFETY_OPENAI_KEY', env('OPENAI_API_KEY', '')),
        'model' => env('CONTENT_SAFETY_OPENAI_MODEL', 'omni-moderation-latest'),
    ],
    'sightengine' => [
        'user'   => env('SIGHTENGINE_API_USER', ''),
        'secret' => env('SIGHTENGINE_API_SECRET', ''),
    ],

    // عتبات الحجب (0..1) — أقل = أشد.
    'thresholds' => [
        'sexual'          => (float) env('CONTENT_SAFETY_T_SEXUAL', 0.30),
        'sexual_minors'   => (float) env('CONTENT_SAFETY_T_MINORS', 0.02),
        'violence_graphic' => (float) env('CONTENT_SAFETY_T_GORE', 0.55),
        'suggestive'      => (float) env('CONTENT_SAFETY_T_SUGGESTIVE', 0.80), // Sightengine very_suggestive
    ],

    // أقصى حجم ملف بنفحصه (MB) — الأكبر بيتفحص على إطارات بس (فيديو) أو بيترفض لو صورة.
    'max_scan_mb' => (int) env('CONTENT_SAFETY_MAX_SCAN_MB', 25),
    'timeout'     => (int) env('CONTENT_SAFETY_TIMEOUT', 12),

    // مسارات مستثناة بالكامل (مفيش فحص نص/ملفات): مصادقة، إشارات المكالمات، الامتحانات (إجابات/كاميرا مراقبة)، تشغيل الكود.
    'skip_paths' => [
        'api/v1/auth/*', 'api/v1/secure/*', 'api/v1/realtime/*', 'api/v1/session/*', 'api/v1/activity/*',
        'api/v1/app/client-report', 'api/v1/messaging/calls/*/signal', 'api/v1/messaging/presence/*',
        'api/v1/attempts/*/answers/*', 'api/v1/attempts/*/snapshots', 'api/v1/attempts/*/security-events', 'api/v1/attempts/*/heartbeat',
        'api/v1/zipbench/*', 'api/v1/terminal/*', 'api/v1/sandbox/*', 'api/v1/code/run*',
        // الأدمن بيكتب كلمات ممنوعة هنا + المستخدم لازم يقدر يبلّغ عن محتوى مسيء بنص صريح => مستثنى.
        'api/v1/admin/content-safety*', 'api/v1/admin/chat-media*', 'api/v1/*report*', 'api/v1/*abuse*', 'api/v1/security/*',
    ],

    // مسارات مفيهاش فحص صور (كاميرا مراقبة الامتحان: وش الطالب + خصوصية) — النصوص لسه بتتفحص.
    'skip_image_paths' => ['api/v1/attempts/*/snapshots', 'api/v1/exams/*/attempts/*/identity-review', 'api/v1/meetings/*/recordings*'],

    // أسماء حقول مش بتتفحص أبدًا.
    'skip_fields' => '/^(password|password_confirmation|current_password|new_password|token|refresh_token|access_token|otp|code|secret|signature|hash|sdp|candidate|payload|sessionDescription|email|_token|challenge_token|device_id|fingerprint)$/i',

    // حقول بتتطبّق عليها الطبقة الأشد (اسم/عنوان قصير ظاهر للكل): tier1 + tier2.
    'strict_fields' => '/^(full_name|name|first_name|last_name|username|nickname|display_name|headline|pack_name|sticker_name|group_name)$/i',

    // كلمات إضافية (CSV) بتتحجب في كل مكان / أو بتتسمح (استثناء) — من .env من غير deploy للكود.
    'extra_block' => env('CONTENT_SAFETY_EXTRA_WORDS', ''),
    'allow'       => env('CONTENT_SAFETY_ALLOW_WORDS', ''),

    // عدد المخالفات في 24 ساعة اللي يطلّع تنبيه للأدمن.
    'strike_alert' => (int) env('CONTENT_SAFETY_STRIKE_ALERT', 5),

    // Giphy: أعلى تصنيف مسموح (g | pg | pg-13). مفيش 'r' أبدًا.
    'gif_max_rating' => env('CONTENT_SAFETY_GIF_MAX_RATING', 'pg'),
];
