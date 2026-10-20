<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingNotesService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/notes/* و .../action-items/* — Round 7
 * (Collaboration Extras): بند 20 (Meeting Notes). نفس نمط
 * MeetingsFilesApiController بالظبط (uip.auth.optional،
 * resolveActorOr403) — أي actor حاضر (ضيف مقبول برضو) يقدر يشوف
 * الملاحظات/الـaction items، لكن التعديل مقصور على هوست/co-host
 * (MeetingNotesService::requireManager يرمي RuntimeException فبنحوّلها
 * لـ 403).
 */
class MeetingsNotesApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingNotesService $notes
    ) {
    }

    private function authUserId(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id !== null ? (int) $id : null;
    }

    private function findMeeting(string $uuid)
    {
        return $this->meetings->findByUuid($uuid);
    }

    private function resolveActorOr403(Request $request, $meeting)
    {
        try {
            return $this->signaling->resolveActor($meeting, $this->authUserId($request), $request->input('guest_token'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }
    }

    private function presentItem($item): array
    {
        return [
            'id'                     => $item->id,
            'type'                   => $item->type,
            'title'                  => $item->title,
            'assignee_display_name'  => $item->assignee_display_name,
            'due_at'                 => $item->due_at,
            'status'                 => $item->status,
            'created_by_display_name' => $item->created_by_display_name,
            'created_at'             => $item->created_at,
        ];
    }

    public function show(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $note = $this->notes->get($meeting);

        return $this->apiSuccess([
            'body'                          => $note->body,
            'last_edited_by_display_name'   => $note->last_edited_by_display_name,
            'last_edited_at'                => $note->last_edited_at,
        ], 'Notes retrieved successfully.');
    }

    public function update(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'body'        => 'required|string',
            'guest_token' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $note = $this->notes->updateBody($meeting, $actor, $request->input('body'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess([
            'body'                        => $note->body,
            'last_edited_by_display_name' => $note->last_edited_by_display_name,
            'last_edited_at'              => $note->last_edited_at,
        ], 'Notes updated successfully.');
    }

    public function indexItems(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $items = array_map(fn ($item) => $this->presentItem($item), $this->notes->listItems($meeting));

        return $this->apiSuccess(['action_items' => $items], 'Action items retrieved successfully.');
    }

    public function storeItem(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'type'                  => 'nullable|in:decision,action_item,task',
            'title'                 => 'required|string|max:500',
            'assignee_display_name' => 'nullable|string|max:255',
            'due_at'                => 'nullable|date',
            'guest_token'           => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $item = $this->notes->createItem($meeting, $actor, $validator->validated());
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess($this->presentItem($item), 'Action item created successfully.', 201);
    }

    public function updateItem(Request $request, string $uuid, string $itemId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'type'                  => 'nullable|in:decision,action_item,task',
            'title'                 => 'nullable|string|max:500',
            'assignee_display_name' => 'nullable|string|max:255',
            'due_at'                => 'nullable|date',
            'status'                => 'nullable|in:open,done',
            'guest_token'           => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $item = $this->meetings->findActionItem($meeting->id, (int) $itemId);
        if (!$item) {
            return $this->apiError('Action item not found.', null, 404);
        }

        try {
            $item = $this->notes->updateItem($meeting, $actor, $item, $validator->validated());
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess($this->presentItem($item), 'Action item updated successfully.');
    }

    public function destroyItem(Request $request, string $uuid, string $itemId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $item = $this->meetings->findActionItem($meeting->id, (int) $itemId);
        if (!$item) {
            return $this->apiError('Action item not found.', null, 404);
        }

        try {
            $this->notes->deleteItem($meeting, $actor, $item);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess(null, 'Action item removed successfully.');
    }
}
