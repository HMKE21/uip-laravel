# UIPE Mail — المرحلة 7 (الباك) — نسخة جزئية

## اللي في الـ zip
- migration: `2027_11_24_100000_create_mail_security_admin.php` (audit / security events / IP rules / external deliveries / bounces / suppressions + أعمدة retention وlimits وlegal_hold)
- `app/Support/IpMatcher.php` (IPv4/IPv6/CIDR — اتجرّب)
- Services: MailAuditService, MailSecurityService, MailBounceService, MailQueueService, MailRetentionService
- تعديل `MailboxService` (حدود إرسال ساعية، suppression list، retry للإرسال الخارجي، legal hold، retention للسلة)
- تعديل `BrevoExternalMailGateway` (tag للـ webhook) و `config/mailbox.php`

## لسه ناقص (الدفعة الجاية)
Controller + routes + middleware الـ IP + commands (scan/retention/retry) + schedule + audit في أدمن الجامعة + tests.

## ملاحظات
- التفعيل: `php artisan migrate`. الحدود الافتراضية: 200 رسالة/ساعة لكل صندوق (قابلة للتغيير في config).
- اتفحصت بـ `php -l` بس؛ مش متجرّبة تشغيل (مفيش vendor عندي).
- إعادة محاولة الإرسال الخارجي (`retryExternal`) لسه مش متجدولة.
