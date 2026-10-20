<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\PortfolioRepository;
use App\Repositories\ProjectRepository;

/**
 * منقولة من app/Services/PortfolioService.php القديمة — بند 12.
 * بتنسّق بين PortfolioRepository + ProjectRepository عشان صفحة الـ
 * Portfolio تعرض: البروفايل القابل للتعديل (headline/about/visibility)،
 * كل مشروع *منشور* (published) الكولر مالكه (دول بس المؤهلين يتحطوا
 * "مميزين" — مشروع draft أو pending معندوش صفحة عامة تتلينك بيها)،
 * وأنهي منهم مميز دلوقتي وترتيبه.
 */
class PortfolioService
{
    public function __construct(
        private PortfolioRepository $portfolios,
        private ProjectRepository $projects
    ) {
    }

    /**
     * الداتا الكاملة لصفحة الـ Portfolio بتاعة الطالب نفسه.
     * @return array{portfolio: \App\Models\Portfolio, eligibleProjects: array, featuredProjects: array}
     */
    public function forOwner($userId): array
    {
        $portfolio = $this->portfolios->getOrCreate($userId);
        $featuredIds = $this->portfolios->featuredProjectIds((int) $portfolio->id);

        $published = array_filter($this->projects->forOwner($userId), fn (Project $p) => $p->status === 'published');

        $eligible = array_map(function (Project $p) use ($featuredIds) {
            $card = $p->toCardArray();
            $card['db_id'] = $p->id;
            $card['is_featured'] = in_array((int) $p->id, $featuredIds, true);
            return $card;
        }, $published);

        $featuredRows = $this->portfolios->featuredProjectsRaw((int) $portfolio->id);
        $featured = Project::hydrate($featuredRows)->map(fn (Project $p) => $p->toCardArray())->all();

        return [
            'portfolio'        => $portfolio,
            'eligibleProjects' => array_values($eligible),
            'featuredProjects' => $featured,
        ];
    }

    /**
     * الداتا لصفحة المشاركة العامة (من غير auth). بترجع null لو الـ
     * portfolio مش موجود أو صاحبه خلاه private — الكنترولر بيحوّل ده
     * لـ 404 دايمًا، مش استجابة ناقصة.
     */
    public function forPublicShare($userId): ?array
    {
        $portfolio = $this->portfolios->findByUserId($userId);
        if (!$portfolio || !$portfolio->is_public) {
            return null;
        }

        $featuredRows = $this->portfolios->featuredProjectsRaw((int) $portfolio->id);
        $featured = Project::hydrate($featuredRows)->map(fn (Project $p) => $p->toCardArray())->all();

        return [
            'portfolio'        => $portfolio,
            'featuredProjects' => $featured,
        ];
    }

    public function updateProfile($userId, array $data): void
    {
        $portfolio = $this->portfolios->getOrCreate($userId);
        $this->portfolios->update($portfolio, [
            'headline'  => trim((string) ($data['headline'] ?? '')) ?: null,
            'about'     => trim((string) ($data['about'] ?? '')) ?: null,
            'is_public' => !empty($data['is_public']) ? 1 : 0,
        ]);
    }

    /**
     * بتبدّل حالة "مميز" لمشروع منشور بتاع الكولر نفسه على الـ portfolio
     * بتاعه. بتسكت (no-op) لو المشروع مش منشور أو مش ملك الكولر — الفعل
     * ده تسهيلة UI، مش بوابة workflow.
     */
    public function toggleFeatured($userId, $projectDbId): void
    {
        $portfolio = $this->portfolios->getOrCreate($userId);
        $owned = array_filter(
            $this->projects->forOwner($userId),
            fn (Project $p) => (int) $p->id === (int) $projectDbId && $p->status === 'published'
        );

        if (!$owned) {
            return;
        }

        if ($this->portfolios->isFeatured((int) $portfolio->id, (int) $projectDbId)) {
            $this->portfolios->removeFeatured((int) $portfolio->id, (int) $projectDbId);
        } else {
            $this->portfolios->addFeatured((int) $portfolio->id, (int) $projectDbId);
        }
    }
}
