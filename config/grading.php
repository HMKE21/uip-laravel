<?php

/**
 * كشف الدرجات والـ GPA — القيم الافتراضية (لو الأدمن/الجامعة/الكلية ما حدّدوش نظام درجات خاص بيهم).
 * الأولوية: نظام الكلية ← نظام الجامعة ← نظام الأدمن (global) ← القيم دي.
 * الافتراضي هنا هو التقدير الشائع (مقياس 4.0): A+ ≥ 97 … F < 60. الأدمن/الجامعة/الكلية بيعدّلوه من لوحاتهم.
 */
return [
    'bands' => [
        ['letter' => 'A+', 'min_percent' => 97, 'points' => 4.0, 'is_fail' => false],
        ['letter' => 'A',  'min_percent' => 93, 'points' => 4.0, 'is_fail' => false],
        ['letter' => 'A-', 'min_percent' => 89, 'points' => 3.7, 'is_fail' => false],
        ['letter' => 'B+', 'min_percent' => 84, 'points' => 3.3, 'is_fail' => false],
        ['letter' => 'B',  'min_percent' => 80, 'points' => 3.0, 'is_fail' => false],
        ['letter' => 'B-', 'min_percent' => 76, 'points' => 2.7, 'is_fail' => false],
        ['letter' => 'C+', 'min_percent' => 73, 'points' => 2.3, 'is_fail' => false],
        ['letter' => 'C',  'min_percent' => 70, 'points' => 2.0, 'is_fail' => false],
        ['letter' => 'C-', 'min_percent' => 67, 'points' => 1.7, 'is_fail' => false],
        ['letter' => 'D+', 'min_percent' => 64, 'points' => 1.3, 'is_fail' => false],
        ['letter' => 'D',  'min_percent' => 60, 'points' => 1.0, 'is_fail' => false],
        ['letter' => 'F',  'min_percent' => 0,  'points' => 0.0, 'is_fail' => true],
    ],

    // الطالب المحروم (من الحضور) بياخد التقدير ده لو deprivation_fails مفعّل.
    'deprivation_letter'    => 'DN',
    'deprivation_fails'     => true,

    // true = المدرّس/المعيد يرفع النتيجة "للاعتماد" وكلية/جامعة هي اللي تنشرها.
    'require_approval'      => false,

    // عدد الخانات العشرية للمعدل.
    'gpa_decimals'          => 2,
];
