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
    // قفل APK بـ API token (شوف UipAppTokenMiddleware). مطفي افتراضيًا عشان نشر الكود ما يقفلش التطبيق الحالي.
    // توكن التطبيق (اختياري): بيتحط من Admin → Platform Policy بدل .env. القيمة هنا fallback بس.
    'app_token'              => env('APP_TOKEN', ''),
    'app_token_required'     => filter_var(env('APP_TOKEN_REQUIRED', false), FILTER_VALIDATE_BOOL),
    'app_token_block_webview' => filter_var(env('APP_TOKEN_BLOCK_WEBVIEW', false), FILTER_VALIDATE_BOOL),
    // كام يوم نحتفظ بسجل استخدام التوكن (api_token_usages) قبل التنضيف التلقائي.
    'app_token_log_days'     => (int) env('APP_TOKEN_LOG_DAYS', 60),

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
    // إيميلات/لينكات الطلبة والدكاترة في صفحة المشروع العامة بتظهر للضيوف
    // كمان (قرار صاحب المنصة). الافتراضي true؛ لو عايز ترجّعها للمسجّلين بس
    // ضيف PROJECT_CONTACTS_PUBLIC=false في الـ env.
    'project_contacts_public' => (bool) env('PROJECT_CONTACTS_PUBLIC', true),

    'privacy_policy_updated_at' => env('PRIVACY_POLICY_UPDATED_AT', '2026-08-13'),
];
