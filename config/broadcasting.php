<?php
/**
 * Round 3 (Signaling) — بند 6 والجزء الخاص بالـ Signaling، بند 33
 * (Real-Time Architecture: "Laravel WebSockets / Reverb / compatible
 * WebSocket infrastructure for signaling"). شكل قياسي 100% من سكيلتون
 * لارافيل (config/broadcasting.php)، من غير أي تعديل على البنية
 * المعتادة — الفرق الوحيد إن default الاتصال 'reverb' مش 'null'.
 *
 * ⚠️ ملاحظة تثبيت مهمة: driver 'reverb' (illuminate/broadcasting
 * ReverbBroadcaster) جزء من laravel/framework نفسه من Laravel 11 —
 * مش محتاج باكدج إضافي عشان event/ShouldBroadcast يبعت للسيرفر. لكن
 * تشغيل سيرفر الـ WebSocket الفعلي (`php artisan reverb:start`) محتاج
 * الباكدج `laravel/reverb` مثبت فعليًا (composer require laravel/reverb
 * — مُضاف بالفعل لـ composer.json، لكن الشبكة في بيئة الـ sandbox دي
 * كانت مقفولة على packagist وقت البناء، فـ composer.lock لسه ما
 * اتحدّثش. لازم تشغّل composer update laravel/reverb قبل ما تجرّب
 * reverb:start فعليًا).
 *
 * قناة auth (private-/presence-) هنا **مش** عبر `/broadcasting/auth`
 * القياسي بتاع لارافيل (اللي بيعتمد على Auth::user()/الـ guard الافتراضي) —
 * النظام كله هنا (زي كل موديول تاني في المشروع) بيستخدم JWT بيرر توكن
 * يدوي (UipJwtService) مش Laravel Auth facade/session. فبدل ما نلف حراسة
 * مخصصة فوق Auth::user() بس عشان broadcasting، سطح الـ auth الفعلي هو
 * endpoint عادي جوه MeetingsSignalingApiController (POST
 * meetings/{uuid}/signaling/auth) تحت نفس ميدلوير uip.auth.optional
 * بتاعة Round 2 (عشان الضيوف كمان يقدروا يوقّعوا قناتهم)، بيتحقق من
 * الصلاحية بنفسه (host/co-host/participant/ضيف مقبول عبر
 * MeetingLobbyService)، وبعدين بيوقّع التوقيع بنفس بروتوكول Pusher
 * (اللي Reverb متوافق معاه) يدويًا (MeetingSignalingService::signChannel()).
 * لذلك routes/channels.php ماتلاقيهوش مسجّل هنا ولا withBroadcasting()
 * في bootstrap/app.php — قرار متعمد، مش حاجة ناقصة.
 */

return [
    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [
        'reverb' => [
            'driver'  => 'reverb',
            'key'     => env('REVERB_APP_KEY'),
            'secret'  => env('REVERB_APP_SECRET'),
            'app_id'  => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST'),
                'port'   => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        // Fallback عملي لبيئة التطوير/الاختبار (زي ما phpunit.xml بيظبط
        // BROADCAST_CONNECTION=log بالفعل) — بيكتب البرودكاست في الـ log
        // بدل ما يحاول يوصل سيرفر Reverb حقيقي، فـ ShouldBroadcast events
        // ما بتفشلش الاختبارات لو مفيش سيرفر شغال.
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
