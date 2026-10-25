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
            // null صريح = اجتماع مفتوح بلا نهاية (مفيش وقت محدد). لو الحقل مش مبعوت أصلاً نرجع للمدة الافتراضية.
            'duration_minutes'     => array_key_exists('duration_minutes', $data) ? ($data['duration_minutes'] !== null ? (int) $data['duration_minutes'] : null) : $this->policy->defaultDurationMinutes(),
            'meeting_code'         => $this->meetings->generateUniqueMeetingCode(),
            'join_token'           => $this->meetings->generateUniqueJoinToken(),
            'password_hash'        => !empty($data['password']) ? Hash::make($data['password']) : null,
            'waiting_room_enabled' => \App\Services\MeetingAdminSettings::get('force_waiting_room') ? true : ($data['waiting_room_enabled'] ?? $this->policy->waitingRoomDefaultEnabled()),
            'allow_guests'         => \App\Services\MeetingAdminSettings::on('guests') ? ($data['allow_guests'] ?? $this->policy->allowGuestsDefault()) : false,
            'max_participants'     => $maxParticipants,
            'settings'             => self::cleanSettings(array_merge(\App\Services\MeetingAdminSettings::defaultMeetingSettings(), $data['settings'] ?? [])),
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

        // قيود الأدمن: الـ waiting room إجباري / الضيوف مقفولين / كلمة السر إجبارية.
        if (\App\Services\MeetingAdminSettings::get('force_waiting_room') && array_key_exists('waiting_room_enabled', $fields)) {
            $fields['waiting_room_enabled'] = true;
        }
        if (!\App\Services\MeetingAdminSettings::on('guests') && array_key_exists('allow_guests', $fields)) {
            $fields['allow_guests'] = false;
        }
        if (\App\Services\MeetingAdminSettings::get('require_password') && array_key_exists('password', $data) && empty($data['password'])) {
            throw new \InvalidArgumentException('A meeting password is required by the administrator.');
        }

        if (isset($data['max_participants'])) {
            $fields['max_participants'] = min((int) $data['max_participants'], $this->policy->maxMeshParticipants());
        }

        if (array_key_exists('password', $data)) {
            $fields['password_hash'] = !empty($data['password']) ? Hash::make($data['password']) : null;
        }

        if (isset($fields['settings'])) {
            $fields['settings'] = self::cleanSettings(array_merge($meeting->settings ?? [], $fields['settings']));
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

        $this->attendance?->persistMissingSessions($meeting);
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
        if (!\App\Services\MeetingAdminSettings::on('invitations')) {
            throw new \RuntimeException('Invitations are disabled by the administrator.');
        }
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

    /**
     * يبعت إيميل دعوة الاجتماع (التفاصيل + رابط صفحة الاجتماع) للمستخدم المدعو.
     * الدعوة نفسها اتسجلت قبل كده في invite() — فشل الإيميل مبيلغيهاش، بس بيرجّع
     * false (والسبب في $error) عشان الواجهة تبلّغ الهوست.
     */
    public function emailInvitation(Meeting $meeting, \App\Models\User $invitee, ?string $message = null, ?string &$error = null): bool
    {
        $error = null;
        $mail = app(MailService::class);

        $locale = ($invitee->preferred_language ?? 'ar') === 'en' ? 'en' : 'ar';
        $ar = $locale === 'ar';

        $host = \App\Models\User::find($meeting->host_user_id);
        $hostName = $host ? $host->nameFor($locale) : ($ar ? 'مضيف الاجتماع' : 'The host');

        $details = $this->inviteDetails($meeting, $hostName, $ar);

        $ok = $mail->sendMeetingInvite(
            $invitee->email,
            $invitee->nameFor($locale),
            $hostName,
            (string) $meeting->title,
            $details,
            $message,
            $this->meetingUrlFor($meeting, $invitee),
            $locale
        );
        if (!$ok) {
            $error = $mail->getLastError() ?: 'Email could not be sent.';
        }

        return $ok;
    }

    /** صفوف "تفاصيل الاجتماع" اللي بتظهر في إيميل الدعوة. @return array<int,array{label:string,value:string}> */
    private function inviteDetails(Meeting $meeting, string $hostName, bool $ar): array
    {
        $details = [['label' => $ar ? 'الاجتماع' : 'Meeting', 'value' => (string) $meeting->title]];
        if (!empty($meeting->description)) {
            $details[] = ['label' => $ar ? 'الوصف' : 'Description', 'value' => mb_substr((string) $meeting->description, 0, 300)];
        }
        $details[] = ['label' => $ar ? 'المضيف' : 'Host', 'value' => $hostName];
        if ($meeting->scheduled_start_at) {
            $details[] = [
                'label' => $ar ? 'الموعد' : 'Starts at',
                'value' => $meeting->scheduled_start_at->copy()->utc()->format('Y-m-d H:i') . ' (UTC)',
            ];
        } else {
            $details[] = ['label' => $ar ? 'الموعد' : 'Starts at', 'value' => $ar ? 'اجتماع فوري / بدون موعد محدد' : 'Instant meeting / no fixed time'];
        }
        if (!empty($meeting->duration_minutes)) {
            $details[] = ['label' => $ar ? 'المدة' : 'Duration', 'value' => $meeting->duration_minutes . ($ar ? ' دقيقة' : ' min')];
        }

        return $details;
    }

    /**
     * دعوة بالإيميل لشخص **مالوش حساب** على المنصة: بنبعتله إيميل فيه تفاصيل الاجتماع + رابط الانضمام
     * المباشر (/join/{join_token}) — بيدخل كضيف من غير تسجيل (لازم الاجتماع يسمح بالضيوف،
     * والـ waiting room / كلمة السر لو متفعلين بيفضلوا شغالين زي ما هما). مفيش صف دعوة (الجدول مربوط بـ users).
     */
    public function emailGuestInvitation(Meeting $meeting, string $email, ?string $message = null, ?string &$error = null): bool
    {
        $error = null;
        $mail = app(MailService::class);

        $host = \App\Models\User::find($meeting->host_user_id);
        $locale = ($host && ($host->preferred_language ?? 'ar') === 'en') ? 'en' : 'ar';
        $ar = $locale === 'ar';
        $hostName = $host ? $host->nameFor($locale) : ($ar ? 'مضيف الاجتماع' : 'The host');

        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
        $details = $this->inviteDetails($meeting, $hostName, $ar);
        if ($meeting->password_hash) {
            $details[] = ['label' => $ar ? 'كلمة السر' : 'Password', 'value' => $ar ? 'الاجتماع محمي بكلمة سر — اطلبها من المضيف' : 'This meeting is password protected — ask the host for it'];
        }

        $ok = $mail->sendMeetingInvite(
            $email,
            $ar ? 'ضيفنا الكريم' : 'there',
            $hostName,
            (string) $meeting->title,
            $details,
            $message,
            "{$base}/join/{$meeting->join_token}",
            $locale,
            true
        );
        if (!$ok) {
            $error = $mail->getLastError() ?: 'Email could not be sent.';
        }

        return $ok;
    }

    /** رابط صفحة الاجتماع في الفرونت حسب بوابة (portal) دور المدعو — نفس mapping portalPrefix() في navConfig.js. */
    public function meetingUrlFor(Meeting $meeting, \App\Models\User $invitee): string
    {
        $role = app(RoleService::class)->primaryRoleFor((int) $invitee->id);
        $prefix = [
            'data_analyst'    => 'data-analysis',
            'security_admin'  => 'security',
            'security_officer' => 'security',
            'academic_staff'  => 'academic-staff',
        ][$role] ?? $role;
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');

        return "{$base}/{$prefix}/meetings/{$meeting->uuid}";
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

    /** Host-chosen chat history policy must be exactly "keep" or "hide"; the muted list is only ever written by host controls. */
    private static function cleanSettings(array $settings): array
    {
        $settings['chat_history'] = ($settings['chat_history'] ?? 'keep') === 'hide' ? 'hide' : 'keep';
        if (isset($settings['chat_muted_keys']) && !is_array($settings['chat_muted_keys'])) {
            unset($settings['chat_muted_keys']);
        }

        return $settings;
    }
}
