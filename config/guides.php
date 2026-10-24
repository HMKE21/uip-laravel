<?php

/**
 * صفحات الدليل (Portal Guides) — إعدادات.
 * المحتوى الافتراضي نفسه في database/data/guides/<portal>.php (الشكل في GUIDE_FORMAT.md).
 */
return [
    // الصلاحية اللي بتسمح بتعديل الأدلة (الأدمن معاه دايمًا). تقدر تديها لأي دور من /admin/roles.
    'manage_permission' => 'guides.manage',

    // البوابات المتاحة (slug هو نفس البادئة بتاعة الـ URL في الفرونت).
    'portals' => [
        'student', 'university', 'faculty', 'academic-staff',
        'supervisor', 'data-analysis', 'security', 'admin',
    ],

    // الدور => دليل البوابة اللي بيشوفه.
    'role_portal' => [
        'student'          => 'student',
        'university'       => 'university',
        'faculty'          => 'faculty',
        'academic_staff'   => 'academic-staff',
        'supervisor'       => 'supervisor',
        'data_analyst'     => 'data-analysis',
        'security_admin'   => 'security',
        'security_officer' => 'security',
        'admin'            => 'admin',
    ],

    'limits' => [
        'title'   => 160,
        'summary' => 500,
        'body'    => 4000,
        'intro'   => 2000,
        'step'    => 400,
        'steps'   => 15,
        'tips'    => 10,
        'search'  => 30,
        'revisions' => 100,
    ],
];
