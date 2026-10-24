# شكل ملفات المحتوى الافتراضي — database/data/guides/<portal>.php

```php
return [
  'title_ar' => '', 'title_en' => '', 'subtitle_ar' => '', 'subtitle_en' => '',
  'intro_ar' => '', 'intro_en' => '', 'icon' => 'book',
  'sections' => [[
      'key' => 'nav-main',               // فريد جوه الدليل، حروف صغيرة وأرقام و-
      'kind' => 'group',                 // overview | group | tasks | faq | custom
      'title_ar' => '', 'title_en' => '', 'summary_ar' => '', 'summary_en' => '', 'icon' => null,
      'audience_roles' => ['security_admin'],   // اختياري: أدوار معيّنة بس
      'items' => [[
          'key' => 'dashboard',          // فريد جوه الدليل كله
          'title_ar' => '', 'title_en' => '', 'summary_ar' => '', 'summary_en' => '',
          'body_ar' => '', 'body_en' => '',
          'route' => '/student/dashboard', 'permission' => 'ai.request_analysis', 'icon' => 'dashboard',
          'steps' => [['ar' => '', 'en' => '']], 'tips' => [['ar' => '', 'en' => '']],
      ]],
  ]],
];
```
الملفات الحالية متولّدة من `navConfig.js` (كل عنصر في الـ sidebar له شرح ومكان)، فلما تضيف صفحة جديدة للـ sidebar أضف عنصرها هنا وشغّل `php artisan guides:sync`، أو خلّي الأدمن يضيفها من وضع التعديل.
