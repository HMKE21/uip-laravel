<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\AuditLogService;
use App\Services\MeetingHostControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/host-controls/* — Round 6 (Host
 * Controls): بند 5 (الجزء الخاص بأوامر الهوست/moderator في الـ
 * toolbar)، بند 10 (Participant Management panel).
 *
 * مسجّلة تحت uip.auth العادي (مش uip.auth.optional زي signaling/chat)
 * — الـ actor هنا دايمًا هوست/co-host، وده زي ما
 * MeetingHostControlService::actorIdentity() بيفترض بالظبط لازم يكون
 * مستخدم UIP مسجّل بتوكن حقيقي، مفيش ضيف يقدر يدير اجتماع. الهدف
 * (participant_key) بييجي في body/route كـ actor key ('user:5' أو
 * 'guest:12') ويتحل جوه الـ service نفسه عبر
 * MeetingSignalingService::resolveTargetActor() — نفس شكل
 * participant_key في MeetingsSignalingApiController::screenShareStop()
 * بالظبط.
 *
 * كل الاستثناءات هنا \RuntimeException بس (لا InvalidArgumentException
 * — مفيش تحقق شكل body معقد غير الـ validation العادي)، فبترجع 403
 * لو صلاحية أو 422/404 لو الهدف نفسه غير صالح (Signaling بيرمي
 * RuntimeException برضو لعدم وجود العنصر — بنرجعها 404 بدل 403 هنا
 * تحديدًا عشان نفرّق "مفيش صلاحية" عن "الهدف مش موجود" للفرونت).
 */
class MeetingsHostControlApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingHostControlService $hostControl,
        private AuditLogService $auditLog
    ) {
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function findMeeting(string $uuid)
    {
        return $this->meetings->findByUuid($uuid);
    }

    /** الفرق بين "مش مصرّحلك" (403) و"الهدف مش موجود" (404) بناءً على نص رسالة الاستثناء. */
    private function handle(callable $action)
    {
        try {
            return $action();
        } catch (\RuntimeException $e) {
            $notFoundNeedles = ['could not be found', 'not currently in the meeting'];
            foreach ($notFoundNeedles as $needle) {
                if (str_contains($e->getMessage(), $needle)) {
                    return $this->apiError($e->getMessage(), null, 404);
                }
            }

            return $this->apiError($e->getMessage(), null, 403);
        }
    }

    private function targetKeyValidator(array $extra = []): array
    {
        return array_merge(['participant_key' => 'required|string|max:40'], $extra);
    }

    /** بند 5 — "Lock meeting" / "Unlock meeting". */
    public function lock(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), ['locked' => 'required|boolean']);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $locked = $this->hostControl->setLock($meeting, $this->userId($request), (bool) $request->input('locked'));
            $this->auditLog->record($this->userId($request), $locked ? 'meetings.locked' : 'meetings.unlocked', 'Meeting', $meeting->id);

            return $this->apiSuccess(['locked' => $locked], 'Meeting lock updated successfully.');
        });
    }

    /** بند 5/10 — "Mute participant". */
    public function mute(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), $this->targetKeyValidator());
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $state = $this->hostControl->mute($meeting, $this->userId($request), $request->input('participant_key'));
            $this->auditLog->record($this->userId($request), 'meetings.participant_muted', 'Meeting', $meeting->id);

            return $this->apiSuccess($state, 'Participant muted successfully.');
        });
    }

    /** بند 5/10 — "Disable participant camera". */
    public function disableCamera(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), $this->targetKeyValidator());
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $state = $this->hostControl->disableCamera($meeting, $this->userId($request), $request->input('participant_key'));
            $this->auditLog->record($this->userId($request), 'meetings.participant_camera_disabled', 'Meeting', $meeting->id);

            return $this->apiSuccess($state, 'Participant camera disabled successfully.');
        });
    }

    /** بند 5/10 — "Remove participant". */
    public function remove(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), $this->targetKeyValidator());
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $this->hostControl->remove($meeting, $this->userId($request), $request->input('participant_key'));
            $this->auditLog->record($this->userId($request), 'meetings.participant_removed', 'Meeting', $meeting->id);

            return $this->apiSuccess(null, 'Participant removed from the meeting.');
        });
    }

    /** بند 5/10 — "Promote to moderator" (host الأساسي بس). */
    public function promote(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), $this->targetKeyValidator());
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $this->hostControl->promote($meeting, $this->userId($request), $request->input('participant_key'));
            $this->auditLog->record($this->userId($request), 'meetings.participant_promoted', 'Meeting', $meeting->id);

            return $this->apiSuccess(null, 'Participant promoted to moderator.');
        });
    }

    /** بند 5/10 — "Remove moderator" (Demote) (host الأساسي بس). */
    public function demote(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), $this->targetKeyValidator());
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $this->hostControl->demote($meeting, $this->userId($request), $request->input('participant_key'));
            $this->auditLog->record($this->userId($request), 'meetings.participant_demoted', 'Meeting', $meeting->id);

            return $this->apiSuccess(null, 'Participant is no longer a moderator.');
        });
    }

    /** بند 5 — "Make participant host" (host الأساسي بس). */
    public function transferHost(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), $this->targetKeyValidator());
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        return $this->handle(function () use ($request, $meeting) {
            $this->hostControl->transferHost($meeting, $this->userId($request), $request->input('participant_key'));
            $this->auditLog->record($this->userId($request), 'meetings.host_transferred', 'Meeting', $meeting->id);

            return $this->apiSuccess(null, 'Host role transferred successfully.');
        });
    }
}
