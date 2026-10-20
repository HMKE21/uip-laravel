<?php

namespace App\Services;

use App\Events\Meetings\MeetingStatusBroadcast;
use App\Events\Meetings\ParticipantConnectionQualityChanged;
use App\Events\Meetings\ParticipantConnectionStateChanged;
use App\Events\Meetings\ParticipantHandRaised;
use App\Events\Meetings\ParticipantLeftMeeting;
use App\Events\Meetings\ParticipantMediaFailure;
use App\Events\Meetings\ParticipantMediaStateChanged;
use App\Events\Meetings\ParticipantReactionSent;
use App\Events\Meetings\ParticipantScreenShareForceStopped;
use App\Events\Meetings\ScreenShareParticipantPolicyChanged;
use App\Events\Meetings\ScreenSharePolicyChanged;
use App\Models\Meeting;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Repositories\MeetingRepository;

/**
 * Meetings & Collaboration Platform — Round 3 (Signaling): بند 6 (الجزء
 * الخاص بالـ Signaling)، بند 33 (Real-Time Architecture). المنطق كله
 * هنا زي كل خدمة تانية في الموديول ده — الكنترولر بس بيتحقق من الشكل.
 *
 * "actor" (المستخدم/الضيف اللي بيتعامل مع القناة) بيتمثل هنا بمصفوفة
 * موحّدة {type, model, key, display_name, role} بدل كلاس منفصل، عشان
 * باقي الميثودز (updateMediaState/leave/...) تتعامل مع اليوزر المسجّل
 * والضيف بنفس الكود بالظبط من غير if منتشر — راجع docblock migration
 * 2026_08_31_030000 ليه نفس أعمدة الحالة على الجدولين.
 *
 * توقيع القناة (signChannel): بروتوكول Pusher القياسي اللي Reverb متوافق
 * معاه بالكامل (نفس اللي Laravel Echo عميله بيفهمه من غير أي تعديل) —
 * لكن بنعمله يدويًا هنا (مش عبر Broadcast::routes()/Auth::user() بتاعة
 * لارافيل) عشان النظام هنا كله JWT بيرر توكن (UipJwtService)، مش
 * Laravel session/guard. راجع docblock config/broadcasting.php لتفاصيل
 * أكتر.
 *
 * Round 4 (WebRTC Core) إضافات: iceServers() (بند 6 — STUN/TURN)،
 * updateConnectionQuality()/reportFailure() (بند 34)، وroster()/
 * meshCapacity() (بند 4 — Participant Grid). تبادل SDP/ICE الفعلي نفسه
 * (offer/answer/candidates) **مش** جوه الملف ده ولا أي endpoint REST —
 * بيتم عبر client events (whisper) على presence channel Round 3 نفسه،
 * من غير ما يعدي على لارافيل أصلًا. راجع docblock config/webrtc.php
 * للتفاصيل والسبب.
 *
 * Round 5 (Live Collaboration) إضافات: personalChannelName() (بند 8 —
 * القناة الشخصية للرسائل الخاصة)، raiseHand()/sendReaction() (بند 11)،
 * setScreenSharingLock()/setParticipantScreenSharePolicy()/
 * stopParticipantScreenShare() (بند 9 — host controls)، وربط
 * updateMediaState() بسياسة مشاركة الشاشة + رسائل الشات النظامية (بند
 * 7 — "Ahmed joined the meeting"/"Sara raised her hand"/"Mohamed
 * started sharing his screen") عبر $chat (nullable، زي $notifications
 * في MeetingLobbyService، عشان `new MeetingSignalingService($meetings,
 * $lobby, $policy)` القديمة في Round 3/4 tests تفضل شغالة من غير تعديل).
 *
 * Round 6 (Host Controls) إضافة: resolveTargetActor() (بند 10) — نفس
 * شكل resolveActor() بس بمفتاح actor key جاهز، عشان
 * MeetingHostControlService يقدر يدير حد **تاني** غير اللي بعت الـ
 * request. المنطق الفعلي لأوامر الهوست (mute/remove/promote/...) في
 * MeetingHostControlService نفسه، مش هنا — الملف ده لسه مقصور على
 * "الـ actor بيدير نفسه" + "الهوست بيتحكم في مشاركة الشاشة" (Round 5).
 */
class MeetingSignalingService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingLobbyService $lobby,
        private MeetingPolicyService $policy,
        private ?MeetingChatService $chat = null,
        private ?MeetingAttendanceService $attendance = null
    ) {
    }

    /**
     * @return array{type:string, model:MeetingParticipant|MeetingJoinRequest, key:string, display_name:string, role:string}
     * @throws \RuntimeException لو مفيش حد داخل فعلًا (لا مستخدم مسجّل شارك، ولا ضيف توكنه صالح).
     */
    public function resolveActor(Meeting $meeting, ?int $authUserId, ?string $guestToken): array
    {
        if ($authUserId !== null) {
            $participant = $this->meetings->findParticipant($meeting->id, $authUserId);
            if (!$participant || !in_array($participant->status, ['joined', 'left'], true)) {
                throw new \RuntimeException('You must join this meeting before using signaling.');
            }

            return [
                'type'         => 'participant',
                'model'        => $participant,
                'key'          => 'user:' . $authUserId,
                'display_name' => $participant->user->full_name ?? ('User #' . $authUserId),
                'role'         => $participant->role,
            ];
        }

        $joinRequest = $this->lobby->resolveGuestToken((string) $guestToken, $meeting->id);
        if (!$joinRequest || !in_array($joinRequest->status, ['admitted', 'left'], true)) {
            throw new \RuntimeException('You must be admitted to this meeting before using signaling.');
        }

        return [
            'type'         => 'guest',
            'model'        => $joinRequest,
            'key'          => 'guest:' . $joinRequest->id,
            'display_name' => (string) $joinRequest->guest_name,
            'role'         => 'guest',
        ];
    }

    /** الاسم اللي المفروض يوصل من الفرونت (Echo.join يبعته كامل بالـ prefix). */
    public function expectedChannelName(Meeting $meeting): string
    {
        return 'presence-meeting.' . $meeting->uuid;
    }

    /** بند 8 — تحويل actor key ('user:5'/'guest:12') لجزء اسم قناة صالح (Pusher/Reverb مش دايمًا مرتاح لـ ':'). */
    public function channelSafeKey(string $actorKey): string
    {
        return str_replace(':', '-', $actorKey);
    }

    /**
     * بند 8 (Private Chat) — قناة شخصية خاصة بكل actor لوحده جوه
     * الاجتماع ده، مش قناة لكل زوج DM (أبسط بكتير: N قناة بدل N²، وكل
     * actor بيعمل subscribe لقناته هو مرة واحدة بس). راجع docblock
     * ChatMessageSent ليه رسالة خاصة بتتبعت هنا بس مش على presence
     * channel العام.
     */
    public function personalChannelName(Meeting $meeting, string $actorKey): string
    {
        return 'private-meeting.' . $meeting->uuid . '.inbox.' . $this->channelSafeKey($actorKey);
    }

    /**
     * @return array{auth:string, channel_data?:string}
     * @throws \InvalidArgumentException اسم قناة غلط لهذا الاجتماع.
     * @throws \RuntimeException الـ actor مش مصرح له (راجع resolveActor).
     */
    public function authorizeChannel(Meeting $meeting, ?int $authUserId, ?string $guestToken, string $socketId, string $channelName): array
    {
        $actor = $this->resolveActor($meeting, $authUserId, $guestToken);

        if ($channelName === $this->personalChannelName($meeting, $actor['key'])) {
            // خاصة بس بصاحبها (بند 8) — من غير presence data (زي أي
            // private channel عادي، مفيش member list هنا).
            return $this->signChannel($socketId, $channelName, null);
        }

        if ($channelName !== $this->expectedChannelName($meeting)) {
            throw new \InvalidArgumentException('This channel does not belong to this meeting.');
        }

        // بند 7 — "Ahmed joined the meeting" مرة واحدة بس لكل دخول حقيقي
        // (last_seen_at لسه null يعني الجلسة دي أول مرة تدخل الغرفة
        // الفعلية أصلًا؛ heartbeat/media-state/connection-state كلهم
        // بيعدّوها بعد كده، فمفيش تكرار على reconnect/refresh عادي).
        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        if ($model->last_seen_at === null) {
            $this->chat?->postSystemMessage($meeting, $actor['display_name'] . ' joined the meeting');
        }

        // بند 17 (Round 7 — Attendance Tracking). راجع docblock
        // MeetingAttendanceService ليه النقطة دي (مش last_seen_at===null
        // فوق) هي المستخدمة للحضور تحديدًا.
        $this->attendance?->recordJoin($meeting, $actor);

        $channelData = [
            'user_id'   => $actor['key'],
            'user_info' => [
                'name'     => $actor['display_name'],
                'role'     => $actor['role'],
                'is_guest' => $actor['type'] === 'guest',
            ],
        ];

        return $this->signChannel($socketId, $channelName, $channelData);
    }

    /**
     * @param array<string,mixed>|null $channelData null لقناة private عادية (بدون presence data).
     * @return array{auth:string, channel_data?:string}
     */
    public function signChannel(string $socketId, string $channelName, ?array $channelData = null): array
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');

        if ($channelData !== null) {
            $encodedData = json_encode($channelData, JSON_UNESCAPED_SLASHES);
            $stringToSign = "{$socketId}:{$channelName}:{$encodedData}";
            $signature = hash_hmac('sha256', $stringToSign, $secret);

            return ['auth' => "{$key}:{$signature}", 'channel_data' => $encodedData];
        }

        $stringToSign = "{$socketId}:{$channelName}";
        $signature = hash_hmac('sha256', $stringToSign, $secret);

        return ['auth' => "{$key}:{$signature}"];
    }

    /**
     * @param array{mic_enabled?:bool, camera_enabled?:bool, screen_sharing?:bool} $data
     * @throws \RuntimeException محاولة تشغيل مشاركة الشاشة وهي ممنوعة عليه (بند 9 — راجع canShareScreen()).
     */
    public function updateMediaState(Meeting $meeting, array $actor, array $data): array
    {
        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        $wasSharing = (bool) $model->screen_sharing;

        if (array_key_exists('mic_enabled', $data)) {
            $model->mic_enabled = (bool) $data['mic_enabled'];
        }
        if (array_key_exists('camera_enabled', $data)) {
            $model->camera_enabled = (bool) $data['camera_enabled'];
        }
        if (array_key_exists('screen_sharing', $data)) {
            $wantsSharing = (bool) $data['screen_sharing'];
            if ($wantsSharing && !$wasSharing && !$this->canShareScreen($meeting, $actor)) {
                throw new \RuntimeException('Screen sharing is currently disabled by the host.');
            }
            $model->screen_sharing = $wantsSharing;
        }
        $model->last_seen_at = now();
        $model->save();

        event(new ParticipantMediaStateChanged(
            $meeting->uuid,
            $actor['key'],
            $actor['display_name'],
            (bool) $model->mic_enabled,
            (bool) $model->camera_enabled,
            (bool) $model->screen_sharing
        ));

        // بند 7 — "Mohamed started sharing his screen" (المثال بالظبط
        // في الـ spec). بنبعتها بس على الانتقال false->true (مش على كل
        // media-state update)، وبرضو نظير الإيقاف عشان الـ timeline
        // يفضل متسق (مفيش "بدأ" من غير "خلص" مقابله).
        if ($this->chat) {
            if (!$wasSharing && $model->screen_sharing) {
                $this->chat->postSystemMessage($meeting, $actor['display_name'] . ' started sharing their screen');
            } elseif ($wasSharing && !$model->screen_sharing) {
                $this->chat->postSystemMessage($meeting, $actor['display_name'] . ' stopped sharing their screen');
            }
        }

        return [
            'mic_enabled'    => (bool) $model->mic_enabled,
            'camera_enabled' => (bool) $model->camera_enabled,
            'screen_sharing' => (bool) $model->screen_sharing,
        ];
    }

    public function updateConnectionState(Meeting $meeting, array $actor, string $connectionState): string
    {
        if (!in_array($connectionState, ['connecting', 'connected', 'reconnecting', 'disconnected'], true)) {
            throw new \InvalidArgumentException('Invalid connection state.');
        }

        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        $model->connection_state = $connectionState;
        $model->last_seen_at = now();
        $model->save();

        event(new ParticipantConnectionStateChanged($meeting->uuid, $actor['key'], $connectionState));

        return $connectionState;
    }

    /**
     * بند 34 — تقييم جودة الاتصال اللي الفرونت حسبه بنفسه من
     * RTCPeerConnection.getStats() (packet loss/jitter/RTT). راجع
     * docblock migration 2026_08_31_040000 وdocblock
     * ParticipantConnectionQualityChanged ليه لارافيل بيسجّل بس مش
     * بيحسب.
     */
    public function updateConnectionQuality(Meeting $meeting, array $actor, string $quality): string
    {
        if (!in_array($quality, ['excellent', 'good', 'poor', 'reconnecting'], true)) {
            throw new \InvalidArgumentException('Invalid connection quality.');
        }

        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        $model->connection_quality = $quality;
        $model->last_seen_at = now();
        $model->save();

        event(new ParticipantConnectionQualityChanged($meeting->uuid, $actor['key'], $quality));

        return $quality;
    }

    /**
     * بند 34 — "Users should receive clear feedback instead of silently
     * failing." بلاغ فشل من الفرونت (كاميرا رفضت تفتح، مايك اتقفل من
     * نظام التشغيل، ICE connection state = failed، إلخ) — راجع docblock
     * ParticipantMediaFailure ليه ده إعلامي بس من غير تغيير حالة تلقائي.
     */
    public function reportFailure(Meeting $meeting, array $actor, string $failureType, ?string $message = null): void
    {
        if (!in_array($failureType, ['camera', 'microphone', 'webrtc', 'network'], true)) {
            throw new \InvalidArgumentException('Invalid failure type.');
        }

        event(new ParticipantMediaFailure($meeting->uuid, $actor['key'], $actor['display_name'], $failureType, $message));
    }

    /**
     * بند 6 — STUN/TURN infrastructure for NAT traversal. راجع docblock
     * config/webrtc.php للبروتوكول الكامل (coturn REST API convention).
     * الـ actor لازم يتحل الأول (participant/guest فعليًا داخل
     * الاجتماع) قبل ما ننادي الميثود دي — مفيش داعي لسر TURN يوصل لحد
     * مش داخل الاجتماع أصلًا.
     *
     * @return array<int, array{urls:string|array<int,string>, username?:string, credential?:string}>
     */
    public function iceServers(): array
    {
        $servers = [];

        $stunUrls = config('webrtc.stun_urls', []);
        if (!empty($stunUrls)) {
            $servers[] = ['urls' => array_values($stunUrls)];
        }

        $turnUrls = config('webrtc.turn_urls', []);
        $secret = (string) config('webrtc.turn_secret');
        if (!empty($turnUrls) && $secret !== '') {
            $ttl = (int) config('webrtc.turn_credential_ttl_seconds', 600);
            $username = (string) (time() + $ttl);
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));

            $servers[] = [
                'urls'       => array_values($turnUrls),
                'username'   => $username,
                'credential' => $credential,
            ];
        }

        return $servers;
    }

    /** بند 4 — الحد العملي للـ mesh (Round 1 policy) مقارنة بعدد المتصلين فعليًا دلوقتي. */
    public function meshCapacity(Meeting $meeting): array
    {
        $limit = $this->policy->maxMeshParticipants();
        $current = count($this->roster($meeting));

        return [
            'limit'               => $limit,
            'current'             => $current,
            'exceeds_recommended' => $current > $limit,
        ];
    }

    /**
     * بند 4 — Participant Grid: قائمة موحّدة لكل عنصر "حاضر فعليًا"
     * دلوقتي (مستخدمين مسجلين status=joined + ضيوف status=admitted) —
     * بعكس MeetingsApiController::indexParticipants() (Round 1) اللي
     * بيرجع meeting_participants بس (كل الحالات، مفيش ضيوف). دي مبنية
     * فوق نفس شكل resolveActor() (type/key/display_name/role) عشان
     * الفرونت يقدر يرسم كارت واحد لكل عنصر من غير ما يفرّق كود.
     *
     * @return array<int, array{key:string,type:string,display_name:string,role:string,is_host:bool,avatar_url:?string,mic_enabled:bool,camera_enabled:bool,screen_sharing:bool,connection_state:string,connection_quality:string,last_seen_at:?string,hand_raised:bool,hand_raised_at:?string,screen_share_allowed:?bool}>
     */
    public function roster(Meeting $meeting): array
    {
        $roster = [];

        foreach ($this->meetings->participantsFor($meeting->id) as $participant) {
            if ($participant->status !== 'joined') {
                continue;
            }
            $roster[] = [
                'key'                   => 'user:' . $participant->user_id,
                'type'                  => 'participant',
                'display_name'          => $participant->user->full_name ?? ('User #' . $participant->user_id),
                'role'                  => $participant->role,
                'is_host'               => $participant->role === 'host',
                'avatar_url'            => app(\App\Services\AvatarPrivacyService::class)->apply($participant->user->avatar_path ?? null, (int) $participant->user_id),
                'mic_enabled'           => (bool) $participant->mic_enabled,
                'camera_enabled'        => (bool) $participant->camera_enabled,
                'screen_sharing'        => (bool) $participant->screen_sharing,
                'connection_state'      => $participant->connection_state,
                'connection_quality'    => $participant->connection_quality,
                'last_seen_at'          => optional($participant->last_seen_at)->toISOString(),
                'hand_raised'           => (bool) $participant->hand_raised,
                'hand_raised_at'        => optional($participant->hand_raised_at)->toISOString(),
                'screen_share_allowed'  => $participant->screen_share_allowed,
            ];
        }

        foreach ($this->meetings->admittedGuestsFor($meeting->id) as $joinRequest) {
            $roster[] = [
                'key'                   => 'guest:' . $joinRequest->id,
                'type'                  => 'guest',
                'display_name'          => (string) $joinRequest->guest_name,
                'role'                  => 'guest',
                'is_host'               => false,
                'avatar_url'            => null,
                'mic_enabled'           => (bool) $joinRequest->mic_enabled,
                'camera_enabled'        => (bool) $joinRequest->camera_enabled,
                'screen_sharing'        => (bool) $joinRequest->screen_sharing,
                'connection_state'      => $joinRequest->connection_state,
                'connection_quality'    => $joinRequest->connection_quality,
                'last_seen_at'          => optional($joinRequest->last_seen_at)->toISOString(),
                'hand_raised'           => (bool) $joinRequest->hand_raised,
                'hand_raised_at'        => optional($joinRequest->hand_raised_at)->toISOString(),
                'screen_share_allowed'  => $joinRequest->screen_share_allowed,
            ];
        }

        return $roster;
    }

    /** بينج دوري من الفرونت (كل ~20-30 ثانية) — بيحدّث last_seen_at بس، من غير أي broadcast (مفيش داعي، الحالة نفسها ما اتغيرتش). */
    public function heartbeat(array $actor): void
    {
        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        $model->last_seen_at = now();
        if ($model->connection_state !== 'connected') {
            $model->connection_state = 'connected';
        }
        $model->save();
    }

    /** خروج متعمّد (زرار "Leave meeting") — راجع docblock ParticipantLeftMeeting للفرق عن presence 'leaving' التلقائي. */
    public function leave(Meeting $meeting, array $actor): void
    {
        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        $model->status = 'left';
        $model->left_at = now();
        $model->connection_state = 'disconnected';
        // بند 11/9 — خروجه بيقفل أي "حالة حية" مربوطة بيه في الـ grid
        // (إيد مرفوعة/مشاركة شاشة) عشان محدش يفضل يشوف كارت شخص خارج
        // شاشته لسه رافعة إيده أو بتشارك شاشة.
        $model->hand_raised = false;
        $model->screen_sharing = false;
        $model->save();

        $this->attendance?->recordLeave($meeting, $actor);

        event(new ParticipantLeftMeeting($meeting->uuid, $actor['key'], $actor['display_name']));
    }

    /** بيتنادى من MeetingService::start()/end()/cancel() — راجع docblock MeetingStatusBroadcast. */
    public function broadcastStatus(Meeting $meeting): void
    {
        event(new MeetingStatusBroadcast($meeting->uuid, $meeting->status));
    }

    // -----------------------------------------------------------------
    // Round 5 (Live Collaboration) — بند 11 (Raise Hand & Reactions)،
    // بند 9 (Screen Sharing host controls).
    // -----------------------------------------------------------------

    /** بند 11 — Raise Hand / Lower Hand. */
    public function raiseHand(Meeting $meeting, array $actor, bool $raised): array
    {
        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        $model->hand_raised = $raised;
        $model->hand_raised_at = $raised ? now() : null;
        $model->save();

        event(new ParticipantHandRaised(
            $meeting->uuid,
            $actor['key'],
            $actor['display_name'],
            $raised,
            optional($model->hand_raised_at)->toISOString()
        ));

        // بند 7 — "Sara raised her hand" (مثال الـ spec بالظبط). بس عند
        // الرفع، مش الخفض (خفض الإيد مش حدث محادثة، بس تحديث حالة).
        if ($raised) {
            $this->chat?->postSystemMessage($meeting, $actor['display_name'] . ' raised their hand');
        }

        return ['hand_raised' => $raised, 'hand_raised_at' => optional($model->hand_raised_at)->toISOString()];
    }

    /**
     * بند 11 — Thumbs Up/Applause/Laugh/Heart/Celebrate + "Other
     * appropriate reactions" (نوع 'other' مع $emoji مخصص). ephemeral
     * بالكامل — راجع docblock ParticipantReactionSent.
     */
    public function sendReaction(Meeting $meeting, array $actor, string $type, ?string $emoji = null): void
    {
        $knownTypes = ['thumbs_up', 'applause', 'laugh', 'heart', 'celebrate', 'other'];
        if (!in_array($type, $knownTypes, true)) {
            throw new \InvalidArgumentException('Invalid reaction type.');
        }
        if ($type === 'other' && (empty($emoji) || mb_strlen($emoji) > 8)) {
            throw new \InvalidArgumentException('A short custom emoji is required for reaction type "other".');
        }

        event(new ParticipantReactionSent($meeting->uuid, $actor['key'], $actor['display_name'], $type, $emoji));
    }

    /**
     * بند 9 — هل الـ actor ده مسموحله يشارك شاشته دلوقتي؟ ترتيب
     * الأولوية: هوست/co-host دايمًا مسموح (هما اللي بيديروا السياسة أصلًا)
     * ← منع صريح لشخصه (screen_share_allowed=false) بيغلب أي حاجة تانية
     * ← سماح صريح لشخصه (screen_share_allowed=true) بيغلب القفل العام ←
     * وإلا القفل العام هو الحاكم.
     */
    public function canShareScreen(Meeting $meeting, array $actor): bool
    {
        if (in_array($actor['role'], ['host', 'co_host'], true)) {
            return true;
        }

        /** @var MeetingParticipant|MeetingJoinRequest $model */
        $model = $actor['model'];
        if ($model->screen_share_allowed === false) {
            return false;
        }
        if ($model->screen_share_allowed === true) {
            return true;
        }

        return !$meeting->screen_sharing_locked;
    }

    /** بند 9 — "Allow screen sharing" / "Disable screen sharing" (القفل العام بتاع الاجتماع كله). @throws \RuntimeException actor مش host/co-host. */
    public function setScreenSharingLock(Meeting $meeting, array $actor, bool $locked): bool
    {
        if (!in_array($actor['role'], ['host', 'co_host'], true)) {
            throw new \RuntimeException('Only the host or co-host can change the screen sharing policy.');
        }

        $meeting->screen_sharing_locked = $locked;
        $meeting->save();

        event(new ScreenSharePolicyChanged($meeting->uuid, $locked));

        return $locked;
    }

    /**
     * بند 9 — استثناء/منع شخص بعينه من قرار القفل العام (tri-state، راجع
     * docblock migration 2026_08_31_050000). $allowed=null بيرجّعه
     * لسياسة القفل العام (يمسح الاستثناء).
     *
     * @throws \RuntimeException actor مش host/co-host، أو الـ target key مش موجود في الاجتماع ده.
     */
    public function setParticipantScreenSharePolicy(Meeting $meeting, array $actor, string $targetKey, ?bool $allowed): void
    {
        if (!in_array($actor['role'], ['host', 'co_host'], true)) {
            throw new \RuntimeException('Only the host or co-host can change a participant\'s screen sharing permission.');
        }

        $target = $this->findActorModelByKey($meeting, $targetKey);
        if (!$target) {
            throw new \RuntimeException('This participant could not be found in this meeting.');
        }

        $target->screen_share_allowed = $allowed;
        $target->save();

        event(new ScreenShareParticipantPolicyChanged($meeting->uuid, $targetKey, $allowed));
    }

    /** بند 9 — "Stop another participant's sharing session." @throws \RuntimeException actor مش host/co-host، أو الـ target مش بيشارك شاشته أصلًا. */
    public function stopParticipantScreenShare(Meeting $meeting, array $actor, string $targetKey): void
    {
        if (!in_array($actor['role'], ['host', 'co_host'], true)) {
            throw new \RuntimeException('Only the host or co-host can stop another participant\'s screen share.');
        }

        $target = $this->findActorModelByKey($meeting, $targetKey);
        if (!$target || !$target->screen_sharing) {
            throw new \RuntimeException('This participant is not currently sharing their screen.');
        }

        $target->screen_sharing = false;
        $target->save();

        $targetDisplayName = $target instanceof MeetingParticipant
            ? ($target->user->full_name ?? ('User #' . $target->user_id))
            : (string) $target->guest_name;

        event(new ParticipantMediaStateChanged(
            $meeting->uuid,
            $targetKey,
            $targetDisplayName,
            (bool) $target->mic_enabled,
            (bool) $target->camera_enabled,
            false
        ));
        event(new ParticipantScreenShareForceStopped($meeting->uuid, $targetKey, $actor['key'], $actor['display_name']));

        $this->chat?->postSystemMessage($meeting, $targetDisplayName . ' stopped sharing their screen');
    }

    /**
     * بند 10 (Participant Management) — Round 6: نفس شكل resolveActor()
     * بالظبط (type/model/key/display_name/role)، بس بمفتاح actor key
     * جاهز (مش JWT/uip_user_id) — لما الهوست بيدير حد **تاني** (يوت/
     * يشيل/يرقّي)، مش هو نفسه اللي بيتعامل مع القناة. راجع docblock
     * MeetingHostControlService لتفاصيل الاستخدام.
     *
     * @throws \RuntimeException الـ key ده مش موجود في الاجتماع ده، أو
     *         بيمثل حد مش حاضر فعليًا دلوقتي (نفس شروط resolveActor
     *         بالظبط — joined/left لليوزر، admitted للضيف).
     */
    public function resolveTargetActor(Meeting $meeting, string $key): array
    {
        $model = $this->findActorModelByKey($meeting, $key);
        if (!$model) {
            throw new \RuntimeException('This participant could not be found in this meeting.');
        }

        if ($model instanceof MeetingParticipant) {
            if (!in_array($model->status, ['joined', 'left'], true)) {
                throw new \RuntimeException('This participant is not currently in the meeting.');
            }

            return [
                'type'         => 'participant',
                'model'        => $model,
                'key'          => $key,
                'display_name' => $model->user->full_name ?? ('User #' . $model->user_id),
                'role'         => $model->role,
            ];
        }

        /** @var MeetingJoinRequest $model */
        if ($model->status !== 'admitted') {
            throw new \RuntimeException('This participant is not currently in the meeting.');
        }

        return [
            'type'         => 'guest',
            'model'        => $model,
            'key'          => $key,
            'display_name' => (string) $model->guest_name,
            'role'         => 'guest',
        ];
    }

    /** بند 9 — 'user:ID'/'guest:ID' -> الموديل الفعلي بتاعه جوه الاجتماع ده، من غير المرور بـ resolveActor() (مش لازم يكون هو نفسه اللي بيبعت الـ request). */
    private function findActorModelByKey(Meeting $meeting, string $key)
    {
        if (str_starts_with($key, 'user:')) {
            return $this->meetings->findParticipant($meeting->id, (int) substr($key, strlen('user:')));
        }

        if (str_starts_with($key, 'guest:')) {
            $joinRequest = $this->meetings->findJoinRequest((int) substr($key, strlen('guest:')));
            if ($joinRequest && (int) $joinRequest->meeting_id === (int) $meeting->id) {
                return $joinRequest;
            }
        }

        return null;
    }
}
