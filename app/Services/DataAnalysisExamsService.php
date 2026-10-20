<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Data Analysis Portal — تحليلات الامتحانات والدرجات. بيربط محلل البيانات
 * بنظام الامتحانات (exams / exam_attempts / exam_grades / courses) على
 * مستوى المنصة كلها، بعكس ExamAnalyticsService اللي مقيّد بدكتور واحد.
 * قراءة بس — مفيش أي كتابة على جداول الامتحانات.
 *
 * قواعد الحساب (نفس ExamAnalyticsService عشان الأرقام تتطابق):
 *  - الدرجات/نسب النجاح بتتحسب بس من محاولات status=graded وفيها percentage.
 *  - النجاح = percentage >= exams.passing_score (لو الامتحان ليه passing_score).
 *  - المحاولات الملغية (cancelled) مش بتدخل في أي رقم.
 *  - قوالب الامتحانات (is_template) مستبعدة.
 *  - مستوى الطالب: بنستخدم أفضل محاولة لكل (طالب، امتحان) عشان إعادة
 *    المحاولة ماتظلمش الطالب ولا تضخّم عدد الامتحانات.
 *
 * التجميع بيتم في PHP فوق صفوف مفلترة (سقف MAX_ROWS) عشان الكود يشتغل
 * بنفس الشكل على MySQL وSQLite.
 */
class DataAnalysisExamsService
{
    private const MAX_ROWS = 50000;
    private const BANDS = [
        ['key' => 'lt50',  'min' => 0,  'max' => 50],
        ['key' => '50-59', 'min' => 50, 'max' => 60],
        ['key' => '60-69', 'min' => 60, 'max' => 70],
        ['key' => '70-79', 'min' => 70, 'max' => 80],
        ['key' => '80-89', 'min' => 80, 'max' => 90],
        ['key' => '90-100', 'min' => 90, 'max' => 100.01],
    ];
    private const OBJECTIVE_TYPES = ['mcq', 'multi_select', 'true_false'];

    /** أنواع تصدير الامتحانات اللي بورتال المحلل بيعرضها في Export Center. */
    public const EXPORT_TYPES = [
        'exam_overview',
        'exam_scores_detail',
        'student_exam_performance',
        'exam_summary',
        'doctor_exam_performance',
    ];

    public const EXPORT_LABELS = [
        'exam_overview'            => 'Exam Overview (KPIs)',
        'exam_scores_detail'       => 'Exam Scores (every graded attempt)',
        'student_exam_performance' => 'Student Exam Performance',
        'exam_summary'             => 'Exams Summary (per exam)',
        'doctor_exam_performance'  => 'Doctors Exam Performance',
    ];

    // -----------------------------------------------------------------
    // Filters (dropdown options)
    // -----------------------------------------------------------------

    public function filters(): array
    {
        $universities = \App\Models\University::applyListable(DB::table('universities'))->select('id', 'official_name_en', 'official_name_ar')->orderBy('official_name_en')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $this->bi($r->official_name_en, $r->official_name_ar)])->all();

        $faculties = DB::table('faculties')->select('id', 'university_id', 'name_en', 'name_ar')->orderBy('name_en')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'university_id' => (int) $r->university_id, 'name' => $this->bi($r->name_en, $r->name_ar)])->all();

        $courses = DB::table('courses')->select('id', 'university_id', 'faculty_id', 'code', 'name_en', 'name_ar')->orderBy('code')->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'university_id' => (int) $r->university_id,
                'faculty_id' => $r->faculty_id !== null ? (int) $r->faculty_id : null,
                'code' => $r->code, 'name' => $this->bi($r->name_en, $r->name_ar),
            ])->all();

        $types = DB::table('exams')->whereNull('deleted_at')->whereNotNull('exam_type')->distinct()->orderBy('exam_type')->pluck('exam_type')->all();

        return compact('universities', 'faculties', 'courses') + ['exam_types' => array_values($types)];
    }

    // -----------------------------------------------------------------
    // Overview
    // -----------------------------------------------------------------

    public function overview(array $f): array
    {
        $statusCounts = $this->baseQuery($f, false)
            ->select('a.status', DB::raw('COUNT(*) as c'))->groupBy('a.status')->pluck('c', 'status')->all();
        $statusCounts = array_map('intval', $statusCounts);

        $examsInScope = (int) $this->examScope($f)->count();

        $rows = $this->gradedRows($f);
        $pcts = array_map(fn ($r) => $r['pct'], $rows);
        sort($pcts);
        $judged = array_values(array_filter($rows, fn ($r) => $r['passed'] !== null));
        $passed = count(array_filter($judged, fn ($r) => $r['passed']));

        $students = [];
        foreach ($rows as $r) {
            $students[$r['student_id']] = true;
        }

        $totalAttempts = array_sum($statusCounts) - ($statusCounts['cancelled'] ?? 0);
        $submitted = ($statusCounts['submitted'] ?? 0) + ($statusCounts['auto_submitted'] ?? 0) + ($statusCounts['grading'] ?? 0) + ($statusCounts['graded'] ?? 0);

        return [
            'kpis' => [
                'exams'              => $examsInScope,
                'attempts'           => $totalAttempts,
                'submitted'          => $submitted,
                'graded'             => count($rows),
                'pending_grading'    => ($statusCounts['submitted'] ?? 0) + ($statusCounts['auto_submitted'] ?? 0) + ($statusCounts['grading'] ?? 0),
                'in_progress'        => $statusCounts['in_progress'] ?? 0,
                'students'           => count($students),
                'average_pct'        => $this->avg($pcts),
                'median_pct'         => $this->median($pcts),
                'highest_pct'        => $pcts ? round(end($pcts), 2) : null,
                'lowest_pct'         => $pcts ? round($pcts[0], 2) : null,
                'pass_rate'          => $judged ? round($passed / count($judged) * 100, 2) : null,
                'late_attempts'      => count(array_filter($rows, fn ($r) => $r['late'])),
                'flagged_attempts'   => count(array_filter($rows, fn ($r) => $r['violations'] > 0)),
            ],
            'status_counts' => $statusCounts,
            'distribution'  => $this->distribution($pcts),
            'by_exam_type'  => $this->groupStats($rows, fn ($r) => $r['exam_type'] ?: 'other', fn ($k) => ['label' => $k]),
            'by_faculty'    => $this->groupStats($rows, fn ($r) => $r['faculty_id'] ?: 0, fn ($k) => ['faculty_id' => (int) $k], 10),
            'by_course'     => $this->groupStats($rows, fn ($r) => $r['course_id'] ?: 0, fn ($k) => ['course_id' => (int) $k], 10),
            'by_university' => $this->groupStats($rows, fn ($r) => $r['university_id'], fn ($k) => ['university_id' => (int) $k], 10),
            'trend'         => $this->trend($rows),
            'exams'         => $this->examList($rows, $f),
            'names'         => $this->names($rows),
        ];
    }

    // -----------------------------------------------------------------
    // Single exam drill-down
    // -----------------------------------------------------------------

    public function exam(int $id): ?array
    {
        $exam = DB::table('exams as e')
            ->leftJoin('courses as c', 'c.id', '=', 'e.course_id')
            ->leftJoin('universities as un', 'un.id', '=', 'e.university_id')
            ->leftJoin('faculties as fa', 'fa.id', '=', 'e.faculty_id')
            ->where('e.id', $id)->whereNull('e.deleted_at')
            ->select('e.id', 'e.title', 'e.subject', 'e.exam_type', 'e.status', 'e.passing_score', 'e.total_marks', 'e.duration_minutes',
                'e.start_at', 'e.end_at', 'e.max_attempts', 'e.academic_year', 'e.semester',
                'c.code as course_code', 'c.name_en as course_en', 'c.name_ar as course_ar',
                'un.official_name_en as uni_en', 'un.official_name_ar as uni_ar',
                'fa.name_en as fac_en', 'fa.name_ar as fac_ar')
            ->first();
        if (!$exam) {
            return null;
        }

        $attempts = DB::table('exam_attempts as a')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('a.exam_id', $id)->where('a.status', '!=', 'cancelled')
            ->select('a.id', 'a.student_id', 'a.attempt_number', 'a.status', 'a.score', 'a.percentage', 'a.started_at', 'a.submitted_at',
                'a.violations_count', 'a.is_late', 's.student_number', 'u.full_name')
            ->orderByDesc('a.percentage')->limit(self::MAX_ROWS)->get();

        $passing = $exam->passing_score !== null ? (float) $exam->passing_score : null;
        $graded = $attempts->filter(fn ($a) => $a->status === 'graded' && $a->percentage !== null)->values();
        $pcts = $graded->map(fn ($a) => (float) $a->percentage)->all();
        sort($pcts);
        $passCount = $passing !== null ? count(array_filter($pcts, fn ($p) => $p >= $passing)) : null;

        $durations = [];
        foreach ($attempts as $a) {
            if ($a->started_at && $a->submitted_at) {
                $d = (strtotime($a->submitted_at) - strtotime($a->started_at)) / 60;
                if ($d >= 0) {
                    $durations[] = $d;
                }
            }
        }

        $rows = $attempts->take(300)->map(fn ($a) => [
            'attempt_id' => (int) $a->id, 'student_id' => (int) $a->student_id, 'student' => $a->full_name,
            'student_number' => $a->student_number, 'attempt_number' => (int) $a->attempt_number, 'status' => $a->status,
            'score' => $a->score !== null ? (float) $a->score : null,
            'percentage' => $a->percentage !== null ? (float) $a->percentage : null,
            'passed' => ($a->status === 'graded' && $a->percentage !== null && $passing !== null) ? (float) $a->percentage >= $passing : null,
            'violations' => (int) $a->violations_count, 'late' => (bool) $a->is_late, 'submitted_at' => $a->submitted_at,
        ])->all();

        return [
            'exam' => [
                'id' => (int) $exam->id, 'title' => $exam->title, 'subject' => $exam->subject, 'exam_type' => $exam->exam_type,
                'status' => $exam->status, 'passing_score' => $passing, 'total_marks' => (float) $exam->total_marks,
                'duration_minutes' => (int) $exam->duration_minutes, 'start_at' => $exam->start_at, 'end_at' => $exam->end_at,
                'max_attempts' => $exam->max_attempts, 'academic_year' => $exam->academic_year, 'semester' => $exam->semester,
                'course' => $exam->course_code ? ['code' => $exam->course_code, 'name' => $this->bi($exam->course_en, $exam->course_ar)] : null,
                'university' => $this->bi($exam->uni_en, $exam->uni_ar),
                'faculty' => $exam->fac_en || $exam->fac_ar ? $this->bi($exam->fac_en, $exam->fac_ar) : null,
            ],
            'stats' => [
                'attempts' => $attempts->count(),
                'students' => $attempts->pluck('student_id')->unique()->count(),
                'graded' => count($pcts),
                'average_pct' => $this->avg($pcts), 'median_pct' => $this->median($pcts),
                'highest_pct' => $pcts ? round(end($pcts), 2) : null, 'lowest_pct' => $pcts ? round($pcts[0], 2) : null,
                'std_dev' => $this->stdDev($pcts),
                'pass_rate' => ($passing !== null && $pcts) ? round($passCount / count($pcts) * 100, 2) : null,
                'avg_duration_min' => $this->avg($durations),
                'flagged_attempts' => $attempts->filter(fn ($a) => (int) $a->violations_count > 0)->count(),
                'late_attempts' => $attempts->filter(fn ($a) => (bool) $a->is_late)->count(),
            ],
            'distribution' => $this->distribution($pcts),
            'questions' => $this->questionStats($id),
            'attempts' => $rows,
        ];
    }

    /** إحصائيات كل سؤال: متوسط الدرجة %، وصح/غلط للأسئلة الموضوعية بس (نفس قاعدة ExamAnalyticsService). */
    private function questionStats(int $examId): array
    {
        $rows = DB::table('exam_grades as g')
            ->join('exam_questions as eq', 'eq.id', '=', 'g.exam_question_id')
            ->join('questions as q', 'q.id', '=', 'eq.question_id')
            ->where('eq.exam_id', $examId)->whereNotNull('g.marks_awarded')
            ->select('q.id as question_id', 'q.type', 'q.prompt', 'q.difficulty', 'q.topic',
                DB::raw('COUNT(*) as graded_count'),
                DB::raw('SUM(CASE WHEN g.is_correct = 1 THEN 1 ELSE 0 END) as correct_count'),
                DB::raw('AVG(g.marks_awarded) as avg_marks'),
                DB::raw('AVG(g.max_marks) as avg_max'))
            ->groupBy('q.id', 'q.type', 'q.prompt', 'q.difficulty', 'q.topic')
            ->get();

        $items = $rows->map(function ($r) {
            $n = (int) $r->graded_count;
            $obj = in_array($r->type, self::OBJECTIVE_TYPES, true);
            return [
                'question_id' => (int) $r->question_id, 'type' => $r->type, 'difficulty' => $r->difficulty, 'topic' => $r->topic,
                'prompt' => mb_strlen($r->prompt) > 160 ? mb_substr($r->prompt, 0, 160) . '…' : $r->prompt,
                'answered' => $n,
                'correct_pct' => ($obj && $n > 0) ? round((int) $r->correct_count / $n * 100, 2) : null,
                'average_score_pct' => ($n > 0 && (float) $r->avg_max > 0) ? round((float) $r->avg_marks / (float) $r->avg_max * 100, 2) : null,
            ];
        })->all();

        usort($items, fn ($a, $b) => ($a['average_score_pct'] ?? 101) <=> ($b['average_score_pct'] ?? 101));
        return $items; // الأصعب أولًا
    }

    // -----------------------------------------------------------------
    // Students
    // -----------------------------------------------------------------

    public function students(array $f, string $q, string $sort, string $risk, int $page, int $perPage): array
    {
        $best = [];
        foreach ($this->gradedRows($f) as $r) {
            $k = $r['student_id'] . ':' . $r['exam_id'];
            if (!isset($best[$k]) || $r['pct'] > $best[$k]['pct']) {
                $best[$k] = $r;
            }
        }

        $by = [];
        foreach ($best as $r) {
            $by[$r['student_id']][] = $r;
        }

        $list = [];
        foreach ($by as $sid => $rs) {
            usort($rs, fn ($a, $b) => strcmp((string) $a['submitted_at'], (string) $b['submitted_at']));
            $pcts = array_column($rs, 'pct');
            $judged = array_filter($rs, fn ($r) => $r['passed'] !== null);
            $passed = count(array_filter($judged, fn ($r) => $r['passed']));
            $avg = $this->avg($pcts);
            $half = intdiv(count($pcts), 2);
            $trend = $half >= 1 ? round($this->avg(array_slice($pcts, $half)) - $this->avg(array_slice($pcts, 0, $half)), 2) : null;
            $passRate = $judged ? round($passed / count($judged) * 100, 2) : null;

            $level = 'average';
            if ($avg < 50 || ($passRate !== null && $passRate < 50)) {
                $level = 'at_risk';
            } elseif ($avg >= 85 && ($passRate === null || $passRate >= 90)) {
                $level = 'excellent';
            }

            $list[] = [
                'student_id' => (int) $sid, 'student' => $rs[0]['student'], 'student_number' => $rs[0]['student_number'],
                'email' => $rs[0]['email'], 'faculty_id' => $rs[0]['faculty_id'],
                'exams_taken' => count($rs), 'average_pct' => $avg, 'best_pct' => round(max($pcts), 2), 'lowest_pct' => round(min($pcts), 2),
                'pass_rate' => $passRate, 'trend' => $trend, 'flagged' => array_sum(array_column($rs, 'violations')), 'level' => $level,
            ];
        }

        $summary = [
            'total' => count($list),
            'at_risk' => count(array_filter($list, fn ($s) => $s['level'] === 'at_risk')),
            'excellent' => count(array_filter($list, fn ($s) => $s['level'] === 'excellent')),
        ];

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $list = array_values(array_filter($list, fn ($s) => str_contains(mb_strtolower($s['student'] . ' ' . $s['student_number'] . ' ' . $s['email']), $needle)));
        }
        if (in_array($risk, ['at_risk', 'excellent', 'average'], true)) {
            $list = array_values(array_filter($list, fn ($s) => $s['level'] === $risk));
        }

        $sorts = [
            'avg_desc' => fn ($a, $b) => $b['average_pct'] <=> $a['average_pct'],
            'avg_asc' => fn ($a, $b) => $a['average_pct'] <=> $b['average_pct'],
            'exams_desc' => fn ($a, $b) => $b['exams_taken'] <=> $a['exams_taken'],
            'name' => fn ($a, $b) => strcmp((string) $a['student'], (string) $b['student']),
        ];
        usort($list, $sorts[$sort] ?? $sorts['avg_asc']);

        $total = count($list);
        return [
            'items' => array_slice($list, ($page - 1) * $perPage, $perPage),
            'summary' => $summary,
            'total' => $total,
        ];
    }

    // -----------------------------------------------------------------
    // Export Center rows (CSV / XLSX / PDF)
    // -----------------------------------------------------------------

    /**
     * صفوف جاهزة للتصدير لنفس البيانات اللي صفحة Exam Analytics بتعرضها
     * (نفس الفلاتر ونفس قواعد الحساب: أفضل محاولة لكل طالب/امتحان،
     * المحاولات الملغية والقوالب مستبعدة).
     *
     * @param array<string,mixed> $f نفس شكل filtersFrom() في الكنترولر
     * @return array{0:string[],1:array<int,array<int,mixed>>} [header, rows]
     */
    public function exportRows(string $type, array $f = []): array
    {
        [$header, $rows] = match ($type) {
            'exam_overview'            => $this->exportOverviewRows($f),
            'exam_scores_detail'       => $this->exportScoresDetailRows($f),
            'student_exam_performance' => $this->exportStudentRows($f),
            'exam_summary'             => $this->exportExamSummaryRows($f),
            'doctor_exam_performance'  => $this->exportDoctorRows($f),
            default                    => [[], []],
        };

        return [$header, array_map(fn ($row) => array_map([$this, 'safeCell'], $row), $rows)];
    }

    private function exportOverviewRows(array $f): array
    {
        $kpis = $this->overview($f)['kpis'];
        $rows = [];
        foreach ($kpis as $metric => $value) {
            $rows[] = [$metric, $value];
        }
        return [['metric', 'value'], $rows];
    }

    private function exportScoresDetailRows(array $f): array
    {
        $graded = $this->gradedRows($f);
        $nm = $this->names($graded);
        usort($graded, fn ($a, $b) => strcmp((string) $a['submitted_at'], (string) $b['submitted_at']));

        $rows = [];
        foreach ($graded as $r) {
            $rows[] = [
                $r['title'],
                $r['exam_type'],
                $nm['universities'][$r['university_id']]['en'] ?? '',
                $r['faculty_id'] ? ($nm['faculties'][$r['faculty_id']]['en'] ?? '') : '',
                $r['course_id'] ? trim(($nm['courses'][$r['course_id']]['code'] ?? '') . ' ' . ($nm['courses'][$r['course_id']]['en'] ?? '')) : '',
                $r['student'],
                $r['student_number'],
                $r['email'],
                round($r['pct'], 2),
                $r['passed'] === null ? '' : ($r['passed'] ? 'passed' : 'failed'),
                $r['late'] ? 'yes' : 'no',
                $r['violations'],
                $r['submitted_at'],
            ];
        }

        return [[
            'Exam', 'Exam Type', 'University', 'Faculty', 'Course', 'Student', 'Student Number', 'Email',
            'Percentage', 'Result', 'Late', 'Violations', 'Submitted At',
        ], $rows];
    }

    private function exportStudentRows(array $f): array
    {
        // نفس منطق تبويب Students (أفضل محاولة لكل امتحان) من غير تقسيم صفحات.
        $list = $this->students($f, '', 'name', '', 1, PHP_INT_MAX)['items'];

        $facultyIds = array_values(array_filter(array_unique(array_column($list, 'faculty_id'))));
        $faculties = [];
        if ($facultyIds) {
            foreach (DB::table('faculties')->whereIn('id', $facultyIds)->select('id', 'name_en', 'name_ar')->get() as $r) {
                $faculties[$r->id] = $r->name_en ?: $r->name_ar;
            }
        }

        $rows = [];
        foreach ($list as $s) {
            $rows[] = [
                $s['student'], $s['student_number'], $s['email'],
                $s['faculty_id'] ? ($faculties[$s['faculty_id']] ?? '') : '',
                $s['exams_taken'], $s['average_pct'], $s['best_pct'], $s['lowest_pct'],
                $s['pass_rate'], $s['trend'], $s['flagged'], $s['level'],
            ];
        }

        return [[
            'Student', 'Student Number', 'Email', 'Faculty', 'Exams Taken', 'Average %', 'Best %', 'Lowest %',
            'Pass Rate %', 'Trend (pts)', 'Flagged Attempts', 'Level',
        ], $rows];
    }

    private function exportExamSummaryRows(array $f): array
    {
        $graded = $this->gradedRows($f);
        $nm = $this->names($graded);

        $by = [];
        foreach ($graded as $r) {
            $by[$r['exam_id']][] = $r;
        }

        $rows = [];
        foreach ($by as $id => $rs) {
            $pcts = array_column($rs, 'pct');
            $judged = array_filter($rs, fn ($r) => $r['passed'] !== null);
            $first = $rs[0];
            $rows[] = [
                (int) $id,
                $first['title'],
                $first['exam_type'],
                $nm['universities'][$first['university_id']]['en'] ?? '',
                $first['course_id'] ? trim(($nm['courses'][$first['course_id']]['code'] ?? '') . ' ' . ($nm['courses'][$first['course_id']]['en'] ?? '')) : '',
                count($rs),
                count(array_unique(array_column($rs, 'student_id'))),
                $this->avg($pcts),
                round(max($pcts), 2),
                round(min($pcts), 2),
                $judged ? round(count(array_filter($judged, fn ($r) => $r['passed'])) / count($judged) * 100, 2) : null,
                max(array_column($rs, 'submitted_at')),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b[11], (string) $a[11]));

        return [[
            'Exam ID', 'Exam', 'Exam Type', 'University', 'Course', 'Graded Attempts', 'Students',
            'Average %', 'Highest %', 'Lowest %', 'Pass Rate %', 'Last Submission',
        ], $rows];
    }

    private function exportDoctorRows(array $f): array
    {
        // كل الامتحانات في النطاق (حتى اللي لسه ماتحلّش) متجمّعة على صاحبها.
        $created = $this->examScope($f)
            ->select('e.created_by_academic_staff_id as staff_id', DB::raw('COUNT(*) as exams'))
            ->groupBy('e.created_by_academic_staff_id')
            ->pluck('exams', 'staff_id')->all();

        $attempts = $this->baseQuery($f)
            ->select('e.created_by_academic_staff_id as staff_id', 'e.id as exam_id', 'e.passing_score', 'a.student_id', 'a.percentage')
            ->limit(self::MAX_ROWS)->get();

        $stats = [];
        foreach ($attempts as $a) {
            $sid = (int) $a->staff_id;
            $pct = (float) $a->percentage;
            $stats[$sid]['pcts'][] = $pct;
            $stats[$sid]['exams'][(int) $a->exam_id] = true;
            $stats[$sid]['students'][(int) $a->student_id] = true;
            if ($a->passing_score !== null) {
                $stats[$sid]['judged'] = ($stats[$sid]['judged'] ?? 0) + 1;
                $stats[$sid]['passed'] = ($stats[$sid]['passed'] ?? 0) + ($pct >= (float) $a->passing_score ? 1 : 0);
            }
        }

        $doctors = [];
        if ($created) {
            $doctors = DB::table('academic_staff as st')->join('users as u', 'u.id', '=', 'st.user_id')
                ->whereIn('st.id', array_keys($created))
                ->select('st.id', 'u.full_name', 'u.email')->get()->keyBy('id')->all();
        }

        $rows = [];
        foreach ($created as $staffId => $examCount) {
            $d = $doctors[$staffId] ?? null;
            $s = $stats[(int) $staffId] ?? null;
            $rows[] = [
                $d->full_name ?? ('#' . $staffId),
                $d->email ?? '',
                (int) $examCount,
                $s ? count($s['exams']) : 0,
                $s ? count($s['pcts']) : 0,
                $s ? count($s['students']) : 0,
                $s ? $this->avg($s['pcts']) : null,
                ($s && !empty($s['judged'])) ? round($s['passed'] / $s['judged'] * 100, 2) : null,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));

        return [[
            'Doctor', 'Email', 'Exams Created', 'Exams With Graded Attempts', 'Graded Attempts', 'Students',
            'Average %', 'Pass Rate %',
        ], $rows];
    }

    /**
     * حماية من CSV/Excel formula injection: أسماء الطلاب وعناوين الامتحانات
     * بيكتبها مستخدمين، فأي نص بيبدأ بـ = + - @ بيتحوّل لنص عادي.
     */
    private function safeCell($v)
    {
        if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v;
        }
        return $v;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** exams في نطاق الفلاتر (بدون قوالب/محذوف). */
    private function examScope(array $f)
    {
        $q = DB::table('exams as e')->whereNull('e.deleted_at')
            ->where(fn ($w) => $w->whereNull('e.is_template')->orWhere('e.is_template', 0));
        foreach (['university_id', 'faculty_id', 'course_id'] as $k) {
            if (!empty($f[$k])) {
                $q->where('e.' . $k, (int) $f[$k]);
            }
        }
        if (!empty($f['exam_type'])) {
            $q->where('e.exam_type', $f['exam_type']);
        }
        return $q;
    }

    private function baseQuery(array $f, bool $gradedOnly = true)
    {
        $q = $this->examScope($f)->join('exam_attempts as a', 'a.exam_id', '=', 'e.id')->where('a.status', '!=', 'cancelled');
        if ($gradedOnly) {
            $q->where('a.status', 'graded')->whereNotNull('a.percentage');
        }
        if (!empty($f['from'])) {
            $q->where('a.submitted_at', '>=', $f['from'] . ' 00:00:00');
        }
        if (!empty($f['to'])) {
            $q->where('a.submitted_at', '<=', $f['to'] . ' 23:59:59');
        }
        return $q;
    }

    /** @return array<int,array<string,mixed>> صفوف المحاولات المصحّحة مع بيانات الامتحان والطالب. */
    private function gradedRows(array $f): array
    {
        return $this->baseQuery($f)
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select('a.id', 'a.exam_id', 'a.student_id', 'a.percentage', 'a.submitted_at', 'a.violations_count', 'a.is_late',
                'e.title', 'e.exam_type', 'e.passing_score', 'e.university_id', 'e.faculty_id', 'e.course_id',
                's.student_number', 'u.full_name', 'u.email')
            ->orderBy('a.id')->limit(self::MAX_ROWS)->get()
            ->map(function ($r) {
                $pct = (float) $r->percentage;
                $ps = $r->passing_score !== null ? (float) $r->passing_score : null;
                return [
                    'id' => (int) $r->id, 'exam_id' => (int) $r->exam_id, 'student_id' => (int) $r->student_id, 'pct' => $pct,
                    'passed' => $ps !== null ? $pct >= $ps : null, 'submitted_at' => $r->submitted_at,
                    'violations' => (int) $r->violations_count, 'late' => (bool) $r->is_late,
                    'title' => $r->title, 'exam_type' => $r->exam_type, 'university_id' => (int) $r->university_id,
                    'faculty_id' => $r->faculty_id !== null ? (int) $r->faculty_id : null,
                    'course_id' => $r->course_id !== null ? (int) $r->course_id : null,
                    'student' => $r->full_name, 'student_number' => $r->student_number, 'email' => $r->email,
                ];
            })->all();
    }

    private function distribution(array $pcts): array
    {
        return array_map(function ($b) use ($pcts) {
            return ['key' => $b['key'], 'count' => count(array_filter($pcts, fn ($p) => $p >= $b['min'] && $p < $b['max']))];
        }, self::BANDS);
    }

    private function groupStats(array $rows, callable $keyOf, callable $extra, ?int $limit = null): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$keyOf($r)][] = $r;
        }
        $out = [];
        foreach ($groups as $k => $rs) {
            $pcts = array_column($rs, 'pct');
            $judged = array_filter($rs, fn ($r) => $r['passed'] !== null);
            $out[] = $extra($k) + [
                'attempts' => count($rs),
                'students' => count(array_unique(array_column($rs, 'student_id'))),
                'average_pct' => $this->avg($pcts),
                'pass_rate' => $judged ? round(count(array_filter($judged, fn ($r) => $r['passed'])) / count($judged) * 100, 2) : null,
            ];
        }
        usort($out, fn ($a, $b) => $b['attempts'] <=> $a['attempts']);
        return $limit ? array_slice($out, 0, $limit) : $out;
    }

    /** متوسط الدرجات شهريًا (آخر 12 شهر فيها بيانات). */
    private function trend(array $rows): array
    {
        $m = [];
        foreach ($rows as $r) {
            if ($r['submitted_at']) {
                $m[substr($r['submitted_at'], 0, 7)][] = $r['pct'];
            }
        }
        ksort($m);
        $m = array_slice($m, -12, null, true);
        $out = [];
        foreach ($m as $month => $pcts) {
            $out[] = ['month' => $month, 'average_pct' => $this->avg($pcts), 'attempts' => count($pcts)];
        }
        return $out;
    }

    private function examList(array $rows, array $f): array
    {
        $by = [];
        foreach ($rows as $r) {
            $by[$r['exam_id']][] = $r;
        }
        $out = [];
        foreach ($by as $id => $rs) {
            $pcts = array_column($rs, 'pct');
            $judged = array_filter($rs, fn ($r) => $r['passed'] !== null);
            $out[] = [
                'exam_id' => (int) $id, 'title' => $rs[0]['title'], 'exam_type' => $rs[0]['exam_type'], 'course_id' => $rs[0]['course_id'],
                'graded' => count($rs), 'average_pct' => $this->avg($pcts), 'highest_pct' => round(max($pcts), 2), 'lowest_pct' => round(min($pcts), 2),
                'pass_rate' => $judged ? round(count(array_filter($judged, fn ($r) => $r['passed'])) / count($judged) * 100, 2) : null,
                'last_submitted_at' => max(array_column($rs, 'submitted_at')),
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_submitted_at'], (string) $a['last_submitted_at']));
        return array_slice($out, 0, 100);
    }

    /** أسماء الجامعات/الكليات/المقررات المذكورة في الـ breakdowns — عشان الفرونت مايحتاجش lookup تاني. */
    private function names(array $rows): array
    {
        $uni = array_unique(array_column($rows, 'university_id'));
        $fac = array_filter(array_unique(array_column($rows, 'faculty_id')));
        $crs = array_filter(array_unique(array_column($rows, 'course_id')));
        $out = ['universities' => [], 'faculties' => [], 'courses' => []];
        if ($uni) {
            foreach (DB::table('universities')->whereIn('id', $uni)->select('id', 'official_name_en', 'official_name_ar')->get() as $r) {
                $out['universities'][$r->id] = $this->bi($r->official_name_en, $r->official_name_ar);
            }
        }
        if ($fac) {
            foreach (DB::table('faculties')->whereIn('id', $fac)->select('id', 'name_en', 'name_ar')->get() as $r) {
                $out['faculties'][$r->id] = $this->bi($r->name_en, $r->name_ar);
            }
        }
        if ($crs) {
            foreach (DB::table('courses')->whereIn('id', $crs)->select('id', 'code', 'name_en', 'name_ar')->get() as $r) {
                $out['courses'][$r->id] = ['code' => $r->code] + $this->bi($r->name_en, $r->name_ar);
            }
        }
        return $out;
    }

    private function bi($en, $ar): array
    {
        return ['en' => $en ?: $ar, 'ar' => $ar ?: $en];
    }

    private function avg(array $v): ?float
    {
        return $v ? round(array_sum($v) / count($v), 2) : null;
    }

    private function median(array $sorted): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        return $n % 2 ? round($sorted[intdiv($n, 2)], 2) : round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2, 2);
    }

    private function stdDev(array $v): ?float
    {
        $n = count($v);
        if ($n < 2) {
            return null;
        }
        $m = array_sum($v) / $n;
        return round(sqrt(array_sum(array_map(fn ($x) => ($x - $m) ** 2, $v)) / $n), 2);
    }
}
