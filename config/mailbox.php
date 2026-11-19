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
];
