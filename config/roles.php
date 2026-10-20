<?php
/**
 * نسخة طبق الأصل من config/roles.php القديمة.
 */

return [
    'available' => [
        'student', 'university', 'admin',
        'security_admin', 'security_officer', 'data_analyst',
        'supervisor', 'academic_staff', 'faculty',
    ],

    'home_route' => [
        'student'          => '/student/dashboard',
        'university'       => '/university/dashboard',
        'admin'            => '/admin/dashboard',
        'security_admin'   => '/security/dashboard',
        'security_officer' => '/security/dashboard',
        'data_analyst'     => '/data-analysis/dashboard',
        'supervisor'       => '/supervisor/dashboard',
        'academic_staff'   => '/academic-staff/dashboard',
        'faculty'          => '/faculty/dashboard',
    ],

    'portal_prefixes' => [
        'security'       => ['security_admin', 'security_officer', 'admin'],
        'data-analysis'  => ['data_analyst', 'admin'],
        'academic-staff' => ['academic_staff', 'admin'],
    ],
];
