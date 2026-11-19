<?php

/**
 * UIPE Mail — المرحلة 5 (الهوية). كل القيم هنا (مش env() جوه الكود) عشان `php artisan config:cache` ميكسرش حاجة.
 *   PASSKEY_RP_ID    : نطاق الموقع اللي الـ passkeys بتتسجّل عليه (من غير https ولا port). مثال: uipe.example.com
 *   PASSKEY_ORIGINS  : origins الفرونت المسموحة (مفصولة بفاصلة). مثال: https://uipe.example.com
 *   PASSKEY_RP_NAME  : الاسم اللي بيظهر للمستخدم في نافذة المتصفح.
 */
return [
    'login_events_days' => (int) env('LOGIN_EVENTS_DAYS', 180),

    'passkeys' => [
        'rp_id'    => (string) env('PASSKEY_RP_ID', ''),
        'rp_name'  => (string) env('PASSKEY_RP_NAME', 'UIPE'),
        'origins'  => (string) env('PASSKEY_ORIGINS', ''),
        'max_per_user' => (int) env('PASSKEY_MAX_PER_USER', 10),
    ],

    'scim' => [
        'enabled' => filter_var(env('SCIM_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
