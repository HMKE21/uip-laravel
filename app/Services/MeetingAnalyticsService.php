<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\MeetingFile;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Meetings & Collaboration Platform — Round 10 (Admin & Docs):
 * بند 15 ("Meeting Dashboard" -> "Meeting Analytics" widget، per-meeting)
 * وبند 39 ("Admin Monitoring" -> platform-wide). نفس تقسيمة المواصفة
 * نفسها بين البندين: forMeeting() لصاحب/host اجتماع واحد بيشوف إحصائيات
 * اجتماعه هو بس (مفيش أي محتوى خاص زي الشات/التسجيل نفسه — أرقام
 * مجمّعة بس، زي attendance report الموجود بالفعل)، platformMonitoring()
 * للأدمن بس (بند 39: "Admins should NOT automatically have access to
 * private meeting content" — النطاق هنا برضه أرقام/عدادات، مش محتوى).
 *
 * "Failed connection statistics"/"Security events" مصدرهم audit_logs
 * (راجع docblock MeetingsSignalingApiController::reportFailure() —
 * مفيش جدول مخصص لأن الحدث ده أصلًا بيتسجل هناك دلوقتي، بدل ما نضيف
 * جدول تاني بيكرر نفس المعلومة). "Storage usage" مصدره
 * meeting_recordings.size_bytes (SUM)، نفس الملفات الفعلية اللي
 * FileUploadService خزّنها في Round 9.
 */
class MeetingAnalyticsService
{
    /** بند 39 — actions اللي بتترص جوه "security_events" (لوك، إزالة، فشل باسورد، فشل اتصال). */
    private const SECURITY_ACTIONS = [
        'meetings.locked',
        'meetings.participant_removed',
        'meetings.join_failed_wrong_password',
        'meetings.connection_failure',
    ];

    /**
     * بند 15 — "Meeting Analytics" widget لاجتماع واحد. بيتنادى بعد ما
     * canView() اتأكد في الكنترولر (راجع MeetingsAnalyticsApiController).
     * @return array<string,mixed>
     */
    public function forMeeting(Meeting $meeting): array
    {
        $participants = MeetingParticipant::where('meeting_id', $meeting->id)->get();
        $joinCount = $participants->whereIn('status', ['joined', 'left'])->count();
        $currentlyConnected = $participants->where('connection_state', 'connected')->count();

        $recordings = MeetingRecording::where('meeting_id', $meeting->id)->get();

        $durationMinutes = null;
        if ($meeting->started_at && $meeting->ended_at) {
            $durationMinutes = $meeting->started_at->diffInMinutes($meeting->ended_at);
        }

        $failedConnections = AuditLog::where('subject_type', 'Meeting')
            ->where('subject_id', $meeting->id)
            ->where('action', 'meetings.connection_failure')
            ->count();

        return [
            'meeting_id'             => $meeting->id,
            'status'                 => $meeting->status,
            'invited_count'          => $meeting->invitations()->count(),
            'joined_count'           => $joinCount,
            'currently_connected'    => $currentlyConnected,
            'peak_participants'      => max($joinCount, $currentlyConnected),
            'duration_minutes'       => $durationMinutes,
            'recordings_count'       => $recordings->count(),
            'recordings_storage_bytes' => (int) $recordings->sum('size_bytes'),
            'failed_connections'     => $failedConnections,
            'chat_messages_count'    => $meeting->chatMessages()->count(),
            'files_shared_count'     => MeetingFile::where('meeting_id', $meeting->id)->count(),
        ];
    }

    /**
     * بند 39 — لوحة مراقبة الأدمن بالكامل. $days بيتحكم في نطاق
     * "meetings this week"-جزء الفترة الافتراضي (7 أيام).
     * @return array<string,mixed>
     */
    public function platformMonitoring(): array
    {
        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $weekStart = $now->copy()->startOfWeek();

        $totalMeetings = Meeting::count();
        $activeMeetings = Meeting::where('status', 'live')->count();
        $meetingsToday = Meeting::where('created_at', '>=', $todayStart)->count();
        $meetingsThisWeek = Meeting::where('created_at', '>=', $weekStart)->count();

        $activeParticipants = MeetingParticipant::where('connection_state', 'connected')->count();

        $completed = Meeting::whereNotNull('started_at')->whereNotNull('ended_at')->get(['started_at', 'ended_at']);
        $durations = $completed->map(fn ($m) => $m->started_at->diffInMinutes($m->ended_at));
        $avgDuration = $durations->count() > 0 ? round($durations->avg(), 1) : null;
        $maxDuration = $durations->count() > 0 ? $durations->max() : null;

        $failedConnections = AuditLog::where('action', 'meetings.connection_failure')->count();
        $failedConnectionsToday = AuditLog::where('action', 'meetings.connection_failure')->where('created_at', '>=', $todayStart)->count();

        $securityEvents = AuditLog::whereIn('action', self::SECURITY_ACTIONS)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'action', 'subject_id', 'user_id', 'created_at']);

        $totalRecordings = MeetingRecording::count();
        $storageBytes = (int) MeetingRecording::sum('size_bytes');
        $recordingsByStatus = MeetingRecording::select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'active_meetings'          => $activeMeetings,
            'active_participants'      => $activeParticipants,
            'total_meetings'           => $totalMeetings,
            'meetings_today'           => $meetingsToday,
            'meetings_this_week'       => $meetingsThisWeek,
            'duration_stats'           => [
                'average_minutes' => $avgDuration,
                'max_minutes'     => $maxDuration,
                'sample_size'     => $durations->count(),
            ],
            'failed_connections'       => [
                'total' => $failedConnections,
                'today' => $failedConnectionsToday,
            ],
            'security_events'          => $securityEvents->map(fn ($row) => [
                'id'         => $row->id,
                'action'     => $row->action,
                'meeting_id' => $row->subject_id,
                'user_id'    => $row->user_id,
                'created_at' => $row->created_at,
            ])->all(),
            'recordings'                => [
                'total'        => $totalRecordings,
                'by_status'    => $recordingsByStatus,
                'storage_bytes' => $storageBytes,
            ],
        ];
    }
}
