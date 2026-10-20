<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * بيانات منصة الامتحانات + الدكاترة + الطلاب لداشبورد Data Analysis
 * (الرئيسي والمحفوظ). مفيش منطق أرقام جديد هنا للامتحانات: كل مؤشرات
 * الامتحانات والطلاب جاية من DataAnalysisExamsService (مصدر وحيد للحقيقة،
 * نفس اللي صفحة Exam Analytics بتعرضه)، والباقي COUNT/GROUP BY بسيط على
 * الجداول الحقيقية. أي جزء بيفشل (جدول ناقص مثلًا) بيرجع null بدل ما
 * يوقّع الداشبورد كلها.
 */
class DataAnalysisAcademicService
{
    private const CACHE_TTL = 300;

    private const STATUS_LABELS = [
        'in_progress'    => ['en' => 'In progress',    'ar' => 'جارٍ'],
        'submitted'      => ['en' => 'Submitted',      'ar' => 'تم التسليم'],
        'auto_submitted' => ['en' => 'Auto-submitted', 'ar' => 'تسليم تلقائي'],
        'expired'        => ['en' => 'Expired',        'ar' => 'منتهي'],
        'grading'        => ['en' => 'Grading',        'ar' => 'قيد التصحيح'],
        'graded'         => ['en' => 'Graded',         'ar' => 'تم التصحيح'],
    ];

    public function __construct(private DataAnalysisExamsService $exams)
    {
    }

    /** كل أقسام الداشبورد الرئيسي في مفتاح واحد لكل قسم. */
    public function overview(): array
    {
        return [
            'academic'          => $this->safe(fn () => $this->ecosystem()),
            'exam_analytics'    => $this->safe(fn () => $this->examSnapshot()['exam_analytics']),
            'students_snapshot' => $this->safe(fn () => $this->examSnapshot()['students_snapshot']),
            'top_doctors'       => $this->safe(fn () => $this->topDoctors(5)),
            'integrity'         => $this->safe(fn () => $this->integrity()),
        ];
    }

    /** بيانات widget واحد للداشبورد المحفوظ (شكل {label:{en,ar},value} أو object مسطّح). */
    public function widget(string $type)
    {
        return $this->safe(function () use ($type) {
            switch ($type) {
                case 'academic_kpis':
                    return $this->ecosystem();
                case 'exam_kpis':
                    $k = $this->examSnapshot()['exam_analytics']['kpis'];
                    return [
                        'exams' => $k['exams'], 'attempts' => $k['attempts'], 'graded' => $k['graded'],
                        'pending_grading' => $k['pending_grading'], 'average_pct' => $k['average_pct'], 'pass_rate' => $k['pass_rate'],
                    ];
                case 'exam_status':
                    return $this->statusSeries($this->examSnapshot()['exam_analytics']['status_counts']);
                case 'score_distribution':
                    return array_map(fn ($d) => ['label' => ['en' => $d['key'], 'ar' => $d['key']], 'value' => $d['count']],
                        $this->examSnapshot()['exam_analytics']['distribution']);
                case 'exam_trend':
                    return array_map(fn ($t) => ['label' => ['en' => $t['month'], 'ar' => $t['month']], 'value' => $t['average_pct']],
                        $this->examSnapshot()['exam_analytics']['trend']);
                case 'top_doctors':
                    return array_map(fn ($d) => ['label' => ['en' => $d['name'], 'ar' => $d['name']], 'value' => $d['exams']], $this->topDoctors(5));
                case 'students_at_risk':
                    return array_map(fn ($s) => ['label' => ['en' => $s['student'], 'ar' => $s['student']], 'value' => $s['average_pct']],
                        $this->examSnapshot()['students_snapshot']['at_risk_list']);
                case 'exam_integrity':
                    return $this->integrity();
            }
            return null;
        });
    }

    /** أعداد المنظومة الأكاديمية الحقيقية. */
    public function ecosystem(): array
    {
        $liveExams = DB::table('exams')->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('is_template')->orWhere('is_template', 0));

        return [
            'students'        => (int) DB::table('students')->count(),
            'doctors'         => (int) DB::table('academic_staff')->count(),
            'active_doctors'  => (int) DB::table('academic_staff')->where('status', 'active')->count(),
            'courses'         => (int) DB::table('courses')->count(),
            'exams'           => (int) $liveExams->count(),
            'question_banks'  => (int) DB::table('question_banks')->whereNull('deleted_at')->count(),
            'questions'       => (int) DB::table('questions')->whereNull('deleted_at')->count(),
        ];
    }

    /** أكتر الدكاترة إنشاءً للامتحانات + عدد الدرجات اللي صححوها يدويًا. */
    public function topDoctors(int $limit = 5): array
    {
        $rows = DB::table('academic_staff as st')
            ->join('users as u', 'u.id', '=', 'st.user_id')
            ->leftJoin('exams as e', function ($j) {
                $j->on('e.created_by_academic_staff_id', '=', 'st.id')->whereNull('e.deleted_at');
            })
            ->select('st.id', 'u.full_name', DB::raw('COUNT(e.id) as exams'))
            ->groupBy('st.id', 'u.full_name')
            ->orderByDesc('exams')->orderBy('st.id')
            ->limit($limit)->get();

        $grades = $rows->isEmpty() ? [] : DB::table('exam_grades')
            ->whereIn('graded_by_academic_staff_id', $rows->pluck('id')->all())
            ->select('graded_by_academic_staff_id', DB::raw('COUNT(*) as c'))
            ->groupBy('graded_by_academic_staff_id')->pluck('c', 'graded_by_academic_staff_id')->all();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => $r->full_name, 'exams' => (int) $r->exams, 'grades' => (int) ($grades[$r->id] ?? 0),
        ])->all();
    }

    /** مؤشرات النزاهة والتظلمات (بتتطلب مراجعة). */
    public function integrity(): array
    {
        return [
            'pending_similarity_flags' => (int) DB::table('exam_similarity_flags')->where('status', 'pending')->count(),
            'pending_appeals'          => (int) DB::table('exam_grade_appeals')->where('status', 'pending')->count(),
            'violation_events_30d'     => (int) DB::table('exam_security_events')->where('is_violation', 1)
                ->where('occurred_at', '>=', now()->subDays(30)->toDateTimeString())->count(),
            'attempts_with_violations' => (int) DB::table('exam_attempts')->where('violations_count', '>', 0)->where('status', '!=', 'cancelled')->count(),
        ];
    }

    /** تحليلات الامتحانات + لقطة الطلاب، متخزنة دقايق قليلة عشان التجميع بيتم في PHP. */
    private function examSnapshot(): array
    {
        return Cache::remember('data_analysis:academic_snapshot', self::CACHE_TTL, function () {
            $o = $this->exams->overview([]);
            $s = $this->exams->students([], '', 'avg_asc', 'at_risk', 1, 5);

            return [
                'exam_analytics' => [
                    'kpis'          => $o['kpis'],
                    'status_counts' => $o['status_counts'],
                    'distribution'  => $o['distribution'],
                    'by_exam_type'  => $o['by_exam_type'],
                    'trend'         => $o['trend'],
                ],
                'students_snapshot' => [
                    'summary'      => $s['summary'],
                    'at_risk_list' => array_map(fn ($i) => [
                        'student_id' => $i['student_id'], 'student' => $i['student'], 'student_number' => $i['student_number'],
                        'exams_taken' => $i['exams_taken'], 'average_pct' => $i['average_pct'], 'pass_rate' => $i['pass_rate'],
                    ], $s['items']),
                ],
            ];
        });
    }

    /**
     * بحث عام في الامتحانات/الطلاب/الدكاترة/المقررات للبورتال. LIKE آمن
     * (القيمة bound، والـ % و_ بتتهرّب). مفيش أعمدة حساسة بترجع.
     *
     * @return array{exams:array,students:array,doctors:array,courses:array}
     */
    public function search(string $q, int $limit = 10): array
    {
        $needle = '%' . $q . '%'; // القيمة bound كـ parameter، فمفيش SQL injection؛ أي % أو _ بتوسّع البحث بس.
        $limit = max(1, min(25, $limit));

        $exams = $this->safe(fn () => DB::table('exams as e')
            ->whereNull('e.deleted_at')
            ->where(fn ($w) => $w->where('e.title', 'like', $needle)->orWhere('e.subject', 'like', $needle))
            ->orderByDesc('e.id')->limit($limit)
            ->get(['e.id', 'e.title', 'e.subject', 'e.exam_type', 'e.status', 'e.start_at'])
            ->map(fn ($r) => (array) $r)->all()) ?? [];

        $students = $this->safe(fn () => DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where(fn ($w) => $w->where('u.full_name', 'like', $needle)->orWhere('s.student_number', 'like', $needle))
            ->orderBy('u.full_name')->limit($limit)
            ->get(['s.id', 'u.full_name', 's.student_number', 's.academic_year'])
            ->map(fn ($r) => (array) $r)->all()) ?? [];

        $doctors = $this->safe(fn () => DB::table('academic_staff as st')
            ->join('users as u', 'u.id', '=', 'st.user_id')
            ->where(fn ($w) => $w->where('u.full_name', 'like', $needle)->orWhere('st.staff_number', 'like', $needle))
            ->orderBy('u.full_name')->limit($limit)
            ->get(['st.id', 'u.full_name', 'st.staff_number', 'st.status'])
            ->map(fn ($r) => (array) $r)->all()) ?? [];

        $courses = $this->safe(fn () => DB::table('courses')
            ->where(fn ($w) => $w->where('code', 'like', $needle)->orWhere('name_en', 'like', $needle)->orWhere('name_ar', 'like', $needle))
            ->orderBy('code')->limit($limit)
            ->get(['id', 'code', 'name_en', 'name_ar', 'status'])
            ->map(fn ($r) => (array) $r)->all()) ?? [];

        return compact('exams', 'students', 'doctors', 'courses');
    }

    /**
     * digest للـ AI Insights: أرقام مجمّعة بس — من غير أسماء طلاب أو دكاترة
     * (مش بنبعت بيانات شخصية لموديل خارجي).
     */
    public function aiDigest(): ?array
    {
        return $this->safe(function () {
            $snap = $this->examSnapshot();
            $a = $snap['exam_analytics'];
            return [
                'ecosystem'      => $this->ecosystem(),
                'exam_kpis'      => $a['kpis'],
                'attempt_status' => $a['status_counts'],
                'score_bands'    => $a['distribution'],
                'by_exam_type'   => $a['by_exam_type'],
                'monthly_avg'    => $a['trend'],
                'students'       => $snap['students_snapshot']['summary'],
                'integrity'      => $this->integrity(),
                'doctor_activity' => array_map(fn ($d) => ['exams' => $d['exams'], 'grades' => $d['grades']], $this->topDoctors(10)),
            ];
        });
    }

    private function statusSeries(array $counts): array
    {
        $out = [];
        foreach (self::STATUS_LABELS as $key => $label) {
            if (!empty($counts[$key])) {
                $out[] = ['label' => $label, 'value' => (int) $counts[$key]];
            }
        }
        return $out;
    }

    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }
}
