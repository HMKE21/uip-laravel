# UIPE Mail — إدارة الوصول (Backend) — الدفعة الأولى

فك الـ zip فوق مشروع Laravel وبدّل الملفات، وبعدها:
```
php artisan migrate
php artisan optimize:clear
```

## اللي اتضاف
- جداول: mail_access_settings, mail_role_policies, mail_role_blocks, mail_domain_rules, mail_user_overrides
- MailAccessService: التقييم + CRUD (النطاق 0 = المنصة، رقم = جامعة، والأشد يكسب)
- MailAccessApiController: /api/v1/mail-admin/access/*
- UipMailAccessMiddleware (alias mail.access): يمنع الدخول للبريد لو الدور/المستخدم ممنوع "use"

## الـ endpoints
GET overview · PUT settings · PUT roles/{slug} · GET/PUT matrix · GET/POST/DELETE domains · GET/POST/DELETE users · POST check

## اللي بيتفرض
- الدخول للبريد (use) — middleware
- الإرسال/الاستقبال/الإرسال الخارجي، مصفوفة الأدوار، الدومينات المحظورة/المسموحة (+ allowlist)، استثناءات المستخدمين — في resolveRecipients وعند تسليم المجدول
- إنشاء الصناديق (create) — provisionMembers + الصندوق الشخصي (personal_mailbox_creation = auto | admin_only)
- أدمن المنصة مستثنى من كل القيود، وممنوع تحظر دومين المنصة/جامعتك

## ملفات معدّلة
routes/mail.php · bootstrap/app.php · MailboxService · MailTenantService

## ملاحظات
- اتكتب من غير تشغيل (مفيش PHP عندي) — راجع/شغّل وقولي لو حاجة وقعت.
- لسه ماتكتبش: اختبارات PHPUnit، وواجهة /admin/mail (React).
