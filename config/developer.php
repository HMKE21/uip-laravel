<?php

/**
 * UIPE Developer Center — المرحلة 6: OAuth Apps, API Keys, Scopes, Webhooks, Sandbox, سجل التكاملات.
 */
return [
    'enabled'     => (bool) env('DEV_CENTER_ENABLED', true),
    'api_version' => '2027-11-01',

    // الحدود الافتراضية (الجامعة تقدر تنزّل max_apps_per_user من dev-admin/policy).
    'limits' => [
        'apps_per_user'     => (int) env('DEV_MAX_APPS', 10),
        'keys_per_user'     => (int) env('DEV_MAX_KEYS', 20),
        'webhooks_per_user' => (int) env('DEV_MAX_WEBHOOKS', 10),
        'redirect_uris'     => 10,
        'rate_per_min'      => (int) env('DEV_RATE_PER_MIN', 300),   // لكل مفتاح/توكن (التطبيق ممكن يتخصص له رقم تاني)
        'sandbox_messages'  => 500,                                    // أقصى رسائل في Sandbox لكل مستخدم
        'ai_per_min'        => 20,
    ],

    'oauth' => [
        'code_ttl'          => 600,       // ثواني
        'access_ttl'        => 3600,
        'refresh_ttl_days'  => 30,
        'allow_http_localhost' => true,    // redirect إلى http://localhost مسموح (للتطوير)
    ],

    'webhooks' => [
        'timeout'        => 8,
        'max_attempts'   => 6,
        'backoff'        => [60, 300, 1800, 7200, 21600],   // ثواني بين المحاولات
        'disable_after'  => 20,                              // فشل متتالي → تعطيل تلقائي
        'allow_http'     => (bool) env('DEV_WEBHOOK_ALLOW_HTTP', false),
        'allow_private'  => (bool) env('DEV_WEBHOOK_ALLOW_PRIVATE', false),   // للتطوير المحلي بس
        'excerpt_bytes'  => 500,
    ],

    'logs' => [
        'retention_days' => (int) env('DEV_LOG_DAYS', 30),
        'delivery_days'  => (int) env('DEV_WEBHOOK_LOG_DAYS', 14),
    ],

    /**
     * الـ Scopes. sensitive = بتغيّر بيانات/بتبعت باسم المستخدم → بتظهر بتحذير في شاشة الموافقة،
     * والجامعة تقدر تقصر الـ allowed_scopes على جزء منها.
     */
    'scopes' => [
        'profile.read'        => ['sensitive' => false, 'en' => 'See your name and mailbox addresses',        'ar' => 'عرض اسمك وعناوين صناديق بريدك'],
        'mail.read'           => ['sensitive' => false, 'en' => 'Read your mail',                              'ar' => 'قراءة بريدك'],
        'mail.send'           => ['sensitive' => true,  'en' => 'Send mail on your behalf',                    'ar' => 'إرسال بريد باسمك'],
        'mail.drafts'         => ['sensitive' => false, 'en' => 'Create and edit drafts',                      'ar' => 'إنشاء وتعديل المسودات'],
        'mail.modify'         => ['sensitive' => true,  'en' => 'Mark, star, archive and delete your mail',    'ar' => 'تعليم الرسائل وأرشفتها وحذفها'],
        'mail.labels'         => ['sensitive' => false, 'en' => 'Manage your labels',                          'ar' => 'إدارة التصنيفات'],
        'chat.write'          => ['sensitive' => true,  'en' => 'Share mail into your chats',                  'ar' => 'مشاركة الرسائل في محادثاتك'],
        'meetings.write'      => ['sensitive' => true,  'en' => 'Create meetings from mail',                   'ar' => 'إنشاء اجتماعات من البريد'],
        'drive.write'         => ['sensitive' => true,  'en' => 'Save mail and attachments to your Drive',     'ar' => 'حفظ الرسائل والمرفقات في Drive'],
        'ai.use'              => ['sensitive' => false, 'en' => 'Use the AI assistant on your mail',           'ar' => 'استخدام المساعد الذكي على بريدك'],
        'notifications.write' => ['sensitive' => false, 'en' => 'Send you notifications',                      'ar' => 'إرسال إشعارات لك'],
        'webhooks.manage'     => ['sensitive' => false, 'en' => 'Manage webhooks',                             'ar' => 'إدارة الـ Webhooks'],
    ],

    /** الأحداث اللي الـ Webhooks تقدر تشترك فيها. */
    'events' => [
        'mail.message.received' => 'A new message arrived in the inbox',
        'mail.message.sent'     => 'A message was sent from the mailbox',
        'app.authorized'        => 'A user authorized your OAuth app',
        'app.revoked'           => 'A user revoked your OAuth app',
        'webhook.test'          => 'Test ping',
    ],
];
