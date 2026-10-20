<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingAttachableService;
use App\Services\MeetingInvitationService;
use App\Services\MeetingPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح Round 8 (Invitations & Calendar): بند 13 (Bulk Invitations — أدوار/
 * مجموعات/مشاريع، راجع docblock MeetingInvitationService) وبند 14
 * (Calendar، عرض Day/Week/Month) وبند 26 (Project Integration —
 * استعراض اجتماعات كيان UIP معيّن، راجع docblock migration
 * 2026_08_31_090000).
 *
 * مسجّلة زي باقي مجموعة meetings/* الأساسية تحت uip.auth العادي —
 * مفيش ضيف هنا (بند 13/14 الاتنين أفعال هوست/مستخدم مسجّل بس، مش
 * جزء من تجربة الانضمام العامة زي join/signaling/chat).
 */
class MeetingsInvitationsApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy,
        private MeetingInvitationService $bulkInvites,
        private MeetingAttachableService $attachables
    ) {
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function role(Request $request): string
    {
        return (string) $request->attributes->get('uip_role');
    }

    private function present($meeting): array
    {
        $data = $meeting->toArray();
        $data['has_password'] = $meeting->hasPassword();
        return $data;
    }

    /**
     * POST /api/v1/meetings/{uuid}/invitations/bulk — بند 13. الهوست/
     * co-host بس (canManage) — نفس تدرّج صلاحيات storeInvitation() الفردي.
     * body: {targets: array[], message?: string} — راجع docblock
     * MeetingInvitationService لشكل كل عنصر في targets.
     */
    public function bulk(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting || !$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'targets'   => 'required|array|min:1',
            'targets.*' => 'array',
            'message'   => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->bulkInvites->bulkInvite(
                $meeting,
                $this->userId($request),
                $this->role($request),
                $request->input('targets'),
                $request->input('message')
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'invited_count'        => $result['invited_count'],
            'skipped_self_or_host' => $result['skipped_self_or_host'],
            'invitations'          => $result['invitations'],
        ], 'Bulk invitations sent successfully.', 201);
    }

    /**
     * GET /api/v1/meetings/calendar — بند 14 ("Calendar view: Day, Week,
     * Month"). start/end بيحددهم الفرونت حسب الـ view المختارة عنده
     * (يوم/أسبوع/شهر) — الـ API هنا عام لأي مدى تاريخ، مفيش منطق
     * "أسبوعي/شهري" مقفول في الباك اند نفسه.
     */
    public function calendar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|date',
            'end'   => 'required|date|after_or_equal:start',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $meetings = $this->meetings->calendarForUser(
            $this->userId($request),
            $request->input('start'),
            $request->input('end')
        );

        return $this->apiSuccess(array_map(fn ($m) => $this->present($m), $meetings), 'Calendar meetings retrieved successfully.');
    }

    /**
     * GET /api/v1/meetings/by-attachable?attachable_type=&attachable_id=
     * — بند 26. مفتوحة لأي مستخدم مسجّل (زي index() العادي) ثم بترشّح
     * على canView() لكل اجتماع — نفس مبدأ "تقدر تشوف بس اللي انت هوست
     * أو مشارك فيه"، حتى لو الاجتماع مربوط بكيان انت عضو فيه.
     */
    public function byAttachable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attachable_type' => 'required|string|max:40',
            'attachable_id'   => 'required|integer',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $userId = $this->userId($request);
        $meetings = array_values(array_filter(
            $this->meetings->forAttachable($request->input('attachable_type'), $request->input('attachable_id')),
            fn ($m) => $this->policy->canView($m, $userId, $this->meetings)
        ));

        return $this->apiSuccess(array_map(fn ($m) => $this->present($m), $meetings), 'Meetings retrieved successfully.');
    }
}
