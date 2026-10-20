<?php

namespace App\Services;

use App\Events\Meetings\MeetingHostTransferred;
use App\Events\Meetings\MeetingLockChanged;
use App\Events\Meetings\ParticipantCameraDisabledByHost;
use App\Events\Meetings\ParticipantMediaStateChanged;
use App\Events\Meetings\ParticipantMutedByHost;
use App\Events\Meetings\ParticipantRemovedFromMeeting;
use App\Events\Meetings\ParticipantRoleChanged;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Repositories\MeetingRepository;

/**
 * Meetings & Collaboration Platform — Round 6 (Host Controls): بند 5
 * (الجزء الخاص بأوامر الهوست/moderator في الـ toolbar — Mute/Remove/
 * Disable camera/Make host/Promote/Remove moderator/Lock meeting)، بند
 * 10 (Participant Management panel — نفس الأوامر من ناحية اللوحة).
 * "End meeting for everyone" و"Manage waiting room" و"Manage
 * permissions (screen sharing)" مش هنا — دول Round 1/2/5 بالفعل
 * (MeetingService::end()، MeetingsApiController::waitingRoom*()،
 * MeetingSignalingService::setScreenSharingLock()) — الملف ده بيغطي
 * بس أوامر الهوست الجديدة اللي محتاجاله Round 6 تحديدًا.
 *
 * نفس فلسفة MeetingChatService بالظبط: المنطق كله هنا، الكنترولر بس
 * بيتحقق من شكل الـ input ويحوّل الاستثناءات لردود API.
 *
 * "actor يدير حد تاني" (مش نفسه) هنا طول الوقت — بعكس
 * MeetingSignalingService اللي فيه actor بيتحكم في حالته هو. الهوست/
 * co-host اللي بيبعت الأمر بيوصلنا كـ $actorUserId (int عادي من
 * uip_user_id في التوكن، زي باقي MeetingsApiController — الهوست/
 * co-host دايمًا مستخدم مسجّل، مفيش ضيف يقدر يدير اجتماع)، والهدف
 * بيوصلنا كـ actor key ('user:5'/'guest:12') بيتحل عبر
 * MeetingSignalingService::resolveTargetActor() — نفس الأسلوب اللي
 * stopParticipantScreenShare() في Round 5 بنته أصلًا.
 *
 * صلاحيات متدرّجة عمدًا (مش "canManage بس" لكل حاجة): mute/disable-
 * camera/remove(مشارك عادي)/lock — هوست أو co-host (canManage).
 * remove(co-host)/promote/demote/make-host — الهوست الأساسي بس
 * (isHost) — عشان co-host مايقدرش يشيل co-host تاني أو يرقّي حد لنفس
 * مستواه من غير علم الهوست الحقيقي.
 */
class MeetingHostControlService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy,
        private MeetingSignalingService $signaling,
        private ?MeetingChatService $chat = null
    ) {
    }

    /** @throws \RuntimeException actor مش هوست ولا co-host. */
    private function requireManager(Meeting $meeting, int $actorUserId): void
    {
        if (!$this->policy->canManage($meeting, $actorUserId, $this->meetings)) {
            throw new \RuntimeException('Only the host or co-host can perform this action.');
        }
    }

    /** @throws \RuntimeException actor مش الهوست الأساسي. */
    private function requirePrimaryHost(Meeting $meeting, int $actorUserId): void
    {
        if (!$this->policy->isHost($meeting, $actorUserId)) {
            throw new \RuntimeException('Only the host can perform this action.');
        }
    }

    /** @return array{key:string, display_name:string} هوية الـ actor (الهوست/co-host) نفسه لغرض الأحداث/رسائل النظام. */
    private function actorIdentity(Meeting $meeting, int $userId): array
    {
        $participant = $this->meetings->findParticipant($meeting->id, $userId);

        return [
            'key'          => 'user:' . $userId,
            'display_name' => $participant?->user->full_name ?? ('User #' . $userId),
        ];
    }

    /** الهدف لازم يكون مستخدم مسجّل (مشارك حقيقي في meeting_participants) — مش ضيف. @throws \RuntimeException الهدف ضيف. */
    private function resolveRegisteredTarget(Meeting $meeting, string $targetKey, string $actionForMessage): array
    {
        $target = $this->signaling->resolveTargetActor($meeting, $targetKey);
        if ($target['type'] !== 'participant') {
            throw new \RuntimeException('Guests cannot be ' . $actionForMessage . '.');
        }

        return $target;
    }

    // -----------------------------------------------------------------
    // بند 5 — "Lock meeting"
    // -----------------------------------------------------------------

    public function setLock(Meeting $meeting, int $actorUserId, bool $locked): bool
    {
        $this->requireManager($meeting, $actorUserId);

        $meeting->locked = $locked;
        $meeting->save();

        event(new MeetingLockChanged($meeting->uuid, $locked));

        $actor = $this->actorIdentity($meeting, $actorUserId);
        $this->chat?->postSystemMessage(
            $meeting,
            $actor['display_name'] . ($locked ? ' locked the meeting' : ' unlocked the meeting')
        );

        return $locked;
    }

    // -----------------------------------------------------------------
    // بند 5/10 — "Mute participant" / "Disable participant camera"
    // -----------------------------------------------------------------

    /** @return array{mic_enabled:bool} */
    public function mute(Meeting $meeting, int $actorUserId, string $targetKey): array
    {
        $this->requireManager($meeting, $actorUserId);
        $target = $this->signaling->resolveTargetActor($meeting, $targetKey);

        /** @var MeetingParticipant|\App\Models\MeetingJoinRequest $model */
        $model = $target['model'];
        $model->mic_enabled = false;
        $model->save();

        $actor = $this->actorIdentity($meeting, $actorUserId);

        event(new ParticipantMediaStateChanged(
            $meeting->uuid,
            $targetKey,
            $target['display_name'],
            false,
            (bool) $model->camera_enabled,
            (bool) $model->screen_sharing
        ));
        event(new ParticipantMutedByHost($meeting->uuid, $targetKey, $actor['key'], $actor['display_name']));

        return ['mic_enabled' => false];
    }

    /** @return array{camera_enabled:bool} */
    public function disableCamera(Meeting $meeting, int $actorUserId, string $targetKey): array
    {
        $this->requireManager($meeting, $actorUserId);
        $target = $this->signaling->resolveTargetActor($meeting, $targetKey);

        /** @var MeetingParticipant|\App\Models\MeetingJoinRequest $model */
        $model = $target['model'];
        $model->camera_enabled = false;
        $model->save();

        $actor = $this->actorIdentity($meeting, $actorUserId);

        event(new ParticipantMediaStateChanged(
            $meeting->uuid,
            $targetKey,
            $target['display_name'],
            (bool) $model->mic_enabled,
            false,
            (bool) $model->screen_sharing
        ));
        event(new ParticipantCameraDisabledByHost($meeting->uuid, $targetKey, $actor['key'], $actor['display_name']));

        return ['camera_enabled' => false];
    }

    // -----------------------------------------------------------------
    // بند 5/10 — "Remove participant"
    // -----------------------------------------------------------------

    /**
     * @throws \RuntimeException actor مش هوست/co-host، أو الهدف هو
     *         الهوست نفسه (استخدم transferHost() أو end() بدلًا)، أو
     *         الهدف co-host وactor مش الهوست الأساسي.
     */
    public function remove(Meeting $meeting, int $actorUserId, string $targetKey): void
    {
        $this->requireManager($meeting, $actorUserId);
        $target = $this->signaling->resolveTargetActor($meeting, $targetKey);

        if ($target['role'] === 'host') {
            throw new \RuntimeException('The host cannot be removed from the meeting.');
        }
        if ($target['role'] === 'co_host' && !$this->policy->isHost($meeting, $actorUserId)) {
            throw new \RuntimeException('Only the host can remove a moderator.');
        }

        /** @var MeetingParticipant|\App\Models\MeetingJoinRequest $model */
        $model = $target['model'];
        $now = now();

        // راجع docblock migration 2026_08_31_070000 ليه الضيف بياخد
        // 'rejected' هنا بدل قيمة enum جديدة، والمشارك المسجّل بياخد
        // 'removed' الموجودة أصلًا من Round 1.
        $model->status = $model instanceof MeetingParticipant ? 'removed' : 'rejected';
        $model->left_at = $now;
        $model->connection_state = 'disconnected';
        $model->hand_raised = false;
        $model->screen_sharing = false;
        $model->removed_by_user_id = $actorUserId;
        $model->removed_at = $now;
        $model->save();

        $actor = $this->actorIdentity($meeting, $actorUserId);

        event(new ParticipantRemovedFromMeeting($meeting->uuid, $targetKey, $target['display_name'], $actor['key'], $actor['display_name']));

        $this->chat?->postSystemMessage($meeting, $target['display_name'] . ' was removed from the meeting');
    }

    // -----------------------------------------------------------------
    // بند 5/10 — "Promote moderator" / "Remove moderator" (Demote)
    // -----------------------------------------------------------------

    /** @throws \RuntimeException actor مش الهوست الأساسي، الهدف ضيف، أو الهدف هوست بالفعل. */
    public function promote(Meeting $meeting, int $actorUserId, string $targetKey): void
    {
        $this->requirePrimaryHost($meeting, $actorUserId);
        $target = $this->resolveRegisteredTarget($meeting, $targetKey, 'promoted to moderator');

        if ($target['role'] === 'host') {
            throw new \RuntimeException('This participant is already the host.');
        }
        if ($target['role'] === 'co_host') {
            throw new \RuntimeException('This participant is already a moderator.');
        }

        /** @var MeetingParticipant $model */
        $model = $target['model'];
        $model->role = 'co_host';
        $model->save();

        event(new ParticipantRoleChanged($meeting->uuid, $targetKey, $target['display_name'], 'co_host', 'user:' . $actorUserId));
        $this->chat?->postSystemMessage($meeting, $target['display_name'] . ' was promoted to moderator');
    }

    /** @throws \RuntimeException actor مش الهوست الأساسي، الهدف ضيف، أو الهدف مش co-host أصلًا. */
    public function demote(Meeting $meeting, int $actorUserId, string $targetKey): void
    {
        $this->requirePrimaryHost($meeting, $actorUserId);
        $target = $this->resolveRegisteredTarget($meeting, $targetKey, 'demoted');

        if ($target['role'] !== 'co_host') {
            throw new \RuntimeException('This participant is not currently a moderator.');
        }

        /** @var MeetingParticipant $model */
        $model = $target['model'];
        $model->role = 'participant';
        $model->save();

        event(new ParticipantRoleChanged($meeting->uuid, $targetKey, $target['display_name'], 'participant', 'user:' . $actorUserId));
        $this->chat?->postSystemMessage($meeting, $target['display_name'] . ' is no longer a moderator');
    }

    // -----------------------------------------------------------------
    // بند 5 — "Make participant host"
    // -----------------------------------------------------------------

    /**
     * الهوست القديم بيتحوّل لـ co_host تلقائيًا (مش بيرجع participant
     * عادي) — نفس سلوك Zoom القياسي، عشان محدش يفضل الاجتماع من غير
     * حد قادر يديره لو الهوست الجديد قطع اتصاله فجأة.
     *
     * @throws \RuntimeException actor مش الهوست الأساسي، الهدف ضيف، أو الهدف هوست بالفعل.
     */
    public function transferHost(Meeting $meeting, int $actorUserId, string $targetKey): void
    {
        $this->requirePrimaryHost($meeting, $actorUserId);
        $target = $this->resolveRegisteredTarget($meeting, $targetKey, 'made host');

        if ($target['role'] === 'host') {
            throw new \RuntimeException('This participant is already the host.');
        }

        /** @var MeetingParticipant $newHostParticipant */
        $newHostParticipant = $target['model'];
        $newHostParticipant->role = 'host';
        $newHostParticipant->save();

        $oldHostParticipant = $this->meetings->findParticipant($meeting->id, $actorUserId);
        if ($oldHostParticipant) {
            $oldHostParticipant->role = 'co_host';
            $oldHostParticipant->save();
        }

        $meeting->host_user_id = $newHostParticipant->user_id;
        $meeting->save();

        event(new MeetingHostTransferred($meeting->uuid, 'user:' . $actorUserId, $targetKey, $target['display_name']));
        $this->chat?->postSystemMessage($meeting, $target['display_name'] . ' is now the host');
    }
}
