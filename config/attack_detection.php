<?php

/**
 * كشف هجمات الـ API (Security Portal — بند 10 / Intrusion Defense، الجزء 1).
 *
 * UipAttackProbeMiddleware بيراقب بس (مبيغيّرش الرد ومبيحجبش حاجة) وبعد إرسال الرد بيسجّل في محرك الكشف:
 *   attack_probe : توقيع هجوم (SQLi / XSS / path traversal / command injection / SSRF / scanner UA / مسار حسّاس)
 *   path_probe   : طلب لمسار مالوش route (404/405) — استكشاف/Scanning
 * والقواعد (attack_probe_ip / path_scan / credential_stuffing) من شاشة Detection Rules هي اللي بتنبّه/بتحظر.
 */
return [
    'enabled' => (bool) env('ATTACK_DETECTION_ENABLED', true),

    // فحص الـ body (POST/PUT/PATCH/DELETE) كمان — الـ query والمسار والـ User-Agent بيتفحصوا دايمًا.
    'scan_body' => (bool) env('ATTACK_DETECTION_SCAN_BODY', true),

    // أقصى عدد أحداث بنسجّله من IP واحد في الدقيقة (حماية الـ DB لو المهاجم بيغرقنا).
    'max_events_per_ip_minute' => (int) env('ATTACK_DETECTION_MAX_PER_MIN', 30),

    // مسارات الـ body فيها كود/SQL مشروع (محلل البيانات، الترمنال، إجابات الامتحانات، الرسائل، المنشورات…) — مبتتفحصش.
    'body_skip_paths' => [
        'api/v1/*data-analysis*', 'api/v1/*queries*', 'api/v1/terminal/*', 'api/v1/sandbox/*', 'api/v1/code/*', 'api/v1/zipbench/*',
        'api/v1/attempts/*/answers/*', 'api/v1/attempts/*/snapshots', 'api/v1/attempts/*/security-events',
        'api/v1/messaging/*', 'api/v1/mail/*', 'api/v1/feed/*', 'api/v1/posts*', 'api/v1/ai-assistant/*', 'api/v1/ai/*',
        'api/v1/*code-review*', 'api/v1/projects/*', 'api/v1/drive/*', 'api/v1/meetings/*/signal*', 'api/v1/realtime/*',
        'api/v1/admin/content-safety*', 'api/v1/security/*', 'api/v1/*report*',
    ],

    // حقول مبتتفحصش (أسرار/توكنات/محتوى base64).
    'skip_fields' => '/^(password|password_confirmation|current_password|new_password|token|refresh_token|access_token|otp|secret|signature|hash|sdp|payload|sessionDescription|image|file|data)$/i',

    // مسارات عادي تتطلب وهي مش موجودة (متصفحات/بوتات شرعية) — مبتتعدّش path_probe.
    'path_probe_ignore' => '#^/(?:favicon\.ico|robots\.txt|sitemap\.xml|apple-touch-icon.*|\.well-known/.*|up|index\.php)$#i',
];
