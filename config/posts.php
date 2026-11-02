<?php

/**
 * Social Posts — الحدود الثابتة والقوائم. القيم اللي الأدمن يقدر يغيّرها وقت التشغيل (أوزان الخوارزمية، حدود الرفع، الموافقة
 * المسبقة ...) في App\Services\PostSettings (جدول settings بمفتاح posts_*)، وهنا الافتراضي بس.
 */
return [
    'max_body'        => 6000,
    'max_title'       => 180,
    'max_comment'     => 2000,
    'max_hashtags'    => 10,
    'max_audience'    => 200,
    'max_mentions'    => 20,
    'code_text_bytes' => 524288,   // أقصى حجم كود بيتبعت كنص للمعاينة (512KB)

    'visibilities'    => ['public', 'followers', 'connections', 'contacts', 'university', 'faculty', 'selected', 'private'],
    'comments_modes'  => ['everyone', 'followers', 'contacts', 'none'],
    'reactions'       => ['like', 'love', 'insightful', 'celebrate', 'support', 'funny'],
    'share_channels'  => ['link', 'chat', 'external'],
    'report_reasons'  => ['spam', 'harassment', 'hate', 'violence', 'sexual', 'misinformation', 'copyright', 'other'],
    'hide_reasons'    => ['not_interested', 'seen_too_much', 'irrelevant', 'other'],

    // الكود: امتداد -> لغة العرض. runner: html (iframe sandbox في المتصفح) | sandbox (بوابة uip-RUN) | none.
    'code_languages'  => [
        'html' => ['html', 'html'], 'css' => ['css', 'html'], 'js' => ['javascript', 'html'], 'ts' => ['typescript', 'sandbox'],
        'py' => ['python', 'sandbox'], 'php' => ['php', 'sandbox'], 'java' => ['java', 'sandbox'], 'c' => ['c', 'sandbox'],
        'cpp' => ['cpp', 'sandbox'], 'cs' => ['csharp', 'sandbox'], 'go' => ['go', 'sandbox'], 'rb' => ['ruby', 'sandbox'],
        'sh' => ['bash', 'sandbox'], 'sql' => ['sql', 'none'], 'yml' => ['yaml', 'none'], 'yaml' => ['yaml', 'none'],
        'json' => ['json', 'none'], 'md' => ['markdown', 'none'], 'txt' => ['text', 'none'], 'xml' => ['xml', 'none'],
        'log' => ['text', 'none'],
    ],

    // امتدادات العروض/المستندات اللي بتتحوّل لـ PDF (لو LibreOffice متثبّت) عشان تتعرض carousel.
    'convertible_to_pdf' => ['ppt', 'pptx', 'odp', 'doc', 'docx', 'odt', 'rtf'],

    'feed' => [
        'cache_seconds' => 90,
        'max_per_page'  => 30,
    ],
];
