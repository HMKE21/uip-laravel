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
        $meetingDurationSeconds = $this->meetingDurationSeconds($meeting);
        $now = now();

        $byParticipant = [];
        foreach ($this->meetings->attendanceSessionsFor($meeting->id) as $session) {
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

            $sessionEnd = $session->left_at ?? $now;
            $row['duration_seconds'] += max(0, $sessionEnd->diffInSeconds($session->joined_at));
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

    private function meetingDurationSeconds(Meeting $meeting): int
    {
        if (!$meeting->started_at) {
            return 0;
        }

        $end = $meeting->ended_at ?? now();

        return max(0, $end->diffInSeconds($meeting->started_at));
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
