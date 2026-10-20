<?php

namespace App\Console\Commands;

use App\Repositories\MeetingRepository;
use App\Services\MeetingPolicyService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Meetings & Collaboration Platform — Round 3 (Signaling، بند 12 —
 * Meeting Notifications: "Meeting starting soon"، "Support configurable
 * reminders"). نفس نمط NotifyUpcomingExamEvents بالظبط (sweep دوري، مش
 * event-driven، لأنه مبني على الوقت الحالي نفسه).
 *
 * Dedup: مختلف عن نمط الـ exam (link_url check) — هنا بنستخدم عمود
 * مخصص meetings.starting_soon_reminder_sent_at (migration
 * 2026_08_31_030000) بدل ما نفحص وجود صف Notification لكل مستخدم لكل
 * اجتماع، لأن العدد هنا أصلًا اجتماع واحد = تذكير واحد لكل المشاركين
 * (مش لكل يوزر لوحده زي الامتحان)، فعمود واحد على meetings كافي ومحقق
 * نفس الغرض بكفاءة أعلى (استعلام واحد بدل N استعلام).
 *
 * الشباك الزمني قابل للتعديل من الأدمن (MeetingPolicyService::
 * reminderMinutesBefore()، افتراضي 10 دقايق) — "Support configurable
 * reminders" في بند 12 بالظبط.
 *
 * سجّلها في crontab حقيقي بتاع السيرفر (كل دقيقة كافية، الشباك بالدقايق):
 *   * * * * * cd /path/to/project && php artisan meetings:send-starting-soon-reminders >> storage/logs/cron.log 2>&1
 * أو عبر جدولة لارافيل نفسها في routes/console.php:
 *   Schedule::command('meetings:send-starting-soon-reminders')->everyMinute();
 */
class SendMeetingStartingSoonReminders extends Command
{
    protected $signature = 'meetings:send-starting-soon-reminders';

    protected $description = 'Notifies invited/joined participants of scheduled meetings starting within the configured reminder window.';

    public function handle(MeetingRepository $meetings, MeetingPolicyService $policy, NotificationService $notifications): int
    {
        $minutesBefore = $policy->reminderMinutesBefore();
        if ($minutesBefore <= 0) {
            $this->info('Reminders are disabled (meeting_reminder_minutes_before <= 0).');
            return self::SUCCESS;
        }

        $now = now();
        $windowEnd = $now->copy()->addMinutes($minutesBefore);

        // اجتماعات scheduled لسه، ميعادها جوه الشباك، ولسه ما اتبعتش
        // لها تذكير. بند 12: مفيش هنا اجتماعات type=instant (مالهاش
        // scheduled_start_at أصلًا، فمالهاش معنى "starting soon").
        $due = DB::table('meetings')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_start_at')
            ->whereNull('starting_soon_reminder_sent_at')
            ->where('scheduled_start_at', '>=', $now)
            ->where('scheduled_start_at', '<=', $windowEnd)
            ->get(['id', 'title', 'host_user_id', 'scheduled_start_at']);

        $sentCount = 0;
        foreach ($due as $meetingRow) {
            $participants = $meetings->participantsFor($meetingRow->id);

            foreach ($participants as $participant) {
                if (in_array($participant->status, ['declined', 'removed'], true)) {
                    continue;
                }
                $notifications->notify(
                    $participant->user_id,
                    'meeting_starting_soon',
                    'Meeting starting soon: ' . $meetingRow->title,
                    'Starts at ' . $meetingRow->scheduled_start_at . '.',
                    null,
                    'high'
                );
            }

            DB::table('meetings')->where('id', $meetingRow->id)->update(['starting_soon_reminder_sent_at' => $now]);
            $sentCount++;
        }

        $this->info("Starting-soon reminders sent for {$sentCount} meeting(s).");
        return self::SUCCESS;
    }
}
