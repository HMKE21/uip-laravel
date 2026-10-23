<?php

/**
 * المكالمات (صوت أولًا، الفيديو لاحقًا) — 1:1 جوّه المحادثات المباشرة.
 *
 * الصوت/الصورة نفسه بيعدي مباشرة بين المتصفحين (WebRTC P2P)؛ لارافيل بيشتغل كـ signaling
 * (جدول calls + call_signals بيتقروا بـ polling سريع) ويوزّع إعدادات STUN/TURN.
 *
 * TURN ضروري عشان المكالمات تنجح على شبكات الموبايل وأغلب الشبكات. اختار مزوّد واحد (CALLS_TURN_PROVIDER)
 * أو سيبه فاضي وهيتحدد تلقائيًا من المفاتيح الموجودة:
 *   cloudflare : مجاني (أول 1000GB/شهر).  CF_TURN_KEY_ID + CF_TURN_API_TOKEN  (Cloudflare ▸ Realtime ▸ TURN)
 *   metered    : خطة مجانية.             METERED_APP (اسم التطبيق) + METERED_API_KEY
 *   coturn     : سيرفرك الخاص.           TURN_URLS + TURN_SECRET (نفس مفاتيح config/webrtc.php، credentials مؤقتة)
 *   static     : TURN_URLS + TURN_USERNAME + TURN_CREDENTIAL
 *   (فاضي)     : STUN بس — بيشتغل على الشبكات البسيطة.
 */
return [
    'enabled'       => (bool) env('CALLS_ENABLED', true),
    'video_enabled' => (bool) env('CALLS_VIDEO_ENABLED', false),   // المرحلة 2

    'ring_seconds'         => (int) env('CALLS_RING_SECONDS', 45),    // بعدها المكالمة تتحسب "فايتة"
    'stale_seconds'        => (int) env('CALLS_STALE_SECONDS', 25),   // طرف مبعتش نبضة المدة دي => المكالمة تنتهي
    'max_signals_per_call' => (int) env('CALLS_MAX_SIGNALS', 600),
    'max_signal_bytes'     => (int) env('CALLS_MAX_SIGNAL_BYTES', 24000),
    'start_per_minute'     => (int) env('CALLS_START_PER_MINUTE', 8),
    'prune_days'           => (int) env('CALLS_PRUNE_DAYS', 14),

    // bucket مستقل لـ /messaging/calls/* في UipRateLimitMiddleware (الـ polling السريع ميتحسبش على الـ 60/دقيقة العامة).
    'rate_limit_per_min'   => (int) env('CALLS_RATE_LIMIT_PER_MIN', 300),

    'stun_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'))))),
    'turn_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURN_URLS', ''))))),

    'turn' => [
        'provider'   => strtolower((string) env('CALLS_TURN_PROVIDER', '')),
        'ttl'        => (int) env('CALLS_TURN_TTL', 14400),     // عمر الـ credentials (ثواني) — أطول من أي مكالمة عادية
        'cache'      => (int) env('CALLS_TURN_CACHE', 600),     // نخزّن الرد المدة دي لكل مستخدم بدل طلب لكل مكالمة
        'cloudflare' => [
            'key_id'    => env('CF_TURN_KEY_ID'),
            'api_token' => env('CF_TURN_API_TOKEN'),
        ],
        'metered' => [
            'app'     => env('METERED_APP'),
            'api_key' => env('METERED_API_KEY'),
        ],
        'secret'     => env('TURN_SECRET'),
        'username'   => env('TURN_USERNAME'),
        'credential' => env('TURN_CREDENTIAL'),
    ],
];
