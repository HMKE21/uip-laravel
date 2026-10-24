<?php

namespace App\Services;

use App\Models\Meeting;
use App\Repositories\MeetingRepository;
use Illuminate\Support\Carbon;

/**
 * Meetings & Collaboration Platform — Round 7 (Collaboration Extras):
 * بند 17 (Attendance Tracking). نفس فلسفة باقي خدمات الموديول: المنطق
 * كله هنا، الكنترولر بس بيتحقق من الشكل ويحوّل الاستثناءات.
 *
 * ليه جدول جديد (meeting_attendance_sessions) بدل الاعتماد على
 * meeting_participants.joined_at/left_at الموجودين من Round 1: العمودين
 * دول صف واحد ثابت لكل شخص، فمايقدروش يعبّروا عن "دخل وخرج ورجع دخل
 * تاني" (بند 17 صراحة عايز "Number of joins"/"Number of leaves" منفصلين
 * وduration إجمالي مش مجرد آخر-دخول لآخر-خروج). راجع docblock migration
 * 2026_08_31_080000 لتفاصيل الشكل.
 *
 * نقطة الدخول/الخروج الحقيقية المستخدمة هنا **مش** نفس نقطة رسالة "Ahmed
 * joined the meeting" في MeetingSignalingService::authorizeChannel()
 * (اللي بتعتمد على last_seen_at===null وده بيفضل مش-null بعد أول مرة،
 * فمش بيتكرر تاني لو الشخص خرج ورجع دخل — سلوك مقصود هناك، بس مش
 * مناسب للحضور هنا). بدل كده: recordJoin()/recordLeave() بيتنادوا مباشرة
 * من authorizeChannel()/leave() (حقن اختياري nullable، نفس نمط $chat في
 * MeetingSignalingService) بمنطق idempotent مستقل بيعتمد بس على "فيه
 * جلسة مفتوحة (left_at=null) لنفس الـ actor ولا لأ" — مش على أي حالة
 * تانية موجودة في meeting_participants.
 */
class MeetingAttendanceService
{
    public function __construct(
        private MeetingRepository $meetings
    ) {
    }

    /** بيتنادى من authorizeChannel() — مفيش تأثير لو فيه جلسة مفتوحة بالفعل (reconnect/refresh عادي). */
    public function recordJoin(Meeting $meeting, array $actor): void
    {
        if ($this->meetings->openAttendanceSession($meeting->id, $actor['key'])) {
            return;
        }

        $this->meetings->createAttendanceSession([
            'meeting_id'      => $meeting->id,
            'participant_key' => $actor['key'],
            'display_name'    => $actor['display_name'],
            'joined_at'       => now(),
        ]);
    }

    /**
     * شبكة أمان ثانية للحضور: بتتنادى من أي طلب "وأنا جوه الغرفة" (media-state/heartbeat). authorizeChannel() لوحده
     * مش مضمون (لو الـ presence channel اتفتح من غير ما يعدّي على الـ auth endpoint، أو الطلب فشل بصمت، الجدول بيفضل
     * فاضي ومفيش تقرير). recordJoin() idempotent فالنداء المتكرر مفيهوش ضرر، واللي حالته "left" بنتجاهله عشان
     * طلب متأخر بعد زرار Leave مايفتحش جلسة جديدة.
     */
    public function ensureJoined(Meeting $meeting, array $actor): void
    {
        $status = $actor['model']->status ?? null;
        if (!in_array($status, ['joined', 'admitted'], true)) {
            return;
        }

        $this->recordJoin($meeting, $actor);
    }

    /** بيتنادى من leave() — بيقفل الجلسة المفتوحة الحالية (لو موجودة). */
    public function recordLeave(Meeting $meeting, array $actor): void
    {
        $session = $this->meetings->openAttendanceSession($meeting->id, $actor['key']);
        if ($session) {
            $session->left_at = now();
            $session->save();
        }
    }

    /** بتتنادى من MeetingService::end() — أي جلسة لسه مفتوحة (حد قافل التاب من غير "Leave") بتتقفل بلحظة نهاية الاجتماع. */
    public function closeAllOpenSessions(Meeting $meeting): void
    {
        $endedAt = $meeting->ended_at ?? now();
        foreach ($this->meetings->openAttendanceSessionsFor($meeting->id) as $session) {
            $session->left_at = $endedAt;
            $session->save();
        }
    }

    /**
     * بند 17 — "Generate an attendance report": صف واحد لكل شخص شارك،
     * مجمّع من كل جلساته. attendance_percentage نسبة إلى مدة الاجتماع
     * الفعلية (started_at → ended_at، أو started_at → الآن لو لسه شغال) —
     * صفر لو الاجتماع أصلًا لسه ما بدأش (started_at=null، مفيش قسمة على
     * صفر).
     *
     * @return array<int,array{
     *   participant_key:string, display_name:string, joined_at:string,
     *   left_at:?string, duration_seconds:int, joins:int, leaves:int,
     *   attendance_percentage:float
     * }>
     */
    public function report(Meeting $meeting): array
    {
        $sessions = $this->meetings->attendanceSessionsFor($meeting->id);
        $now = now();

        // Fallback: anyone who really entered the meeting but has no attendance session (joined before this tracking
        // was reliable, or the join hook never fired) is rebuilt from meeting_participants / admitted guests, so the
        // report is never empty for a meeting people actually attended.
        $known = [];
        foreach ($sessions as $session) {
            $known[$session->participant_key] = true;
        }
        $synthetic = [];
        foreach ($this->meetings->participantsFor($meeting->id) as $p) {
            $key = 'user:' . $p->user_id;
            if (isset($known[$key]) || !$p->joined_at || !in_array($p->status, ['joined', 'left'], true)) {
                continue;
            }
            $synthetic[] = new \App\Models\MeetingAttendanceSession([
                'meeting_id' => $meeting->id, 'participant_key' => $key,
                'display_name' => $p->user->full_name ?? ('User #' . $p->user_id),
                'joined_at' => $p->joined_at, 'left_at' => $p->status === 'left' ? $p->left_at : null,
            ]);
        }
        foreach ($this->meetings->admittedGuestsFor($meeting->id) as $g) {
            $key = 'guest:' . $g->id;
            if (isset($known[$key])) {
                continue;
            }
            $synthetic[] = new \App\Models\MeetingAttendanceSession([
                'meeting_id' => $meeting->id, 'participant_key' => $key,
                'display_name' => (string) $g->guest_name,
                'joined_at' => $g->decided_at ?? $g->requested_at ?? $now, 'left_at' => null,
            ]);
        }
        $sessions = array_merge($sessions, $synthetic);

        $meetingDurationSeconds = $this->meetingDurationSeconds($meeting, $sessions);

        $byParticipant = [];
        foreach ($sessions as $session) {
            $key = $session->participant_key;
            if (!isset($byParticipant[$key])) {
                $byParticipant[$key] = [
                    'participant_key'  => $key,
                    'display_name'     => $session->display_name,
                    'first_joined_at'  => $session->joined_at,
                    'last_left_at'     => $session->left_at,
                    'duration_seconds' => 0,
                    'joins'            => 0,
                    'leaves'           => 0,
                ];
            }

            $row = &$byParticipant[$key];
            $row['joins']++;
            if ($session->left_at !== null) {
                $row['leaves']++;
                $row['last_left_at'] = $session->left_at;
            } else {
                $row['last_left_at'] = null;
            }

            // Carbon 3: diffInSeconds() is SIGNED (start->diffInSeconds(end) is positive), so always older -> newer.
            $sessionEnd = $session->left_at ?? $now;
            $row['duration_seconds'] += (int) max(0, round($session->joined_at->diffInSeconds($sessionEnd)));
            unset($row);
        }

        return array_values(array_map(function ($row) use ($meetingDurationSeconds) {
            $percentage = $meetingDurationSeconds > 0
                ? round(min(100, ($row['duration_seconds'] / $meetingDurationSeconds) * 100), 1)
                : 0.0;

            return [
                'participant_key'        => $row['participant_key'],
                'display_name'           => $row['display_name'],
                'joined_at'              => optional($row['first_joined_at'])->toIso8601String(),
                'left_at'                => optional($row['last_left_at'])->toIso8601String(),
                'duration_seconds'       => $row['duration_seconds'],
                'joins'                  => $row['joins'],
                'leaves'                 => $row['leaves'],
                'attendance_percentage'  => $percentage,
            ];
        }, $byParticipant));
    }

    /**
     * مدة الاجتماع المرجعية للنسبة. لو started_at فاضي (الهوست دخل الغرفة من غير ما يدوس "ابدأ") بنستخدم أول دخول فعلي
     * بدل ما نرجّع 0 ونخلي كل النسب 0%.
     * @param \App\Models\MeetingAttendanceSession[] $sessions
     */
    private function meetingDurationSeconds(Meeting $meeting, array $sessions = []): int
    {
        $start = $meeting->started_at;
        if (!$start) {
            foreach ($sessions as $session) {
                if ($session->joined_at && (!$start || $session->joined_at->lt($start))) {
                    $start = $session->joined_at;
                }
            }
        }
        if (!$start) {
            return 0;
        }

        $end = $meeting->ended_at ?? now();

        return (int) max(0, round($start->diffInSeconds($end)));
    }

    /** بند 17 — "Allow authorized users to export attendance data" (CSV). */
    public function exportCsv(Meeting $meeting): string
    {
        $rows = $this->report($meeting);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Participant', 'Joined', 'Left', 'Duration (min)', 'Joins', 'Leaves', 'Attendance %']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['display_name'],
                $row['joined_at'],
                $row['left_at'] ?? 'Still in meeting',
                round($row['duration_seconds'] / 60, 1),
                $row['joins'],
                $row['leaves'],
                $row['attendance_percentage'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
