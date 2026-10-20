<?php

namespace App\Repositories;

use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingChatMessage;
use App\Models\MeetingChatMessageReaction;
use App\Models\MeetingFile;
use App\Models\MeetingInvitation;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingNote;
use App\Models\MeetingParticipant;
use App\Models\MeetingPoll;
use App\Models\MeetingPollOption;
use App\Models\MeetingPollVote;
use App\Models\MeetingRecording;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Meetings & Collaboration Platform — Round 1 (Foundation). نفس نمط
 * ExamRepository::findOwned() بالظبط لـ findHosted() هنا، بس الملكية
 * هنا على مستوى users.id مباشرة (host_user_id) مش عبر جدول وسيط زي
 * academic_staff.
 */
class MeetingRepository
{
    public function find($id): ?Meeting
    {
        return Meeting::find($id);
    }

    public function findByUuid(string $uuid): ?Meeting
    {
        return Meeting::where('uuid', $uuid)->first();
    }

    /** بيبحث بـ join_token بدل uuid — سطح "الدخول للاجتماع" العام (Round 2). */
    public function findByJoinToken(string $token): ?Meeting
    {
        return Meeting::where('join_token', $token)->first();
    }

    /** Ownership-checked lookup — مقيدة بـ host_user_id بتاع المستخدم نفسه. */
    public function findHosted($id, $hostUserId): ?Meeting
    {
        $meeting = Meeting::find($id);
        if (!$meeting || (int) $meeting->host_user_id !== (int) $hostUserId) {
            return null;
        }
        return $meeting;
    }

    /** @return Meeting[] كل الاجتماعات اللي المستخدم ده مضيفها أو مشارك مدعو فيها. */
    public function forUser($userId): array
    {
        return Meeting::where('host_user_id', $userId)
            ->orWhereHas('participants', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function create(array $data): Meeting
    {
        return Meeting::create($data);
    }

    /** توليد uuid فريد لسطح الـ API العام (مايتسربش الـ id التسلسلي). */
    public function generateUniqueUuid(): string
    {
        do {
            $uuid = (string) Str::uuid();
        } while (Meeting::where('uuid', $uuid)->exists());

        return $uuid;
    }

    /** كود اجتماع قابل للنطق/الكتابة يدويًا (زي Zoom Meeting ID) — 10 أرقام. */
    public function generateUniqueMeetingCode(): string
    {
        do {
            $code = (string) random_int(1000000000, 9999999999);
        } while (Meeting::where('meeting_code', $code)->exists());

        return $code;
    }

    /** توكن انضمام طويل وعشوائي — هو الجزء الفعلي اللي بيمنع التخمين. */
    public function generateUniqueJoinToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (Meeting::where('join_token', $token)->exists());

        return $token;
    }

    // -----------------------------------------------------------------
    // Participants
    // -----------------------------------------------------------------

    public function participantsFor($meetingId): array
    {
        return MeetingParticipant::with('user')
            ->where('meeting_id', $meetingId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function findParticipant($meetingId, $userId): ?MeetingParticipant
    {
        return MeetingParticipant::where('meeting_id', $meetingId)->where('user_id', $userId)->first();
    }

    public function addParticipant(array $data): MeetingParticipant
    {
        return MeetingParticipant::create($data);
    }

    public function isParticipant($meetingId, $userId): bool
    {
        return MeetingParticipant::where('meeting_id', $meetingId)
            ->where('user_id', $userId)
            ->whereNotIn('status', ['removed', 'declined'])
            ->exists();
    }

    // -----------------------------------------------------------------
    // Invitations
    // -----------------------------------------------------------------

    public function invitationsFor($meetingId): array
    {
        return MeetingInvitation::with('invitedUser')
            ->where('meeting_id', $meetingId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function pendingInvitationsFor($userId): array
    {
        return MeetingInvitation::with('meeting', 'invitedBy')
            ->where('invited_user_id', $userId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function findInvitation($meetingId, $invitedUserId): ?MeetingInvitation
    {
        return MeetingInvitation::where('meeting_id', $meetingId)->where('invited_user_id', $invitedUserId)->first();
    }

    public function findInvitationByToken(string $token): ?MeetingInvitation
    {
        return MeetingInvitation::where('token', $token)->first();
    }

    public function generateUniqueInvitationToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (MeetingInvitation::where('token', $token)->exists());

        return $token;
    }

    public function createInvitation(array $data): MeetingInvitation
    {
        return MeetingInvitation::create($data);
    }

    public function saveInvitation(MeetingInvitation $invitation): MeetingInvitation
    {
        $invitation->save();
        return $invitation;
    }

    // -----------------------------------------------------------------
    // Join Requests (Round 2 — Lobby & Access: Waiting Room + Guest
    // Access). راجع docblock migration 2026_08_31_010000 وموديل
    // MeetingJoinRequest لتفاصيل ليه الجدول ده منفصل عن meeting_participants.
    // -----------------------------------------------------------------

    public function findJoinRequest($id): ?MeetingJoinRequest
    {
        return MeetingJoinRequest::find($id);
    }

    /** Ownership-checked lookup — مقيدة بالاجتماع نفسه (زي findHosted() لكن للـ join requests). */
    public function findJoinRequestForMeeting($meetingId, $id): ?MeetingJoinRequest
    {
        return MeetingJoinRequest::where('meeting_id', $meetingId)->where('id', $id)->first();
    }

    /** طلب pending موجود بالفعل لنفس اليوزر المسجّل — عشان نعيد استخدامه بدل تكرار الصفوف. */
    public function pendingJoinRequestForUser($meetingId, $userId): ?MeetingJoinRequest
    {
        return MeetingJoinRequest::where('meeting_id', $meetingId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
    }

    /** @return MeetingJoinRequest[] قائمة الـ waiting room بتاعة الهوست، الأقدم أولًا (FIFO). */
    public function pendingJoinRequestsFor($meetingId): array
    {
        return MeetingJoinRequest::with('user')
            ->where('meeting_id', $meetingId)
            ->where('status', 'pending')
            ->orderBy('requested_at')
            ->get()
            ->all();
    }

    /** @return MeetingJoinRequest[] الضيوف المقبولين حاليًا (بند 23) — مفيش صف موازي ليهم في meeting_participants. */
    public function admittedGuestsFor($meetingId): array
    {
        return MeetingJoinRequest::where('meeting_id', $meetingId)
            ->whereNull('user_id')
            ->where('status', 'admitted')
            ->orderBy('decided_at')
            ->get()
            ->all();
    }

    /** المشاركين الفعليين دلوقتي = يوزرز مسجلين status=joined + ضيوف مقبولين — عشان فحص max_participants. */
    public function countActiveParticipants($meetingId): int
    {
        $joinedUsers = MeetingParticipant::where('meeting_id', $meetingId)->where('status', 'joined')->count();
        $admittedGuests = MeetingJoinRequest::where('meeting_id', $meetingId)->whereNull('user_id')->where('status', 'admitted')->count();

        return $joinedUsers + $admittedGuests;
    }

    public function createJoinRequest(array $data): MeetingJoinRequest
    {
        $data['requested_at'] = $data['requested_at'] ?? now();

        return MeetingJoinRequest::create($data);
    }

    public function saveJoinRequest(MeetingJoinRequest $joinRequest): MeetingJoinRequest
    {
        $joinRequest->save();

        return $joinRequest;
    }

    // -----------------------------------------------------------------
    // Chat (Round 5 — Live Collaboration: بند 7 Meeting Chat، بند 8
    // Private Chat). راجع docblock migration 2026_08_31_060000 وMeetingChatService.
    // -----------------------------------------------------------------

    public function findChatMessage($meetingId, $id): ?MeetingChatMessage
    {
        return MeetingChatMessage::where('meeting_id', $meetingId)->where('id', $id)->first();
    }

    public function createChatMessage(array $data): MeetingChatMessage
    {
        return MeetingChatMessage::create($data);
    }

    /**
     * @return MeetingChatMessage[] الرسائل اللي actor معيّن مسموحله يشوفها
     *         (كل الرسائل العامة + الرسائل الخاصة اللي هو طرف فيها بس)،
     *         الأقدم أولًا. $afterId (لو موجود) بيرجع بس الأحدث منه —
     *         بند 7 ("Chat should update in real time") لسه بيغطي
     *         التحديث اللحظي عبر الـ WebSocket، الـ endpoint ده لتحميل
     *         التاريخ الأول/بعد إعادة اتصال بس.
     */
    public function chatMessagesVisibleTo($meetingId, string $actorKey, ?int $afterId = null, int $limit = 200): array
    {
        $query = MeetingChatMessage::with('reactions')
            ->where('meeting_id', $meetingId)
            ->where(function ($q) use ($actorKey) {
                $q->whereNull('recipient_key')
                    ->orWhere('recipient_key', $actorKey)
                    ->orWhere('sender_key', $actorKey);
            });

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        return $query->orderBy('id')->limit($limit)->get()->all();
    }

    public function findChatReaction($messageId, string $actorKey, string $emoji): ?MeetingChatMessageReaction
    {
        return MeetingChatMessageReaction::where('message_id', $messageId)
            ->where('actor_key', $actorKey)
            ->where('emoji', $emoji)
            ->first();
    }

    public function addChatReaction(array $data): MeetingChatMessageReaction
    {
        return MeetingChatMessageReaction::create($data);
    }

    public function deleteChatReaction(MeetingChatMessageReaction $reaction): void
    {
        $reaction->delete();
    }

    // -----------------------------------------------------------------
    // Attendance (Round 7 — بند 17). راجع docblock migration
    // 2026_08_31_080000 وMeetingAttendanceService.
    // -----------------------------------------------------------------

    public function createAttendanceSession(array $data): MeetingAttendanceSession
    {
        return MeetingAttendanceSession::create($data);
    }

    public function openAttendanceSession($meetingId, string $participantKey): ?MeetingAttendanceSession
    {
        return MeetingAttendanceSession::where('meeting_id', $meetingId)
            ->where('participant_key', $participantKey)
            ->whereNull('left_at')
            ->latest('id')
            ->first();
    }

    /** @return MeetingAttendanceSession[] كل جلسات الحضور المفتوحة (left_at=null) في اجتماع معيّن — لقفلها كلها عند end(). */
    public function openAttendanceSessionsFor($meetingId): array
    {
        return MeetingAttendanceSession::where('meeting_id', $meetingId)->whereNull('left_at')->get()->all();
    }

    /** @return MeetingAttendanceSession[] كل جلسات الحضور لاجتماع معيّن، الأقدم أولًا — أساس التقرير/التصدير. */
    public function attendanceSessionsFor($meetingId): array
    {
        return MeetingAttendanceSession::where('meeting_id', $meetingId)->orderBy('joined_at')->get()->all();
    }

    // -----------------------------------------------------------------
    // File Sharing (Round 7 — بند 19).
    // -----------------------------------------------------------------

    public function findMeetingFile($meetingId, $id): ?MeetingFile
    {
        return MeetingFile::where('meeting_id', $meetingId)->where('id', $id)->first();
    }

    public function createMeetingFile(array $data): MeetingFile
    {
        return MeetingFile::create($data);
    }

    /** @return MeetingFile[] */
    public function meetingFilesFor($meetingId): array
    {
        return MeetingFile::where('meeting_id', $meetingId)->orderByDesc('id')->get()->all();
    }

    // -----------------------------------------------------------------
    // Meeting Notes (Round 7 — بند 20).
    // -----------------------------------------------------------------

    public function findOrCreateNotes($meetingId): MeetingNote
    {
        return MeetingNote::firstOrCreate(['meeting_id' => $meetingId], ['body' => '']);
    }

    public function findActionItem($meetingId, $id): ?MeetingActionItem
    {
        return MeetingActionItem::where('meeting_id', $meetingId)->where('id', $id)->first();
    }

    public function createActionItem(array $data): MeetingActionItem
    {
        return MeetingActionItem::create($data);
    }

    /** @return MeetingActionItem[] */
    public function actionItemsFor($meetingId): array
    {
        return MeetingActionItem::where('meeting_id', $meetingId)->orderBy('id')->get()->all();
    }

    // -----------------------------------------------------------------
    // Polls (Round 7 — بند 21).
    // -----------------------------------------------------------------

    public function findPoll($meetingId, $id): ?MeetingPoll
    {
        return MeetingPoll::where('meeting_id', $meetingId)->where('id', $id)->first();
    }

    public function createPoll(array $data): MeetingPoll
    {
        return MeetingPoll::create($data);
    }

    public function createPollOption(array $data): MeetingPollOption
    {
        return MeetingPollOption::create($data);
    }

    public function findPollOption($pollId, $id): ?MeetingPollOption
    {
        return MeetingPollOption::where('poll_id', $pollId)->where('id', $id)->first();
    }

    /** @return MeetingPoll[] */
    public function pollsFor($meetingId): array
    {
        return MeetingPoll::with('options')->where('meeting_id', $meetingId)->orderByDesc('id')->get()->all();
    }

    public function findExistingVote($pollId, $optionId, string $voterKey): ?MeetingPollVote
    {
        return MeetingPollVote::where('poll_id', $pollId)
            ->where('option_id', $optionId)
            ->where('voter_key', $voterKey)
            ->first();
    }

    /** كل أصوات actor معيّن على استفتاء معيّن (بغض النظر عن الاختيار) — عشان single-choice نمسحهم كلهم قبل تسجيل صوت جديد. */
    public function deleteVotesFor($pollId, string $voterKey): void
    {
        MeetingPollVote::where('poll_id', $pollId)->where('voter_key', $voterKey)->delete();
    }

    public function createVote(array $data): MeetingPollVote
    {
        return MeetingPollVote::create($data);
    }

    // -----------------------------------------------------------------
    // Round 8 (Invitations & Calendar).
    // -----------------------------------------------------------------

    /** بند 26 — كل اجتماعات كيان UIP معيّن (مشروع/كلية/جامعة/مجموعة طلاب...). */
    public function forAttachable(string $attachableType, $attachableId): array
    {
        return Meeting::where('attachable_type', $attachableType)
            ->where('attachable_id', $attachableId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * بند 14 — نفس فلترة forUser() (هوست أو مشارك) زائد نطاق تاريخ
     * لعرض التقويم (Day/Week/Month view). المرشّح على scheduled_start_at
     * **أو** started_at معًا (اجتماع فوري started_at بس، اجتماع مجدول
     * scheduled_start_at بس، الاتنين ممكن يقعوا جوه نفس المدى).
     */
    public function calendarForUser($userId, $start, $end): array
    {
        return Meeting::where(function ($q) use ($userId) {
                $q->where('host_user_id', $userId)
                    ->orWhereHas('participants', function ($q2) use ($userId) {
                        $q2->where('user_id', $userId);
                    });
            })
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_start_at', [$start, $end])
                    ->orWhereBetween('started_at', [$start, $end]);
            })
            ->orderBy('scheduled_start_at')
            ->get()
            ->all();
    }

    // -----------------------------------------------------------------
    // Round 9 (Recording، بند 18).
    // -----------------------------------------------------------------

    public function createMeetingRecording(array $data): MeetingRecording
    {
        return MeetingRecording::create($data);
    }

    public function findMeetingRecording($meetingId, $id): ?MeetingRecording
    {
        return MeetingRecording::where('meeting_id', $meetingId)->where('id', $id)->first();
    }

    /** @return MeetingRecording[] */
    public function meetingRecordingsFor($meetingId): array
    {
        return MeetingRecording::where('meeting_id', $meetingId)->orderByDesc('id')->get()->all();
    }

    /** بند 18 — يمنع بدء تسجيل تاني من نفس النوع لسه شغال. */
    public function activeRecordingFor($meetingId, string $kind): ?MeetingRecording
    {
        return MeetingRecording::where('meeting_id', $meetingId)
            ->where('kind', $kind)
            ->where('status', 'recording')
            ->first();
    }
}
