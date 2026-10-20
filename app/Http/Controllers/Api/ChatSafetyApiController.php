<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatSafetyService;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/**
 * Chat safety — حظر المستخدمين وبلاغات الشات.
 *
 *  /api/v1/messaging/blocks            (كل الأدوار اللي معاها messaging.use): قايمة المحظورين / حظر / إلغاء حظر
 *  /api/v1/messaging/reports           (نفس الصلاحية): إرسال بلاغ + "بلاغاتي"
 *  /api/v1/chat-reports                (admin / university / faculty): طابور المتابعة وتغيير الحالة
 */
class ChatSafetyApiController extends Controller
{
    public function __construct(private ChatSafetyService $safety, private MessagingService $messaging)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function role(Request $request): string
    {
        return (string) $request->attributes->get('uip_role');
    }

    /** GET /messaging/blocks */
    public function blocks(Request $request)
    {
        return $this->apiSuccess($this->safety->blockedList($this->uid($request)), 'Blocked users retrieved successfully.');
    }

    /** POST /messaging/blocks — body: user_id */
    public function block(Request $request)
    {
        return $this->handle(function () use ($request) {
            $this->safety->block($this->uid($request), (int) $request->input('user_id'));
            return $this->apiSuccess($this->safety->blockedList($this->uid($request)), 'User blocked.');
        });
    }

    /** DELETE /messaging/blocks/{userId} */
    public function unblock(Request $request, $userId)
    {
        $this->safety->unblock($this->uid($request), (int) $userId);
        return $this->apiSuccess($this->safety->blockedList($this->uid($request)), 'User unblocked.');
    }

    /** POST /messaging/reports — body: message_id | user_id(+conversation_id), reason, details?, also_block? */
    public function report(Request $request)
    {
        return $this->handle(function () use ($request) {
            $result = $this->safety->report($this->uid($request), [
                'message_id'      => $request->filled('message_id') ? (int) $request->input('message_id') : null,
                'user_id'         => $request->filled('user_id') ? (int) $request->input('user_id') : null,
                'conversation_id' => $request->filled('conversation_id') ? (int) $request->input('conversation_id') : null,
                'reason'          => (string) $request->input('reason', ''),
                'details'         => $request->input('details'),
                'also_block'      => filter_var($request->input('also_block', false), FILTER_VALIDATE_BOOLEAN),
            ]);
            return $this->apiSuccess($result, 'Thanks — your report was sent to the university, faculty and platform admins.', 201);
        });
    }

    /** GET /messaging/reports/mine */
    public function myReports(Request $request)
    {
        return $this->apiSuccess($this->safety->myReports($this->uid($request)), 'Your reports retrieved successfully.');
    }

    /** GET /chat-reports — query: status?, reason?, q? */
    public function index(Request $request)
    {
        return $this->handle(function () use ($request) {
            $data = $this->safety->reportsFor($this->role($request), $this->uid($request), $request->only(['status', 'reason', 'q']));
            return $this->apiSuccess($data, 'Reports retrieved successfully.');
        }, 403);
    }

    /** POST /chat-reports/{id}/status — body: status, notes?, action? ('delete_message' للأدمن) */
    public function updateStatus(Request $request, $id)
    {
        if (!in_array($this->role($request), ['admin', 'university', 'faculty'], true)) {
            return $this->apiError('You are not allowed to manage chat reports.', null, 403);
        }
        return $this->handle(function () use ($request, $id) {
            $this->safety->updateStatus(
                $this->role($request),
                $this->uid($request),
                (int) $id,
                (string) $request->input('status', ''),
                $request->filled('notes') ? (string) $request->input('notes') : null,
                $request->filled('action') ? (string) $request->input('action') : null,
                $this->messaging
            );
            return $this->apiSuccess(null, 'Report updated successfully.');
        });
    }

    private function handle(\Closure $action, int $status = 422)
    {
        try {
            return $action();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, $status);
        }
    }
}
