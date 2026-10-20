<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تذكير قرب موعد تسليم مشروع التخرج: قبل 7 / 3 / 1 يوم. بيروح لصاحب أي مشروع لسه
 * مسودة أو مرفوض (يعني لسه مقدّمش) ولأعضاء فريقه من الطلبة، في جامعة/كلية الموعد.
 *
 * عدم التكرار: جدول project_deadline_reminders (موعد، مستخدم، عدد الأيام). لو الـ job اتأخر وأول
 * تشغيل بعد ما بقى 2 يوم، بيبعت تذكير "3 أيام" مرة واحدة (أصغر نافذة منطبقة) مش تلاتة مرة واحدة.
 * اللي مفيش له مشروع أصلًا مبيوصلوش تذكير (النظام معندوش قايمة بدفعة التخرج).
 */
class ProjectDeadlineReminderService
{
    private const WINDOWS = [1, 3, 7];

    public function __construct(private NotificationService $notifications)
    {
    }

    /** @return array{deadlines:int, notified:int} */
    public function sweep(): array
    {
        $out = ['deadlines' => 0, 'notified' => 0];
        if (!Schema::hasTable('project_deadlines') || !Schema::hasTable('project_deadline_reminders')) {
            return $out;
        }

        $deadlines = DB::table('project_deadlines')->where('is_active', 1)
            ->whereNotNull('submission_deadline')->where('submission_deadline', '>', now())->get();

        foreach ($deadlines as $d) {
            $secondsLeft = strtotime((string) $d->submission_deadline) - time();
            $daysLeft = (int) ceil($secondsLeft / 86400);
            $window = null;
            foreach (self::WINDOWS as $w) {
                if ($daysLeft <= $w) { $window = $w; break; }   // أصغر نافذة منطبقة
            }
            if ($window === null) {
                continue;
            }
            $out['deadlines']++;

            $projects = DB::table('projects as p')
                ->join('students as s', 's.user_id', '=', 'p.owner_id')
                ->where('s.university_id', $d->university_id)
                ->when($d->faculty_id, fn ($q) => $q->where('s.faculty_id', $d->faculty_id))
                ->whereIn('p.status', ['draft', 'rejected'])->whereNull('p.deleted_at')
                ->get(['p.id', 'p.uuid', 'p.owner_id', 'p.title_ar', 'p.title_en', 'p.status']);
            if ($projects->isEmpty()) {
                continue;
            }

            $members = DB::table('project_team_members')->whereIn('project_id', $projects->pluck('id'))
                ->where('status', 'accepted')->whereNotNull('user_id')->whereIn('role', ['student_member', 'collaborator'])
                ->get(['project_id', 'user_id'])->groupBy('project_id');

            $dateLabel = date('Y-m-d H:i', strtotime((string) $d->submission_deadline));
            foreach ($projects as $p) {
                $recipients = array_unique(array_merge([(int) $p->owner_id], $members->get($p->id, collect())->pluck('user_id')->map(fn ($v) => (int) $v)->all()));
                $title = $p->title_ar ?: $p->title_en;
                foreach ($recipients as $uid) {
                    // insertOrIgnore: لو الصف موجود يبقى اتذكّر قبل كده (unique key)
                    $inserted = DB::table('project_deadline_reminders')->insertOrIgnore([
                        'deadline_id' => $d->id, 'user_id' => $uid, 'days_before' => $window, 'created_at' => now(),
                    ]);
                    if (!$inserted) {
                        continue;
                    }
                    $when = $daysLeft <= 1 ? 'أقل من يوم' : "{$daysLeft} أيام";
                    $this->notifications->notify(
                        $uid, 'project_deadline',
                        $daysLeft <= 1 ? 'آخر يوم لتسليم مشروع التخرج' : 'اقترب موعد تسليم مشروع التخرج',
                        "فاضل {$when} على الموعد ({$dateLabel}) — مشروع \"{$title}\" لسه " . ($p->status === 'rejected' ? 'مرفوض ولم يُعاد تقديمه' : 'مسودة ولم يُقدَّم') . '.',
                        '/student/projects/' . $p->uuid,
                        $daysLeft <= 1 ? 'high' : 'normal'
                    );
                    $out['notified']++;
                }
            }
        }

        return $out;
    }
}
