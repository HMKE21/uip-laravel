<?php

namespace App\Repositories;

use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * منقولة كاملة الآن من app/Repositories/NotificationRepository.php القديمة
 * (408 سطر) — بند 19 (Notifications) الكامل، فوق forUser()/unreadCount()
 * اللي كانوا موجودين من بند 4 (لسه هما زي ما هما، مفيش كسر لأي مستهلك حالي).
 *
 * حالة كل إشعار: unread/read (is_read) مستقلة عن pinned/important، ومستقلة
 * عن active/archived/deleted (is_archived/is_deleted — soft delete، نفس شكل
 * messaging's delete-for-me عشان صف "deleted" يفضل موجود لحساب الـ retention
 * بدل ما يختفي). كل query هنا بتستبعد الصفوف المحذوفة (soft) إلا لو مطلوب
 * صراحة (تبويب Trash / retention sweep).
 */
class NotificationRepository
{
    private const PORTALS = ['admin', 'student', 'university', 'security', 'data-analysis', 'supervisor', 'academic-staff', 'faculty', 'company', 'investor', 'researcher', 'designer'];

    /** @var array<string,?array> per-request memo: "uid:role" => portals the viewer may see (null = no restriction) */
    private array $portalMemo = [];

    /** Which portal a notification belongs to, from its link (/student/...) or type (system_*). null = unknown. */
    public static function portalFor(string $type, ?string $linkUrl): ?string
    {
        $path = trim((string) $linkUrl);
        if ($path !== '' && preg_match('#^https?://[^/]+(/.*)$#i', $path, $m)) {
            $path = $m[1];
        }
        if ($path !== '' && $path[0] === '/' && preg_match('#^/([a-z-]+)(?:[/?\#]|$)#', $path, $m) && in_array($m[1], self::PORTALS, true)) {
            return $m[1];
        }
        return str_starts_with($type, 'system_') ? 'admin' : null;
    }

    /** Portals whose notifications a user acting as $role may see. Admin also works inside the security / data-analysis / academic-staff portals. */
    public static function portalsForRole(string $role): array
    {
        return match ($role) {
            'admin'                              => ['admin', 'security', 'data-analysis', 'academic-staff'],
            'security_admin', 'security_officer' => ['security'],
            'data_analyst'                       => ['data-analysis'],
            'academic_staff'                     => ['academic-staff'],
            default                              => [str_replace('_', '-', $role)],
        };
    }

    /**
     * Joker accounts hold every role on ONE user_id: only the notifications of the role they are currently acting as
     * (plus unclassified ones) are visible. Everyone else is unaffected. Applies only when the owner is the one asking.
     */
    private function visiblePortals($userId): ?array
    {
        if (!app()->bound('request')) {
            return null;
        }
        $req = request();
        $role = (string) $req->attributes->get('uip_role', '');
        $uid = (int) $req->attributes->get('uip_user_id', 0);
        if ($role === '' || $uid === 0 || $uid !== (int) $userId) {
            return null;
        }
        $key = $uid . ':' . $role;
        if (!array_key_exists($key, $this->portalMemo)) {
            $this->portalMemo[$key] = app(\App\Services\RoleService::class)->isJoker($uid) ? self::portalsForRole($role) : null;
        }
        return $this->portalMemo[$key];
    }

    /** Base query: this user's notifications, narrowed to the active role's portal for joker accounts. */
    private function base($userId)
    {
        $q = Notification::where('user_id', $userId);
        $portals = $this->visiblePortals($userId);
        if ($portals !== null) {
            $q->where(fn ($w) => $w->whereNull('portal')->orWhereIn('portal', $portals));
        }
        return $q;
    }

    /** @return Notification[] newest first, active (مش archived/deleted) بس */
    public function forUser($userId, int $limit = 50): array
    {
        return $this->base($userId)
            ->where('is_archived', 0)
            ->where('is_deleted', 0)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function unreadCount($userId): int
    {
        return $this->base($userId)
            ->where('is_read', 0)
            ->where('is_archived', 0)
            ->where('is_deleted', 0)
            ->count();
    }

    /**
     * عدادات كل status/flag bucket — لتابات ومؤشرات الـ Notification Center،
     * رحلة واحدة بدل خمسة.
     * @return array{unread:int,read:int,pinned:int,important:int,archived:int,deleted:int,total:int}
     */
    public function counts($userId): array
    {
        $portals = $this->visiblePortals($userId);
        $portalSql = $portals !== null ? ' AND (portal IS NULL OR portal IN (' . implode(',', array_fill(0, count($portals), '?')) . '))' : '';
        $portalArgs = $portals ?? [];
        $row = DB::selectOne(
            'SELECT
                SUM(CASE WHEN is_deleted = 0 AND is_archived = 0 AND is_read = 0 THEN 1 ELSE 0 END) AS unread,
                SUM(CASE WHEN is_deleted = 0 AND is_archived = 0 AND is_read = 1 THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN is_deleted = 0 AND is_pinned = 1 THEN 1 ELSE 0 END) AS pinned,
                SUM(CASE WHEN is_deleted = 0 AND is_important = 1 THEN 1 ELSE 0 END) AS important,
                SUM(CASE WHEN is_deleted = 0 AND is_archived = 1 THEN 1 ELSE 0 END) AS archived,
                SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) AS deleted,
                SUM(CASE WHEN is_deleted = 0 AND is_archived = 0 THEN 1 ELSE 0 END) AS total
             FROM notifications WHERE user_id = ?' . $portalSql,
            array_merge([$userId], $portalArgs)
        );

        $zeroed = ['unread' => 0, 'read' => 0, 'pinned' => 0, 'important' => 0, 'archived' => 0, 'deleted' => 0, 'total' => 0];
        if (!$row) {
            return $zeroed;
        }
        $row = (array) $row;
        foreach ($zeroed as $key => $_) {
            $zeroed[$key] = (int) ($row[$key] ?? 0);
        }
        return $zeroed;
    }

    public function findOwned($id, $userId): ?Notification
    {
        $n = Notification::find($id);
        if ($n && (string) $n->user_id === (string) $userId) {
            return $n;
        }
        return null;
    }

    // -- Notification Center: search / filter / sort / paginate ----------

    /**
     * @param array<string,mixed> $filters المفاتيح المدعومة:
     *   status    unread|read|pinned|important|archived|deleted (افتراضي: active, unread+read)
     *   type      نوع الإشعار بالظبط
     *   category  الكاتيجوري بالظبط
     *   priority  low|normal|high|urgent
     *   search    مطابقة على title/body (LIKE)
     *   date_from / date_to  'Y-m-d'
     *   sort      'newest' (افتراضي) | 'oldest' | 'priority'
     *   page      1-based (افتراضي 1)
     *   per_page  افتراضي 20، أقصى 100
     * @return array{items:Notification[],total:int,page:int,per_page:int}
     */
    public function search($userId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        $query = $this->applyFilters($this->base($userId), $filters);

        $total = (clone $query)->count();

        $sort = $filters['sort'] ?? 'newest';
        if ($sort === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } elseif ($sort === 'priority') {
            $query->orderByRaw("FIELD(priority,'urgent','high','normal','low') ASC")->orderBy('created_at', 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $items = $query->forPage($page, $perPage)->get()->all();

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    private function applyFilters($query, array $filters)
    {
        switch ($filters['status'] ?? null) {
            case 'unread':
                $query->where('is_deleted', 0)->where('is_archived', 0)->where('is_read', 0);
                break;
            case 'read':
                $query->where('is_deleted', 0)->where('is_archived', 0)->where('is_read', 1);
                break;
            case 'pinned':
                $query->where('is_deleted', 0)->where('is_pinned', 1);
                break;
            case 'important':
                $query->where('is_deleted', 0)->where('is_important', 1);
                break;
            case 'archived':
                $query->where('is_deleted', 0)->where('is_archived', 1);
                break;
            case 'deleted':
                $query->where('is_deleted', 1);
                break;
            default:
                // "Active" الافتراضي: مش archived، مش deleted (unread + read مع بعض).
                $query->where('is_deleted', 0)->where('is_archived', 0);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $needle)->orWhere('body', 'like', $needle));
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query;
    }

    // -- Single-item state transitions ------------------------------------

    public function markRead($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_read' => 1, 'read_at' => now()]);
    }

    public function markUnread($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_read' => 0, 'read_at' => null]);
    }

    public function markConversationNotificationsRead($userId, int $conversationId): int
    {
        return $this->base($userId)
            ->where('type', 'new_message')
            ->where('is_read', 0)
            ->where('link_url', 'like', '%/messages/' . $conversationId)
            ->update(['is_read' => 1, 'read_at' => now()]);
    }

    public function markAllRead($userId): void
    {
        $this->base($userId)
            ->where('is_read', 0)->where('is_archived', 0)->where('is_deleted', 0)
            ->update(['is_read' => 1, 'read_at' => now()]);
    }

    public function pin($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_pinned' => 1]);
    }

    public function unpin($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_pinned' => 0]);
    }

    public function markImportant($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_important' => 1]);
    }

    public function unmarkImportant($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_important' => 0]);
    }

    public function archive($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_archived' => 1, 'archived_at' => now()]);
    }

    public function unarchive($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_archived' => 0, 'archived_at' => null]);
    }

    /** Soft delete (تروح Trash / status "Deleted") — قابلة للرجوع عبر restore(). */
    public function delete($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_deleted' => 1, 'deleted_at' => now()]);
    }

    public function restore($id, $userId): bool
    {
        return $this->update($id, $userId, ['is_deleted' => 0, 'deleted_at' => null]);
    }

    /** حذف نهائي — تستخدمها retention sweep و"Empty Trash". */
    public function purge($id, $userId): bool
    {
        $n = $this->findOwned($id, $userId);
        if (!$n) {
            return false;
        }
        return (bool) $n->delete();
    }

    public function deleteAllRead($userId): void
    {
        // Soft delete برضو، اتساقًا مع delete() المفردة فوق.
        $this->base($userId)
            ->where('is_read', 1)->where('is_archived', 0)->where('is_deleted', 0)
            ->update(['is_deleted' => 1, 'deleted_at' => now()]);
    }

    private function update($id, $userId, array $data): bool
    {
        $n = $this->findOwned($id, $userId);
        if (!$n) {
            return false;
        }
        $n->fill($data);
        return $n->save();
    }

    // -- Bulk actions (toolbar "select all" في الـ Notification Center) ---

    /**
     * @param array<int,int|string> $ids
     * @param string $action markRead|markUnread|pin|unpin|important|unimportant|archive|unarchive|delete|restore
     * @return int عدد الصفوف اللي فعلاً مملوكة لـ $userId واتأثرت
     */
    public function bulkAction(array $ids, $userId, string $action): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }

        $columns = match ($action) {
            'markRead'    => ['is_read' => 1, 'read_at' => now()],
            'markUnread'  => ['is_read' => 0, 'read_at' => null],
            'pin'         => ['is_pinned' => 1],
            'unpin'       => ['is_pinned' => 0],
            'important'   => ['is_important' => 1],
            'unimportant' => ['is_important' => 0],
            'archive'     => ['is_archived' => 1, 'archived_at' => now()],
            'unarchive'   => ['is_archived' => 0, 'archived_at' => null],
            'delete'      => ['is_deleted' => 1, 'deleted_at' => now()],
            'restore'     => ['is_deleted' => 0, 'deleted_at' => null],
            default       => null,
        };
        if ($columns === null) {
            return 0;
        }

        return $this->base($userId)->whereIn('id', $ids)->update($columns);
    }

    // -- Retention (auto-archive / auto-delete بعد X يوم) ------------------

    /** إشعارات مقروءة أقدم من $days يوم ولسه مش archived/deleted. */
    public function autoArchiveOlderThan(int $days): int
    {
        return DB::update(
            'UPDATE notifications
             SET is_archived = 1, archived_at = NOW()
             WHERE is_read = 1 AND is_archived = 0 AND is_deleted = 0
               AND read_at IS NOT NULL AND read_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
    }

    /** إشعارات مؤرشفة أقدم من $days يوم: حذف نهائي. */
    public function autoDeleteOlderThan(int $days): int
    {
        return DB::delete(
            'DELETE FROM notifications
             WHERE is_archived = 1
               AND archived_at IS NOT NULL AND archived_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
    }

    public function create(array $data): Notification
    {
        return Notification::create($data);
    }

    // -- Dedup / flooding control ------------------------------------------

    /**
     * نفس الحدث بيتكرر كذا مرة في نافذة زمنية قصيرة (مثلاً خمس إشعارات
     * "X علّق" خلال 30 ثانية) كان بيعمل خمس صفوف منفصلة — وخمس إيميلات.
     * بتدوّر على إشعار UNREAD نشط (مش archived/deleted) لنفس المستخدم+النوع+
     * الرابط خلال $withinSeconds، عشان notify() تقدر تجمّع التكرار جواه بدل
     * ما تعمل صف جديد. الصفوف المقروءة/المؤرشفة/المحذوفة مستبعدة عمدًا: لو
     * المستخدم شافه أو رحّله، التكرار بقى "جديد" تاني.
     */
    public function findRecentDuplicate($userId, string $type, ?string $linkUrl, int $withinSeconds): ?Notification
    {
        $query = Notification::where('user_id', $userId)
            ->where('type', $type)
            ->where('is_read', 0)->where('is_archived', 0)->where('is_deleted', 0)
            ->where('created_at', '>=', now()->subSeconds($withinSeconds));

        if ($linkUrl === null) {
            $query->whereNull('link_url');
        } else {
            $query->where('link_url', $linkUrl);
        }

        return $query->orderByDesc('created_at')->first();
    }

    /**
     * بتجمّع حدث متكرر جوه إشعار موجود: بتزوّد occurrence_count، بتحدّث
     * العنوان/الجسم/الوقت عشان يبان إنه آخر تكرار، وبترجّعه unread تاني في
     * أول الفيد (مستخدم قفل/قرا الإشعار الأول لازم يشوف النشاط اللي بعده).
     */
    public function bumpOccurrence(Notification $existing, string $title, ?string $body): bool
    {
        $existing->fill([
            'occurrence_count' => (int) ($existing->occurrence_count ?: 1) + 1,
            'title'            => $title,
            'body'             => $body,
            'is_read'          => 0,
            'read_at'          => null,
            'created_at'       => now(),
        ]);
        return $existing->save();
    }
}
