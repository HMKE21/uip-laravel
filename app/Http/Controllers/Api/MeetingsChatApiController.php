<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingChatService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/chat/* — Round 5 (Live Collaboration):
 * بند 7 (Meeting Chat)، بند 8 (Private Chat). منفصل عن
 * MeetingsSignalingApiController عمدًا (بالرغم من نفس الميدلوير ونفس
 * أسلوب resolveActor) — الشات كيان بيانات مستقل ليه تاريخ (list/send)
 * بعكس حالة الـ signaling اللحظية البحتة، فمنطقي يبقى كنترولر منفصل
 * زي ما ExamQuestionsApiController منفصل عن ExamsApiController مثلًا.
 *
 * مسجّلة تحت uip.auth.optional زي كل سطح signaling — الضيف المقبول
 * (بند 23) لازم يقدر يستخدم الشات برضو (بند 7 "Meeting Chat" مفيهاش
 * تفرقة بين مستخدم وضيف).
 */
class MeetingsChatApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingChatService $chat
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

    /** بند 7 — history عند فتح شاشة الاجتماع/بعد إعادة اتصال. ?after_id=123 بيرجع بس الأحدث منها. */
    public function index(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'after_id'    => 'sometimes|integer|min:1',
            'guest_token' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $afterId = $request->filled('after_id') ? (int) $request->input('after_id') : null;

        return $this->apiSuccess(
            ['messages' => $this->chat->listFor($meeting, $actor, $afterId)],
            'Messages retrieved successfully.'
        );
    }

    /** بند 7 (رسالة عامة، recipient_key فاضي) + بند 8 (رسالة خاصة، recipient_key موجود). */
    public function store(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'body'                  => 'required|string|max:4000',
            'recipient_key'         => 'nullable|string|max:40',
            'reply_to_message_id'   => 'nullable|integer|min:1',
            'mentioned_keys'        => 'nullable|array|max:20',
            'mentioned_keys.*'      => 'string|max:40',
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
            $message = $this->chat->send($meeting, $actor, $request->only([
                'body', 'recipient_key', 'reply_to_message_id', 'mentioned_keys',
            ]));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->chat->present($message), 'Message sent successfully.', 201);
    }

    /** بند 7 — "Message reactions" (toggle: نفس الإيموجي تاني من نفس الشخص بيشيلها). */
    public function react(Request $request, string $uuid, string $messageId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'emoji'       => 'required|string|max:32',
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
            $result = $this->chat->toggleReaction($meeting, $actor, (int) $messageId, $request->input('emoji'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($result, 'Reaction updated successfully.');
    }
}
