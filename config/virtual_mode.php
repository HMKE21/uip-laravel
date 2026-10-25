<?php
/**
 * "الوضع الافتراضي" (UIP OS) — المنصة كلها جوّه سطح مكتب "جهازي".
 *
 *  enabled : مفتاح عام لإيقاف الميزة من الـ .env (VIRTUAL_MODE_ENABLED=false) من غير deploy للفرونت.
 *  roles   : الأدوار اللي الوضع متاح ليها. حاليًا الطالب بس؛ لتوسيعه أضف الدور هنا (والفرونت بيقرأ available من الـ API).
 */
return [
    'enabled' => (bool) env('VIRTUAL_MODE_ENABLED', true),

    'roles' => ['student'],
];
