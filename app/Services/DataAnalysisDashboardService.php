<?php

namespace App\Services;

use App\Repositories\DataExportRepository;
use App\Repositories\SavedDashboardRepository;

/**
 * منقولة من app/Services/DataAnalysisDashboardService.php القديمة —
 * بند 24 (Data Analysis Portal)، مدخل البورتال. عمدًا مش بتكرر استعلامات
 * AnalyticsService — كل KPI/سلسلة/leaderboard في داشبورد Data Analysis
 * جاي من نفس AnalyticsService اللي بيغذّي Admin Analytics أصلًا (مصدر
 * وحيد للحقيقة لمقاييس المنصة، بند 23). الكلاس ده بس بيضيف اللي
 * AnalyticsService ملهوش دخل بيه: لوحات/تصديرات المحلل المحفوظة.
 */
class DataAnalysisDashboardService
{
    public function __construct(
        private AnalyticsService $analytics,
        private SavedDashboardRepository $dashboards,
        private DataExportRepository $exports,
        private DataAnalysisAcademicService $academic
    ) {
    }

    /** @return array<string,mixed> كل اللي صفحة داشبورد Data Analysis محتاجاه */
    public function overview(): array
    {
        return [
            'kpis'           => $this->analytics->platformOverview(),
            'user_growth'    => $this->analytics->userGrowthSeries(6),
            'users_by_role'  => $this->analytics->usersByRoleSeries(),
            'category_dist'  => $this->analytics->categoryDistribution(8),
            'leaderboard'    => $this->analytics->universityLeaderboard(5),
            'trends'         => $this->analytics->categoryGrowthTrends(30, 6),
            'university_map' => $this->analytics->universityGrowthMap(6),
        ] + $this->academic->overview();
    }

    public function savedDashboardsFor($userId): array
    {
        return $this->dashboards->forUser($userId);
    }

    public function recentExportsFor($userId): array
    {
        return $this->exports->forUser($userId, 20);
    }
}
