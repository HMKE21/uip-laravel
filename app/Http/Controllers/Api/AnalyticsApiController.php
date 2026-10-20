<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\University;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AnalyticsApiController.php القديمة —
 * سطح /api/v1/analytics/* واحد مشترك بين البورتالات (كان placeholder-only
 * في api/v1/analytics/{overview,trends,innovation-statistics}.php)، مختلف
 * عن Data Analysis portal (بند 24 — Advanced Analytics/dataset workspace،
 * موديول منفصل) وعن Graduation/Portfolio-style endpoints. بيعيد استخدام
 * AnalyticsService + AIAnalysisRepository زي Admin\AdminAnalyticsController/
 * Admin\AdminInnovationStatisticsController بالظبط للـview الشامل، و
 * ProjectRepository::*ForUniversity() زي University\UniversityAnalyticsController
 * بالظبط للـview الخاص بجامعة واحدة — نفس الأرقام اللي الداشبوردات
 * الموجودة بتعرضها، JSON بدل view بس.
 *
 * RBAC، دورين بس:
 *   - admin: منصة كاملة، فلترة اختيارية بـ university_id/department_id/
 *     date_from/date_to — نفس فلاتر AdminInnovationStatisticsController.
 *   - university: نطاقها هي بس (university_id بيتحل من uip_user_id عبر
 *     UniversityRepository::findByUserId()، مش من client input أبدًا)،
 *     فلترة بفترة تاريخية بس، من غير أي بيانات مقارنة/leaderboard.
 * أي دور تاني بياخد 403 — Analytics مفهاش أي view لطالب/كلية/شركة/
 * مستثمر/باحث في التطبيق القديم كله.
 *
 * فرق شكلي فقط عن القديمة (زي باقي الكنترولرز المنقولة بالظبط):
 * Session::hasRole()/userId() -> $request->attributes->get('uip_role')/
 * 'uip_user_id')، $this->input() -> $request->input().
 */
class AnalyticsApiController extends Controller
{
    public function __construct(
        private AnalyticsService $analytics,
        private AIAnalysisRepository $readiness,
        private ProjectRepository $projects,
        private UniversityRepository $universities,
        private DepartmentRepository $departments
    ) {
    }

    // -- Overview ---------------------------------------------------------

    /**
     * GET /api/v1/analytics/overview
     * admin: كروت المنصة كاملة + نمو المستخدمين/توزيع الأدوار + تسلسل
     * الـecosystem الهرمي + بانلات Most Viewed/Interacted/Active
     * (نفس بيانات AdminAnalyticsController بالظبط).
     * university: نطاقها هي بس — سلسلة نشاط، توزيع الكليات، نسبة
     * الموافقة، متوسط وقت القرار، ترتيبها بين كل الجامعات (نفس بيانات
     * UniversityAnalyticsController بالظبط).
     */
    public function overview(Request $request)
    {
        $role = $request->attributes->get('uip_role');

        if ($role === 'admin') {
            return $this->apiSuccess([
                'platform'                  => $this->analytics->platformOverview(),
                'user_growth'               => $this->analytics->userGrowthSeries(5),
                'users_by_role'             => $this->analytics->usersByRoleSeries(),
                'university_leaderboard'    => $this->analytics->universityLeaderboard(5),
                'ecosystem'                 => $this->analytics->ecosystemOverview(),
                'most_viewed_projects'      => $this->analytics->mostViewedProjects(5),
                'most_interacted_projects'  => $this->analytics->mostInteractedProjects(5),
                'faculty_leaderboard'       => $this->analytics->facultyLeaderboard(5),
                'department_leaderboard'    => $this->analytics->departmentLeaderboard(5),
            ], 'Platform analytics overview retrieved successfully.');
        }

        if ($universityId = $this->ownUniversityId($request)) {
            $counts = $this->projects->countByStatusForUniversity($universityId);
            $published = ($counts['published'] ?? 0) + ($counts['approved'] ?? 0); // معتمد (منشور أو لا)
            $rejected = $counts['rejected'] ?? 0;
            $decided = $published + $rejected;

            $faculty = $this->projects->facultyBreakdownForUniversity($universityId);
            $facultyTotal = array_sum(array_column($faculty, 'total'));

            $leaderboard = $this->projects->universityProjectLeaderboard(1000);
            $ranking = null;
            $totalUniversities = count($leaderboard);
            foreach ($leaderboard as $i => $row) {
                if ((int) $row['id'] === $universityId) {
                    $ranking = [
                        'rank'       => $i + 1,
                        'total'      => $totalUniversities,
                        'percentile' => $totalUniversities > 1 ? round((1 - ($i / ($totalUniversities - 1))) * 100) : 100,
                        'projects'   => (int) $row['projects_count'],
                    ];
                    break;
                }
            }

            // توزيع درجة الجاهزية — نفس مصدر UniversityAnalyticsController::index().
            $readiness = $this->readiness->readinessDistributionForUniversity($universityId);

            return $this->apiSuccess([
                'activity'            => $this->projects->monthlyActivityForUniversity($universityId),
                'faculty_breakdown'   => $faculty,
                'faculty_total'       => $facultyTotal,
                'approval_rate'       => $decided > 0 ? round(($published / $decided) * 100) : 0,
                'avg_decision_days'   => $this->projects->avgDecisionDaysForUniversity($universityId),
                'ranking'             => $ranking,
                'readiness_buckets'   => $readiness['buckets'],
                'readiness_analyzed'  => $readiness['analyzedCount'],
            ], 'University analytics overview retrieved successfully.');
        }

        return $this->apiError('Only admin or university accounts can view analytics.', null, 403);
    }

    // -- Trends -------------------------------------------------------------

    /**
     * GET /api/v1/analytics/trends
     * نمو حقيقي عبر الزمن (period-over-period)، عمرها ما تنبؤ. admin:
     * منصة كاملة (أو مفلترة بجامعة واحدة عبر ?university_id=). university:
     * نطاقها هي بس، بتتجاهل university_id من الـquery string.
     * Query params: days (افتراضي 30)، limit (افتراضي 6)، university_id
     * (admin بس).
     */
    public function trends(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        $days = (int) $request->input('days', 30);
        $limit = (int) $request->input('limit', 6);

        if ($role === 'admin') {
            $filters = array_filter([
                'university_id' => $request->input('university_id', ''),
            ], fn ($v) => $v !== '' && $v !== null);

            return $this->apiSuccess([
                'category_trends'       => $this->analytics->categoryGrowthTrends($days, $limit, $filters),
                'university_growth_map' => $this->analytics->universityGrowthMap(6, $filters),
            ], 'Analytics trends retrieved successfully.');
        }

        if ($universityId = $this->ownUniversityId($request)) {
            return $this->apiSuccess([
                'category_trends' => $this->projects->categoryGrowthForUniversity($universityId, $days, $limit),
            ], 'Analytics trends retrieved successfully.');
        }

        return $this->apiError('Only admin or university accounts can view analytics.', null, 403);
    }

    // -- Innovation statistics -----------------------------------------------

    /**
     * GET /api/v1/analytics/innovation-statistics — admin بس. مقارنة
     * بين الجامعات: leaderboard، توزيع تصنيفات، خريطة نمو، نسبة نجاح،
     * وaggregates جاهزية AI، قابلة للفلترة بـ university_id/department_id/
     * date_from/date_to — نفس البيانات والفلاتر بالظبط زي
     * AdminInnovationStatisticsController.
     */
    public function innovationStatistics(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view innovation statistics.', null, 403);
        }

        $filters = array_filter([
            'university_id' => $request->input('university_id', ''),
            'department_id' => $request->input('department_id', ''),
            'date_from'     => $request->input('date_from', ''),
            'date_to'       => $request->input('date_to', ''),
        ], fn ($v) => $v !== '' && $v !== null);

        // استعلامات التصنيف/الليدربورد/النمو عمرها ما بتاخد department_id
        // (المشاريع مفيهاش department_id مباشر — بس قسم الطالب المالك)،
        // نفس التقليم اللي AdminInnovationStatisticsController بيعمله.
        $projectFilters = array_diff_key($filters, ['department_id' => null]);

        $departmentOptions = !empty($filters['university_id'])
            ? $this->departments->forUniversity((int) $filters['university_id'])
            : [];

        return $this->apiSuccess([
            'university_leaderboard'      => $this->analytics->universityLeaderboard(10, $projectFilters),
            'category_distribution'       => $this->analytics->categoryDistribution(6, $projectFilters),
            'university_count'            => University::count(),
            'university_growth_map'       => $this->analytics->universityGrowthMap(6, $projectFilters),
            'top_trend'                   => $this->analytics->categoryGrowthTrends(30, 1, $projectFilters)[0] ?? null,
            'success_rate'                => $this->analytics->successRate($projectFilters),
            'avg_readiness_by_university' => $this->readiness->avgReadinessByUniversity($filters),
            'readiness_summary'           => $this->readiness->platformReadinessSummary($filters),
            'readiness_by_faculty'        => $this->readiness->avgReadinessByFaculty($projectFilters),
            'readiness_by_semester'       => $this->readiness->avgReadinessBySemester($filters),
            'universities'                => $this->universities->allForRegistration(),
            'departments'                 => $departmentOptions,
            'filters'                     => $filters,
        ], 'Innovation statistics retrieved successfully.');
    }

    // -- helpers --------------------------------------------------------------

    /** بيحل university_id بتاع الجهة المتصلة، أو null لو مش حساب جامعة. */
    private function ownUniversityId(Request $request): ?int
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return null;
        }

        $university = $this->universities->findByUserId($request->attributes->get('uip_user_id'));
        return $university ? (int) $university->id : null;
    }
}
