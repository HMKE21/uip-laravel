<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\Student;
use App\Models\University;
use App\Models\User;
use App\Repositories\ProjectAnalyticsRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;

/**
 * منقولة كاملة من app/Services/AnalyticsService.php القديمة — بند 23
 * (Analytics الكامل). كانت متنقولة جزئيًا فقط من بند 7 (categoryDistribution/
 * categoryGrowthTrends بلا فلاتر + usersByRoleSeries/platformOverview)؛
 * دلوقتي كل الميثودز موجودة، وcategoryDistribution/categoryGrowthTrends
 * دلوقتي بتاخد $filters زي القديمة بالظبط (university_id/date_from/date_to).
 *
 * زي القديمة بالظبط: عمدًا مفيهاش أي حاجة بتحسب AI Readiness Score —
 * ده بيفضل "Coming Soon" لحد ما AI Analysis service نفسه يبقى حقيقي.
 * categoryGrowthTrends() حقيقية فعلًا (period-over-period submission
 * velocity)، مش تنبؤ — عشان كده الفيوز بتسميها "growth" مش "prediction".
 */
class AnalyticsService
{
    private const MONTH_LABELS = [
        1 => ['en' => 'Jan', 'ar' => 'يناير'], 2 => ['en' => 'Feb', 'ar' => 'فبراير'],
        3 => ['en' => 'Mar', 'ar' => 'مارس'], 4 => ['en' => 'Apr', 'ar' => 'أبريل'],
        5 => ['en' => 'May', 'ar' => 'مايو'], 6 => ['en' => 'Jun', 'ar' => 'يونيو'],
        7 => ['en' => 'Jul', 'ar' => 'يوليو'], 8 => ['en' => 'Aug', 'ar' => 'أغسطس'],
        9 => ['en' => 'Sep', 'ar' => 'سبتمبر'], 10 => ['en' => 'Oct', 'ar' => 'أكتوبر'],
        11 => ['en' => 'Nov', 'ar' => 'نوفمبر'], 12 => ['en' => 'Dec', 'ar' => 'ديسمبر'],
    ];

    public function __construct(
        private ProjectRepository $projects,
        private UserRepository $users,
        private UniversityRepository $universities,
        private ProjectAnalyticsRepository $analyticsEvents
    ) {
    }

    /** كروت الأرقام الرئيسية المشتركة في Analytics Dashboard. */
    public function platformOverview(): array
    {
        $decisions = $this->projects->decisionCounts();
        $decided = $decisions['published'] + $decisions['rejected'];

        return [
            'total_users'           => User::count(),
            'new_users_this_month'  => $this->newUsersThisMonth(),
            'verified_universities' => University::where('verification_status', 'verified')->count(),
            'approval_rate'         => $decided > 0 ? (int) round($decisions['published'] / $decided * 100) : 0,
        ];
    }

    /** سلسلة تسجيلات جدد شهريًا لكارت النمو، الأقدم أولًا. */
    public function userGrowthSeries(int $months = 5): array
    {
        $rows = $this->users->monthlySignups($months);
        return array_map(function ($row) {
            $monthNum = (int) substr($row['month'], 5, 2);
            return [
                'label' => self::MONTH_LABELS[$monthNum] ?? ['en' => $row['month'], 'ar' => $row['month']],
                'value' => $row['total'],
            ];
        }, $rows);
    }

    /** المستخدمين مجمّعين بالدور لكارت "Users by Role". */
    public function usersByRoleSeries(): array
    {
        $rows = $this->users->countByRole();
        return array_map(fn ($r) => [
            'label' => ['en' => $r['name_en'], 'ar' => $r['name_ar']],
            'value' => (int) $r['total'],
        ], $rows);
    }

    /**
     * الجامعات مرتبة حسب حجم المشاريع الحقيقي.
     * @param array{date_from?:string,date_to?:string} $filters
     */
    public function universityLeaderboard(int $limit = 5, array $filters = []): array
    {
        $rows = $this->projects->universityProjectLeaderboard($limit, $filters);
        return array_map(fn ($r) => [
            'id'         => (int) $r['id'],
            'university' => ['en' => $r['official_name_en'] ?: $r['official_name_ar'], 'ar' => $r['official_name_ar'] ?: $r['official_name_en']],
            'projects'   => (int) $r['projects_count'],
        ], $rows);
    }

    /**
     * توزيع عدد المشاريع (غير المسودة) على كل تصنيف — الصورة الحقيقية
     * لـ"المجالات الرائجة" لحد ما التصنيف بالـ AI يبقى موجود. فلترة
     * اختيارية بجامعة/فترة تاريخية.
     * @param array{university_id?:int|string,date_from?:string,date_to?:string} $filters
     */
    public function categoryDistribution(int $limit = 6, array $filters = []): array
    {
        $rows = $this->projects->categoryDistribution($limit, $filters);
        return array_map(fn ($r) => [
            'label' => ['en' => $r['category'], 'ar' => $r['category_ar'] ?? $r['category']],
            'value' => (int) $r['total'],
        ], $rows);
    }

    /**
     * Success/Completion Rate — نسبة المشاريع غير المسودة اللي وصلت
     * approved/published.
     * @param array{university_id?:int|string,date_from?:string,date_to?:string} $filters
     * @return array{rate:?float,successful:int,total:int}
     */
    public function successRate(array $filters = []): array
    {
        return $this->projects->successRate($filters);
    }

    public function newUsersThisMonth(): int
    {
        $series = $this->users->monthlySignups(1);
        return $series[0]['total'] ?? 0;
    }

    /**
     * نمو حقيقي عبر الزمن لكل تصنيف (تقديمات آخر $days يوم مقابل الفترة
     * اللي قبلها)، مرتبة تنازليًا حسب نسبة النمو. لو university_id
     * موجود في الفلاتر، بتحول لـ categoryGrowthForUniversity() بدل كده.
     * @param array{university_id?:int|string} $filters
     * @return array<int,array{label:array,current:int,previous:int,growth_pct:?float}>
     */
    public function categoryGrowthTrends(int $days = 30, int $limit = 6, array $filters = []): array
    {
        $rows = !empty($filters['university_id'])
            ? $this->projects->categoryGrowthForUniversity((int) $filters['university_id'], $days, $limit)
            : $this->projects->categoryGrowth($days, $limit);
        usort($rows, function ($a, $b) {
            return ($b['growth_pct'] ?? -1000) <=> ($a['growth_pct'] ?? -1000);
        });
        return array_map(fn ($r) => [
            'label'      => ['en' => $r['category'], 'ar' => $r['category_ar'] ?? $r['category']],
            'current'    => $r['current'],
            'previous'   => $r['previous'],
            'growth_pct' => $r['growth_pct'],
        ], $rows);
    }

    /**
     * خريطة نمو لكل جامعة (university_id => بيانات النمو) — لجدول
     * Innovation Statistics.
     * @param array{university_id?:int|string} $filters
     */
    public function universityGrowthMap(int $months = 6, array $filters = []): array
    {
        return $this->projects->universityGrowth($months, $filters);
    }

    /**
     * صف كروت "UIP Project Ecosystem" (Admin Dashboard §24): إجمالي/منشور/
     * قيد الانتظار + عدد كل كيان في تسلسل جامعة->كلية->قسم->طالب + عدد
     * التصنيفات المستخدمة فعليًا. كل رقم هنا COUNT بسيط، مفيش أي متريك
     * AI/مشتق — نفس اتفاقية الكلاس كله.
     * @return array{total_projects:int,published_projects:int,pending_projects:int,universities:int,faculties:int,departments:int,students:int,project_categories:int}
     */
    public function ecosystemOverview(): array
    {
        return [
            'total_projects'     => $this->projects->totalCount(),
            'published_projects' => $this->projects->countByStatus('published'),
            'pending_projects'   => $this->projects->pendingCount(),
            'universities'       => University::count(),
            'faculties'          => Faculty::count(),
            'departments'        => Department::count(),
            'students'           => Student::count(),
            'project_categories' => $this->projects->distinctCategoryCount(),
        ];
    }

    /** أكتر مشاريع منشورة مشاهدة على مستوى المنصة كلها — بانل "Most Viewed" في Admin Analytics. */
    public function mostViewedProjects(int $limit = 5): array
    {
        return $this->projects->mostViewedPlatformWide($limit);
    }

    /** أكتر مشاريع تفاعلًا (أي حدث تحليلات غير مشاهدة) على مستوى المنصة كلها — بانل "Most Interacted" في Admin Analytics. */
    public function mostInteractedProjects(int $limit = 5): array
    {
        return $this->analyticsEvents->mostInteractedPlatformWide($limit);
    }

    /** الكليات مرتبة حسب حجم المشاريع الحقيقي — بانل "Most Active Faculties" في Admin Analytics. */
    public function facultyLeaderboard(int $limit = 5): array
    {
        $rows = $this->projects->facultyProjectLeaderboard($limit);
        return array_map(fn ($r) => [
            'id'       => (int) $r['id'],
            'name'     => ['en' => $r['name_en'] ?: $r['name_ar'], 'ar' => $r['name_ar'] ?: $r['name_en']],
            'university' => $this->pairOrNull($r['university_name_en'] ?? null, $r['university_name_ar'] ?? null),
            'projects' => (int) $r['projects_count'],
        ], $rows);
    }

    /** الأقسام مرتبة حسب حجم المشاريع الحقيقي — بانل "Most Active Departments" في Admin Analytics. */
    public function departmentLeaderboard(int $limit = 5): array
    {
        $rows = $this->projects->departmentProjectLeaderboard($limit);
        return array_map(fn ($r) => [
            'id'       => (int) $r['id'],
            'name'     => ['en' => $r['name_en'] ?: $r['name_ar'], 'ar' => $r['name_ar'] ?: $r['name_en']],
            'faculty'  => $this->pairOrNull($r['faculty_name_en'] ?? null, $r['faculty_name_ar'] ?? null),
            'university' => $this->pairOrNull($r['university_name_en'] ?? null, $r['university_name_ar'] ?? null),
            'projects' => (int) $r['projects_count'],
        ], $rows);
    }

    /** زوج {en, ar} للاسم، أو null لو الاتنين فاضيين (كلية/قسم من غير جامعة مربوطة). */
    private function pairOrNull(?string $en, ?string $ar): ?array
    {
        if (!$en && !$ar) {
            return null;
        }
        return ['en' => $en ?: $ar, 'ar' => $ar ?: $en];
    }
}
