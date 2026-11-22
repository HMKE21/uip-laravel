<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// بند 22 — App\Console\Commands\PublishScheduledAnnouncements منقولة من
// cron/publish_scheduled_announcements.php القديمة. زي القديمة بالظبط،
// شغّلها من crontab حقيقي بتاع السيرفر كل 5 دقايق (مش مفعّلة هنا عبر
// Laravel's own scheduler عشان نفس آلية التشغيل القديمة تفضل زي ما هي):
//   php artisan announcements:publish-due
// لو حبيت تستخدم جدولة لارافيل نفسها بدل crontab يدوي، ضيف بدل التعليق ده:
//   Schedule::command('announcements:publish-due')->everyFiveMinutes();

// Exam & Assessment System — Round 3 (Phase 11 backstop). App\Console\
// Commands\AutoSubmitExpiredExamAttempts — نفس مبدأ السطرين فوق بالظبط.
// شغّلها من crontab كل دقيقة (مش مفعّلة هنا افتراضيًا):
//   php artisan exams:auto-submit-expired
// أو عبر جدولة لارافيل:
//   Schedule::command('exams:auto-submit-expired')->everyMinute();

// Exam & Assessment System — Round 8 (Phase 29). App\Console\Commands\
// NotifyUpcomingExamEvents — "exam starts soon"/"deadline approaching"،
// الإشعارين الوحيدين اللي وقت-مبني مش حدث-مبني (راجع docblock الكلاس).
// شغّلها من crontab كل 15 دقيقة (مش مفعّلة هنا افتراضيًا):
//   php artisan exams:notify-upcoming
// أو عبر جدولة لارافيل:
//   Schedule::command('exams:notify-upcoming')->everyFifteenMinutes();

// Meetings & Collaboration Platform — Round 3 (Signaling، بند 12).
// App\Console\Commands\SendMeetingStartingSoonReminders — تذكير
// "starting soon" قابل للتعديل من الأدمن (MeetingPolicyService::
// reminderMinutesBefore()). شغّلها من crontab كل دقيقة (مش مفعّلة هنا
// افتراضيًا):
//   php artisan meetings:send-starting-soon-reminders
// أو عبر جدولة لارافيل:
//   Schedule::command('meetings:send-starting-soon-reminders')->everyMinute();

// تذكيرات الامتحانات التلقائية (قبل الفتح/الإقفال) — مفعّلة فعليًا هنا، بتحتاج بس cron السيرفر:
//   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('exams:notify-upcoming')->everyFiveMinutes()->withoutOverlapping();

// إيميلات إشعارات مشاريع التخرج (تقديم/قرار/تقييم/ملاحظة) + تذكيرات قرب موعد التسليم — مفعّلة هنا،
// ومحتاجة نفس cron السيرفر اللي فوق (schedule:run كل دقيقة) أو عملية `php artisan schedule:work` دايمة.
Schedule::command('notifications:send-emails')->everyMinute()->withoutOverlapping();
Schedule::command('projects:send-deadline-reminders')->hourly()->withoutOverlapping();

// Keep the disposable-email blocklist fresh (new temp-mail domains appear all the time).
Schedule::command('mail:update-disposable-domains')->weekly()->withoutOverlapping();

// سلة محذوفات المحادثات: حذف نهائي لكل اللي عدّى عليه 30 يوم.
Schedule::command('messaging:purge-trash')->dailyAt('03:30')->withoutOverlapping();

// "جهازي" (My PC): مسح نهائي لعناصر سلة الملفات اللي عدّى عليها DRIVE_TRASH_DAYS (30 افتراضيًا).
Schedule::command('drive:purge-trash')->dailyAt('04:00')->withoutOverlapping();

// "جهازي": إعادة التهيئة ليها مهلة تراجع 24 ساعة، وبعدها المسح النهائي.
Schedule::command('drive:purge-resets')->hourly()->withoutOverlapping();

// الاستوري: مسح اللي خلصت من أكتر من يوم (ملفات + صفوف). نفس مبدأ الأوامر اللي فوق —
// شغّلها من crontab كل ساعة (مش لازم لعمل الميزة: الاستوري المنتهية بتتفلتر من الاستعلام أصلًا، ده تنضيف مساحة بس):
//   php artisan stories:prune
// أو عبر جدولة لارافيل: Schedule::command('stories:prune')->hourly()->withoutOverlapping();

// المكالمات: تنضيف تخزين بس (الصفوف المنتهية الأقدم من CALLS_PRUNE_DAYS + أي مكالمة عالقة). صحة السلوك مش معتمدة عليه —
// المكالمات المعلّقة بتتقفل كسولًا مع أي poll.
Schedule::command('calls:prune')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('posts:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('posts:prune')->dailyAt('04:45')->withoutOverlapping();

// Per-user activity timeline: keep ACTIVITY_LOG_RETENTION_DAYS (90) days.
Schedule::command('activity:prune')->dailyAt('04:30')->withoutOverlapping();

// Smart Forms — auto-submit expired exam attempts + prune orphan uploads. Run from crontab every 5 minutes:
//   php artisan forms:maintenance
// or via Laravel's scheduler: Schedule::command('forms:maintenance')->everyFiveMinutes();

// UIPE Mail (المرحلة 3): تسليم الرسائل المجدولة / اللي فترة Undo بتاعتها خلصت، وإيقاظ المؤجّلات — كل دقيقة (محتاج cron schedule:run).
// حتى لو الـ scheduler واقف، GET /api/v1/mail/account بيسلّم رسائل صندوق المستخدم الحالي لوحده (تسليم كسول).
Schedule::command('mail:process')->everyMinute()->withoutOverlapping();
// مسح سلة البريد النهائي بعد mailbox.trash_days (30 يوم).
Schedule::command('mail:process --purge')->dailyAt('04:10')->withoutOverlapping();

// UIPE Mail (المرحلة 5): إنشاء الصناديق الناقصة تلقائيًا + تنظيف سجل الدخول القديم — كل 10 دقايق / يوميًا.
Schedule::command('mail:identity-sync --provision')->everyTenMinutes()->withoutOverlapping();
// تعليق صناديق الأعضاء المنتهية عضويتهم (تخرّج/إيقاف/انتهاء تاريخ) واستئناف اللي رجعوا — كل ساعة.
Schedule::command('mail:identity-sync --membership')->hourly()->withoutOverlapping();
Schedule::command('mail:identity-sync --prune')->dailyAt('04:20')->withoutOverlapping();

// Developer Center (المرحلة 6): إعادة محاولات الـ Webhooks كل دقيقة + تنضيف يومي. محتاج schedule:run كل دقيقة.
Schedule::command('dev:maintenance --webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('dev:maintenance --prune')->dailyAt('05:00')->withoutOverlapping();

// UIPE Mail (المرحلة 7): مراقبة النشاط المشبوه (brute-force / bounce spike / مسح قواعد IP المنتهية) كل 5 دقايق،
// وسياسات الاحتفاظ لكل جامعة + تنضيف سجلات التشغيل يوميًا. (إعادة محاولة الإرسال الخارجي جوّه mail:process كل دقيقة.)
Schedule::command('mail:security-scan')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mail:retention')->dailyAt('04:40')->withoutOverlapping();

// Lexicon Hub: مزامنة قوايم الدومينات/الكلمات المحظورة (هاشات موقّعة) يوميًا — بتشتغل بس لو CONTENT_SAFETY_REMOTE_URLS متظبط.
// (بديل الـ crontab المنفصل: `30 3 * * * cd /var/www/uipe && php artisan lexicon:sync`. اختار واحد بس، التكرار مش بيضر لأن نفس الإصدار = no-op.)
Schedule::command('lexicon:sync')->dailyAt('03:20')->withoutOverlapping()->when(fn () => !empty(config('content_safety.remote.urls')));

/*
 * ─── العملاء المعزولين (Multi-tenancy — الخطوة 5) ─────────────────────────────────────────────────────────────
 * الأوامر اللي فوق بتشتغل على قاعدة المنصة الأم بس. كل أمر بيشتغل على بيانات (جدول) لازم يتشغّل كمان على كل عميل:
 * tenants:run بيلف على العملاء الجاهزين ويفعّل قاعدة كل واحد. --feature بيتخطّى اللي الميزة مقفولة عنده.
 * مش متكررين هنا (خاصين بالمنصة بس): mail:update-disposable-domains · lexicon:sync · dev:maintenance (جداول dev_* عند العميل فاضية).
 */
Schedule::command('tenants:run', ['exams:notify-upcoming', '--feature=exams'])->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tenants:run', ['notifications:send-emails'])->everyMinute()->withoutOverlapping();
Schedule::command('tenants:run', ['projects:send-deadline-reminders', '--feature=academic'])->hourly()->withoutOverlapping();
Schedule::command('tenants:run', ['messaging:purge-trash', '--feature=messaging'])->dailyAt('03:30')->withoutOverlapping();
Schedule::command('tenants:run', ['drive:purge-trash', '--feature=drive'])->dailyAt('04:00')->withoutOverlapping();
Schedule::command('tenants:run', ['drive:purge-resets', '--feature=drive'])->hourly()->withoutOverlapping();
Schedule::command('tenants:run', ['calls:prune', '--feature=messaging'])->dailyAt('04:30')->withoutOverlapping();
Schedule::command('tenants:run', ['posts:publish-due', '--feature=feed'])->everyMinute()->withoutOverlapping();
Schedule::command('tenants:run', ['posts:prune', '--feature=feed'])->dailyAt('04:45')->withoutOverlapping();
Schedule::command('tenants:run', ['activity:prune'])->dailyAt('04:30')->withoutOverlapping();
Schedule::command('tenants:run', ['mail:process', '--feature=mail'])->everyMinute()->withoutOverlapping();
Schedule::command('tenants:run', ['mail:process --purge', '--feature=mail'])->dailyAt('04:10')->withoutOverlapping();
Schedule::command('tenants:run', ['mail:identity-sync --provision', '--feature=mail'])->everyTenMinutes()->withoutOverlapping();
Schedule::command('tenants:run', ['mail:identity-sync --membership', '--feature=mail'])->hourly()->withoutOverlapping();
Schedule::command('tenants:run', ['mail:identity-sync --prune', '--feature=mail'])->dailyAt('04:20')->withoutOverlapping();
Schedule::command('tenants:run', ['mail:security-scan', '--feature=mail'])->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tenants:run', ['mail:retention', '--feature=mail'])->dailyAt('04:40')->withoutOverlapping();

// دومينات العملاء: تفعيل تلقائي (فحص DNS) + طلب/فحص شهادة SSL بدون تدخل يدوي. بيحتاج schedule:run كل دقيقة.
Schedule::command('tenant:domains-check')->everyMinute()->withoutOverlapping();
