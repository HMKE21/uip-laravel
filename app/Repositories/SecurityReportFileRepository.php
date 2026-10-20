<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/SecurityReportFileRepository.php القديمة —
 * بند 25 batch 5 (Report Management). الوصول لبيانات
 * `security_report_files` وجداولها المرتبطة (versions/tags/categories) —
 * القايمة المُرقّمة/القابلة للبحث/الفلترة، مزامنة التاجات، أرشفة/إلغاء
 * أرشفة، حذف نهائي، وقراءات التصنيف الصغيرة اللي فورم الرفع/شريط الفلتر
 * محتاجاها. نفس شكل DataAnalysisReportFileRepository بالظبط.
 */
class SecurityReportFileRepository
{
    /**
     * قايمة مُرقّمة/قابلة للبحث/الفلترة/الترتيب.
     *
     * @param array{
     *   q?:string, category_id?:int, tag_id?:int, extension?:string,
     *   view?:string (active|archived), uploaded_by?:int,
     *   sort?:string (newest|oldest|name|size|downloads), page?:int, per_page?:int
     * } $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function paginate(array $filters): array
    {
        $view = $filters['view'] ?? 'active';
        $where = ['1=1'];
        $params = [];

        $where[] = $view === 'archived' ? 'srf.is_archived = 1' : 'srf.is_archived = 0';

        if (!empty($filters['q'])) {
            $where[] = '(srf.title LIKE ? OR srf.description LIKE ? OR srf.original_filename LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'srf.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['extension'])) {
            $where[] = 'srf.file_extension = ?';
            $params[] = strtolower((string) $filters['extension']);
        }
        if (!empty($filters['uploaded_by'])) {
            $where[] = 'srf.uploaded_by = ?';
            $params[] = (int) $filters['uploaded_by'];
        }
        if (!empty($filters['tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM security_report_file_tags t WHERE t.security_report_file_id = srf.id AND t.tag_id = ?)';
            $params[] = (int) $filters['tag_id'];
        }

        $orderBy = match ($filters['sort'] ?? 'newest') {
            'oldest'    => 'srf.created_at ASC',
            'name'      => 'srf.title ASC',
            'size'      => 'srf.file_size_bytes DESC',
            'downloads' => 'srf.download_count DESC',
            default     => 'srf.created_at DESC',
        };

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $whereSql = implode(' AND ', $where);

        $total = (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM security_report_files srf WHERE {$whereSql}",
            $params
        )->c ?? 0);

        $rows = DB::select(
            "SELECT srf.*, u.full_name AS uploader_name,
                    sc.name_en AS category_name_en, sc.name_ar AS category_name_ar
             FROM security_report_files srf
             INNER JOIN users u ON u.id = srf.uploaded_by
             LEFT JOIN security_report_categories sc ON sc.id = srf.category_id
             WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $rows = array_map(fn ($r) => (array) $r, $rows);
        foreach ($rows as &$row) {
            $row['tags'] = $this->tagsForFile((int) $row['id']);
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** صف تفاصيل ملف واحد (أي حالة — الكولر بيفحص is_archived لو محتاج)، أو null. */
    public function findDetailed(int $id): ?array
    {
        $row = DB::selectOne(
            'SELECT srf.*, u.full_name AS uploader_name,
                    sc.name_en AS category_name_en, sc.name_ar AS category_name_ar
             FROM security_report_files srf
             INNER JOIN users u ON u.id = srf.uploaded_by
             LEFT JOIN security_report_categories sc ON sc.id = srf.category_id
             WHERE srf.id = ? LIMIT 1',
            [$id]
        );
        if (!$row) {
            return null;
        }
        $row = (array) $row;
        $row['tags'] = $this->tagsForFile($id);
        return $row;
    }

    /** @return array<int,array<string,mixed>> تاريخ نسخ الملف، الأحدث أولًا */
    public function versionsForFile(int $fileId): array
    {
        $rows = DB::select(
            'SELECT v.*, u.full_name AS uploader_name
             FROM security_report_file_versions v
             INNER JOIN users u ON u.id = v.uploaded_by
             WHERE v.security_report_file_id = ?
             ORDER BY v.version_number DESC',
            [$fileId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function nextVersionNumber(int $fileId): int
    {
        $max = (int) (DB::selectOne(
            'SELECT COALESCE(MAX(version_number), 0) AS m FROM security_report_file_versions WHERE security_report_file_id = ?',
            [$fileId]
        )->m ?? 0);
        return $max + 1;
    }

    public function createVersion(array $data): void
    {
        DB::table('security_report_file_versions')->insert($data + ['created_at' => now()]);
    }

    /** @return array<int,array{id:int,name:string}> */
    public function tagsForFile(int $fileId): array
    {
        $rows = DB::select(
            'SELECT t.id, t.name FROM security_report_tags t
             INNER JOIN security_report_file_tags ft ON ft.tag_id = t.id
             WHERE ft.security_report_file_id = ? ORDER BY t.name',
            [$fileId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    /** بتستبدل ست التاجات بتاعة ملف بـ $tagIds (مصفوفة فاضية بتشيل كل التاجات). */
    public function syncTags(int $fileId, array $tagIds): void
    {
        DB::delete('DELETE FROM security_report_file_tags WHERE security_report_file_id = ?', [$fileId]);
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            DB::table('security_report_file_tags')->insert([
                'security_report_file_id' => $fileId,
                'tag_id'                  => $tagId,
            ]);
        }
    }

    /** بتدور على تاج موجود بالاسم (case-insensitive) أو بتنشئه، وبترجع الـ id بتاعه. */
    public function findOrCreateTag(string $name): int
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $existing = DB::selectOne('SELECT id FROM security_report_tags WHERE slug = ? LIMIT 1', [$slug]);
        if ($existing) {
            return (int) $existing->id;
        }
        return (int) DB::table('security_report_tags')->insertGetId([
            'name'       => trim($name),
            'slug'       => $slug,
            'created_at' => now(),
        ]);
    }

    public function incrementDownloadCount(int $fileId): void
    {
        DB::update('UPDATE security_report_files SET download_count = download_count + 1 WHERE id = ?', [$fileId]);
    }

    public function incrementViewCount(int $fileId): void
    {
        DB::update('UPDATE security_report_files SET view_count = view_count + 1 WHERE id = ?', [$fileId]);
    }

    public function setArchived(int $fileId, bool $archived): void
    {
        DB::update('UPDATE security_report_files SET is_archived = ? WHERE id = ?', [$archived ? 1 : 0, $fileId]);
    }

    /** حذف نهائي لصف قاعدة البيانات (الكولر مسؤول عن حذف الملف/الملفات الفعلية الأول). */
    public function delete(int $fileId): void
    {
        DB::delete('DELETE FROM security_report_files WHERE id = ?', [$fileId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        $rows = DB::select('SELECT * FROM security_report_categories ORDER BY name_en');
        return array_map(fn ($r) => (array) $r, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function tags(): array
    {
        $rows = DB::select('SELECT * FROM security_report_tags ORDER BY name');
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function totalActive(): int
    {
        return (int) (DB::selectOne('SELECT COUNT(*) AS c FROM security_report_files WHERE is_archived = 0')->c ?? 0);
    }
}
