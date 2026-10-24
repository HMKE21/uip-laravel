<?php

/**
 * "جهازي" (My PC) — مساحة الملفات الشخصية لكل مستخدم (طالب وباقي الأدوار). كل قيمة بتتغير من الـ .env بدون لمس الكود.
 *
 *   DRIVE_DISK         اسم الـ disk في config/filesystems.php (الافتراضي 'drive' = storage/app/drive، خاص ومش متاح من الويب).
 *                      على Railway/Heroku الـ filesystem بيتمسح مع كل deploy: PERSISTENT_STORAGE_PATH بيربطه بالـ Volume، أو استخدم 's3'.
 *   DRIVE_QUOTA_MB     حصة التخزين لكل طالب.
 *   DRIVE_MAX_FILE_MB  أكبر ملف واحد (لازم يكون أقل من upload_max_filesize / post_max_size في PHP وفي الـ proxy).
 *   DRIVE_MAX_ZIP_MB   أقصى حجم غير مضغوط جوّه zip واحد (ضغط/فك/تنزيل مجلد) — حماية من zip-bomb.
 *   DRIVE_DIRECT       true (افتراضي) = المتصفح يرفع/ينزّل مباشرة من/إلى الـ bucket (S3 / Cloudflare R2) بروابط موقّعة، فالسيرفر مبيشيلش الملفات.
 *                      بيشتغل تلقائيًا بس لو DRIVE_DISK بيشاور على disk من نوع s3 (غير كده كل شيء بيمشي زي الأول). شوف docs/DRIVE_CLOUD_STORAGE.md.
 *   DRIVE_DIRECT_TTL_MIN  عمر الرابط الموقّع بالدقايق.
 *   DRIVE_MAX_VERSIONS    كام نسخة قديمة نحتفظ بيها لكل ملف (0 = سجل الإصدارات مقفول). الأقدم بيتمسح أوتوماتيك.
 *   DRIVE_VERSION_MAX_MB  أكبر حجم ملف نحتفظ له بنسخ قديمة (الملفات الأكبر بتتستبدل من غير سجل).
 *   DRIVE_VERSIONS_MB     سقف مساحة كل النسخ القديمة لكل طالب (مش محسوبة من الحصة؛ لما يتعدّى الأقدم بيتمسح).
 *   DRIVE_ROLES        الأدوار المسموح لها (افتراضي: كل الأدوار المتاحة في config/roles.php؛ للتضييق: DRIVE_ROLES=student,faculty).
 */
return [
    'disk'             => env('DRIVE_DISK', 'drive'),
    'quota_mb'         => (int) env('DRIVE_QUOTA_MB', 2048),
    'max_file_mb'      => (int) env('DRIVE_MAX_FILE_MB', 100),
    'max_zip_mb'       => (int) env('DRIVE_MAX_ZIP_MB', 1024),
    'max_zip_entries'  => (int) env('DRIVE_MAX_ZIP_ENTRIES', 20000),
    'max_nodes'        => (int) env('DRIVE_MAX_NODES', 50000),
    'max_depth'        => (int) env('DRIVE_MAX_DEPTH', 32),
    'max_text_edit_kb' => (int) env('DRIVE_MAX_TEXT_KB', 2048),
    'trash_days'       => (int) env('DRIVE_TRASH_DAYS', 30),
    'index_max_kb'     => max(8, (int) env('DRIVE_INDEX_MAX_KB', 256)), // أكبر ملف نصي بيتفهرس للبحث في المحتوى
    'max_versions'     => max(0, (int) env('DRIVE_MAX_VERSIONS', 20)),
    'version_max_mb'   => max(1, (int) env('DRIVE_VERSION_MAX_MB', 25)),
    'versions_mb'      => max(1, (int) env('DRIVE_VERSIONS_MB', 300)),
    'direct'           => filter_var(env('DRIVE_DIRECT', true), FILTER_VALIDATE_BOOL),
    'direct_ttl_min'   => max(1, (int) env('DRIVE_DIRECT_TTL_MIN', 15)),
    'roles'            => array_values(array_filter(array_map('trim', explode(',', (string) env('DRIVE_ROLES', implode(',', (array) ((require __DIR__ . '/roles.php')['available'] ?? ['student']))))))),
];
