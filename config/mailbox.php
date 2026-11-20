<?php

/**
 * UIPE Mail — إعدادات صندوق البريد الداخلي (المرحلة 1).
 *
 * external_driver:
 *   'none'  (الافتراضي) → الإرسال بين مستخدمي المنصة بس. أي عنوان خارجي (gmail…) بيترفض برسالة واضحة.
 *   لاحقًا: 'brevo' (إرسال فقط) أو 'stalwart' (SMTP/IMAP حقيقي) — بيتركّبوا ورا App\Services\Mail\ExternalMailGateway.
 */
return [
    'domain'          => env('UIPE_MAIL_DOMAIN', 'uipe.local'),
    'external_driver' => env('UIPE_MAIL_EXTERNAL_DRIVER', 'none'),

    // إرسال خارجي عن طريق Brevo (UIPE_MAIL_EXTERNAL_DRIVER=brevo). FROM لازم يكون Sender/Domain متأكَّد في Brevo.
    'external_from'     => env('UIPE_MAIL_EXTERNAL_FROM', ''),
    'external_reply_to' => env('UIPE_MAIL_EXTERNAL_REPLY_TO', ''),

    'max_recipients'      => 50,
    'max_subject'         => 250,
    'max_body_kb'         => 512,
    'max_attachments'     => 10,
    'max_attachment_mb'   => 20,
    'attachments_disk'    => env('UIPE_MAIL_ATTACHMENTS_DISK', null), // null = FILESYSTEM_DISK
    'trash_days'          => 30,
    'per_page'            => 25,

    // المرحلة 7: الأمان والإدارة
    'security' => [
        'max_per_hour'            => 200,   // رسائل مرسَلة/ساعة لكل صندوق (تتغيّر لكل جامعة)
        'max_recipients_per_hour' => 1000,  // مستلمين/ساعة
        'max_external_per_hour'   => 100,   // مستلمين خارجيين/ساعة
        'bounce_spike_24h'        => 10,    // bounces/24 ساعة لنفس الصندوق = نشاط مشبوه
        'bruteforce_failures'     => 10,    // محاولات دخول فاشلة من نفس الـ IP
        'bruteforce_window_min'   => 15,
        'suspend_after_violations' => 5,    // مخالفات حد الإرسال خلال 24 ساعة قبل الإيقاف التلقائي (لو الجامعة مفعّلاه)
    ],
    'webhook_secret'        => env('UIPE_MAIL_WEBHOOK_SECRET', ''), // توكن webhook بتاع Brevo (Header: X-Webhook-Token أو ?token=)
    'external_max_attempts' => 5,
    'audit_retention_days'  => 365,
    'events_retention_days'     => 180,  // الأحداث الأمنية المقفولة (resolved/ignored)
    'deliveries_retention_days' => 90,   // سجل الإرسال الخارجي (غير الفاشل)
    'bounces_retention_days'    => 365,
];
