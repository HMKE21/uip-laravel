<?php

/**
 * مراقبة الداتا (Security Portal — بند 1 من الأجزاء الناقصة).
 *
 * UipDataGuardMiddleware بيراقب بس (مبيحجبش حاجة، والتسجيل بعد إرسال الرد) وبيغذّي محرك الكشف بـ 4 أنواع أحداث
 * من مستخدم مسجّل دخول وطلب ناجح (2xx):
 *   data_export      : تصدير بيانات (route فيه segment export)                 → قاعدة mass_export
 *   data_delete      : حذف (DELETE) أو حذف/تفريغ جماعي                          → قاعدة mass_delete   (amount = عدد العناصر)
 *   data_bulk_update : تعديل جماعي (bulk / import)                              → قاعدة mass_modify   (amount = عدد العناصر)
 *   config_change    : تغيير صلاحيات/أدوار/سياسات/قواعد كشف/إعدادات شبكة        → قاعدة sensitive_change
 * Patterns بتتطابق مع route->uri() (بالـ {param} وبادئة api/v1) عن طريق fnmatch، وعلى الـ segments بـ regex.
 */
return [
    'enabled' => (bool) env('DATA_GUARD_ENABLED', true),

    // تصدير: أي route فيه segment export / export-all / export.csv. ('exports' لوحدها = قايمة، فمتتعدّش غير مع POST = إنشاء تصدير.)
    'export_pattern'       => '#/(?:export|export-all|export\.[a-z]+)(?:/|$)#',
    'export_create_pattern' => '#/exports$#',

    // حذف/تفريغ جماعي بـ POST/PATCH/DELETE.
    'bulk_delete_pattern' => '#/(?:bulk-delete|bulk-remove|bulk-cancel|purge|purge-trash|delete-all|empty|wipe|chat-clear|clear-activity)(?:/|$)#',

    // تعديل جماعي (POST/PUT/PATCH): bulk أو bulk-xxx أو {x}/bulk أو import.
    'bulk_update_pattern' => '#/(?:bulk|bulk-[a-z\-]+|import)(?:/|$)#',

    // عدّ العناصر في الطلب: أول مفتاح مطابق بيكسب (array → count).
    'bulk_id_keys'        => ['ids', 'user_ids', 'item_ids', 'node_ids', 'file_ids', 'attempt_ids', 'student_ids', 'recipients', 'emails', 'items', 'selected', 'rows', 'users'],
    'sweep_weight'        => 25, // purge / wipe / delete-all من غير قايمة ids صريحة
    'bulk_default_weight' => 5,  // bulk/import من غير قايمة صريحة (ملف مرفوع مثلًا)
    'max_amount'          => 100000,

    // حاجات بتاعة المستخدم نفسه بشكل طبيعي — مبتتعدّش (حذف/تصدير/تعديل جماعي).
    'ignore' => [
        'api/v1/notifications*', 'api/v1/messaging/*', 'api/v1/feed/*', 'api/v1/posts*', 'api/v1/stories*',
        'api/v1/ai-assistant/*', 'api/v1/ai/*', 'api/v1/mail/*', 'api/v1/realtime/*', 'api/v1/session/*',
        'api/v1/meetings/*/chat*', 'api/v1/drive/trash*', 'api/v1/drive/versions*',
    ],

    // تغيير صلاحيات/إعدادات حساسة: أي POST/PUT/PATCH/DELETE ناجح على الـ patterns دي.
    'config_change' => [
        'api/v1/admin/roles',
        'api/v1/admin/roles/*',
        'api/v1/admin/users/*/role',
        'api/v1/admin/users/*/roles',
        'api/v1/admin/users/*/roles/*',
        'api/v1/admin/users/*/joker',
        'api/v1/admin/users/*/block-ip',
        'api/v1/security/policies*',
        'api/v1/security/detection*',
        'api/v1/security/network*',
    ],
];
