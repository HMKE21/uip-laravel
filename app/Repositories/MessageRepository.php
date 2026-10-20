<?php

namespace App\Repositories;

use App\Models\Message;
use App\Models\MessageVersion;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/MessageRepository.php القديمة (Core\Database
 * -> DB facade). بيانات `messages` نفسها + كل اللي متعلّق بيها (083):
 * attachments/reactions/mentions/hashtags/versions/read-receipts —
 * batch-loaded (مفيش N+1) في forConversation()/search(). بند 18.
 */
class MessageRepository
{
    /** صفحة رسائل في محادثة، الأقدم أولًا داخل الصفحة — "Lazy Loading" / "Infinite Scroll". */
    public function forConversation(int $conversationId, $viewerId, ?int $beforeId = null, int $limit = 50): array
    {
        $params = [$conversationId, $viewerId];
        $beforeClause = '';
        if ($beforeId !== null) {
            $beforeClause = ' AND m.id < ?';
            $params[] = $beforeId;
        }
        $params[] = $limit;

        $rows = DB::select(
            "SELECT m.*, u.full_name AS sender_name, u.avatar_path AS sender_avatar,
                    p.body AS parent_body, pu.full_name AS parent_sender_name
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN messages p ON p.id = m.parent_message_id
             LEFT JOIN users pu ON pu.id = p.sender_id
             WHERE m.conversation_id = ?
               AND m.id NOT IN (SELECT message_id FROM message_hidden_for_user WHERE user_id = ?)
               {$beforeClause}
             ORDER BY m.id DESC
             LIMIT ?",
            $params
        );
        $rows = array_reverse(array_map(fn ($r) => (array) $r, $rows));

        return $this->attachRelations($rows);
    }

    /** نفس تحميل العلاقات، معروض لوحده عشان sendMessage() تقدر تشكّل صف واحد جديد من غير إعادة استعلام كل حاجة. */
    public function withRelations(int $messageId): ?array
    {
        $row = DB::selectOne(
            'SELECT m.*, u.full_name AS sender_name, u.avatar_path AS sender_avatar,
                    p.body AS parent_body, pu.full_name AS parent_sender_name
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN messages p ON p.id = m.parent_message_id
             LEFT JOIN users pu ON pu.id = p.sender_id
             WHERE m.id = ? LIMIT 1',
            [$messageId]
        );
        if (!$row) {
            return null;
        }
        $shaped = $this->attachRelations([(array) $row]);
        return $shaped[0] ?? null;
    }

    /** بتحمّل attachments/reactions/mentions دفعة واحدة لمجموعة صفوف رسايل جاهزة (مفيش N+1). */
    public function attachRelations(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_column($rows, 'id');

        $attachments = DB::table('message_attachments')->whereIn('message_id', $ids)->orderBy('id')->get();
        $reactions = DB::table('message_reactions as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereIn('r.message_id', $ids)
            ->select('r.message_id', 'r.emoji', 'r.user_id', 'u.full_name')
            ->get();
        $mentions = DB::table('message_mentions')->whereIn('message_id', $ids)->select('message_id', 'mentioned_user_id')->get();

        $attachmentsByMsg = [];
        foreach ($attachments as $a) {
            $attachmentsByMsg[$a->message_id][] = (array) $a;
        }
        $reactionsByMsg = [];
        foreach ($reactions as $r) {
            $reactionsByMsg[$r->message_id][$r->emoji]['emoji'] = $r->emoji;
            $reactionsByMsg[$r->message_id][$r->emoji]['count'] = ($reactionsByMsg[$r->message_id][$r->emoji]['count'] ?? 0) + 1;
            $reactionsByMsg[$r->message_id][$r->emoji]['users'][] = ['id' => $r->user_id, 'full_name' => $r->full_name];
        }
        $mentionsByMsg = [];
        foreach ($mentions as $m) {
            $mentionsByMsg[$m->message_id][] = (int) $m->mentioned_user_id;
        }

        foreach ($rows as &$row) {
            $row['attachments'] = $attachmentsByMsg[$row['id']] ?? [];
            $row['reactions'] = array_values($reactionsByMsg[$row['id']] ?? []);
            $row['mentions'] = $mentionsByMsg[$row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    public function find(int $id): ?Message
    {
        return Message::find($id);
    }

    public function create(
        int $conversationId,
        $senderId,
        string $body,
        ?string $attachmentPath = null,
        ?int $parentMessageId = null,
        ?int $forwardedFromId = null,
        string $messageType = 'text',
        ?array $metadata = null
    ): Message {
        return Message::create([
            'conversation_id'   => $conversationId,
            'sender_id'         => $senderId,
            'body'              => $body,
            'attachment_path'   => $attachmentPath,
            'parent_message_id' => $parentMessageId,
            'forwarded_from_id' => $forwardedFromId,
            'message_type'      => $messageType,
            'metadata'          => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'created_at'        => now(),
        ]);
    }

    /** بتلقط نسخة من الجسم الحالي في message_versions، وبعدين تكتب فوقه — "Edit Message" + تاريخ التعديلات. */
    public function edit(int $messageId, string $newBody): void
    {
        $current = DB::table('messages')->where('id', $messageId)->value('body');
        if ($current === null) {
            return;
        }
        MessageVersion::create([
            'message_id' => $messageId,
            'body'       => $current,
            'edited_at'  => now(),
        ]);
        Message::where('id', $messageId)->update([
            'body' => $newBody, 'is_edited' => 1, 'edited_at' => now(),
        ]);
    }

    public function history(int $messageId): array
    {
        return MessageVersion::where('message_id', $messageId)->orderByDesc('edited_at')->get()->all();
    }

    /** "Delete For Everyone" — soft delete، الجسم بيتمسح من السيرفر (مش مجرد إخفاء على العميل). */
    public function deleteForEveryone(int $messageId, $deletedBy): void
    {
        Message::where('id', $messageId)->update([
            'is_deleted' => 1, 'deleted_at' => now(), 'deleted_by' => $deletedBy, 'body' => '',
        ]);
    }

    /** "Restore Deleted Message" — بيرجع is_deleted لصفر (الجسم اتمسح فعليًا وقت الحذف فمش بيرجع). */
    public function restore(int $messageId): void
    {
        Message::where('id', $messageId)->update(['is_deleted' => 0, 'deleted_at' => null, 'deleted_by' => null]);
    }

    /** "Undo Send" — حذف فعلي (hard delete) للصف، من غير tombstone. */
    public function hardDelete(int $messageId): void
    {
        Message::where('id', $messageId)->delete();
    }

    /**
     * "Cancel" (Undo Send) — بنمسح المحتوى والمرفقات فعليًا، بس بنسيب الصف بعلامة deleted_reason='unsent'
     * عشان الـ poll يقدر يبلّغ الطرف التاني إن الرسالة اتلغت (الصف المحذوف نهائيًا مفيش طريقة نوصّل بيها الخبر).
     * الواجهة بتخفي الرسائل اللي deleted_reason='unsent' بالكامل (من غير tombstone).
     */
    public function unsend(int $messageId, $by): void
    {
        DB::table('message_attachments')->where('message_id', $messageId)->delete();
        Message::where('id', $messageId)->update([
            'is_deleted' => 1, 'deleted_at' => now(), 'deleted_by' => $by, 'deleted_reason' => 'unsent',
            'body' => '', 'metadata' => null, 'is_pinned' => 0,
        ]);
    }

    /** الرسايل اللي فيها mention لليوزر ده — فلتر "mentions of me". */
    public function mentionsOf($userId, int $limit = 50): array
    {
        $rows = DB::table('messages as m')
            ->join('users as u', 'u.id', '=', 'm.sender_id')
            ->join('message_mentions as mm', 'mm.message_id', '=', 'm.id')
            ->where('mm.mentioned_user_id', $userId)
            ->where('m.is_deleted', 0)
            ->select('m.*', 'u.full_name as sender_name')
            ->orderByDesc('m.created_at')
            ->limit($limit)
            ->get();
        return $this->attachRelations(array_map(fn ($r) => (array) $r, $rows->all()));
    }

    /** "Delete For Me" — إخفاء لكل مستخدم لوحده، من غير ما يلمس الصف الحقيقي. */
    public function hideForUser(int $messageId, $userId): void
    {
        DB::table('message_hidden_for_user')->insertOrIgnore([
            'message_id' => $messageId, 'user_id' => $userId, 'hidden_at' => now(),
        ]);
    }

    public function setPinned(int $messageId, bool $pinned): void
    {
        Message::where('id', $messageId)->update(['is_pinned' => $pinned ? 1 : 0]);
    }

    public function pinnedForConversation(int $conversationId): array
    {
        $rows = DB::select(
            'SELECT m.*, u.full_name AS sender_name
             FROM messages m INNER JOIN users u ON u.id = m.sender_id
             WHERE m.conversation_id = ? AND m.is_pinned = 1 AND m.is_deleted = 0
             ORDER BY m.created_at DESC',
            [$conversationId]
        );
        return $this->attachRelations(array_map(fn ($r) => (array) $r, $rows));
    }

    /** بحث نصّي (LIKE) داخل محادثات المستخدم، مع دعم #هاشتاج. */
    public function search($viewerId, string $query, ?int $conversationId = null, int $limit = 50): array
    {
        $isHashtag = str_starts_with(trim($query), '#');
        $needle = ltrim(trim($query), '#');
        if ($needle === '') {
            return [];
        }

        if ($isHashtag) {
            $q = DB::table('messages as m')
                ->join('users as u', 'u.id', '=', 'm.sender_id')
                ->join('message_hashtags as h', function ($j) use ($needle) {
                    $j->on('h.message_id', '=', 'm.id')->where('h.tag', 'like', '%' . $needle . '%');
                })
                ->join('conversation_participants as cp', function ($j) use ($viewerId) {
                    $j->on('cp.conversation_id', '=', 'm.conversation_id')->where('cp.user_id', $viewerId);
                })
                ->where('m.is_deleted', 0)
                ->select('m.*', 'u.full_name as sender_name')
                ->distinct();
        } else {
            $q = DB::table('messages as m')
                ->join('users as u', 'u.id', '=', 'm.sender_id')
                ->join('conversation_participants as cp', function ($j) use ($viewerId) {
                    $j->on('cp.conversation_id', '=', 'm.conversation_id')->where('cp.user_id', $viewerId);
                })
                ->where('m.is_deleted', 0)
                ->where('m.body', 'like', '%' . $needle . '%')
                ->select('m.*', 'u.full_name as sender_name');
        }

        if ($conversationId !== null) {
            $q->where('m.conversation_id', $conversationId);
        }

        $rows = $q->orderByDesc('m.created_at')->limit($limit)->get();
        return $this->attachRelations(array_map(fn ($r) => (array) $r, $rows->all()));
    }

    public function recordMention(int $messageId, $mentionedUserId): void
    {
        DB::table('message_mentions')->insertOrIgnore([
            'message_id' => $messageId, 'mentioned_user_id' => $mentionedUserId, 'created_at' => now(),
        ]);
    }

    /** بتستخرج #tags من الجسم وتخزّنهم — بتتنادى مرة واحدة وقت الإرسال. */
    public function recordHashtags(int $messageId, string $body): void
    {
        if (!preg_match_all('/#([\p{L}\p{N}_]{2,100})/u', $body, $matches)) {
            return;
        }
        foreach (array_unique($matches[1]) as $tag) {
            DB::table('message_hashtags')->insert(['message_id' => $messageId, 'tag' => mb_strtolower($tag)]);
        }
    }

    /** عدد الرسايل اللي بعتها اليوزر ده خلال النافذة الزمنية — Rate Limiting / Spam Protection. */
    public function countSentSince($userId, string $sinceDatetime): int
    {
        return DB::table('messages')->where('sender_id', $userId)->where('created_at', '>=', $sinceDatetime)->count();
    }

    // -- Admin Messaging Oversight (بند 25 batch 5) ----------------------

    /**
     * كل رسالة في محادثة، بما فيها اللي أي مشارك مخفيها لنفسه ("delete
     * for me" مبتأثرش على شاشة الإشراف قراءة-فقط). الرسايل المحذوفة
     * فضلة (الجسم اتمسح خلاص جوّه shapeMessage) عشان الأدمن يشوف
     * "deleted" tombstone في سياقها بدل فجوة في الثريد.
     */
    public function forConversationAdmin(int $conversationId, int $limit = 500): array
    {
        $rows = DB::select(
            'SELECT m.*, u.full_name AS sender_name, u.avatar_path AS sender_avatar,
                    p.body AS parent_body, pu.full_name AS parent_sender_name
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN messages p ON p.id = m.parent_message_id
             LEFT JOIN users pu ON pu.id = p.sender_id
             WHERE m.conversation_id = ?
             ORDER BY m.id ASC
             LIMIT ?',
            [$conversationId, $limit]
        );
        return $this->attachRelations(array_map(fn ($r) => (array) $r, $rows));
    }

    /**
     * Cron نظير NotificationRepository::autoDeleteOlderThan() — بيحذف
     * (hard delete) الرسايل الأقدم من $days يوم. كل الجداول الفرعية
     * (attachments/reactions/mentions/hashtags/versions/read-receipts)
     * مربوطة بـ FK ON DELETE CASCADE، فمفيش صفوف يتيمة.
     */
    public function purgeOlderThan(int $days): int
    {
        return DB::table('messages')->where('created_at', '<', now()->subDays($days))->delete();
    }
}
