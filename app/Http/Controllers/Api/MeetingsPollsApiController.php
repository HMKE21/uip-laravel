<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingPollService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/polls/* — Round 7 (Collaboration Extras):
 * بند 21 (Polls). نفس نمط MeetingsFilesApiController/MeetingsNotesApiController
 * (uip.auth.optional، resolveActorOr403) — الإنشاء/القفل مقصور على
 * هوست/co-host (MeetingPollService::requireManager)، أي actor حاضر
 * يقدر يصوّت/يشوف النتائج.
 */
class MeetingsPollsApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingPollService $polls
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

    private function findPollOr404($meeting, string $pollId)
    {
        $poll = $this->meetings->findPoll($meeting->id, (int) $pollId);
        if (!$poll) {
            return $this->apiError('Poll not found.', null, 404);
        }

        return $poll;
    }

    public function index(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $polls = array_map(fn ($poll) => $this->polls->results($poll), $this->polls->listFor($meeting));

        return $this->apiSuccess(['polls' => $polls], 'Polls retrieved successfully.');
    }

    public function store(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'question'      => 'required|string|max:500',
            'poll_type'     => 'nullable|in:single_choice,multiple_choice',
            'is_anonymous'  => 'nullable|boolean',
            'options'       => 'required|array|min:2',
            'options.*'     => 'required|string|max:255',
            'guest_token'   => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $poll = $this->polls->create($meeting, $actor, $validator->validated());
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->polls->present($poll), 'Poll created successfully.', 201);
    }

    public function vote(Request $request, string $uuid, string $pollId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'option_ids'   => 'required|array|min:1',
            'option_ids.*' => 'required|integer',
            'guest_token'  => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $poll = $this->findPollOr404($meeting, $pollId);
        if ($poll instanceof \Illuminate\Http\JsonResponse) {
            return $poll;
        }

        try {
            $results = $this->polls->vote($meeting, $actor, $poll, $request->input('option_ids'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($results, 'Vote recorded successfully.');
    }

    public function close(Request $request, string $uuid, string $pollId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $poll = $this->findPollOr404($meeting, $pollId);
        if ($poll instanceof \Illuminate\Http\JsonResponse) {
            return $poll;
        }

        try {
            $results = $this->polls->close($meeting, $actor, $poll);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess($results, 'Poll closed successfully.');
    }
}
