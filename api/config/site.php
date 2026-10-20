<?php

/**
 * منقولة من الحقول العامة (public-facing) في config/app.php القديمة —
 * بند 11 مرحلة 3 (PublicApiController::siteInfo()). ملف منفصل بدل
 * التعديل في config/app.php الأصلي بتاع لارافيل (اللي المشروع الحي
 * أصلاً عنده نسخته)، عشان النسخ ميحتاجش دمج يدوي فيه — انسخه زي ما هو
 * جنب باقي ملفات config/ بتوع الـ delta ده (roles.php/security.php/
 * upload.php/languages.php).
 *
 * support_email: نفس منطق القديمة بالظبط — بيرجع لعنوان الـ reply-to/
 * from بتاع الميل الحقيقي لو معملوش override صريح، عشان الفوتر/صفحة
 * الخصوصية ميعرضوش عنوان مختلق. سيبه فاضي (متغيرات البيئة الاتنين) عشان
 * سطر "Contact support" يختفي تمامًا بدل ما يعرض حاجة وهمية.
 */
return [
    'support_email' => env('APP_SUPPORT_EMAIL', env('MAIL_REPLY_TO', env('MAIL_FROM_ADDRESS', ''))),

    // حسابات UIP الرسمية على السوشيال ميديا، لو الجهة المشغّلة للنسخة دي
    // عندها. فاضية افتراضيًا — الفوتر العام بيعرض أيقونة السوشيال ميديا
    // بس لو القيمة دي فعلًا متظبطة، عمره ما يخترع حساب. تتظبط عبر env،
    // مثال SOCIAL_LINKEDIN_URL.
    'social_links' => array_filter([
        'linkedin'  => env('SOCIAL_LINKEDIN_URL', ''),
        'twitter'   => env('SOCIAL_TWITTER_URL', ''),
        'facebook'  => env('SOCIAL_FACEBOOK_URL', ''),
        'instagram' => env('SOCIAL_INSTAGRAM_URL', ''),
        'youtube'   => env('SOCIAL_YOUTUBE_URL', ''),
    ]),

    // "آخر تحديث" ظاهر في /privacy-policy — بيتظبط يدويًا لما نص السياسة
    // فعلًا يتغيّر، مش date('Y-m-d') آلي في كل request (ده كان هيدّعي إن
    // المستند اتغيّر النهاردة حتى لو مالمسهوش حد).
    // لو true: إيميلات/لينكات الطلبة والدكاترة في صفحة المشروع العامة بتظهر
    // للضيوف كمان. الافتراضي false (للمسجّلين بس) — حماية من الـ scraping.
    'project_contacts_public' => (bool) env('PROJECT_CONTACTS_PUBLIC', false),

    'privacy_policy_updated_at' => env('PRIVACY_POLICY_UPDATED_AT', '2026-08-13'),
];
