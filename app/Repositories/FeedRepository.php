<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/FeedRepository.php القديمة حرف بحرف —
 * Core\Database::connection()->fetchOne/fetchAll/query/insert/update
 * استُبدلت بـ DB::selectOne/select/statement/table()->insertGetId()/
 * table()->update() (نفس أسلوب AnnouncementRepository::
 * publishedForUniversity() بالظبط: raw SQL بـ `?` placeholders، والنتائج
 * بترجع array عبر array_map((array) $row))، عشان الـ MATCH...AGAINST
 * والـ EXISTS subqueries المعقدة تفضل زي ما هي بالظبط من غير إعادة كتابة
 * بـ query builder. كل قراءة الطالب بيعملها لسه متقيدة بـ
 * university_id = طالب.university_id على مستوى الـ SQL نفسه، مش فلترة
 * client-side، زي القديم بالظبط.
 */
class FeedRepository
{
    public function create(array $data): int
    {
        return (int) DB::table('feed_posts')->insertGetId($data);
    }

    public function addAttachment(int $postId, array $data): void
    {
        DB::table('feed_post_attachments')->insert(array_merge($data, ['post_id' => $postId]));
    }

    /** @return array<int,array<string,mixed>> */
    public function attachmentsForPosts(array $postIds): array
    {
        if (!$postIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $rows = DB::select(
            "SELECT * FROM feed_post_attachments WHERE post_id IN ($placeholders) ORDER BY sort_order ASC, id ASC",
            $postIds
        );
        $grouped = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $grouped[(int) $row['post_id']][] = $row;
        }
        return $grouped;
    }

    public function findOwnedByUniversity(int $postId, $universityId): ?array
    {
        $row = DB::selectOne(
            'SELECT fp.*, u.official_name_en AS university_name_en, u.official_name_ar AS university_name_ar,
                    usr.full_name AS author_name
             FROM feed_posts fp
             INNER JOIN universities u ON u.id = fp.university_id
             INNER JOIN users usr ON usr.id = fp.author_user_id
             WHERE fp.id = ? AND fp.university_id = ? AND fp.deleted_at IS NULL
             LIMIT 1',
            [$postId, $universityId]
        );
        return $row ? (array) $row : null;
    }

    /** Same as findOwnedByUniversity() but also returns soft-deleted posts — needed to resolve a report on a post that was already taken down. */
    public function findOwnedByUniversityIncludingDeleted(int $postId, $universityId): ?array
    {
        $row = DB::selectOne(
            'SELECT fp.* FROM feed_posts fp WHERE fp.id = ? AND fp.university_id = ? LIMIT 1',
            [$postId, $universityId]
        );
        return $row ? (array) $row : null;
    }

    /** Sets the draft/published gate (migration 122). */
    public function setStatus(int $postId, bool $published): void
    {
        DB::table('feed_posts')->where('id', $postId)->update(['status' => $published ? 1 : 0]);
    }

    /**
     * Paginated feed for a student — scoped to their own university,
     * carrying is_liked/is_saved flags for $userId. Pinned posts always
     * float to the top of page 1 regardless of created_at. Only
     * `published` posts are visible (drafts stay hidden — migration 122),
     * and each post's target_faculty_id/target_department_id/
     * target_academic_year (NULL = everyone) is matched against the
     * student's own scope, passed in via $studentScope.
     * @param array{faculty_id:?int,department_id:?int,academic_year:?int} $studentScope
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function feedForUniversity($universityId, $userId, array $filters, int $page, int $perPage, array $studentScope = []): array
    {
        $where = [
            'fp.university_id = ?', 'fp.deleted_at IS NULL', 'fp.status = 1',
            '(fp.target_faculty_id IS NULL OR fp.target_faculty_id = ?)',
            '(fp.target_department_id IS NULL OR fp.target_department_id = ?)',
            '(fp.target_academic_year IS NULL OR fp.target_academic_year = ?)',
        ];
        $params = [
            $universityId,
            $studentScope['faculty_id'] ?? 0,
            $studentScope['department_id'] ?? 0,
            $studentScope['academic_year'] ?? 0,
        ];

        if (!empty($filters['q'])) {
            $where[] = 'MATCH(fp.title, fp.body) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $filters['q'];
        }
        if (($filters['type'] ?? 'all') === 'events') {
            $where[] = 'fp.is_event = 1';
        } elseif (($filters['type'] ?? 'all') === 'saved') {
            $where[] = 'EXISTS (SELECT 1 FROM feed_post_saves s WHERE s.post_id = fp.id AND s.user_id = ?)';
            $params[] = $userId;
        } elseif (($filters['type'] ?? 'all') === 'pinned') {
            $where[] = 'fp.is_pinned = 1';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS c FROM feed_posts fp WHERE $whereSql", $params)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $items = DB::select(
            "SELECT fp.*, usr.full_name AS author_name, CASE WHEN EXISTS(SELECT 1 FROM settings st_av WHERE st_av.scope = 'user' AND st_av.user_id = usr.id AND st_av.`key` = 'profile_photo_public' AND st_av.value = '0') THEN NULL ELSE usr.avatar_path END AS author_avatar,
                    EXISTS(SELECT 1 FROM feed_post_likes l WHERE l.post_id = fp.id AND l.user_id = ?) AS is_liked,
                    EXISTS(SELECT 1 FROM feed_post_saves sv WHERE sv.post_id = fp.id AND sv.user_id = ?) AS is_saved
             FROM feed_posts fp
             INNER JOIN users usr ON usr.id = fp.author_user_id
             WHERE $whereSql
             ORDER BY fp.is_pinned DESC, fp.created_at DESC
             LIMIT $perPage OFFSET $offset",
            array_merge([$userId, $userId], $params)
        );

        return ['items' => array_map(fn ($r) => (array) $r, $items), 'total' => $total];
    }

    /** University-side management listing (no like/save flags needed). */
    public function postsForUniversity($universityId, array $filters, int $page, int $perPage): array
    {
        $where = ['fp.university_id = ?', 'fp.deleted_at IS NULL'];
        $params = [$universityId];

        if (!empty($filters['q'])) {
            $where[] = 'MATCH(fp.title, fp.body) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $filters['q'];
        }
        switch ($filters['status'] ?? 'all') {
            case 'published': $where[] = 'fp.status = 1'; break;
            case 'draft':     $where[] = 'fp.status = 0'; break;
            case 'events':    $where[] = 'fp.is_event = 1'; break;
            case 'pinned':    $where[] = 'fp.is_pinned = 1'; break;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS c FROM feed_posts fp WHERE $whereSql", $params)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $items = DB::select(
            "SELECT fp.*, usr.full_name AS author_name, CASE WHEN EXISTS(SELECT 1 FROM settings st_av WHERE st_av.scope = 'user' AND st_av.user_id = usr.id AND st_av.`key` = 'profile_photo_public' AND st_av.value = '0') THEN NULL ELSE usr.avatar_path END AS author_avatar
             FROM feed_posts fp
             INNER JOIN users usr ON usr.id = fp.author_user_id
             WHERE $whereSql
             ORDER BY fp.is_pinned DESC, fp.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return ['items' => array_map(fn ($r) => (array) $r, $items), 'total' => $total];
    }

    /** @return bool true if now liked, false if now unliked */
    public function toggleLike(int $postId, $userId): bool
    {
        $existing = DB::selectOne('SELECT id FROM feed_post_likes WHERE post_id = ? AND user_id = ?', [$postId, $userId]);
        if ($existing) {
            DB::delete('DELETE FROM feed_post_likes WHERE id = ?', [$existing->id]);
            DB::update('UPDATE feed_posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = ?', [$postId]);
            return false;
        }
        DB::table('feed_post_likes')->insert(['post_id' => $postId, 'user_id' => $userId]);
        DB::update('UPDATE feed_posts SET likes_count = likes_count + 1 WHERE id = ?', [$postId]);
        return true;
    }

    /** @return bool true if now saved, false if now unsaved */
    public function toggleSave(int $postId, $userId): bool
    {
        $existing = DB::selectOne('SELECT id FROM feed_post_saves WHERE post_id = ? AND user_id = ?', [$postId, $userId]);
        if ($existing) {
            DB::delete('DELETE FROM feed_post_saves WHERE id = ?', [$existing->id]);
            DB::update('UPDATE feed_posts SET saves_count = GREATEST(0, saves_count - 1) WHERE id = ?', [$postId]);
            return false;
        }
        DB::table('feed_post_saves')->insert(['post_id' => $postId, 'user_id' => $userId]);
        DB::update('UPDATE feed_posts SET saves_count = saves_count + 1 WHERE id = ?', [$postId]);
        return true;
    }

    public function recordShare(int $postId, $userId, ?int $conversationId): void
    {
        DB::table('feed_post_shares')->insert([
            'post_id' => $postId, 'user_id' => $userId, 'conversation_id' => $conversationId,
        ]);
        DB::update('UPDATE feed_posts SET shares_count = shares_count + 1 WHERE id = ?', [$postId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function commentsForPost(int $postId): array
    {
        $rows = DB::select(
            'SELECT c.*, u.full_name AS user_name
             FROM feed_post_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.post_id = ? AND c.deleted_at IS NULL
             ORDER BY c.created_at ASC',
            [$postId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function addComment(int $postId, $userId, string $body): int
    {
        $id = (int) DB::table('feed_post_comments')->insertGetId([
            'post_id' => $postId, 'user_id' => $userId, 'body' => $body,
        ]);
        DB::update('UPDATE feed_posts SET comments_count = comments_count + 1 WHERE id = ?', [$postId]);
        return $id;
    }

    public function findComment(int $commentId): ?array
    {
        $row = DB::selectOne('SELECT * FROM feed_post_comments WHERE id = ?', [$commentId]);
        return $row ? (array) $row : null;
    }

    public function deleteComment(int $commentId, int $postId): void
    {
        DB::update('UPDATE feed_post_comments SET deleted_at = NOW() WHERE id = ?', [$commentId]);
        DB::update('UPDATE feed_posts SET comments_count = GREATEST(0, comments_count - 1) WHERE id = ?', [$postId]);
    }

    public function setPinned(int $postId, bool $pinned): void
    {
        DB::table('feed_posts')->where('id', $postId)->update([
            'is_pinned' => $pinned ? 1 : 0,
            'pinned_at' => $pinned ? now() : null,
        ]);
    }

    public function softDelete(int $postId): void
    {
        DB::update('UPDATE feed_posts SET deleted_at = NOW() WHERE id = ?', [$postId]);
    }

    public function update(int $postId, array $data): void
    {
        DB::table('feed_posts')->where('id', $postId)->update($data);
    }

    // -- Moderation / Reports (migration 122: feed_post_reports) --

    /** @throws \Throwable on the UNIQUE(post_id, reporter_user_id) violation — let the service translate it */
    public function createReport(int $postId, $reporterUserId, string $reason): int
    {
        return (int) DB::table('feed_post_reports')->insertGetId([
            'post_id'          => $postId,
            'reporter_user_id' => $reporterUserId,
            'reason'           => $reason,
        ]);
    }

    public function findReport(int $reportId): ?array
    {
        $row = DB::selectOne('SELECT * FROM feed_post_reports WHERE id = ? LIMIT 1', [$reportId]);
        return $row ? (array) $row : null;
    }

    /** Pending + recently-resolved reports across this university's own feed, newest first. */
    public function reportsForUniversity($universityId): array
    {
        $rows = DB::select(
            'SELECT r.*, fp.title AS post_title, fp.deleted_at AS post_deleted_at, usr.full_name AS reporter_name
             FROM feed_post_reports r
             INNER JOIN feed_posts fp ON fp.id = r.post_id
             INNER JOIN users usr ON usr.id = r.reporter_user_id
             WHERE fp.university_id = ?
             ORDER BY (r.status = "pending") DESC, r.created_at DESC
             LIMIT 200',
            [$universityId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function resolveReport(int $reportId, string $status, $reviewerUserId, ?string $notes): void
    {
        DB::table('feed_post_reports')->where('id', $reportId)->update([
            'status'           => $status,
            'reviewed_by'      => $reviewerUserId,
            'reviewed_at'      => now(),
            'resolution_notes' => $notes,
        ]);
    }
}
