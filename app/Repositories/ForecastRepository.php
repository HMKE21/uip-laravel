<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ForecastRepository.php القديمة — بند 24
 * batch 3 (Forecasting، enhancement spec section 5). مفيش جداول جديدة:
 * كل سلسلة زمنية هنا aggregate حي فوق جداول موجودة بالفعل ومتغذية
 * ببيانات منصة حقيقية (users/user_roles/roles، projects، universities،
 * ai_readiness_scores) — نفس اتفاقية "حي، مش
 * مخزّن مؤقتًا، مفيش بيانات تجريبية" بتاعة DataQualityRepository/
 * DataExplorerRepository. ForecastingService بيحوّل السلاسل دي لتوقعات؛
 * الكلاس ده بس بيقرر اللي حصل فعلًا.
 */
class ForecastRepository
{
    /**
     * بتملى {month=>total} sparse map في سلسلة شهرية متصلة، الأقدم
     * أولًا، تغطي بالظبط آخر $months شهر تقويمي (شامل الشهر الحالي) —
     * نفس اتفاقية UserRepository::monthlySignups().
     * @param array<int,array{month:string,total:int|float}> $rows
     * @return array<int,array{month:string,value:float}>
     */
    private function zeroFill(array $rows, int $months): array
    {
        $byMonth = array_column($rows, 'total', 'month');
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("-{$i} months"));
            $series[] = ['month' => $key, 'value' => (float) ($byMonth[$key] ?? 0)];
        }
        return $series;
    }

    private function since(int $months): string
    {
        return date('Y-m-d', strtotime('-' . ($months - 1) . ' months'));
    }

    /** طلاب جدد اتسجلوا كل شهر. */
    public function studentGrowth(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(u.created_at, '%Y-%m') AS month, COUNT(*) AS total
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'student'
              WHERE u.deleted_at IS NULL AND u.created_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        return $this->zeroFill(array_map(fn ($r) => (array) $r, $rows), $months);
    }

    /** كل المشاريع المُقدَّمة كل شهر (بأي حالة)، على مستوى المنصة كلها. */
    public function projectGrowth(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total
               FROM projects
              WHERE deleted_at IS NULL AND created_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        return $this->zeroFill(array_map(fn ($r) => (array) $r, $rows), $months);
    }

    /** المشاريع المنشورة فعليًا كل شهر — سرعة "الابتكار وصل للمنصة" الحقيقية، مختلفة عن عدد التقديمات الخام. */
    public function innovationTrends(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(published_at, '%Y-%m') AS month, COUNT(*) AS total
               FROM projects
              WHERE deleted_at IS NULL AND published_at IS NOT NULL
                AND published_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        return $this->zeroFill(array_map(fn ($r) => (array) $r, $rows), $months);
    }

    /** جامعات وصلت لحالة موثّقة كل شهر. */
    public function universityPerformance(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(verified_at, '%Y-%m') AS month, COUNT(*) AS total
               FROM universities
              WHERE verification_status = 'verified' AND verified_at IS NOT NULL
                AND verified_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        return $this->zeroFill(array_map(fn ($r) => (array) $r, $rows), $months);
    }

    /**
     * متوسط overall_score لجاهزية الذكاء الاصطناعي كل شهر، فوق صفوف
     * ai_readiness_scores حقيقية (مش تجريبية). كل صف بيتعمله upsert مرة
     * واحدة لكل مشروع عند أول تحليل AI (App\Services\AIReadinessScoreService)،
     * فـ created_at تاريخ حقيقي "أول مرة اتقيّم فيها المشروع ده".
     */
    public function aiReadinessTrends(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, AVG(overall_score) AS total
               FROM ai_readiness_scores
              WHERE is_demo_data = 0 AND created_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        $rows = array_map(fn ($r) => ['month' => $r->month, 'total' => round((float) $r->total, 1)], $rows);
        return $this->zeroFill($rows, $months);
    }

    /**
     * نسبة الحضور الشهرية (0-100): (present + late) ÷ (present + late +
     * absent) على attendance_records، حسب شهر فتح التحضير
     * (attendance_windows.opens_at). excused بره المقام — غياب بعذر مش
     * "ضعف حضور". الشهور اللي مفيهاش أي تحضير بتتشال من السلسلة بدل ما
     * تتملى بصفر: 0% في شهر إجازة معناه "ماحدش حضر" وده غلط، والمطلوب
     * "مفيش بيانات". فالسلسلة ممكن تبقى أقصر من $months، وده طبيعي.
     */
    public function attendanceRate(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(w.opens_at, '%Y-%m') AS month,
                    SUM(CASE WHEN r.status IN ('present','late') THEN 1 ELSE 0 END) AS attended,
                    SUM(CASE WHEN r.status IN ('present','late','absent') THEN 1 ELSE 0 END) AS counted
               FROM attendance_records r
               JOIN attendance_windows w ON w.id = r.window_id
              WHERE w.opens_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );

        $series = [];
        foreach ($rows as $r) {
            if ((int) $r->counted <= 0) {
                continue;
            }
            $series[] = ['month' => $r->month, 'value' => round(((int) $r->attended / (int) $r->counted) * 100, 1)];
        }
        return $series;
    }

    /** تسجيلات الطلاب في المواد كل شهر (course_enrollments، بتاريخ enrolled_at أو created_at لو فاضي). */
    public function enrollmentTrends(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(COALESCE(enrolled_at, created_at), '%Y-%m') AS month, COUNT(*) AS total
               FROM course_enrollments
              WHERE COALESCE(enrolled_at, created_at) >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        return $this->zeroFill(array_map(fn ($r) => (array) $r, $rows), $months);
    }

    /** إجمالي المصاريف المحصَّلة فعليًا كل شهر: fee_payments المؤكَّدة بس (مش pending ولا rejected). */
    public function feeCollection(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(COALESCE(decided_at, created_at), '%Y-%m') AS month, SUM(amount) AS total
               FROM fee_payments
              WHERE status = 'confirmed' AND COALESCE(decided_at, created_at) >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        $rows = array_map(fn ($r) => ['month' => $r->month, 'total' => round((float) $r->total, 2)], $rows);
        return $this->zeroFill($rows, $months);
    }

    /**
     * النشاط: عدد المستخدمين النشطين (مميّزين) كل شهر من
     * user_activity_events. ملحوظة: الجدول ده بيتقصّ بأمر activity:prune
     * فالتاريخ بيقف عند فترة الاحتفاظ — الشهور الأقدم منها هتبان صفر.
     */
    public function activeUsers(int $months = 12): array
    {
        $rows = DB::select(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m') AS month, COUNT(DISTINCT user_id) AS total
               FROM user_activity_events
              WHERE occurred_at >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [$this->since($months)]
        );
        return $this->zeroFill(array_map(fn ($r) => (array) $r, $rows), $months);
    }
}
