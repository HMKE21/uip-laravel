<?php

/**
 * ميزات العملاء المعزولين (tenants بـ mode=isolated).
 *
 * - "core": متاحة لكل عميل دايمًا (دخول، بروفايل، إشعارات...).
 * - "features": كل ميزة مقفولة افتراضيًا. أدمن العميل يطلبها، وأدمن المنصة يوافق → تتفتح.
 *
 * الـ prefixes نسبية لـ /api/v1/ (يعني 'meetings' = /api/v1/meetings/...). المسارات اللي برا v1 (dev/v1, oauth, scim/v2) بتتكتب كاملة.
 * الأطول هو اللي بيكسب: 'admin/meetings' بتاعة meetings حتى لو 'admin' عامة core.
 * أي مسار مش مذكور هنا = "unmapped" وبيتعامل حسب 'unmapped' تحت (الافتراضي deny).
 * شغّل  php artisan tenant:features-audit  يعرضلك أي مسار لسه مش متصنّف.
 */
return [
    /** deny (الأأمن: أي مسار مش متصنّف يترفض عند العملاء) | allow */
    'unmapped' => env('TENANT_UNMAPPED', 'deny'),

    'core' => [
        'auth', 'secure', 'app-config', 'app', 'session', 'sessions', 'session-takeover', 'activity',
        'profile', 'profile-photos', 'account', 'account-recovery', 'user-settings', 'settings', 'me',
        'users', 'people', 'search', 'notifications', 'push', 'realtime', 'public', 'files', 'uploads',
        'registration', 'site-info', 'maintenance', 'virtual-mode', 'theme', 'tenant', 'config',
        // نقاط عامة بيسألها الفرونت قبل الدخول: صفحة حظر الجهاز + حالة المنصة.
        'device-status', 'platform',
        // هوية العميل (branding) + فحص الدومين: الفرونت محتاجهم قبل أي ميزة (white-label).
        'dev/v1/tenant',
        // الأدمن العام (مستخدمين، أدوار، سياسات...). الأجزاء الخاصة بميزة بعينها بتتسحب منها تحت.
        'admin',
    ],

    'features' => [
        'messaging' => [
            'label_ar' => 'الشات والمحادثات', 'label_en' => 'Chat & messaging',
            'prefixes' => ['messaging', 'group-chat', 'group-hub', 'groups', 'contacts', 'chat-reports', 'admin/messaging', 'admin/chat-media', 'account/chat-wallpapers'],
        ],
        'meetings' => [
            'label_ar' => 'الاجتماعات', 'label_en' => 'Meetings',
            'prefixes' => ['meetings', 'admin/meetings', 'subtitles/auto'],
        ],
        'feed' => [
            'label_ar' => 'البوستات والستوري', 'label_en' => 'Posts & stories',
            'prefixes' => ['feed', 'posts', 'posts-admin', 'comments', 'stories', 'admin/featured-projects'],
        ],
        'drive' => [
            'label_ar' => 'التخزين والملفات (Drive)', 'label_en' => 'Drive & storage',
            // 'desktop' = جهازي (My PC) + الوضع الافتراضي، و terminal-token / vscode-token بيتطلبوا من Drive.
            'prefixes' => ['drive', 'drive-share', 'admin/drive', 'library-lock', 'cloud/google', 'github', 'shares', 'desktop', 'terminal-token', 'vscode-token'],
        ],
        'mail' => [
            'label_ar' => 'البريد', 'label_en' => 'Mail',
            'prefixes' => ['mail', 'mail-admin'],
        ],
        'exams' => [
            'label_ar' => 'الامتحانات والتقييم', 'label_en' => 'Exams & grading',
            'prefixes' => ['exam-system', 'exams', 'exam', 'grading'],
        ],
        'academic' => [
            'label_ar' => 'الإدارة الأكاديمية (جامعات، كليات، طلاب، مشاريع)', 'label_en' => 'Academic management',
            'prefixes' => [
                'universities', 'university', 'university-email', 'university-hierarchy', 'faculty', 'faculties', 'academic-staff', 'academic-calendar',
                'supervisors', 'supervisor', 'students', 'student', 'departments', 'courses', 'sections', 'assignments', 'attendance',
                'graduation', 'fees', 'summer', 'project-deadlines', 'projects', 'project', 'portfolios', 'patents', 'bulk', 'announcements',
                'access', 'code-review-report', 'admin-requests', 'admin/universities', 'admin/approvals', 'admin/attendance-settings',
            ],
        ],
        'ai' => [
            'label_ar' => 'المساعد الذكي', 'label_en' => 'AI assistant',
            'prefixes' => ['ai', 'ai-assistant', 'ai-code', 'ai-insights', 'ai-conversations', 'admin/ai-assistant-settings', 'admin/ai-code-review', 'admin/faq-intents', 'web-search'],
        ],
        'analytics' => [
            'label_ar' => 'التحليلات والتقارير', 'label_en' => 'Analytics & reports',
            'prefixes' => ['analytics', 'advanced-analytics', 'dashboards', 'kpis', 'reports', 'report-files', 'forecasting', 'data-explorer', 'data-quality', 'data-analysis', 'segments', 'queries', 'exports', 'items', 'admin/reports', 'admin/visitors', 'admin/dashboard'],
        ],
        'security_portal' => [
            'label_ar' => 'بوابة الأمان', 'label_en' => 'Security portal',
            'prefixes' => ['security', 'vulnerabilities', 'incidents', 'alerts', 'network', 'logs', 'policies', 'control'],
        ],
        'forms' => [
            'label_ar' => 'النماذج والاستبيانات', 'label_en' => 'Forms & surveys',
            'prefixes' => ['forms', 'f'],
        ],
        'guides' => [
            'label_ar' => 'الأدلة الإرشادية', 'label_en' => 'Guides',
            'prefixes' => ['guides', 'admin/guides', 'portal'],
        ],
        'developer' => [
            'label_ar' => 'واجهة المطورين والتكامل', 'label_en' => 'Developer API & integrations',
            'prefixes' => ['developer', 'dev-admin', 'dev/v1', 'oauth', 'scim/v2', 'identity'],
        ],
    ],
];
