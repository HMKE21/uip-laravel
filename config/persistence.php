<?php

/**
 * التخزين الدائم على Railway (Volume).
 *
 * Railway بيسمح بـ Volume واحد بس لكل خدمة، والكود بيكتب في كذا مكان جوه الـ container
 * (بيتمسح مع كل نشر). لو PERSISTENT_STORAGE_PATH متظبط (مسار تركيب الـ Volume، مثلًا /data)،
 * App\Support\PersistentStorage بيعمل symlink من كل مسار تحت لمجلد جوه الـ Volume، وبينقل أي
 * محتوى موجود قبل كده. من غير المتغير ده مفيش أي تغيير في السلوك (لوكال/اختبارات).
 *
 * الشكل: 'المسار نسبةً لـ base_path()' => 'اسم المجلد جوه الـ Volume'
 *
 * بديل أبسط: تركّب الـ Volume مباشرةً على /app/public/uploads — بس كده بيحمي الرفع وحده،
 * ومراقبة الامتحانات وسجلات الأمان وتصدير الامتحانات هتفضل بتتمسح.
 */
return [
    'path' => env('PERSISTENT_STORAGE_PATH'),

    'links' => [
        'public/uploads'           => 'uploads',          // كل رفع الملفات (مشاريع، رسائل، تقارير، تسجيلات...)
        'storage/app/private'      => 'storage-private',  // disk 'local': لقطات/تسجيلات مراقبة الامتحانات
        'storage/app/exam-exports' => 'exam-exports',     // ملفات تصدير الامتحانات (ليها صفوف في DB)
        'storage/logs/security'    => 'security-logs',    // سجلات الأمان (أثر تدقيق)
        'storage/app/drive'        => 'drive',            // "جهازي": ملفات الطلاب الشخصية (disk 'drive' — صفوفها في drive_nodes)
    ],
];
