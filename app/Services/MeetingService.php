<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Repositories\MeetingRepository;
use Illuminate\Support\Facades\Hash;

/**
 * Meetings & Collaboration Platform — Round 1 (Foundation) + إضافات
 * Round 3 (Signaling، بند 12 — Meeting Notifications). المنطق كله هنا
 * (زي ExamSystemService)، الكنترولر بس بيتحقق من الشكل (validation)
 * وبيعدي القرارات لـ MeetingPolicyService. status transitions هنا بس
 * على مستوى الميتاداتا (scheduled/live/ended/cancelled) — الاتصال
 * الفعلي (WebRTC) هيتبني فوقها في Round 4، مش جزء من الخدمة دي.
 *
 * $notifications/$signaling: nullable مع default null عمدًا (مش
 * required dependency) — عشان `new MeetingService($meetings, $policy)`
 * القديمة في MeetingFoundationTest/MeetingLobbyTest (Round 1/2، مُسلّمين
 * بالفعل) تفضل شغالة من غير أي تعديل. الكنترولر الحقيقي بيحلّهم تلقائيًا
 * عبر الـ container عادي (auto DI)، مفيش حاجة يدوية هناك.
 */
class MeetingService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy,
        private ?NotificationService $notifications = null,
        private ?MeetingSignalingService $signaling = null,
        private ?MeetingAttendanceService $attendance = null
    ) {
    }

    /** بند 12 — إشعار كل المشاركين (اللي مش الهوست نفسه) بحدث على مستوى الاجتماع كله. */
    private function notifyParticipants(Meeting $meeting, string $type, string $title, ?string $body, ?int $excludeUserId = null): void
    {
        if (!$this->notifications) {
            return;
        }
        foreach ($this->meetings->participantsFor($meeting->id) as $participant) {
            if ($participant->user_id === $excludeUserId) {
                continue;
            }
            if (in_array($participant->status, ['declined', 'removed'], true)) {
                continue;
            }
            $this->notifications->notify($participant->user_id, $type, $title, $body, null, 'normal');
        }
    }

    /** @param array $data title, description?, type?, scheduled_start_at?, duration_minutes?, max_participants?, waiting_room_enabled?, allow_guests?, password?, settings? */
    public function create(int $hostUserId, array $data): Meeting
    {
        $type = $data['type'] ?? 'instant';

        $maxParticipants = isset($data['max_participants']) ? (int) $data['max_participants'] : null;
        if ($maxParticipants !== null && $maxParticipants > $this->policy->maxMeshParticipants()) {
            $maxParticipants = $this->policy->maxMeshParticipants();
        }

        $payload = [
            'uuid'                 => $this->meetings->generateUniqueUuid(),
            'host_user_id'         => $hostUserId,
            'title'                => $data['title'],
            'description'          => $data['description'] ?? null,
            'type'                 => $type,
            'status'               => 'scheduled',
            'scheduled_start_at'   => $type === 'scheduled' ? ($data['scheduled_start_at'] ?? null) : null,
            'duration_minutes'     => $data['duration_minutes'] ?? $this->policy->defaultDurationMinutes(),
            'meeting_code'         => $this->meetings->generateUniqueMeetingCode(),
            'join_token'           => $this->meetings->generateUniqueJoinToken(),
            'password_hash'        => !empty($data['password']) ? Hash::make($data['password']) : null,
            'waiting_room_enabled' => $data['waiting_room_enabled'] ?? $this->policy->waitingRoomDefaultEnabled(),
            'allow_guests'         => $data['allow_guests'] ?? $this->policy->allowGuestsDefault(),
            'max_participants'     => $maxParticipants,
            'settings'             => array_merge(config('meetings.default_settings'), $data['settings'] ?? []),
            // Round 8 (Invitations & Calendar، بند 26 — Project Integration).
            // اتحقق منها في MeetingAttachableService جوه الكنترولر قبل
            // ما توصل هنا؛ مفيش تحقق إضافي في الخدمة دي عمدًا.
            'attachable_type'      => $data['attachable_type'] ?? null,
            'attachable_id'        => $data['attachable_id'] ?? null,
        ];

        $meeting = $this->meetings->create($payload);

        // الهوست نفسه صف participant جاهز/joined من الأول — مش "مدعو"،
        // عشان indexوindex/participants list يعرضه من غير حاجة خاصة.
        $this->meetings->addParticipant([
            'meeting_id' => $meeting->id,
            'user_id'    => $hostUserId,
            'role'       => 'host',
            'status'     => 'joined',
            'joined_at'  => now(),
        ]);

        return $meeting;
    }

    public function update(Meeting $meeting, array $data): Meeting
    {
        $fields = array_intersect_key($data, array_flip([
            'title', 'description', 'scheduled_start_at', 'duration_minutes',
            'waiting_room_enabled', 'allow_guests', 'settings',
            // Round 8، بند 26 — الربط ممكن يتضاف/يتغيّر بعد الإنشاء برضو
            // (نفس تحقق MeetingAttachableService في الكنترولر).
            'attachable_type', 'attachable_id',
        ]));

        if (isset($data['max_participants'])) {
            $fields['max_participants'] = min((int) $data['max_participants'], $this->policy->maxMeshParticipants());
        }

        if (array_key_exists('password', $data)) {
            $fields['password_hash'] = !empty($data['password']) ? Hash::make($data['password']) : null;
        }

        if (isset($fields['settings'])) {
            $fields['settings'] = array_merge($meeting->settings ?? [], $fields['settings']);
        }

        // بند 12 — "Meeting rescheduled": بنقارن قبل ما نحفظ، مش بعده
        // (بعد save() القيمة القديمة مش موجودة تاني في الـ object).
        $wasRescheduled = array_key_exists('scheduled_start_at', $fields)
            && (string) $meeting->scheduled_start_at !== (string) $fields['scheduled_start_at'];

        $meeting->fill($fields);
        $meeting->save();

        if ($wasRescheduled) {
            // reminder اللي اتبعت (لو اتبعت) بقى غير دقيق دلوقتي بعد
            // التغيير — بنصفّره عشان meetings:send-starting-soon-reminders
            // يقدر يبعت واحد جديد صحيح لو الميعاد الجديد لسه هيجي.
            $meeting->starting_soon_reminder_sent_at = null;
            $meeting->save();

            $this->notifyParticipants(
                $meeting,
                'meeting_rescheduled',
                'Meeting rescheduled: ' . $meeting->title,
                'The host rescheduled this meeting.',
                $meeting->host_user_id
            );
        }

        return $meeting;
    }

    public function cancel(Meeting $meeting): Meeting
    {
        $meeting->status = 'cancelled';
        $meeting->cancelled_at = now();
        $meeting->save();

        $this->notifyParticipants(
            $meeting,
            'meeting_cancelled',
            'Meeting cancelled: ' . $meeting->title,
            'The host cancelled this meeting.',
            $meeting->host_user_id
        );
        $this->signaling?->broadcastStatus($meeting);

        return $meeting;
    }

    public function delete(Meeting $meeting): void
    {
        $meeting->delete();
    }

    /** الميتاداتا بس (status=live + started_at) — الاتصال الفعلي Round 4. */
    public function start(Meeting $meeting): Meeting
    {
        $meeting->status = 'live';
        $meeting->started_at = now();
        $meeting->save();

        $this->notifyParticipants(
            $meeting,
            'meeting_started',
            'Host started the meeting: ' . $meeting->title,
            null,
            $meeting->host_user_id
        );
        $this->signaling?->broadcastStatus($meeting);

        return $meeting;
    }

    public function end(Meeting $meeting): Meeting
    {
        $meeting->status = 'ended';
        $meeting->ended_at = now();
        $meeting->save();

        $this->attendance?->closeAllOpenSessions($meeting);

        $this->notifyParticipants(
            $meeting,
            'meeting_ended',
            'Meeting ended: ' . $meeting->title,
            null,
            $meeting->host_user_id
        );
        $this->signaling?->broadcastStatus($meeting);

        return $meeting;
    }

    // -----------------------------------------------------------------
    // Invitations (دعوة مستخدم واحد بالـ id — bulk role/group/project
    // هو Round 8، بيبني فوق نفس الجدول ده).
    // -----------------------------------------------------------------

    public function invite(Meeting $meeting, int $invitedByUserId, int $invitedUserId, ?string $message = null): MeetingInvitation
    {
        $existing = $this->meetings->findInvitation($meeting->id, $invitedUserId);

        $expiresAt = now()->addHours($this->policy->invitationExpiryHours());

        if ($existing) {
            // إعادة دعوة بعد رفض/انتهاء — بنعيد استخدام نفس الصف بدل ما
            // نصطدم بـ unique(meeting_id, invited_user_id).
            $existing->fill([
                'invited_by_user_id' => $invitedByUserId,
                'token'              => $this->meetings->generateUniqueInvitationToken(),
                'status'             => 'pending',
                'message'            => $message,
                'expires_at'         => $expiresAt,
                'responded_at'       => null,
            ]);
            $invitation = $this->meetings->saveInvitation($existing);
        } else {
            $invitation = $this->meetings->createInvitation([
                'meeting_id'         => $meeting->id,
                'invited_by_user_id' => $invitedByUserId,
                'invited_user_id'    => $invitedUserId,
                'token'              => $this->meetings->generateUniqueInvitationToken(),
                'status'             => 'pending',
                'message'            => $message,
                'expires_at'         => $expiresAt,
            ]);
        }

        $participant = $this->meetings->findParticipant($meeting->id, $invitedUserId);
        if (!$participant) {
            $this->meetings->addParticipant([
                'meeting_id'         => $meeting->id,
                'user_id'            => $invitedUserId,
                'role'               => 'participant',
                'status'             => 'invited',
                'invited_by_user_id' => $invitedByUserId,
            ]);
        } elseif ($participant->status === 'declined') {
            $participant->status = 'invited';
            $participant->invited_by_user_id = $invitedByUserId;
            $participant->save();
        }

        // بند 12 — "Meeting invitation".
        $this->notifications?->notify(
            $invitedUserId,
            'meeting_invitation',
            'You have been invited to a meeting: ' . $meeting->title,
            $message,
            null,
            'normal'
        );

        return $invitation;
    }

    public function respondToInvitation(MeetingInvitation $invitation, int $userId, bool $accept): MeetingInvitation
    {
        if ((int) $invitation->invited_user_id !== $userId) {
            throw new \InvalidArgumentException('This invitation does not belong to this user.');
        }

        if ($invitation->status !== 'pending' || $invitation->isExpired()) {
            if ($invitation->status === 'pending' && $invitation->isExpired()) {
                $invitation->status = 'expired';
                $invitation->save();
            }
            throw new \RuntimeException('This invitation is no longer pending.');
        }

        $invitation->status = $accept ? 'accepted' : 'declined';
        $invitation->responded_at = now();
        $invitation->save();

        $participant = $this->meetings->findParticipant($invitation->meeting_id, $userId);
        if ($participant) {
            $participant->status = $accept ? 'joined' : 'declined';
            if ($accept) {
                $participant->joined_at = now();
            }
            $participant->save();
        }

        return $invitation;
    }
}
