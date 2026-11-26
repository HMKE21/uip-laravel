<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بند 1 (الناقص للمحلل) — أقسام الداشبورد الإضافية: المقررات، الحضور،
 * الرسوم، التخرج، النشاط، + ويدجتس المشاريع والامتحانات العميقة.
 *
 * مفيش أرقام مختلقة: كل قسم COUNT/SUM/GROUP BY على الجداول الحقيقية
 * (courses / course_enrollments / attendance_* / student_fees /
 * fee_payments / graduation_records / user_activity_events /
 * login_events) أو kpis جاهزة من DataAnalysisProjectsDeepService /
 * DataAnalysisExamsDeepService (مصدر وحيد للحقيقة، نفس أرقام صفحات
 * Project Insights وExam Deep). أي قسم بيفشل (جدول ناقص مثلًا) بيرجع
 * null بدل ما يوقّع الداشبورد، وكل قسم متخزن دقايق قليلة.
 *
 * بيتنادى عليه من:
 *  - GET /api/v1/data-analysis/dashboard/extras (الداشبورد الرئيسي)
 *  - SavedDashboardService::hydrate() عبر widget() (اللوحات المحفوظة)
 */
class DataAnalysisDashboardExtrasService
{
    public const SECTIONS = ['courses', 'attendance', 'fees', 'graduation', 'activity', 'projects', 'exams'];

    private const CACHE_TTL = 300;
    private const DAYS = 30;          // نافذة قسمي المشاريع والامتحانات
    private const ACTIVITY_DAYS = 14; // نافذة قسم النشاط

    private const ATTENDANCE_LABELS = [
        'present' => ['en' => 'Present', 'ar' => 'حاضر'],
        'late'    => ['en' => 'Late',    'ar' => 'متأخر'],
        'absent'  => ['en' => 'Absent',  'ar' => 'غائب'],
        'excused' => ['en' => 'Excused', 'ar' => 'بعذر'],
    ];

    private const FEE_STATUS_LABELS = [
        'paid'    => ['en' => 'Paid',    'ar' => 'مدفوع'],
        'partial' => ['en' => 'Partial', 'ar' => 'مدفوع جزئيًا'],
        'unpaid'  => ['en' => 'Unpaid',  'ar' => 'غير مدفوع'],
        'waived'  => ['en' => 'Waived',  'ar' => 'معفى'],
    ];

    private const FEE_KIND_LABELS = [
        'tuition'      => ['en' => 'Tuition',      'ar' => 'مصروفات دراسية'],
        'registration' => ['en' => 'Registration', 'ar' => 'رسوم تسجيل'],
        'service'      => ['en' => 'Service',      'ar' => 'خدمات'],
        'other'        => ['en' => 'Other',        'ar' => 'أخرى'],
    ];

    public function __construct(
        private DataAnalysisProjectsDeepService $projects,
        private DataAnalysisExamsDeepService $exams
    ) {
    }

    /** كل الأقسام في مفتاح لكل قسم (null لو القسم فشل). */
    public function overview(): array
    {
        $out = [];
        foreach (self::SECTIONS as $key) {
            $out[$key] = $this->section($key);
        }
        return $out;
    }

    public function section(string $key): ?array
    {
        if (!in_array($key, self::SECTIONS, true)) {
            return null;
        }

        return Cache::remember('data_analysis:dashboard_extra:' . $key, self::CACHE_TTL, function () use ($key) {
            try {
                return $this->{$key}();
            } catch (\Throwable $e) {
                report($e);
                return null;
            }
        });
    }

    /**
     * بيانات widget واحد للوحة المحفوظة: object مسطّح (KPIs) أو سلسلة
     * [{label:{en,ar},value}] — نفس الشكلين اللي بيعرضهم WidgetPreview.
     */
    public function widget(string $type)
    {
        $map = [
            'courses_kpis'             => ['courses',    fn ($s) => $s['kpis']],
            'course_enrollment_trend'  => ['courses',    fn ($s) => $s['enrollment_trend']],
            'attendance_kpis'          => ['attendance', fn ($s) => $s['kpis']],
            'attendance_status'        => ['attendance', fn ($s) => $s['by_status']],
            'fees_kpis'                => ['fees',       fn ($s) => $s['kpis']],
            'fees_status'              => ['fees',       fn ($s) => $s['by_status']],
            'graduation_kpis'          => ['graduation', fn ($s) => $s['kpis']],
            'graduation_by_year'       => ['graduation', fn ($s) => $s['by_year']],
            'activity_kpis'            => ['activity',   fn ($s) => $s['kpis']],
            'activity_trend'           => ['activity',   fn ($s) => $s['daily']],
            'project_approvals_kpis'   => ['projects',   fn ($s) => $s['approvals']],
            'project_grading_kpis'     => ['projects',   fn ($s) => $s['grading']],
            'project_team_kpis'        => ['projects',   fn ($s) => $s['team']],
            'exam_ai_kpis'             => ['exams',      fn ($s) => $s['ai']],
            'exam_proctoring_kpis'     => ['exams',      fn ($s) => $s['proctoring']],
        ];

        if (!isset($map[$type])) {
            return null;
        }
        [$section, $pick] = $map[$type];
        $data = $this->section($section);
        if ($data === null) {
            return null;
        }
        try {
            return $pick($data);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    // -----------------------------------------------------------------
    // المقررات
    // -----------------------------------------------------------------

    private function courses(): array
    {
        $total = (int) DB::table('courses')->count();
        $active = (int) DB::table('courses')->where('status', 'active')->count();

        $enrollments = Schema::hasTable('course_enrollments') ? (int) DB::table('course_enrollments')->count() : 0;
        $enrolledStudents = Schema::hasTable('course_enrollments')
            ? (int) DB::table('course_enrollments')->distinct()->count('student_id') : 0;

        $published = null;
        $avgPct = null;
        $failRate = null;
        if (Schema::hasTable('course_final_grades')) {
            $g = fn () => DB::table('course_final_grades')->where('status', 'published');
            $published = (int) $g()->count();
            $avgPct = $published ? round((float) $g()->avg('percentage'), 1) : null;
            $failRate = $published ? round($g()->where('is_fail', 1)->count() / $published * 100, 1) : null;
        }

        $materials = Schema::hasTable('course_materials')
            ? (int) DB::table('course_materials')->where('status', 'published')->count() : null;
        $videos = Schema::hasTable('course_videos')
            ? (int) DB::table('course_videos')->where('status', 'published')->count() : null;

        $top = [];
        if (Schema::hasTable('course_enrollments')) {
            $top = DB::table('course_enrollments as e')
                ->join('courses as c', 'c.id', '=', 'e.course_id')
                ->select('c.id', 'c.code', 'c.name_en', 'c.name_ar', DB::raw('COUNT(*) as n'))
                ->groupBy('c.id', 'c.code', 'c.name_en', 'c.name_ar')
                ->orderByDesc('n')->orderBy('c.id')->limit(5)->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id, 'code' => $r->code,
                    'name' => ['en' => $r->name_en ?: $r->name_ar, 'ar' => $r->name_ar ?: $r->name_en],
                    'enrollments' => (int) $r->n,
                ])->all();
        }

        $trend = [];
        if (Schema::hasTable('course_enrollments')) {
            $months = $this->monthKeys(6);
            $rows = DB::table('course_enrollments')
                ->where(fn ($w) => $w->where('enrolled_at', '>=', $months[0] . '-01 00:00:00')
                    ->orWhere(fn ($x) => $x->whereNull('enrolled_at')->where('created_at', '>=', $months[0] . '-01 00:00:00')))
                ->select(DB::raw('SUBSTR(COALESCE(enrolled_at, created_at), 1, 7) as m'), DB::raw('COUNT(*) as c'))
                ->groupBy(DB::raw('SUBSTR(COALESCE(enrolled_at, created_at), 1, 7)'))
                ->pluck('c', 'm')->all();
            $trend = $this->monthSeries($months, $rows);
        }

        return [
            'kpis' => [
                'courses'            => $total,
                'active_courses'     => $active,
                'enrollments'        => $enrollments,
                'enrolled_students'  => $enrolledStudents,
                'avg_per_course'     => $total ? round($enrollments / $total, 1) : null,
                'published_grades'   => $published,
                'avg_final_pct'      => $avgPct,
                'fail_rate'          => $failRate,
                'materials'          => $materials,
                'videos'             => $videos,
            ],
            'top_courses'      => $top,
            'enrollment_trend' => $trend,
        ];
    }

    // -----------------------------------------------------------------
    // الحضور
    // -----------------------------------------------------------------

    private function attendance(): array
    {
        $windows = (int) DB::table('attendance_windows')->count();
        $open = (int) DB::table('attendance_windows')->where('status', 'open')->count();

        $counts = DB::table('attendance_records')->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $present = $counts['present'] ?? 0;
        $late = $counts['late'] ?? 0;
        $absent = $counts['absent'] ?? 0;
        $excused = $counts['excused'] ?? 0;
        $counted = $present + $late + $absent; // المعذور مش بيتحسب ضد الطالب

        $byStatus = [];
        foreach (self::ATTENDANCE_LABELS as $k => $label) {
            $byStatus[] = ['label' => $label, 'value' => (int) ($counts[$k] ?? 0)];
        }

        // اتجاه أسبوعي (آخر 8 أسابيع) — التجميع في PHP عشان يشتغل على أي DB.
        $since = Carbon::today()->subDays(55)->startOfWeek();
        $rows = DB::table('attendance_records')
            ->where('created_at', '>=', $since->toDateTimeString())
            ->select(DB::raw('DATE(created_at) as d'), 'status', DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw('DATE(created_at)'), 'status')->get();
        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $weeks[Carbon::today()->startOfWeek()->subWeeks($i)->toDateString()] = ['ok' => 0, 'all' => 0];
        }
        foreach ($rows as $r) {
            $wk = Carbon::parse($r->d)->startOfWeek()->toDateString();
            if (!isset($weeks[$wk]) || $r->status === 'excused') {
                continue;
            }
            $weeks[$wk]['all'] += (int) $r->c;
            if (in_array($r->status, ['present', 'late'], true)) {
                $weeks[$wk]['ok'] += (int) $r->c;
            }
        }
        $weekly = [];
        foreach ($weeks as $d => $w) {
            $weekly[] = ['label' => ['en' => $d, 'ar' => $d], 'value' => $w['all'] ? round($w['ok'] / $w['all'] * 100, 1) : null];
        }

        // أضعف المقررات حضورًا (بحد أدنى 10 سجلات عشان النسبة تبقى ليها معنى).
        $lowest = DB::table('attendance_records as r')
            ->join('courses as c', 'c.id', '=', 'r.course_id')
            ->where('r.status', '!=', 'excused')
            ->select(
                'c.id', 'c.code', 'c.name_en', 'c.name_ar',
                DB::raw('COUNT(*) as n'),
                DB::raw("SUM(CASE WHEN r.status IN ('present','late') THEN 1 ELSE 0 END) as ok")
            )
            ->groupBy('c.id', 'c.code', 'c.name_en', 'c.name_ar')
            ->havingRaw('COUNT(*) >= 10')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'code' => $r->code,
                'name' => ['en' => $r->name_en ?: $r->name_ar, 'ar' => $r->name_ar ?: $r->name_en],
                'records' => (int) $r->n,
                'rate' => round(((int) $r->ok) / ((int) $r->n) * 100, 1),
            ])
            ->sortBy('rate')->take(5)->values()->all();

        return [
            'kpis' => [
                'windows'         => $windows,
                'open_windows'    => $open,
                'records'         => $present + $late + $absent + $excused,
                'attendance_rate' => $counted ? round(($present + $late) / $counted * 100, 1) : null,
                'present'         => $present,
                'late'            => $late,
                'absent'          => $absent,
                'excused'         => $excused,
            ],
            'by_status'      => $byStatus,
            'weekly_rate'    => $weekly,
            'lowest_courses' => $lowest,
        ];
    }

    // -----------------------------------------------------------------
    // الرسوم
    // -----------------------------------------------------------------

    private function fees(): array
    {
        $t = DB::table('student_fees')->selectRaw(
            "COUNT(*) as n,
             SUM(CASE WHEN status != 'waived' THEN amount ELSE 0 END) as due,
             SUM(CASE WHEN status != 'waived' THEN paid_amount ELSE 0 END) as paid"
        )->first();
        $due = (float) ($t->due ?? 0);
        $paid = (float) ($t->paid ?? 0);

        $today = Carbon::today()->toDateString();
        $overdue = DB::table('student_fees')
            ->whereIn('status', ['unpaid', 'partial'])->whereNotNull('due_on')->where('due_on', '<', $today)
            ->selectRaw('COUNT(*) as n, SUM(amount - paid_amount) as b')->first();

        $pending = Schema::hasTable('fee_payments')
            ? (int) DB::table('fee_payments')->where('status', 'pending')->count() : null;

        $counts = DB::table('student_fees')->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $byStatus = [];
        foreach (self::FEE_STATUS_LABELS as $k => $label) {
            $byStatus[] = ['label' => $label, 'value' => (int) ($counts[$k] ?? 0)];
        }

        $byKind = DB::table('student_fees as f')
            ->join('fee_items as i', 'i.id', '=', 'f.fee_item_id')
            ->where('f.status', '!=', 'waived')
            ->select('i.kind', DB::raw('SUM(f.amount) as due'), DB::raw('SUM(f.paid_amount) as paid'))
            ->groupBy('i.kind')->orderByDesc('due')->get()
            ->map(fn ($r) => [
                'label' => self::FEE_KIND_LABELS[$r->kind] ?? ['en' => (string) $r->kind, 'ar' => (string) $r->kind],
                'due'   => round((float) $r->due, 2),
                'paid'  => round((float) $r->paid, 2),
            ])->all();

        $collected = [];
        if (Schema::hasTable('fee_payments')) {
            $months = $this->monthKeys(6);
            $rows = DB::table('fee_payments')->where('status', 'confirmed')
                ->where(fn ($w) => $w->where('decided_at', '>=', $months[0] . '-01 00:00:00')
                    ->orWhere(fn ($x) => $x->whereNull('decided_at')->where('created_at', '>=', $months[0] . '-01 00:00:00')))
                ->select(DB::raw('SUBSTR(COALESCE(decided_at, created_at), 1, 7) as m'), DB::raw('SUM(amount) as s'))
                ->groupBy(DB::raw('SUBSTR(COALESCE(decided_at, created_at), 1, 7)'))
                ->pluck('s', 'm')->map(fn ($v) => round((float) $v, 2))->all();
            $collected = $this->monthSeries($months, $rows);
        }

        $currencies = DB::table('fee_items')->select('currency', DB::raw('COUNT(*) as c'))
            ->groupBy('currency')->orderByDesc('c')->pluck('c', 'currency')->all();

        return [
            'kpis' => [
                'fees_issued'      => (int) ($t->n ?? 0),
                'total_due'        => round($due, 2),
                'total_paid'       => round($paid, 2),
                'outstanding'      => round(max(0, $due - $paid), 2),
                'collection_rate'  => $due > 0 ? round($paid / $due * 100, 1) : null,
                'overdue_count'    => (int) ($overdue->n ?? 0),
                'overdue_amount'   => round((float) ($overdue->b ?? 0), 2),
                'pending_payments' => $pending,
                'currency'         => $currencies ? (string) array_key_first($currencies) : 'EGP',
                'mixed_currency'   => count($currencies) > 1,
            ],
            'by_status'         => $byStatus,
            'by_kind'           => $byKind,
            'collected_monthly' => $collected,
        ];
    }

    // -----------------------------------------------------------------
    // التخرج
    // -----------------------------------------------------------------

    private function graduation(): array
    {
        $g = fn () => DB::table('graduation_records')->where('status', 'graduated');
        $year = (int) date('Y');

        $years = [];
        for ($y = $year - 4; $y <= $year; $y++) {
            $years[(string) $y] = 0;
        }
        $rows = $g()->where('graduation_date', '>=', ($year - 4) . '-01-01')
            ->select(DB::raw('SUBSTR(graduation_date, 1, 4) as y'), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw('SUBSTR(graduation_date, 1, 4)'))->pluck('c', 'y')->all();
        $byYear = [];
        foreach ($years as $y => $_) {
            $byYear[] = ['label' => ['en' => $y, 'ar' => $y], 'value' => (int) ($rows[$y] ?? 0)];
        }

        $top = DB::table('graduation_records as r')
            ->join('universities as u', 'u.id', '=', 'r.university_id')
            ->where('r.status', 'graduated')
            ->select('u.id', 'u.official_name_en', 'u.official_name_ar', DB::raw('COUNT(*) as n'))
            ->groupBy('u.id', 'u.official_name_en', 'u.official_name_ar')
            ->orderByDesc('n')->orderBy('u.id')->limit(5)->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'university' => ['en' => $r->official_name_en ?: $r->official_name_ar, 'ar' => $r->official_name_ar ?: $r->official_name_en],
                'graduates' => (int) $r->n,
            ])->all();

        $graduated = (int) $g()->count();
        $avgGpa = $g()->whereNotNull('final_gpa')->avg('final_gpa');

        return [
            'kpis' => [
                'graduates'     => $graduated,
                'this_year'     => (int) $g()->where('graduation_date', '>=', $year . '-01-01')->where('graduation_date', '<=', $year . '-12-31')->count(),
                'revoked'       => (int) DB::table('graduation_records')->where('status', 'revoked')->count(),
                'avg_gpa'       => $avgGpa !== null ? round((float) $avgGpa, 2) : null,
                'universities'  => (int) $g()->distinct()->count('university_id'),
            ],
            'by_year'          => $byYear,
            'top_universities' => $top,
        ];
    }

    // -----------------------------------------------------------------
    // النشاط (user_activity_events + login_events)
    // -----------------------------------------------------------------

    private function activity(): array
    {
        $from = Carbon::today()->subDays(self::ACTIVITY_DAYS - 1);
        $start = $from->toDateString() . ' 00:00:00';
        $end = Carbon::today()->toDateString() . ' 23:59:59';

        $kpis = [
            'days' => self::ACTIVITY_DAYS,
            'events' => null, 'active_users' => null, 'page_views' => null, 'errors' => null, 'error_rate' => null,
            'logins_success' => null, 'logins_failed' => null,
        ];
        $daily = [];
        $topPages = [];

        if (Schema::hasTable('user_activity_events')) {
            $base = fn () => DB::table('user_activity_events')->whereBetween('occurred_at', [$start, $end]);
            $events = (int) $base()->count();
            $errors = (int) $base()->whereIn('type', ['api_error', 'network_error', 'js_error'])->count();
            $kpis['events'] = $events;
            $kpis['active_users'] = (int) $base()->distinct()->count('user_id');
            $kpis['page_views'] = (int) $base()->where('type', 'page_view')->count();
            $kpis['errors'] = $errors;
            $kpis['error_rate'] = $events ? round($errors / $events * 100, 2) : null;

            $map = $base()->select(DB::raw('DATE(occurred_at) as d'), DB::raw('COUNT(*) as c'))
                ->groupBy(DB::raw('DATE(occurred_at)'))->pluck('c', 'd')->all();
            for ($i = 0; $i < self::ACTIVITY_DAYS; $i++) {
                $d = $from->copy()->addDays($i)->toDateString();
                $daily[] = ['label' => ['en' => $d, 'ar' => $d], 'value' => (int) ($map[$d] ?? 0)];
            }

            $topPages = $base()->where('type', 'page_view')->whereNotNull('path')
                ->select('path', DB::raw('COUNT(*) as c'), DB::raw('COUNT(DISTINCT user_id) as users'))
                ->groupBy('path')->orderByDesc('c')->limit(5)->get()
                ->map(fn ($x) => ['path' => $x->path, 'views' => (int) $x->c, 'users' => (int) $x->users])->all();
        }

        if (Schema::hasTable('login_events')) {
            $l = fn () => DB::table('login_events')->whereBetween('created_at', [$start, $end]);
            $kpis['logins_success'] = (int) $l()->where('success', 1)->count();
            $kpis['logins_failed'] = (int) $l()->where('success', 0)->count();
        }

        return ['kpis' => $kpis, 'daily' => $daily, 'top_pages' => $topPages];
    }

    // -----------------------------------------------------------------
    // المشاريع والامتحانات (kpis من السيرفيسات العميقة، آخر 30 يوم)
    // -----------------------------------------------------------------

    private function projects(): array
    {
        $f = $this->window();
        return [
            'days'      => self::DAYS,
            'approvals' => $this->deepKpis(fn () => $this->projects->section('approvals', $f),
                ['reviews', 'pending', 'approved', 'rejected', 'approval_rate', 'avg_decision_hours']),
            'grading'   => $this->deepKpis(fn () => $this->projects->section('grading', $f),
                ['grades', 'final', 'projects_graded', 'avg_pct', 'pass_rate']),
            'team'      => $this->deepKpis(fn () => $this->projects->section('team', $f),
                ['invitations', 'accepted', 'accept_rate', 'projects_without_team', 'avg_team_size']),
            'deadlines' => $this->deepKpis(fn () => $this->projects->section('deadlines', $f),
                ['deadlines', 'active', 'upcoming_submissions', 'defense_upcoming', 'without_deadline']),
        ];
    }

    private function exams(): array
    {
        $f = $this->window();
        return [
            'days'       => self::DAYS,
            'ai'         => $this->deepKpis(fn () => $this->exams->section('ai', $f),
                ['gradings', 'completed', 'failure_rate', 'avg_confidence', 'agreement_rate']),
            'proctoring' => $this->deepKpis(fn () => $this->exams->section('proctoring', $f),
                ['snapshots', 'flagged_snapshots', 'flag_rate', 'students_covered']),
            'grading'    => $this->deepKpis(fn () => $this->exams->section('grading', $f),
                ['grades_changed', 'attempts_affected', 'manual_share', 'avg_delta']),
        ];
    }

    /** kpis مفلترة بمفاتيح معروفة (القيمة الناقصة null)، أو null لو القسم مش متاح/فشل. */
    private function deepKpis(callable $load, array $keys): ?array
    {
        try {
            $s = $load();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
        if (!is_array($s) || !($s['available'] ?? false)) {
            return null;
        }
        $k = is_array($s['kpis'] ?? null) ? $s['kpis'] : [];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $k[$key] ?? null;
        }
        return $out;
    }

    private function window(): array
    {
        return [
            'from' => Carbon::today()->subDays(self::DAYS - 1)->toDateString(),
            'to'   => Carbon::today()->toDateString(),
        ];
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** @return string[] آخر $n شهور (Y-m) من الأقدم للأحدث. */
    private function monthKeys(int $n): array
    {
        $out = [];
        $m = Carbon::today()->startOfMonth()->subMonths($n - 1);
        for ($i = 0; $i < $n; $i++) {
            $out[] = $m->copy()->addMonths($i)->format('Y-m');
        }
        return $out;
    }

    /** @return array<int,array{label:array{en:string,ar:string},value:int|float}> */
    private function monthSeries(array $months, array $map): array
    {
        return array_map(fn ($m) => [
            'label' => ['en' => $m, 'ar' => $m],
            'value' => $map[$m] ?? 0,
        ], $months);
    }
}
