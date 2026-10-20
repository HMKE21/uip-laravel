<?php

/**
 * منقولة من config/messaging.php القديمة — نفس القيم الافتراضية بالظبط
 * (بند 18: Messaging). مفيش UI إداري لتعديلها لسه (ده جزء من بند 25 —
 * Security portal / Admin policies)، فالقيم هنا ثابتة زي ما كانت قبل أي
 * override إداري.
 */
return [
    'max_message_length' => 8000,
    'max_attachments_per_message' => 10,

    // رفع الملفات الكبيرة على أجزاء (زي تيليجرام): الفيديو بيتقسّم لقطع 3MB (أقل من حد 4.5MB بتاع Vercel)، كل قطعة بتتعاد لوحدها
    // لو النت قطع، والرفع بيكمّل من آخر قطعة. MESSAGING_MAX_UPLOAD_MB = أقصى حجم للملف الواحد (الافتراضي 2GB).
    'chunked' => [
        'max_bytes'   => (int) env('MESSAGING_MAX_UPLOAD_MB', 2048) * 1024 * 1024,
        'chunk_bytes' => 3 * 1024 * 1024,
        'ttl_hours'   => 24,
    ],

    'rate_limit' => [
        'max_messages'    => 30,
        'window_seconds'  => 60,
    ],

    'edit_window_minutes' => 0, // 0 = بدون حد زمني للتعديل
    'delete_for_everyone_window_minutes' => 15,
    // نافذة "إلغاء الإرسال" (زرار Cancel جنب الرسالة). بعدها الزرار بيبقى "حذف".
    'undo_send_window_seconds' => (int) env('MESSAGING_UNDO_WINDOW_SECONDS', 60),

    'presence' => [
        'online_threshold_s' => 30,
        'typing_ttl_s'       => 6,
    ],

    'poll_interval_ms' => 4000,

    // عزل الضوضاء عند الرفع (اختياري من المرسل) — بيحتاج ffmpeg على السيرفر. بدون ffmpeg الملف بيتبعت زي ما هو.
    'denoise' => [
        'model_path' => env('MESSAGING_DENOISE_MODEL', resource_path('audio/rnnoise-bd.rnnn')), // RNNoise model لفلتر arnndn
        'timeout'    => 180, // ثواني لكل ملف
    ],
];
