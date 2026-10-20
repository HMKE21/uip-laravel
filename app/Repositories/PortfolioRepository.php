<?php

namespace App\Repositories;

use App\Models\Portfolio;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/PortfolioRepository.php القديمة — بند 12.
 * وصول للداتا لجدولي `portfolios` + `portfolio_projects` (migration 016).
 * صف portfolio واحد لكل مستخدم، زائد مجموعة project ids "مميزة" مرتبة
 * (display_order) بيستخدمها PortfolioService.
 *
 * فرق شكلي بس عن القديمة: Database::connection()->fetchAll/fetchValue/
 * insert/query الخام -> DB::table() (query builder بتاع Laravel)، ونفس
 * الـ selectSub لـ live_demo_url/repo_url اللي ProjectRepository::
 * publishedPaginated() بتستخدمه بالظبط (project_links هو مصدر الحقيقة،
 * مش أعمدة projects.repository_url/demo_url القديمة).
 */
class PortfolioRepository
{
    /** بيجيب صف الـ portfolio بتاع الكولر، وبيعمله واحد لو أول زيارة. */
    public function getOrCreate($userId): Portfolio
    {
        $portfolio = $this->findByUserId($userId);
        if ($portfolio) {
            return $portfolio;
        }

        return Portfolio::create([
            'user_id'   => $userId,
            'is_public' => 1,
        ]);
    }

    /** زي getOrCreate() بس بترجع null بدل ما تعمل واحد — لصفحة المشاركة العامة. */
    public function findByUserId($userId): ?Portfolio
    {
        return Portfolio::where('user_id', $userId)->first();
    }

    public function update(Portfolio $portfolio, array $data): bool
    {
        $portfolio->fill($data);
        return $portfolio->save();
    }

    /** @return int[] project ids المميزة على الـ portfolio ده، بترتيب العرض */
    public function featuredProjectIds(int $portfolioId): array
    {
        return DB::table('portfolio_projects')
            ->where('portfolio_id', $portfolioId)
            ->orderBy('display_order')
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * المشاريع المميزة مع صف `projects` الكامل بتاعها، بترتيب العرض —
     * صفوف خام عشان الكولر يعمل hydrate عبر Project::hydrate(). نفس
     * pattern الـ correlated-subquery بتاع ProjectRepository::
     * publishedPaginated() (project_links هو مصدر الحقيقة لـ
     * live_demo_url/repo_url، مش عمودي projects.repository_url/demo_url
     * القديمين) — عشان صفحة المشاركة العامة (/p/{uuid}) وصفحة الـ
     * Portfolio بتاعة React تقدر تعرض cover image + رابط demo حقيقيين،
     * زي portfolio فعلي، مش بس عنوان/ملخص.
     * @return array<int,array<string,mixed>>
     */
    public function featuredProjectsRaw(int $portfolioId): array
    {
        return DB::table('portfolio_projects as pp')
            ->join('projects as p', 'p.id', '=', 'pp.project_id')
            ->where('pp.portfolio_id', $portfolioId)
            ->orderBy('pp.display_order')
            ->select('p.*')
            ->selectSub(function ($q) {
                $q->select('pl.url')->from('project_links as pl')
                    ->whereColumn('pl.project_id', 'p.id')
                    ->whereIn('pl.type', ['live_demo', 'website'])
                    ->orderByDesc('pl.is_primary')->orderBy('pl.id')->limit(1);
            }, 'live_demo_url')
            ->selectSub(function ($q) {
                $q->select('pl.url')->from('project_links as pl')
                    ->whereColumn('pl.project_id', 'p.id')
                    ->whereIn('pl.type', ['github', 'gitlab'])
                    ->orderByDesc('pl.is_primary')->orderBy('pl.id')->limit(1);
            }, 'repo_url')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function isFeatured(int $portfolioId, int $projectId): bool
    {
        return DB::table('portfolio_projects')
            ->where('portfolio_id', $portfolioId)
            ->where('project_id', $projectId)
            ->exists();
    }

    public function addFeatured(int $portfolioId, int $projectId): void
    {
        $nextOrder = (int) (DB::table('portfolio_projects')
            ->where('portfolio_id', $portfolioId)
            ->max('display_order') ?? -1) + 1;

        DB::table('portfolio_projects')->insert([
            'portfolio_id'  => $portfolioId,
            'project_id'    => $projectId,
            'display_order' => $nextOrder,
        ]);
    }

    public function removeFeatured(int $portfolioId, int $projectId): void
    {
        DB::table('portfolio_projects')
            ->where('portfolio_id', $portfolioId)
            ->where('project_id', $projectId)
            ->delete();
    }
}
