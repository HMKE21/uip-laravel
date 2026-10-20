<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AnnouncementRepository.php القديمة حرف بحرف
 * منطقيًا — نفس أسلوب FeedRepository بالظبط: Core\Database::connection()->
 * fetchOne/fetchAll/query/insert/update (بـ named placeholders `:x`)
 * استُبدلت بـ DB::selectOne/select/statement/table()->insertGetId()/
 * table()->update() (positional `?` placeholders)، والنتائج بترجع array
 * عبر array_map((array) $row). كل قراءة الطالب بيعملها لسه متقيدة بـ
 * university_id = طالب.university_id على مستوى الـ SQL نفسه، مش فلترة
 * client-side، زي القديم بالظبط.
 *
 * publishedForUniversity() هي نفس الميثود اللي كانت جزء من النسخة
 * الجزئية اللي اتنقلت مسبقًا مع بند 4 (Students dashboard) — من غير أي
 * تغيير هنا في منطقها. باقي الميثودز (CRUD الكامل + المرفقات +
 * duePendingNotification/markNotified لبند الـ scheduling) هي اللي بند
 * 22 بيضيفها.
 */
class AnnouncementRepository
{
    public function create(array $data): int
    {
        return (int) DB::table('announcements')->insertGetId($data);
    }

    public function addAttachment(int $announcementId, array $data): void
    {
        DB::table('announcement_attachments')->insert(array_merge($data, ['announcement_id' => $announcementId]));
    }

    /** @return array<int,array<string,mixed>> */
    public function attachmentsFor(array $announcementIds): array
    {
        if (!$announcementIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($announcementIds), '?'));
        $rows = DB::select(
            "SELECT * FROM announcement_attachments WHERE announcement_id IN ($placeholders) ORDER BY sort_order ASC, id ASC",
            $announcementIds
        );
        $grouped = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $grouped[(int) $row['announcement_id']][] = $row;
        }
        return $grouped;
    }

    public function findOwnedByUniversity(int $id, $universityId): ?array
    {
        $row = DB::selectOne(
            'SELECT a.*, usr.full_name AS author_name
             FROM announcements a
             INNER JOIN users usr ON usr.id = a.author_user_id
             WHERE a.id = ? AND a.university_id = ? AND a.deleted_at IS NULL
             LIMIT 1',
            [$id, $universityId]
        );
        return $row ? (array) $row : null;
    }

    /**
     * Student-facing listing — scoped to their own university, only
     * announcements that are actually due and not yet expired (migration
     * 122's expires_at — NULL means it never expires), with optional
     * category/search filters and the same target_faculty_id/
     * target_department_id/target_academic_year visibility-scope match
     * (NULL = everyone) that feed_posts uses.
     * @param array{faculty_id:?int,department_id:?int,academic_year:?int} $studentScope
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function publishedForUniversity($universityId, array $filters, int $page, int $perPage, array $studentScope = []): array
    {
        $where = [
            'a.university_id = ?', 'a.deleted_at IS NULL',
            '(a.publish_at IS NULL OR a.publish_at <= NOW())',
            '(a.expires_at IS NULL OR a.expires_at > NOW())',
            '(a.target_faculty_id IS NULL OR a.target_faculty_id = ?)',
            '(a.target_department_id IS NULL OR a.target_department_id = ?)',
            '(a.target_academic_year IS NULL OR a.target_academic_year = ?)',
        ];
        $params = [
            $universityId,
            $studentScope['faculty_id'] ?? 0,
            $studentScope['department_id'] ?? 0,
            $studentScope['academic_year'] ?? 0,
        ];

        if (!empty($filters['q'])) {
            $where[] = 'MATCH(a.title, a.body) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $filters['q'];
        }
        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $where[] = 'a.category = ?';
            $params[] = $filters['category'];
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS c FROM announcements a WHERE $whereSql", $params)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $items = DB::select(
            "SELECT a.*, usr.full_name AS author_name
             FROM announcements a
             INNER JOIN users usr ON usr.id = a.author_user_id
             WHERE $whereSql
             ORDER BY COALESCE(a.published_at, a.publish_at, a.created_at) DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return ['items' => array_map(fn ($r) => (array) $r, $items), 'total' => $total];
    }

    /** University-side management listing — every non-deleted announcement, published or still scheduled. */
    public function forUniversity($universityId, array $filters, int $page, int $perPage): array
    {
        $where = ['a.university_id = ?', 'a.deleted_at IS NULL'];
        $params = [$universityId];

        if (!empty($filters['q'])) {
            $where[] = 'MATCH(a.title, a.body) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $filters['q'];
        }
        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $where[] = 'a.category = ?';
            $params[] = $filters['category'];
        }
        switch ($filters['status'] ?? 'all') {
            case 'live':
                $where[] = '(a.publish_at IS NULL OR a.publish_at <= NOW())';
                $where[] = '(a.expires_at IS NULL OR a.expires_at > NOW())';
                break;
            case 'scheduled': $where[] = 'a.publish_at IS NOT NULL AND a.publish_at > NOW()'; break;
            case 'expired':   $where[] = 'a.expires_at IS NOT NULL AND a.expires_at <= NOW()'; break;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS c FROM announcements a WHERE $whereSql", $params)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $items = DB::select(
            "SELECT a.*, usr.full_name AS author_name
             FROM announcements a
             INNER JOIN users usr ON usr.id = a.author_user_id
             WHERE $whereSql
             ORDER BY a.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return ['items' => array_map(fn ($r) => (array) $r, $items), 'total' => $total];
    }

    /** Due-but-not-yet-notified rows across all universities — for the scheduled-publish cron/console command. */
    public function duePendingNotification(): array
    {
        $rows = DB::select(
            "SELECT * FROM announcements
             WHERE deleted_at IS NULL AND notified_at IS NULL
               AND (publish_at IS NULL OR publish_at <= NOW())"
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function markNotified(int $id): void
    {
        DB::update(
            'UPDATE announcements SET notified_at = NOW(), published_at = COALESCE(published_at, NOW()) WHERE id = ?',
            [$id]
        );
    }

    public function update(int $id, array $data): void
    {
        DB::table('announcements')->where('id', $id)->update($data);
    }

    public function softDelete(int $id): void
    {
        DB::update('UPDATE announcements SET deleted_at = NOW() WHERE id = ?', [$id]);
    }
}
