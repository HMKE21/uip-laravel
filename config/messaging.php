<?php

/**
 * منقولة من config/messaging.php القديمة — نفس القيم الافتراضية بالظبط
 * (بند 18: Messaging). مفيش UI إداري لتعديلها لسه (ده جزء من بند 25 —
 * Security portal / Admin policies)، فالقيم هنا ثابتة زي ما كانت قبل أي
 * override إداري.
 */
return [
    // كل الحدود هنا من الـ .env، و0 (أو فاضي) = من غير حد. السيرفر مبيفرضش أي حد مش إنت اللي ظبطته.
    'max_message_length' => (int) env('MESSAGING_MAX_MESSAGE_LENGTH', 0),          // عدد الحروف في الرسالة
    'max_attachments_per_message' => (int) env('MESSAGING_MAX_ATTACHMENTS', 0),    // عدد الملفات في الرسالة الواحدة

    // رفع الملفات الكبيرة على أجزاء (زي تيليجرام): الفيديو بيتقسّم لقطع 3MB (أقل من حد 4.5MB بتاع Vercel)، كل قطعة بتتعاد لوحدها
    // لو النت قطع، والرفع بيكمّل من آخر قطعة. MESSAGING_MAX_UPLOAD_MB = أقصى حجم للملف الواحد (الافتراضي 2GB).
    'chunked' => [
        'max_bytes'   => (int) env('MESSAGING_MAX_UPLOAD_MB', 0) * 1024 * 1024,   // 0 = من غير حد لحجم الملف
        // ملفات الـ svg/json/كود بتتقرا كاملة في الذاكرة عند الفحص؛ لو عايز سقف ليها ضيف رقم (MB). 0 = من غير حد.
        'non_bulky_max_mb' => (int) env('MESSAGING_NONBULKY_MAX_MB', 0),
        'chunk_bytes' => 3 * 1024 * 1024,
        'ttl_hours'   => 24,
    ],

    'rate_limit' => [
        'max_messages'    => (int) env('MESSAGING_RATE_MAX', 0),            // 0 = من غير حد لسرعة الإرسال
        'window_seconds'  => (int) env('MESSAGING_RATE_WINDOW', 60),
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

    // ffmpeg/ffprobe: صورة مصغّرة + مدة + تحويل أي فيديو مرفوع لـ mp4 (H.264/AAC) عشان يشتغل في كل المتصفحات.
    'media_probe' => [
        'ffmpeg_binary'        => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary'       => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout'              => 15,
        'thumbnail_max_width'  => 480,
        'transcode_enabled'    => (bool) env('VIDEO_TRANSCODE_ENABLED', true),
        'transcode_timeout'    => (int) env('VIDEO_TRANSCODE_TIMEOUT', 1800), // ثواني لكل فيديو
    ],
];
