<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConversationTrashService;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/**
 * سلة محذوفات المحادثات — /api/v1/messaging/trash/*
 * body للأفعال: ids[] (محادثات محدّدة) أو all=true (الكل).
 */
class ConversationTrashController extends Controller
{
    public function __construct(private ConversationTrashService $trash, private MessagingService $messaging)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function run(Request $request, string $method, string $message)
    {
        $data = $request->validate([
            'ids'   => 'sometimes|array|max:' . ConversationTrashService::MAX_BATCH,
            'ids.*' => 'integer',
            'all'   => 'sometimes|boolean',
        ]);
        try {
            $count = $this->trash->{$method}($this->uid($request), $data['ids'] ?? [], (bool) ($data['all'] ?? false));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        return $this->apiSuccess(['count' => $count], $message);
    }

    /** GET /messaging/trash — محادثات السلة + الأيام المتبقية لكل واحدة */
    public function index(Request $request)
    {
        return $this->apiSuccess([
            'retention_days' => ConversationTrashService::RETENTION_DAYS,
            'items'          => $this->messaging->inboxForUser($this->uid($request), true),
        ], 'Trash retrieved successfully.');
    }

    /** POST /messaging/trash/delete — نقل محادثة/أكتر/الكل للسلة */
    public function delete(Request $request)
    {
        return $this->run($request, 'delete', 'Conversations moved to trash.');
    }

    /** POST /messaging/trash/restore */
    public function restore(Request $request)
    {
        return $this->run($request, 'restore', 'Conversations restored.');
    }

    /** POST /messaging/trash/purge — حذف نهائي (محادثات محدّدة أو all=true لتفريغ السلة) */
    public function purge(Request $request)
    {
        return $this->run($request, 'purge', 'Conversations permanently deleted.');
    }
}
