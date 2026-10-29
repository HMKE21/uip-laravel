<?php

namespace App\Services;

use App\Repositories\ConversationRepository;
use Illuminate\Support\Facades\DB;

/**
 * سلة محذوفات المحادثات — لكل مستخدم على حدة (conversation_participants.deleted_at).
 *
 *  - delete   : المحادثة بتختفي من قايمة المستخدم بس وتروح للسلة؛ باقي الأعضاء مش بيتأثروا.
 *  - restore  : بترجع للقايمة بكل رسايلها.
 *  - purge    : حذف نهائي من السلة (محادثة مباشرة: بتتشال من عند المستخدم؛ جروب: بيخرج منه
 *               بنفس منطق GroupChatService::leave فملكية الجروب بتتنقل). لو مفيش أعضاء باقيين المحادثة بتتمسح.
 *  - purgeExpired : الأمر المجدول messaging:purge-trash — بيحذف نهائيًا اللي عدّى عليه RETENTION_DAYS يوم.
 *
 * رسالة جديدة في محادثة محذوفة بترجّعها تلقائيًا (راجع MessagingService::send).
 */
class ConversationTrashService
{
    public const RETENTION_DAYS = 30;
    public const MAX_BATCH = 200;

    public function __construct(
        private ConversationRepository $conversations,
        private GroupChatService $groups
    ) {
    }

    public static function daysLeft(string $deletedAt): int
    {
        $expires = strtotime($deletedAt) + self::RETENTION_DAYS * 86400;
        return max(0, (int) ceil(($expires - time()) / 86400));
    }

    /** @param array<int,mixed> $ids @return int[] ids المحادثات اللي المستخدم عضو فيها فعلًا (من غير تكرار). */
    private function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Too many conversations selected (max ' . self::MAX_BATCH . ').');
        }
        return $ids;
    }

    /** ids الفعلية: لو $all = true كل محادثات المستخدم (في الحالة المطلوبة)، وإلا المحدّدة بس. */
    private function targetIds(int $userId, array $ids, bool $all, bool $inTrash): array
    {
        $q = DB::table('conversation_participants')->where('user_id', $userId);
        $inTrash ? $q->whereNotNull('deleted_at') : $q->whereNull('deleted_at');
        if (!$all) {
            $ids = $this->normalize($ids);
            if (!$ids) {
                throw new \InvalidArgumentException('No conversations selected.');
            }
            $q->whereIn('conversation_id', $ids);
        }
        return $q->pluck('conversation_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return int عدد المحادثات اللي اتنقلت للسلة */
    public function delete(int $userId, array $ids, bool $all = false): int
    {
        $targets = $this->targetIds($userId, $ids, $all, false);
        // "الرسائل المحفوظة" ثابتة: ما بتتحذفش ولا بتروح للسلة.
        if ($targets) {
            $saved = DB::table('conversations')->whereIn('id', $targets)->whereNotNull('saved_for_user_id')->pluck('id')->map(fn ($i) => (int) $i)->all();
            $targets = array_values(array_diff($targets, $saved));
        }
        if (!$targets) {
            return 0;
        }
        return DB::table('conversation_participants')
            ->where('user_id', $userId)->whereIn('conversation_id', $targets)
            ->update(['deleted_at' => now(), 'is_pinned' => 0]);
    }

    public function restore(int $userId, array $ids, bool $all = false): int
    {
        $targets = $this->targetIds($userId, $ids, $all, true);
        if (!$targets) {
            return 0;
        }
        return DB::table('conversation_participants')
            ->where('user_id', $userId)->whereIn('conversation_id', $targets)
            ->update(['deleted_at' => null]);
    }

    /** حذف نهائي لمحادثات في السلة. */
    public function purge(int $userId, array $ids, bool $all = false): int
    {
        $targets = $this->targetIds($userId, $ids, $all, true);
        foreach ($targets as $conversationId) {
            $this->purgeOne($conversationId, $userId);
        }
        return count($targets);
    }

    /** الأمر المجدول: كل اللي عدّى عليه RETENTION_DAYS يوم. */
    public function purgeExpired(): int
    {
        $rows = DB::table('conversation_participants')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', now()->subDays(self::RETENTION_DAYS))
            ->limit(5000)
            ->get(['conversation_id', 'user_id']);
        $n = 0;
        foreach ($rows as $r) {
            try {
                $this->purgeOne((int) $r->conversation_id, (int) $r->user_id);
                $n++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return $n;
    }

    private function purgeOne(int $conversationId, int $userId): void
    {
        DB::transaction(function () use ($conversationId, $userId) {
            $conv = DB::table('conversations')->where('id', $conversationId)->first(['id', 'is_group']);
            if (!$conv) {
                return;
            }
            if ($conv->is_group) {
                // نفس منطق "مغادرة الجروب": نقل الملكية + رسالة نظام + مسح الجروب لو آخر عضو.
                $this->groups->leave($conversationId, $userId);
                return;
            }
            $this->conversations->removeMember($conversationId, $userId);
            if ($this->conversations->memberCount($conversationId) === 0) {
                DB::table('conversations')->where('id', $conversationId)->delete();
            }
        });
    }
}
