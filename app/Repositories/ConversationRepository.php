<?php

namespace App\Repositories;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ConversationRepository.php القديمة (Core\Database
 * -> DB facade). بيانات `conversations` + `conversation_participants` —
 * عضوية الـ threads، قايمة الـ inbox، وحالة القراءة لكل مشارك.
 * بند 18 (Messaging).
 */
class ConversationRepository
{
    /** @return array<int,array<string,mixed>> صفوف inbox لمستخدم معين، بكل حالة العضو (favorite/pinned/muted/archived/category). */
    /** $trashed=true بيرجّع سلة المحذوفات (deleted_at مش null) بدل الـ inbox العادي. */
    public function inboxForUser($userId, bool $trashed = false): array
    {
        $rows = DB::select(
            "SELECT c.id, c.subject, c.related_project_id, c.updated_at,
                    c.is_group, c.group_name, c.avatar_path AS group_avatar, c.saved_for_user_id,
                    cp.last_read_at, cp.role AS my_role,
                    cp.is_favorite, cp.is_pinned, cp.is_muted, cp.is_archived, cp.category, cp.deleted_at,
                    m.body AS last_message_body, m.created_at AS last_message_at, m.sender_id AS last_message_sender_id,
                    m.is_deleted AS last_message_deleted,
                    m.message_type AS last_message_type, m.metadata AS last_message_meta,
                    (SELECT ma.kind FROM message_attachments ma WHERE ma.message_id = m.id ORDER BY ma.id ASC LIMIT 1) AS last_message_attach_kind,
                    (SELECT COUNT(*) FROM message_attachments ma2 WHERE ma2.message_id = m.id) AS last_message_attach_count
             FROM conversations c
             INNER JOIN conversation_participants cp ON cp.conversation_id = c.id AND cp.user_id = ?
             LEFT JOIN messages m ON m.id = (
                 SELECT id FROM messages
                 WHERE conversation_id = c.id
                   AND id NOT IN (SELECT message_id FROM message_hidden_for_user WHERE user_id = ?)
                 ORDER BY created_at DESC LIMIT 1
             )
             WHERE cp.deleted_at " . ($trashed ? 'IS NOT NULL' : 'IS NULL') . "
             ORDER BY " . ($trashed ? 'cp.deleted_at DESC' : 'cp.is_pinned DESC, COALESCE(m.created_at, c.updated_at) DESC'),
            [$userId, $userId]
        );

        $rows = array_map(fn ($r) => (array) $r, $rows);

        foreach ($rows as &$row) {
            $row['other_participants'] = $this->otherParticipants((int) $row['id'], $userId);
            $row['is_unread'] = $row['last_message_at']
                && (string) $row['last_message_sender_id'] !== (string) $userId
                && (!$row['last_read_at'] || strtotime($row['last_message_at']) > strtotime($row['last_read_at']));
        }
        unset($row);

        return $rows;
    }

    /** @return array<int,array{id:int,full_name:string,email:string,avatar_path:?string,account_role:?string,org:?string}> */
    public function otherParticipants(int $conversationId, $excludingUserId): array
    {
        $rows = DB::select(
            'SELECT u.id, u.full_name, u.email, u.avatar_path, u.deleted_at,
                    (SELECT r.slug FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id ORDER BY ur.assigned_at ASC LIMIT 1) AS account_role,
                    COALESCE(uni.official_name_en, s.faculty) AS org
             FROM conversation_participants cp
             INNER JOIN users u ON u.id = cp.user_id
             LEFT JOIN universities uni ON uni.user_id = u.id
             LEFT JOIN students s ON s.user_id = u.id
             WHERE cp.conversation_id = ? AND cp.user_id != ?',
            [$conversationId, $excludingUserId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * محادثة "الرسائل المحفوظة" الخاصة بالمستخدم — بتتعمل أول مرة وتتثبّت في أول القايمة.
     * مشارك واحد بس (صاحبها)، فمفيش حد تاني يقدر يشوفها أو يضيف نفسه (isParticipant هو اللي بيحمي كل الـ endpoints).
     */
    public function savedConversationFor(int $userId): Conversation
    {
        $conversation = Conversation::where('saved_for_user_id', $userId)->first();
        if (!$conversation) {
            try {
                $conversation = DB::transaction(function () use ($userId) {
                    $c = Conversation::create(['saved_for_user_id' => $userId]);
                    DB::table('conversation_participants')->insert([
                        'conversation_id' => $c->id,
                        'user_id'         => $userId,
                        'joined_at'       => now(),
                        'role'            => 'owner',
                        'is_pinned'       => 1,
                    ]);
                    return $c;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // طلبين متزامنين: التاني كسب السباق (unique) — نقرأ اللي اتعمل.
                $conversation = Conversation::where('saved_for_user_id', $userId)->first();
                if (!$conversation) {
                    throw $e;
                }
            }
        }
        // لو المشاركة اتشالت أو المحادثة اتنقلت للسلة بأي طريقة، نرجّعها.
        $exists = DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $userId)->exists();
        if (!$exists) {
            DB::table('conversation_participants')->insert([
                'conversation_id' => $conversation->id, 'user_id' => $userId, 'joined_at' => now(), 'role' => 'owner', 'is_pinned' => 1,
            ]);
        } else {
            DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $userId)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
        }
        return $conversation;
    }

    public function isParticipant(int $conversationId, $userId): bool
    {
        return DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function find(int $id): ?Conversation
    {
        return Conversation::find($id);
    }

    /** ثريد 1:1 موجود بالفعل بين المستخدمين دول بالظبط — لو موجود، منعرض إنشاء تاني. */
    public function findDirectBetween($userIdA, $userIdB): ?Conversation
    {
        $row = DB::selectOne(
            "SELECT c.* FROM conversations c
             WHERE c.is_group = 0
               AND (SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = c.id) = 2
               AND EXISTS (SELECT 1 FROM conversation_participants WHERE conversation_id = c.id AND user_id = ?)
               AND EXISTS (SELECT 1 FROM conversation_participants WHERE conversation_id = c.id AND user_id = ?)
             LIMIT 1",
            [$userIdA, $userIdB]
        );
        return $row ? Conversation::find($row->id) : null;
    }

    public function createWithParticipants(?string $subject, array $userIds, ?int $relatedProjectId = null): Conversation
    {
        return DB::transaction(function () use ($subject, $userIds, $relatedProjectId) {
            $conversation = Conversation::create([
                'subject'            => $subject,
                'related_project_id' => $relatedProjectId,
            ]);

            foreach (array_unique($userIds) as $uid) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversation->id,
                    'user_id'         => $uid,
                    'joined_at'       => now(),
                ]);
            }

            return $conversation;
        });
    }

    public function touchUpdatedAt(int $conversationId): void
    {
        Conversation::where('id', $conversationId)->update(['updated_at' => now()]);
    }

    public function markRead(int $conversationId, $userId): void
    {
        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update(['last_read_at' => now()]);
    }

    // -- إنشاء الجروبات + العضوية + حالة كل عضو (favorite/pin/mute/archive/category/role) --

    public function createGroup(string $groupName, $creatorId, array $memberIds, ?int $studentGroupId = null): Conversation
    {
        return DB::transaction(function () use ($groupName, $creatorId, $memberIds, $studentGroupId) {
            $conversation = Conversation::create([
                'is_group'         => true,
                'group_name'       => $groupName,
                'student_group_id' => $studentGroupId,
            ]);

            $ids = array_unique(array_merge([$creatorId], $memberIds));
            foreach ($ids as $uid) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversation->id,
                    'user_id'         => $uid,
                    'role'            => (string) $uid === (string) $creatorId ? 'owner' : 'member',
                    'joined_at'       => now(),
                ]);
            }

            return $conversation;
        });
    }

    public function addMember(int $conversationId, $userId, string $role = 'member'): void
    {
        $exists = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('conversation_participants')->insert([
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'role'            => $role,
            'joined_at'       => now(),
        ]);
    }

    public function removeMember(int $conversationId, $userId): void
    {
        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->delete();
    }

    public function memberRole(int $conversationId, $userId): ?string
    {
        return DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->value('role');
    }

    public function setMemberRole(int $conversationId, $userId, string $role): void
    {
        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update(['role' => $role]);
    }

    public function renameGroup(int $conversationId, string $groupName): void
    {
        Conversation::where('id', $conversationId)->update([
            'group_name' => $groupName,
            'updated_at' => now(),
        ]);
    }

    private const MEMBER_FLAGS = ['is_favorite', 'is_pinned', 'is_muted', 'is_archived'];

    /** الواجهة بتبعت favorite/pin/mute/archive (من غير is_) — بنطبّعها لأسماء الأعمدة الفعلية. */
    private const FLAG_ALIASES = [
        'favorite' => 'is_favorite', 'favourite' => 'is_favorite', 'favorited' => 'is_favorite',
        'pin' => 'is_pinned', 'pinned' => 'is_pinned',
        'mute' => 'is_muted', 'muted' => 'is_muted',
        'archive' => 'is_archived', 'archived' => 'is_archived',
    ];

    /** بتبدّل واحدة من فلاجز العضو البوليانية (favorite/pinned/muted/archived). */
    public function setMemberFlag(int $conversationId, $userId, string $flag, bool $value): void
    {
        $flag = self::FLAG_ALIASES[strtolower($flag)] ?? $flag;
        if (!in_array($flag, self::MEMBER_FLAGS, true)) {
            throw new \InvalidArgumentException("Unknown conversation member flag: {$flag}");
        }
        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update([$flag => $value ? 1 : 0]);
    }

    public function setMemberCategory(int $conversationId, $userId, ?string $category): void
    {
        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update(['category' => $category]);
    }

    /** الكاتيجوريز اللي المستخدم استخدمها لتصنيف أي من محادثاته — لقايمة الفلتر. */
    public function categoriesForUser($userId): array
    {
        return DB::table('conversation_participants')
            ->where('user_id', $userId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->orderBy('category')
            ->distinct()
            ->pluck('category')
            ->all();
    }

    public function isMuted(int $conversationId, $userId): bool
    {
        return (bool) DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->value('is_muted');
    }

    public function memberCount(int $conversationId): int
    {
        return DB::table('conversation_participants')->where('conversation_id', $conversationId)->count();
    }

    /** كل المشاركين (بمن فيهم المستخدم الحالي) — لشريط أعضاء الجروب. */
    public function allParticipants(int $conversationId): array
    {
        $rows = DB::select(
            'SELECT u.id, u.full_name, cp.role FROM conversation_participants cp
             INNER JOIN users u ON u.id = cp.user_id
             WHERE cp.conversation_id = ? ORDER BY cp.joined_at ASC',
            [$conversationId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    // -- Admin Messaging Oversight (بند 25 batch 5) ----------------------
    // قراءات على مستوى المنصة كلها — مختلفة عن كل اللي فوق (اللي دايمًا
    // بيتقيّد بمحادثات مستخدم معين). AdminMessagingOversightApiController
    // بس هو اللي بينادي الميثودز دول.

    /**
     * @param array{search?:string,type?:string,page?:int,per_page?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function adminSearch(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        $clauses = [];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $clauses[] = '(c.subject LIKE ? OR c.group_name LIKE ?
                OR EXISTS (SELECT 1 FROM conversation_participants cp2 INNER JOIN users u2 ON u2.id = cp2.user_id
                           WHERE cp2.conversation_id = c.id AND (u2.full_name LIKE ? OR u2.email LIKE ?)))';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle, $needle);
        }

        $type = (string) ($filters['type'] ?? '');
        if ($type === 'direct') {
            $clauses[] = 'c.is_group = 0';
        } elseif ($type === 'group') {
            $clauses[] = 'c.is_group = 1';
        }

        $where = $clauses ? implode(' AND ', $clauses) : '1=1';

        $total = (int) DB::selectOne("SELECT COUNT(*) AS c FROM conversations c WHERE {$where}", $params)->c;

        $offset = ($page - 1) * $perPage;
        $rows = DB::select(
            "SELECT c.id, c.subject, c.is_group, c.group_name, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = c.id) AS participant_count,
                    (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) AS message_count,
                    (SELECT MAX(created_at) FROM messages WHERE conversation_id = c.id) AS last_message_at
             FROM conversations c
             WHERE {$where}
             ORDER BY COALESCE((SELECT MAX(created_at) FROM messages WHERE conversation_id = c.id), c.updated_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $rows = array_map(fn ($r) => (array) $r, $rows);

        foreach ($rows as &$row) {
            $others = DB::select(
                'SELECT u.full_name FROM conversation_participants cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.conversation_id = ?',
                [$row['id']]
            );
            $row['participant_names'] = implode(', ', array_column(array_map(fn ($r) => (array) $r, $others), 'full_name'));
        }
        unset($row);

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** ثريد كامل لشاشة الإشراف قراءة-فقط — مفيش فحص عضوية، وكل رسالة/مرفق بغض النظر عن حالة الإخفاء لكل مستخدم. */
    public function adminFind(int $id): ?array
    {
        $conversation = $this->find($id);
        if (!$conversation) {
            return null;
        }

        $participants = DB::select(
            'SELECT u.id, u.full_name, u.email FROM conversation_participants cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.conversation_id = ?',
            [$id]
        );

        return [
            'conversation' => [
                'id'         => $conversation->id,
                'subject'    => $conversation->subject,
                'is_group'   => (bool) $conversation->is_group,
                'group_name' => $conversation->group_name,
                'created_at' => $conversation->created_at,
            ],
            'participants' => array_map(fn ($r) => (array) $r, $participants),
        ];
    }

    /** @return array<string,mixed> عدّادات على مستوى المنصة كلها لداشبورد Messaging Analytics */
    public function adminAnalyticsSummary(): array
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(7)->toDateTimeString();

        $topRows = DB::select(
            "SELECT c.id, c.subject, c.is_group, c.group_name,
                    (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) AS message_count
             FROM conversations c
             ORDER BY message_count DESC
             LIMIT 5"
        );

        return [
            'total_conversations'  => (int) DB::table('conversations')->count(),
            'group_conversations'  => (int) DB::table('conversations')->where('is_group', 1)->count(),
            'direct_conversations' => (int) DB::table('conversations')->where('is_group', 0)->count(),
            'total_messages'       => (int) DB::table('messages')->count(),
            'messages_today'       => (int) DB::table('messages')->whereDate('created_at', $today)->count(),
            'messages_this_week'   => (int) DB::table('messages')->where('created_at', '>=', $weekAgo)->count(),
            'active_users_7d'      => (int) DB::table('messages')->where('created_at', '>=', $weekAgo)->distinct('sender_id')->count('sender_id'),
            'total_attachments'    => (int) DB::table('message_attachments')->count(),
            'storage_bytes'        => (int) DB::table('message_attachments')->sum('size_bytes'),
            'top_conversations'    => array_map(fn ($r) => (array) $r, $topRows),
        ];
    }
}
