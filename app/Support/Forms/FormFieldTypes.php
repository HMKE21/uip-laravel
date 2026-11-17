<?php

namespace App\Support\Forms;

final class FormFieldTypes
{
    public const TYPES = [
        'short_text' => ['group' => 'text', 'ar' => 'نص قصير', 'en' => 'Short text'],
        'long_text' => ['group' => 'text', 'ar' => 'فقرة', 'en' => 'Paragraph'],
        'email' => ['group' => 'identity', 'ar' => 'بريد إلكتروني', 'en' => 'Email'],
        'phone' => ['group' => 'identity', 'ar' => 'رقم موبايل مصري', 'en' => 'Egyptian mobile'],
        'national_id' => ['group' => 'identity', 'ar' => 'الرقم القومي', 'en' => 'National ID'],
        'number' => ['group' => 'text', 'ar' => 'رقم', 'en' => 'Number'],
        'url' => ['group' => 'text', 'ar' => 'رابط', 'en' => 'URL'],
        'date' => ['group' => 'text', 'ar' => 'تاريخ', 'en' => 'Date'],
        'time' => ['group' => 'text', 'ar' => 'وقت', 'en' => 'Time'],
        'single_choice' => ['group' => 'choice', 'ar' => 'اختيار واحد', 'en' => 'Single choice'],
        'multi_choice' => ['group' => 'choice', 'ar' => 'اختيار متعدد', 'en' => 'Multiple choice'],
        'dropdown' => ['group' => 'choice', 'ar' => 'قائمة منسدلة', 'en' => 'Dropdown'],
        'true_false' => ['group' => 'choice', 'ar' => 'صح / خطأ', 'en' => 'True / False'],
        'scale' => ['group' => 'scale', 'ar' => 'مقياس خطي', 'en' => 'Linear scale'],
        'rating' => ['group' => 'scale', 'ar' => 'تقييم نجوم', 'en' => 'Star rating'],
        'file' => ['group' => 'file', 'ar' => 'رفع ملفات (أي نوع)', 'en' => 'File upload (any type)'],
        'section' => ['group' => 'layout', 'ar' => 'عنوان قسم', 'en' => 'Section'],
        'description' => ['group' => 'layout', 'ar' => 'نص توضيحي', 'en' => 'Description'],
    ];

    public static function valid(string $type): bool { return isset(self::TYPES[$type]); }
    public static function isLayout(string $type): bool { return (self::TYPES[$type]['group'] ?? '') === 'layout'; }
    public static function hasOptions(string $type): bool { return in_array($type, ['single_choice', 'multi_choice', 'dropdown'], true); }
    public static function autoGradable(string $type): bool
    {
        return in_array($type, ['single_choice', 'multi_choice', 'dropdown', 'true_false', 'number', 'short_text', 'scale', 'rating'], true);
    }
}
