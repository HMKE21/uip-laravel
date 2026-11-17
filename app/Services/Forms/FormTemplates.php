<?php

namespace App\Services\Forms;

final class FormTemplates
{
    public static function all(): array
    {
        $opts = fn (array $l) => array_map(fn ($x) => ['id' => substr(md5($x), 0, 8), 'label' => $x], $l);
        return [
            'registration' => [
                'title' => 'استمارة تسجيل', 'description' => 'سجّل بياناتك للمشاركة.', 'icon' => 'registration', 'color' => 'indigo', 'mode' => 'form',
                'settings' => ['unique_by' => ['email', 'phone'], 'edit_after_submit' => ['allowed' => true, 'window' => 'until_close']],
                'fields' => [
                    ['type' => 'short_text', 'label' => 'الاسم بالكامل', 'required' => true],
                    ['type' => 'email', 'label' => 'البريد الإلكتروني', 'required' => true, 'config' => ['allow_disposable' => false]],
                    ['type' => 'phone', 'label' => 'رقم الموبايل', 'required' => true],
                    ['type' => 'national_id', 'label' => 'الرقم القومي', 'required' => false],
                    ['type' => 'long_text', 'label' => 'ملاحظات', 'required' => false],
                ],
            ],
            'survey' => [
                'title' => 'استبيان رأي', 'description' => 'رأيك يهمنا.', 'icon' => 'survey', 'color' => 'teal', 'mode' => 'form',
                'settings' => ['one_per_device' => true],
                'fields' => [
                    ['type' => 'rating', 'label' => 'قيّم تجربتك', 'required' => true, 'config' => ['max' => 5]],
                    ['type' => 'scale', 'label' => 'هل توصي بنا لصديق؟', 'required' => true, 'config' => ['min' => 0, 'max' => 10, 'min_label' => 'لا أبدًا', 'max_label' => 'بالتأكيد']],
                    ['type' => 'multi_choice', 'label' => 'أكثر ما أعجبك', 'config' => ['options' => $opts(['الجودة', 'السعر', 'الدعم', 'السرعة'])]],
                    ['type' => 'long_text', 'label' => 'اقتراحاتك'],
                ],
            ],
            'quiz' => [
                'title' => 'اختبار قصير', 'description' => 'اختبار تلقائي التصحيح.', 'icon' => 'exam', 'color' => 'rose', 'mode' => 'exam',
                'settings' => ['unique_by' => ['user'], 'access' => 'platform', 'exam' => ['duration_minutes' => 15, 'shuffle_questions' => true, 'shuffle_options' => true]],
                'fields' => [
                    ['type' => 'short_text', 'label' => 'الاسم', 'required' => true],
                    ['type' => 'single_choice', 'label' => 'سؤال اختيار من متعدد (عدّله)', 'required' => true, 'points' => 1,
                        'config' => ['options' => [['id' => 'a', 'label' => 'الإجابة أ'], ['id' => 'b', 'label' => 'الإجابة ب']]], 'correct' => ['a']],
                    ['type' => 'true_false', 'label' => 'سؤال صح أو خطأ (عدّله)', 'required' => true, 'points' => 1, 'correct' => [true]],
                ],
            ],
            'event_signup' => [
                'title' => 'التسجيل في فعالية', 'description' => 'احجز مكانك.', 'icon' => 'event', 'color' => 'amber', 'mode' => 'form',
                'settings' => ['unique_by' => ['email'], 'max_responses' => 100],
                'fields' => [
                    ['type' => 'short_text', 'label' => 'الاسم', 'required' => true],
                    ['type' => 'email', 'label' => 'البريد الإلكتروني', 'required' => true],
                    ['type' => 'phone', 'label' => 'الموبايل', 'required' => true],
                    ['type' => 'single_choice', 'label' => 'هل ستحضر؟', 'required' => true, 'config' => ['options' => $opts(['نعم', 'ربما', 'لا'])]],
                ],
            ],
            'assignment' => [
                'title' => 'تسليم ملفات', 'description' => 'ارفع ملفاتك (أي نوع، حتى zip).', 'icon' => 'clipboard', 'color' => 'violet', 'mode' => 'form',
                'settings' => ['unique_by' => ['user'], 'access' => 'platform', 'edit_after_submit' => ['allowed' => true, 'window' => 'until_close']],
                'fields' => [
                    ['type' => 'short_text', 'label' => 'الاسم', 'required' => true],
                    ['type' => 'file', 'label' => 'الملفات', 'required' => true, 'config' => ['max_files' => 5]],
                ],
            ],
        ];
    }
}
