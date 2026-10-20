<?php

namespace App\Services;

use App\Repositories\SavedDashboardRepository;

/**
 * منقولة كاملة من app/Services/SavedDashboardService.php القديمة —
 * بند 24 batch 1 (Saved Dashboards management، enhancement spec section 9).
 * CRUD كامل + تنسيق تخطيط الـ widgets (drag & drop) فوق
 * SavedDashboardRepository. كتالوج الـ WIDGETS تحت ده بيستخدم نفس
 * ميثودز AnalyticsService الحقيقية — نفس مصدر الحقيقة الوحيد اللي بيغذي
 * Data Analysis Dashboard الرئيسي (DataAnalysisDashboardService::overview()).
 * مفيش widget هنا بيرجع أرقام مختلقة؛ كله استعلام حي على جداول حقيقية.
 */
class SavedDashboardService
{
    /** نوع الـ widget => [عنوان انجليزي، عنوان عربي، أيقونة]. layout الـ JSON بيخزن المفاتيح دي بس. */
    public const WIDGETS = [
        'kpis'           => ['en' => 'Platform KPIs',          'ar' => 'مؤشرات المنصة',          'icon' => 'award'],
        'user_growth'    => ['en' => 'User Growth (chart)',    'ar' => 'نمو المستخدمين (رسم بياني)', 'icon' => 'trend'],
        'users_by_role'  => ['en' => 'Users by Role (chart)',  'ar' => 'المستخدمون حسب الدور',    'icon' => 'users'],
        'category_dist'  => ['en' => 'Category Distribution',  'ar' => 'توزيع المجالات',          'icon' => 'layers'],
        'leaderboard'    => ['en' => 'University Leaderboard', 'ar' => 'ترتيب الجامعات',           'icon' => 'building'],
        'trends'         => ['en' => 'Growth Trends (30d)',    'ar' => 'اتجاهات النمو',            'icon' => 'chevron-down'],
        'university_map' => ['en' => 'University Growth Map',  'ar' => 'خريطة نمو الجامعات',        'icon' => 'grid'],
        // منصة الامتحانات / الدكاترة / الطلاب (DataAnalysisAcademicService)
        'academic_kpis'      => ['en' => 'Academic Overview',        'ar' => 'نظرة أكاديمية عامة',      'icon' => 'building'],
        'exam_kpis'          => ['en' => 'Exam KPIs',                'ar' => 'مؤشرات الامتحانات',       'icon' => 'note'],
        'exam_status'        => ['en' => 'Attempts by Status',       'ar' => 'المحاولات حسب الحالة',    'icon' => 'check-circle'],
        'score_distribution' => ['en' => 'Score Distribution',       'ar' => 'توزيع الدرجات',           'icon' => 'bar-chart'],
        'exam_trend'         => ['en' => 'Average Score Trend',      'ar' => 'اتجاه متوسط الدرجات',     'icon' => 'trend'],
        'top_doctors'        => ['en' => 'Top Doctors (exams)',      'ar' => 'أكثر الدكاترة نشاطًا',    'icon' => 'briefcase'],
        'students_at_risk'   => ['en' => 'Students at Risk',         'ar' => 'الطلاب المعرّضون للخطر', 'icon' => 'alert-triangle'],
        'exam_integrity'     => ['en' => 'Exam Integrity & Appeals', 'ar' => 'النزاهة والتظلمات',       'icon' => 'shield'],
    ];

    private const ACADEMIC_WIDGETS = ['academic_kpis', 'exam_kpis', 'exam_status', 'score_distribution', 'exam_trend', 'top_doctors', 'students_at_risk', 'exam_integrity'];

    /** تخطيطات جاهزة لقائمة "Templates" في الـ builder — اختيار widgets بس، بدون بيانات. */
    public const TEMPLATES = [
        'overview' => [
            'en'      => 'Executive Overview',
            'ar'      => 'نظرة تنفيذية عامة',
            'widgets' => ['kpis', 'user_growth', 'leaderboard'],
        ],
        'growth' => [
            'en'      => 'Growth Focus',
            'ar'      => 'التركيز على النمو',
            'widgets' => ['user_growth', 'trends', 'university_map'],
        ],
        'composition' => [
            'en'      => 'Platform Composition',
            'ar'      => 'تركيبة المنصة',
            'widgets' => ['kpis', 'users_by_role', 'category_dist'],
        ],
        'exams' => [
            'en'      => 'Exams & Results',
            'ar'      => 'الامتحانات والنتائج',
            'widgets' => ['exam_kpis', 'exam_status', 'score_distribution', 'exam_trend'],
        ],
        'academic' => [
            'en'      => 'Doctors & Students',
            'ar'      => 'الدكاترة والطلاب',
            'widgets' => ['academic_kpis', 'top_doctors', 'students_at_risk', 'exam_integrity'],
        ],
        'full' => [
            'en'      => 'Everything',
            'ar'      => 'كل شيء',
            'widgets' => ['kpis', 'user_growth', 'users_by_role', 'category_dist', 'leaderboard', 'trends', 'university_map',
                'academic_kpis', 'exam_kpis', 'exam_status', 'score_distribution', 'exam_trend', 'top_doctors', 'students_at_risk', 'exam_integrity'],
        ],
    ];

    public function __construct(
        private AnalyticsService $analytics,
        private SavedDashboardRepository $repo,
        private DataAnalysisAcademicService $academic
    ) {
    }

    public function listFor($userId): array
    {
        return $this->repo->forUser($userId);
    }

    public function archivedFor($userId): array
    {
        return $this->repo->archivedForUser($userId);
    }

    public function sharedByOthers($userId): array
    {
        return $this->repo->sharedByOthers($userId);
    }

    public function find(int $id): ?array
    {
        return $this->repo->find($id);
    }

    public function canView(array $dashboard, $userId): bool
    {
        return $this->repo->isViewableBy($dashboard, $userId);
    }

    public function create($userId, string $name, array $layout, bool $isDefault, bool $isShared): int
    {
        $dashboard = $this->repo->create($userId, $name, $this->sanitizeLayout($layout), $isDefault, $isShared);
        return (int) $dashboard->id;
    }

    public function update(int $id, $userId, string $name, array $layout, bool $isDefault): bool
    {
        return $this->repo->update($id, $userId, [
            'name'       => $name,
            'layout'     => $this->sanitizeLayout($layout),
            'is_default' => $isDefault ? 1 : 0,
        ]);
    }

    public function duplicate(int $id, $userId, string $newName): ?int
    {
        $d = $this->repo->duplicate($id, $userId, $newName);
        return $d ? (int) $d->id : null;
    }

    public function toggleShare(int $id, $userId): ?bool
    {
        return $this->repo->toggleShare($id, $userId);
    }

    public function archive(int $id, $userId): bool
    {
        return $this->repo->setArchived($id, $userId, true);
    }

    public function unarchive(int $id, $userId): bool
    {
        return $this->repo->setArchived($id, $userId, false);
    }

    public function delete(int $id, $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }

    /** بيرمي أي widget type مش موجود في الكتالوج الحقيقي وأي عنصر مشوّه. */
    public function sanitizeLayout(array $layout): array
    {
        $clean = [];
        foreach ($layout as $widget) {
            if (!is_array($widget) || empty($widget['type']) || !isset(self::WIDGETS[$widget['type']])) {
                continue;
            }
            $size = $widget['size'] ?? 'md';
            if (!in_array($size, ['sm', 'md', 'lg'], true)) {
                $size = 'md';
            }
            $clean[] = ['type' => $widget['type'], 'size' => $size];
        }
        return $clean;
    }

    /**
     * بتحقن تخطيط محفوظ ببيانات حية لكل widget — بالظبط اللي
     * DataAnalysisDashboardService::overview() بيحسبه، بس بتتجاب مرة
     * واحدة في الريكوست وتتقسم على كل widget عشان لوحة فيها 3 widgets
     * متشغلش استعلامات لباقي الـ 4.
     */
    public function hydrate(array $layout): array
    {
        $needed = array_column($layout, 'type');
        $data = [];

        if (in_array('kpis', $needed, true)) {
            $data['kpis'] = $this->analytics->platformOverview();
        }
        if (in_array('user_growth', $needed, true)) {
            $data['user_growth'] = $this->analytics->userGrowthSeries(6);
        }
        if (in_array('users_by_role', $needed, true)) {
            $data['users_by_role'] = $this->analytics->usersByRoleSeries();
        }
        if (in_array('category_dist', $needed, true)) {
            $data['category_dist'] = $this->analytics->categoryDistribution(8);
        }
        if (in_array('leaderboard', $needed, true)) {
            $data['leaderboard'] = $this->analytics->universityLeaderboard(5);
        }
        if (in_array('trends', $needed, true)) {
            $data['trends'] = $this->analytics->categoryGrowthTrends(30, 6);
        }
        if (in_array('university_map', $needed, true)) {
            $data['university_map'] = $this->analytics->universityGrowthMap(6);
        }

        foreach ($needed as $type) {
            if (isset(self::WIDGETS[$type]) && !array_key_exists($type, $data) && in_array($type, self::ACADEMIC_WIDGETS, true)) {
                $data[$type] = $this->academic->widget($type);
            }
        }

        $widgets = [];
        foreach ($layout as $w) {
            $widgets[] = ['type' => $w['type'], 'size' => $w['size'] ?? 'md', 'data' => $data[$w['type']] ?? null];
        }
        return $widgets;
    }
}
