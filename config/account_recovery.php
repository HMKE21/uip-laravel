<?php

/**
 * سياسة استرجاع/دمج الحسابات المحذوفة.
 *
 * بيتنقل في الدمج حسب 'groups' (المستخدم/الأدمن بيختار منها).
 * مبيتنقلش أبدًا (هوية وأمان): كلمة السر، 2FA، الجلسات والتوكنز، الأجهزة الموثوقة، الأدوار والصلاحيات،
 * حالة الحساب/القفل، سجلات الأمان والـ audit، الإشعارات. دي بتفضل مع الحساب القديم كأرشيف.
 *
 * 'move'         : [table, column, kind]  kind=user => users.id | kind=student => students.id (بنحوّل الـ id)
 * 'fill_user'    : أعمدة users بتتنسخ للهدف لو فاضية عنده بس.
 * 'fill_student' : نفس الفكرة على جدول students.
 */
return [
    'otp' => [
        'ttl_minutes'    => (int) env('ACCOUNT_RECOVERY_OTP_TTL', 10),
        'resend_seconds' => (int) env('ACCOUNT_RECOVERY_OTP_RESEND', 60),
        'max_attempts'   => (int) env('ACCOUNT_RECOVERY_OTP_ATTEMPTS', 5),
    ],

    'self' => [
        'enabled'             => (bool) env('ACCOUNT_SELF_RECOVERY_ENABLED', true),
        // الاسترجاع الذاتي للطالب بس؛ باقي الأدوار (جامعة/مشرف/هيئة/أدمن) عن طريق الأدمن.
        'roles'               => ['student'],
        // مدة الاحتفاظ بعد الحذف: بعدها الاسترجاع بالأدمن بس. 0 = بدون حد.
        'retention_days'      => (int) env('ACCOUNT_SELF_RECOVERY_DAYS', 90),
    ],

    'default_groups' => ['profile', 'academic', 'projects'],

    'groups' => [
        'profile' => [
            'label_ar' => 'الملف الشخصي (الهاتف، الصورة، النبذة، المهارات، الروابط)',
            'label_en' => 'Profile (phone, avatar, bio, skills, links)',
            'fill_user'    => ['phone', 'avatar_path'],
            'fill_student' => ['bio', 'skills', 'social_links'],
            'move' => [['portfolios', 'user_id', 'user']],
        ],
        'academic' => [
            'label_ar' => 'البيانات الأكاديمية (المقررات، الامتحانات، التخرج، طلبات الجامعة)',
            'label_en' => 'Academic data (courses, exams, graduation, university requests)',
            'fill_student' => ['student_number', 'faculty', 'department', 'academic_year', 'gpa', 'current_semester'],
            'move' => [
                ['course_enrollments', 'student_id', 'student'],
                ['exam_attempts', 'student_id', 'student'],
                ['exam_grade_appeals', 'student_id', 'student'],
                ['exam_student_overrides', 'student_id', 'student'],
                ['graduation_records', 'student_id', 'student'],
                ['student_university_requests', 'student_id', 'student'],
            ],
        ],
        'projects' => [
            'label_ar' => 'المشاريع والملفات',
            'label_en' => 'Projects and files',
            'move' => [
                ['projects', 'owner_id', 'user'],
                ['project_files', 'uploaded_by', 'user'],
                ['project_team_members', 'user_id', 'user'],
                ['project_discussion_messages', 'user_id', 'user'],
                ['project_grade_members', 'user_id', 'user'],
            ],
        ],
        'social' => [
            'label_ar' => 'المنشورات والتعليقات والإعجابات',
            'label_en' => 'Posts, comments and likes',
            'default' => false,
            'move' => [
                ['feed_posts', 'author_user_id', 'user'],
                ['feed_post_comments', 'user_id', 'user'],
                ['feed_post_likes', 'user_id', 'user'],
                ['feed_post_saves', 'user_id', 'user'],
            ],
        ],
        'groups' => [
            'label_ar' => 'شغل المجموعات (ملفات، مهام، إعلانات)',
            'label_en' => 'Group work (files, tasks, announcements)',
            'default' => false,
            'move' => [
                ['group_files', 'user_id', 'user'],
                ['group_tasks', 'created_by_user_id', 'user'],
                ['group_tasks', 'assignee_user_id', 'user'],
                ['group_announcements', 'user_id', 'user'],
            ],
        ],
        'messages' => [
            'label_ar' => 'الرسائل والمحادثات',
            'label_en' => 'Messages and conversations',
            'default' => false,
            'self'    => false, // أدمن بس: ممكن يكشف محادثات لحساب جديد
            'move' => [
                ['conversation_participants', 'user_id', 'user'],
                ['messages', 'sender_id', 'user'],
            ],
        ],
        'ai' => [
            'label_ar' => 'محادثات المساعد الذكي',
            'label_en' => 'AI assistant conversations',
            'default' => false,
            'move' => [['ai_conversations', 'user_id', 'user']],
        ],
    ],
];
