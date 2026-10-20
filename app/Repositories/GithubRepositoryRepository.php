<?php

namespace App\Repositories;

use App\Models\GithubRepository;
use Illuminate\Support\Facades\DB;

/**
 * منقولة بالكامل من app/Repositories/GithubRepositoryRepository.php
 * القديمة — وصول للبيانات لجدول `github_repositories`. forOwner() هي
 * الاستعلام المُجمَّع اللي بتغذي صفحة GitHub Integration hub للطالب/
 * الباحث (كل مشاريعهم + حالة الربط + آخر مراجعة، صف واحد لكل مشروع).
 */
class GithubRepositoryRepository
{
    public function findForProject($projectId): ?GithubRepository
    {
        return GithubRepository::where('project_id', $projectId)->first();
    }

    /** ينشئ أو يعدّل صف الريبو الوحيد اللي ممكن يكون مربوط بمشروع. */
    public function upsertForProject($projectId, array $data): GithubRepository
    {
        $existing = $this->findForProject($projectId);
        if ($existing) {
            $existing->fill($data);
            $existing->save();
            return $existing;
        }

        $data['project_id'] = $projectId;
        return GithubRepository::create($data);
    }

    /**
     * كل مشاريع المستخدم مع صف الريبو المربوط (لو موجود) وآخر مراجعة
     * لهذا المشروع (لو موجودة) — صف واحد لكل مشروع، بيانات حقيقية بس.
     * repository_url بيتحل من project_links (type=github) بنفس أسلوب
     * AiCodeReviewRepository::allWithProject() الـ correlated subquery،
     * مش عمود projects.repository_url القديم. live_demo_url بنفس نمط
     * ProjectRepository لكارت المشروع العام.
     * @return array<int,array<string,mixed>>
     */
    public function forOwner($ownerId): array
    {
        $sql = 'SELECT
                    p.id AS project_id, p.uuid AS project_uuid,
                    p.title_en, p.title_ar, p.cover_image_path,
                    (SELECT pl.url FROM project_links pl
                     WHERE pl.project_id = p.id AND pl.type = \'github\'
                     ORDER BY pl.is_primary DESC, pl.created_at DESC LIMIT 1) AS repository_url,
                    (SELECT pl.url FROM project_links pl
                     WHERE pl.project_id = p.id AND pl.type IN (\'live_demo\',\'website\')
                     ORDER BY pl.is_primary DESC, pl.id ASC LIMIT 1) AS live_demo_url,
                    g.default_branch, g.last_synced_at, g.sync_status,
                    r.status AS review_status, r.issues_found, r.summary AS review_summary,
                    r.created_at AS review_created_at
                FROM projects p
                LEFT JOIN github_repositories g ON g.project_id = p.id
                LEFT JOIN (
                    SELECT rr.*
                    FROM ai_code_review_results rr
                    INNER JOIN (
                        SELECT project_id, MAX(created_at) AS max_created_at
                        FROM ai_code_review_results
                        GROUP BY project_id
                    ) latest ON latest.project_id = rr.project_id AND latest.max_created_at = rr.created_at
                ) r ON r.project_id = p.id
                WHERE p.owner_id = ?
                ORDER BY p.created_at DESC';

        $rows = DB::select($sql, [$ownerId]);
        return array_map(fn ($r) => (array) $r, $rows);
    }
}
