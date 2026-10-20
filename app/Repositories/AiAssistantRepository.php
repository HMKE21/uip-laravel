<?php

namespace App\Repositories;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAttachment;
use Illuminate\Support\Facades\DB;

/**
 * منقولة (Core\Database -> DB facade / Eloquent) من
 * app/Repositories/AiAssistantRepository.php القديمة. App\Services\
 * AiAssistantService هي المسؤولة عن أي قرار RBAC/بزنس (فحص الملكية،
 * سياق RBAC، الـ streaming) — الكلاس ده SQL بحت بس، نفس التقسيم في كل
 * زوج Repository/Service تاني في المشروع.
 */
class AiAssistantRepository
{
    // -- Conversations -----------------------------------------------------

    /**
     * @param array{q?:string,pinned?:bool,archived?:bool} $filters
     * @return array<int,array<string,mixed>>
     */
    public function conversationsForUser(int $userId, array $filters = []): array
    {
        $query = DB::table('ai_conversations AS c')
            ->select('c.*')
            ->where('c.user_id', $userId)
            ->where('c.is_archived', !empty($filters['archived']) ? 1 : 0);

        if (!empty($filters['pinned'])) {
            $query->where('c.is_pinned', 1);
        }

        if (!empty($filters['q'])) {
            // بحث العنوان أو أي رسالة جوّه المحادثة (FULLTEXT).
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('c.title', 'LIKE', '%' . $q . '%')
                  ->orWhereExists(function ($sub) use ($q) {
                      $sub->select(DB::raw(1))
                          ->from('ai_messages AS m')
                          ->whereColumn('m.conversation_id', 'c.id')
                          ->where('m.is_deleted', 0)
                          ->whereRaw('MATCH(m.content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$q]);
                  });
            });
        }

        return $query
            ->orderByDesc('c.is_pinned')
            ->orderByDesc(DB::raw('COALESCE(c.last_message_at, c.created_at)'))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function findConversation(int $id): ?AiConversation
    {
        return AiConversation::find($id);
    }

    public function createConversation(int $userId, string $portal, string $title = 'New chat'): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $userId,
            'portal'  => $portal,
            'title'   => $title,
        ]);
        return $conversation->fresh() ?? $conversation;
    }

    public function touchConversation(int $conversationId): void
    {
        $count = (int) DB::table('ai_messages')
            ->where('conversation_id', $conversationId)
            ->where('is_deleted', 0)
            ->count();

        DB::table('ai_conversations')->where('id', $conversationId)->update([
            'last_message_at' => now(),
            'message_count'   => $count,
        ]);
    }

    public function deleteConversation(int $id): void
    {
        DB::table('ai_conversations')->where('id', $id)->delete();
    }

    // -- Messages -----------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function messagesForConversation(int $conversationId): array
    {
        return DB::table('ai_messages')
            ->where('conversation_id', $conversationId)
            ->where('is_deleted', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function findMessage(int $id): ?AiMessage
    {
        return AiMessage::find($id);
    }

    public function createMessage(array $data): AiMessage
    {
        $message = AiMessage::create($data);
        return $message->fresh() ?? $message;
    }

    /** بحث FULLTEXT + LIKE احتياطي على كل رسالة يملكها اليوزر. */
    public function searchMessages(int $userId, string $q, int $limit = 30): array
    {
        return DB::table('ai_messages AS m')
            ->join('ai_conversations AS c', 'c.id', '=', 'm.conversation_id')
            ->select('m.id', 'm.conversation_id', 'm.role', 'm.content', 'm.created_at', 'c.title AS conversation_title')
            ->where('c.user_id', $userId)
            ->where('m.is_deleted', 0)
            ->where(function ($w) use ($q) {
                $w->whereRaw('MATCH(m.content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$q])
                  ->orWhere('m.content', 'LIKE', '%' . $q . '%');
            })
            ->orderByDesc('m.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    // -- Attachments ----------------------------------------------------------

    public function createAttachment(array $data): AiMessageAttachment
    {
        return AiMessageAttachment::create($data);
    }

    /** @return array<int,array<string,mixed>> */
    public function attachmentsForMessage(int $messageId): array
    {
        return DB::table('ai_message_attachments')
            ->where('message_id', $messageId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function findAttachment(int $id): ?array
    {
        $row = DB::table('ai_message_attachments')->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    // -- Usage / analytics ----------------------------------------------------

    public function logUsage(array $data): void
    {
        $data['created_at'] = $data['created_at'] ?? now();
        DB::table('ai_usage_logs')->insert($data);
    }

    /** عدد الاستدعاءات في آخر 60 ثانية لليوزر — أساس rate limit لكل يوزر. */
    public function callsInLastMinute(int $userId): int
    {
        return (int) DB::table('ai_usage_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subMinute())
            ->count();
    }

    /** ملخص استخدام على مستوى المنصة — Admin AI Analytics. */
    public function usageSummary(int $days = 30): array
    {
        $since = now()->subDays($days);

        $totals = (array) (DB::table('ai_usage_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total_calls,
                COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,
                COALESCE(SUM(completion_tokens),0) AS completion_tokens,
                COALESCE(SUM(was_error),0) AS error_count,
                COUNT(DISTINCT user_id) AS active_users')
            ->first() ?? []);

        $byPortal = DB::table('ai_usage_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('portal, COUNT(*) AS calls, COALESCE(SUM(prompt_tokens + completion_tokens),0) AS tokens')
            ->groupBy('portal')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return ['totals' => $totals, 'by_portal' => $byPortal];
    }
}
