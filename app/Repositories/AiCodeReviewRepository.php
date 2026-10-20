<?php

namespace App\Repositories;

use App\Models\AiCodeReviewResult;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AiCodeReviewRepository.php القديمة (Core\Database
 * -> DB facade / Eloquent). filteredPaginated()/platformStats()/
 * allForProject()/find() تخدم لوحة إشراف الأدمن (AdminAiCodeReviewApiController)،
 * و universityRollup()/facultyRollup() تخدم تقارير التصدير
 * (AdminAiCodeReviewExportController) — بند 26 (Admin) batch 6.
 */
class AiCodeReviewRepository
{
    /** أحدث مراجعة لمشروع، لو موجودة. */
    public function latestForProject($projectId): ?AiCodeReviewResult
    {
        return AiCodeReviewResult::where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->first();
    }

    public function create(array $data): AiCodeReviewResult
    {
        return AiCodeReviewResult::create($data);
    }

    public function find(int $id): ?AiCodeReviewResult
    {
        return AiCodeReviewResult::find($id);
    }

    /**
     * سلسلة تاريخ المراجعات الكاملة لمشروع، الأحدث أولاً — تخدم صفحة
     * التاريخ (history) و compareVersions().
     * @return array<int,AiCodeReviewResult>
     */
    public function allForProject(int $projectId): array
    {
        return AiCodeReviewResult::where('project_id', $projectId)
            ->orderByDesc('version')
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * كل مراجعة تمت على مستوى المنصة كلها، مع عنوان المشروع ومالكه —
     * repository_url بيتحل من project_links (type=github)، مش عمود
     * projects.repository_url القديم.
     * @return array<int,array<string,mixed>>
     */
    public function allWithProject(int $limit = 200): array
    {
        $rows = DB::select(
            "SELECT r.id, r.status, r.issues_found, r.summary, r.raw_result, r.created_at,
                    p.uuid AS project_uuid, p.title_en, p.title_ar,
                    (SELECT pl.url FROM project_links pl
                     WHERE pl.project_id = p.id AND pl.type = 'github'
                     ORDER BY pl.is_primary DESC, pl.created_at DESC LIMIT 1) AS repository_url,
                    u.full_name AS owner_name
             FROM ai_code_review_results r
             INNER JOIN projects p ON p.id = r.project_id
             LEFT JOIN users u ON u.id = p.owner_id
             ORDER BY r.created_at DESC
             LIMIT " . (int) $limit
        );

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * قائمة مفلترة + مقسّمة صفحات على مستوى المنصة — فلترة حسب الجامعة/
     * الحالة/الفترة/نطاق الـ score/بحث، LIMIT/OFFSET + COUNT(*) منفصل.
     *
     * @param array{university_id?:int,status?:string,date_from?:string,date_to?:string,
     *              score_min?:int,score_max?:int,search?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function filteredPaginated(array $filters, int $page, int $perPage): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($filters['university_id'])) {
            $where .= ' AND p.university_id = ?';
            $params[] = (int) $filters['university_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND r.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND r.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND r.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (isset($filters['score_min']) && $filters['score_min'] !== '') {
            $where .= ' AND r.overall_score >= ?';
            $params[] = (int) $filters['score_min'];
        }
        if (isset($filters['score_max']) && $filters['score_max'] !== '') {
            $where .= ' AND r.overall_score <= ?';
            $params[] = (int) $filters['score_max'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (p.title_en LIKE ? OR p.title_ar LIKE ? OR u.full_name LIKE ?)';
            $q = '%' . $filters['search'] . '%';
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        $joins = 'FROM ai_code_review_results r
                  INNER JOIN projects p ON p.id = r.project_id
                  LEFT JOIN users u ON u.id = p.owner_id';

        $total = (int) DB::selectOne("SELECT COUNT(*) AS c {$joins} {$where}", $params)->c;

        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1)) * $perPage;
        $rows = DB::select(
            "SELECT r.*, p.uuid AS project_uuid, p.title_en, p.title_ar,
                    (SELECT pl.url FROM project_links pl
                     WHERE pl.project_id = p.id AND pl.type = 'github'
                     ORDER BY pl.is_primary DESC, pl.created_at DESC LIMIT 1) AS repository_url,
                    p.university_id, u.full_name AS owner_name
             {$joins}
             {$where}
             ORDER BY r.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => array_map(fn ($r) => (array) $r, $rows), 'total' => $total];
    }

    /** @return array{total:int,completed:int,failed:int,total_issues:int} */
    public function platformStats(): array
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    COALESCE(SUM(issues_found), 0) AS total_issues
             FROM ai_code_review_results"
        );
        return [
            'total'        => (int) ($row->total ?? 0),
            'completed'    => (int) ($row->completed ?? 0),
            'failed'       => (int) ($row->failed ?? 0),
            'total_issues' => (int) ($row->total_issues ?? 0),
        ];
    }

    /**
     * تجميع (rollup) لجامعة معينة — متوسط كل سكور من الستة + عدد
     * المراجعات حسب الحالة، مقصورة على أحدث مراجعة لكل مشروع فقط (مشروع
     * اتراجع 5 مرات مايتحسبش 5 مرات في متوسط الجامعة).
     * @return array{
     *   averages: array{overall:?float,security:?float,performance:?float,maintainability:?float,architecture:?float,quality:?float},
     *   reviewed_projects:int, completed:int, failed:int, total_issues:int,
     *   rows: array<int,array<string,mixed>>
     * }
     */
    public function universityRollup(int $universityId): array
    {
        $rows = DB::select(
            'SELECT r.* FROM ai_code_review_results r
             INNER JOIN projects p ON p.id = r.project_id
             INNER JOIN (
                 SELECT project_id, MAX(id) AS max_id
                 FROM ai_code_review_results
                 GROUP BY project_id
             ) latest ON latest.project_id = r.project_id AND latest.max_id = r.id
             WHERE p.university_id = ?
             ORDER BY r.created_at DESC',
            [$universityId]
        );

        return $this->summarizeRollup(array_map(fn ($r) => (array) $r, $rows), $universityId, null);
    }

    /**
     * نظير facultyRollup() لـ universityRollup() — كلية الطالب المالك
     * للمشروع (نفس الجوين اللي AIAnalysisRepository::avgReadinessByFaculty()
     * بيستخدمه)، فده طبيعي بيغطي بس مشاريع الطلاب.
     * @return array<string,mixed> نفس شكل universityRollup()
     */
    public function facultyRollup(string $faculty, ?int $universityId = null): array
    {
        $params = [$faculty];
        $uniClause = '';
        if ($universityId) {
            $uniClause = ' AND p.university_id = ?';
            $params[] = $universityId;
        }

        $rows = DB::select(
            "SELECT r.* FROM ai_code_review_results r
             INNER JOIN projects p ON p.id = r.project_id
             INNER JOIN students s ON s.user_id = p.owner_id
             INNER JOIN (
                 SELECT project_id, MAX(id) AS max_id
                 FROM ai_code_review_results
                 GROUP BY project_id
             ) latest ON latest.project_id = r.project_id AND latest.max_id = r.id
             WHERE s.faculty = ? {$uniClause}
             ORDER BY r.created_at DESC",
            $params
        );

        return $this->summarizeRollup(array_map(fn ($r) => (array) $r, $rows), $universityId, $faculty);
    }

    /** @param array<int,array<string,mixed>> $rows صفوف ai_code_review_results خام (أحدث مراجعة لكل مشروع) */
    private function summarizeRollup(array $rows, ?int $universityId, ?string $faculty): array
    {
        $keys = ['overall_score', 'security_score', 'performance_score', 'maintainability_score', 'architecture_score', 'quality_score'];
        $sums = array_fill_keys($keys, 0.0);
        $counts = array_fill_keys($keys, 0);
        $completed = 0;
        $failed = 0;
        $totalIssues = 0;

        foreach ($rows as $row) {
            if ($row['status'] === 'completed') {
                $completed++;
            } elseif ($row['status'] === 'failed') {
                $failed++;
            }
            $totalIssues += (int) ($row['issues_found'] ?? 0);
            foreach ($keys as $k) {
                if ($row[$k] !== null) {
                    $sums[$k] += (float) $row[$k];
                    $counts[$k]++;
                }
            }
        }

        $averages = [];
        $labelMap = ['overall_score' => 'overall', 'security_score' => 'security', 'performance_score' => 'performance', 'maintainability_score' => 'maintainability', 'architecture_score' => 'architecture', 'quality_score' => 'quality'];
        foreach ($keys as $k) {
            $averages[$labelMap[$k]] = $counts[$k] > 0 ? round($sums[$k] / $counts[$k], 1) : null;
        }

        return [
            'university_id'     => $universityId,
            'faculty'           => $faculty,
            'averages'          => $averages,
            'reviewed_projects' => count($rows),
            'completed'         => $completed,
            'failed'            => $failed,
            'total_issues'      => $totalIssues,
            'rows'              => $rows,
        ];
    }
}
