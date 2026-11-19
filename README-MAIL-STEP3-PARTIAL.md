# UIPE Mail - الخطوة 3 (جزء الباك إند فقط - لسه مش كاملة)

## الملفات (انسخها فوق uip-laravel-main)
- database/migrations/2027_11_20_100000_create_mail_advanced_tables.php  (migration جديدة: php artisan migrate)
- app/Services/Mail/MailboxService.php (معدّل)
- app/Services/Mail/MailFilterEngine.php (جديد)
- app/Services/Mail/MailSettingsService.php (جديد)
- app/Services/Mail/MailPortabilityService.php (جديد)
- app/Http/Controllers/Api/MailApiController.php (معدّل)

## اللي اتعمل في الخدمات
Snooze، Scheduled، Undo Send (release / cancel-send)، Confidential (باسورد/انتهاء/سحب/منع التحويل)،
Filters عند التسليم، Vacation Reply، Signatures/Templates/Settings، Import (mbox/eml) وExport (mbox/json).
التواريخ في الـ API بقت ISO-8601 بدل نص UTC خام.

## لسه ناقص (الدفعة الجاية)
- MailAdvancedController + routes/mail.php (مفيش routes للتوقيعات/القوالب/الفلاتر/Vacation/Import/Export/release/cancel-send/unlock/revoke لحد دلوقتي)
- أمر schedule (mail:process) + سطره في routes/console.php
- كل الفرونت (MailSettings، Snooze، Schedule send، Undo، Confidential، القوالب)

## تنبيه
مفيش PHP عندي، فالكود ما اتشغّلش ولا اتعمله php -l. اتراجع بالعين بس. جرّب migrate ثم افتح /account وشوف إنه شغال.
