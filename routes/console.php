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
