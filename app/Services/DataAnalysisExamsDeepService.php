<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Analysis Portal — الامتحانات بعمق (بند 5). بيكمّل DataAnalysisExamsService
 * (الدرجات والمحاولات) بالجداول اللي كانت ناقصة للمحلل:
 *
 *   targeting  → exam_targets            (مين الامتحان موجّه له)
 *   grading    → exam_grade_history      (تعديلات الدرجات بعد التصحيح)
 *   ai         → exam_ai_gradings        (تصحيح الـ AI ومدى اتفاقه مع الدرجة النهائية)
 *   proctoring → exam_proctoring_snapshots + exam_attempt_sessions + حالة الهوية
 *   pools      → question_pools + exam_question_pools + exam_rubrics(+criteria)
 *   overrides  → exam_student_overrides  (استثناءات الطلاب) + إلغاء المحاولات + الوقت الإضافي
 *
 * قراءة بس. كل الأرقام تجميعية — الخصوصية:
 *  - لقطات المراقبة (صور وجوه/بطاقات): أعداد وأنواع وflags بس؛ مفيش path ولا صورة ولا IP.
 *  - أسباب التعديل/الاستثناء/الإلغاء (reason)، تعليقات الـ AI (feedback/strengths/reasoning)،
 *    ونص الأخطاء، وملاحظات مراجعة الهوية: مبتتعرضش خالص.
 *  - مفيش أسماء/أرقام طلاب في أي قسم هنا — عناوين الامتحانات بس في قوائم "الأعلى".
 *
 * الفلاتر زي DataAnalysisExamsService: university/faculty/course/exam_type
 * بتتطبّق على الامتحانات، و from/to (افتراضي آخر 90 يوم) على تاريخ السجل نفسه
 * (changed_at/captured_at/created_at...). أقسام اللقطة (pools، وضع المراقبة للامتحانات)
 * أرقامها حالية. أي جدول/عمود ناقص بيرجّع available=false (أو بيتخطى الجزء) بدل ما يكسر.
 */
class DataAnalysisExamsDeepService
{
    public const SECTIONS = ['targeting', 'grading', 'ai', 'proctoring', 'pools', 'overrides'];

    private const MAX_ROWS = 20000;
    private const MAX_DAYS = 366;
    private const DEFAULT_DAYS = 90;

    public function section(string $key, array $f): ?array
    {
        return match ($key) {
            'targeting'  => $this->targeting($f),
            'grading'    => $this->grading($f),
            'ai'         => $this->ai($f),
            'proctoring' => $this->proctoring($f),
            'pools'      => $this->pools($f),
            'overrides'  => $this->overrides($f),
            default      => null,
        };
    }

    public function overview(array $f): array
    {
        $r = $this->range($f);
        $sections = [];
        $series = [];
        foreach (self::SECTIONS as $key) {
            $data = $this->section($key, $f) ?? ['available' => false];
            $sections[$key] = ['available' => (bool) ($data['available'] ?? false), 'kpis' => $data['kpis'] ?? []];
            $series[$key] = $this->toMap($data['daily'] ?? []);
        }
        $trend = [];
        foreach ($this->dates($r) as $d) {
            $row = ['date' => $d];
            foreach (self::SECTIONS as $key) {
                $row[$key] = $series[$key][$d] ?? 0;
            }
            $trend[] = $row;
        }
        return ['range' => ['from' => $r['from'], 'to' => $r['to'], 'days' => $r['days']], 'sections' => $sections, 'trend' => $trend];
    }

    // -----------------------------------------------------------------
    // Targeting
    // -----------------------------------------------------------------

    private function targeting(array $f): array
    {
        if (!$this->ready(['exam_targets', 'exams'])) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $t = fn () => $this->between($this->viaExam('exam_targets', 't', $f), 't.created_at', $r);

        $level = "CASE WHEN t.student_id IS NOT NULL THEN 'student' WHEN t.group_id IS NOT NULL THEN 'group'
                  WHEN t.department_id IS NOT NULL THEN 'department' WHEN t.faculty_id IS NOT NULL THEN 'faculty' ELSE 'all' END";

        $rows = (int) $t()->count();
        $examsWith = (int) $t()->distinct()->count('t.exam_id');
        $examsTotal = (int) $this->examsQ($f)->count();

        $top = $t()->select('e.title', DB::raw('COUNT(*) as c'))->groupBy('e.id', 'e.title')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($x) => ['title' => (string) $x->title, 'count' => (int) $x->c])->all();

        return [
            'available' => true,
            'kpis' => [
                'targets'          => $rows,
                'exams_targeted'   => $examsWith,
                'exams_total'      => $examsTotal,
                'exams_untargeted' => max(0, $examsTotal - $examsWith),
                'avg_per_exam'     => $examsWith ? $this->rnd($rows / $examsWith, 2) : null,
                'faculties'        => (int) $t()->whereNotNull('t.faculty_id')->distinct()->count('t.faculty_id'),
                'departments'      => (int) $t()->whereNotNull('t.department_id')->distinct()->count('t.department_id'),
                'groups'           => (int) $t()->whereNotNull('t.group_id')->distinct()->count('t.group_id'),
                'students'         => (int) $t()->whereNotNull('t.student_id')->distinct()->count('t.student_id'),
            ],
            'daily'      => $this->fill($this->daily($t(), 't.created_at'), $r),
            'by_level'   => $this->groupCount($t(), $level),
            'by_academic_year' => $this->groupCount($t()->whereNotNull('t.academic_year'), 't.academic_year'),
            'top_exams'  => $top,
        ];
    }

    // -----------------------------------------------------------------
    // Grade history
    // -----------------------------------------------------------------

    private function grading(array $f): array
    {
        if (!$this->ready(['exam_grade_history', 'exam_grades', 'exam_attempts', 'exams'])) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $h = function () use ($f, $r) {
            $q = DB::table('exam_grade_history as h')
                ->join('exam_grades as g', 'g.id', '=', 'h.exam_grade_id')
                ->join('exam_attempts as ea', 'ea.id', '=', 'g.exam_attempt_id')
                ->join('exams as e', 'e.id', '=', 'ea.exam_id');
            return $this->between($this->scope($q, $f), 'h.changed_at', $r);
        };

        $total = (int) $h()->count();
        $manual = (int) $h()->where('h.source', '!=', 'automatic')->count();

        $deltas = [];
        $rows = $h()->whereNotNull('h.previous_score')->select('h.previous_score', 'h.new_score')->limit(self::MAX_ROWS)->get();
        foreach ($rows as $x) {
            $deltas[] = (float) $x->new_score - (float) $x->previous_score;
        }
        $bucket = fn (callable $fn) => count(array_filter($deltas, $fn));
        $buckets = [
            ['key' => 'down_big',   'count' => $bucket(fn ($d) => $d <= -5)],
            ['key' => 'down_small', 'count' => $bucket(fn ($d) => $d > -5 && $d < 0)],
            ['key' => 'same',       'count' => $bucket(fn ($d) => $d == 0)],
            ['key' => 'up_small',   'count' => $bucket(fn ($d) => $d > 0 && $d < 5)],
            ['key' => 'up_big',     'count' => $bucket(fn ($d) => $d >= 5)],
        ];

        $hasFb = Schema::hasColumn('exam_grade_history', 'new_feedback') && Schema::hasColumn('exam_grade_history', 'previous_feedback');

        $top = $h()->select('e.title', DB::raw('COUNT(*) as c'))->groupBy('e.id', 'e.title')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($x) => ['title' => (string) $x->title, 'count' => (int) $x->c])->all();

        return [
            'available' => true,
            'kpis' => [
                'changes'          => $total,
                'grades_changed'   => (int) $h()->distinct()->count('h.exam_grade_id'),
                'attempts_affected' => (int) $h()->distinct()->count('ea.id'),
                'exams_affected'   => (int) $h()->distinct()->count('e.id'),
                'manual_share'     => $this->rate($manual, $total),
                'avg_delta'        => $deltas ? $this->rnd(array_sum($deltas) / count($deltas), 2) : null,
                'increased'        => count(array_filter($deltas, fn ($d) => $d > 0)),
                'decreased'        => count(array_filter($deltas, fn ($d) => $d < 0)),
                'editors'          => (int) $h()->whereNotNull('h.changed_by_academic_staff_id')->distinct()->count('h.changed_by_academic_staff_id'),
                'with_reason'      => (int) $h()->whereNotNull('h.reason')->where('h.reason', '<>', '')->count(),
                'feedback_changed' => $hasFb ? (int) $h()->whereRaw("COALESCE(h.new_feedback, '') <> COALESCE(h.previous_feedback, '')")->count() : null,
            ],
            'daily'     => $this->fill($this->daily($h(), 'h.changed_at'), $r),
            'by_source' => $this->groupCount($h(), 'h.source'),
            'delta_buckets' => $buckets,
            'top_exams' => $top,
        ];
    }

    // -----------------------------------------------------------------
    // AI grading
    // -----------------------------------------------------------------

    private function ai(array $f): array
    {
        if (!$this->ready(['exam_ai_gradings', 'exam_grades', 'exam_attempts', 'exams'])) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $a = function () use ($f, $r) {
            $q = DB::table('exam_ai_gradings as ai')
                ->join('exam_grades as g', 'g.id', '=', 'ai.exam_grade_id')
                ->join('exam_attempts as ea', 'ea.id', '=', 'g.exam_attempt_id')
                ->join('exams as e', 'e.id', '=', 'ea.exam_id');
            return $this->between($this->scope($q, $f), 'ai.created_at', $r);
        };

        $total = (int) $a()->count();
        $completed = (int) $a()->where('ai.status', 'completed')->count();
        $failed = (int) $a()->where('ai.status', 'failed')->count();

        // سرعة التصحيح + اتفاق الـ AI مع الدرجة النهائية (للي اتراجع بشريًا).
        $lat = [];
        $conf = [];
        $absErr = [];
        $agree = ['exact' => 0, 'within_10' => 0, 'within_25' => 0, 'over_25' => 0];
        $reviewed = 0;
        $kept = 0;

        $rows = $a()->where('ai.status', 'completed')
            ->select('ai.marks_awarded as ai_m', 'ai.max_marks as ai_max', 'ai.confidence', 'ai.requested_at', 'ai.completed_at',
                'g.marks_awarded as final_m', 'g.max_marks as final_max', 'g.source')
            ->limit(self::MAX_ROWS)->get();

        foreach ($rows as $x) {
            if ($x->requested_at && $x->completed_at) {
                $lat[] = max(0, strtotime((string) $x->completed_at) - strtotime((string) $x->requested_at));
            }
            if ($x->confidence !== null) {
                $c = (float) $x->confidence;
                $conf[] = $c > 1 ? $c / 100 : $c;   // بيتخزن 0–1 غالبًا؛ بنطبّع لو جه 0–100.
            }
            $isHuman = in_array((string) $x->source, ['instructor', 'administrator', 'regrade'], true);
            if ($isHuman && $x->ai_m !== null && $x->final_m !== null && (float) $x->final_max > 0) {
                $reviewed++;
                $diffPct = abs((float) $x->ai_m - (float) $x->final_m) * 100 / (float) $x->final_max;
                $absErr[] = $diffPct;
                if ($diffPct == 0) {
                    $agree['exact']++;
                    $kept++;
                } elseif ($diffPct <= 10) {
                    $agree['within_10']++;
                    $kept++;
                } elseif ($diffPct <= 25) {
                    $agree['within_25']++;
                } else {
                    $agree['over_25']++;
                }
            }
        }

        $confBands = [
            ['key' => 'lt_50',   'count' => count(array_filter($conf, fn ($c) => $c < 0.5))],
            ['key' => '50_69',   'count' => count(array_filter($conf, fn ($c) => $c >= 0.5 && $c < 0.7))],
            ['key' => '70_84',   'count' => count(array_filter($conf, fn ($c) => $c >= 0.7 && $c < 0.85))],
            ['key' => '85_100',  'count' => count(array_filter($conf, fn ($c) => $c >= 0.85))],
        ];

        $avgAiPct = $a()->where('ai.status', 'completed')->where('ai.max_marks', '>', 0)->whereNotNull('ai.marks_awarded')
            ->selectRaw('AVG(ai.marks_awarded * 100.0 / ai.max_marks) as v')->value('v');

        return [
            'available' => true,
            'kpis' => [
                'gradings'       => $total,
                'completed'      => $completed,
                'failed'         => $failed,
                'in_flight'      => (int) $a()->whereIn('ai.status', ['queued', 'processing'])->count(),
                'failure_rate'   => $this->rate($failed, $completed + $failed),
                'avg_confidence' => $conf ? $this->rnd(array_sum($conf) / count($conf) * 100, 1) : null,
                'low_confidence' => count(array_filter($conf, fn ($c) => $c < 0.5)),
                'avg_ai_pct'     => $this->rnd($avgAiPct, 1),
                'avg_seconds'    => $lat ? $this->rnd(array_sum($lat) / count($lat), 1) : null,
                'human_reviewed' => $reviewed,
                'agreement_rate' => $this->rate($kept, $reviewed),
                'avg_abs_error_pct' => $absErr ? $this->rnd(array_sum($absErr) / count($absErr), 1) : null,
                'exams'          => (int) $a()->distinct()->count('e.id'),
            ],
            'daily'      => $this->fill($this->daily($a(), 'ai.created_at'), $r),
            'by_status'  => $this->groupCount($a(), 'ai.status'),
            'by_model'   => $this->groupCount($a()->whereNotNull('ai.model'), 'ai.model', 'key', 8),
            'confidence' => $confBands,
            'agreement'  => array_map(fn ($k, $v) => ['key' => $k, 'count' => $v], array_keys($agree), array_values($agree)),
            'final_source' => $this->groupCount($a()->where('ai.status', 'completed')->whereNotNull('g.source'), 'g.source'),
        ];
    }

    // -----------------------------------------------------------------
    // Proctoring (snapshots + sessions + identity)
    // -----------------------------------------------------------------

    private function proctoring(array $f): array
    {
        $hasSnap = $this->ready(['exam_proctoring_snapshots', 'exams']);
        $hasAttempts = $this->ready(['exam_attempts', 'exams']) && Schema::hasColumn('exam_attempts', 'identity_status');
        if (!$hasSnap && !$hasAttempts) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $out = ['available' => true, 'kpis' => [], 'daily' => $this->fill([], $r)];

        if ($hasSnap) {
            $s = fn () => $this->between($this->viaExam('exam_proctoring_snapshots', 's', $f), 's.captured_at', $r);
            $total = (int) $s()->count();
            $flagged = (int) $s()->whereNotNull('s.flags')->count();
            $bytes = (int) ($s()->sum('s.size_bytes') ?? 0);

            // تكرار كل flag (مسموحة بس) — الـ JSON بيتفك في PHP.
            $flags = [];
            foreach ($s()->whereNotNull('s.flags')->limit(self::MAX_ROWS)->pluck('s.flags') as $raw) {
                $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
                foreach ((array) $arr as $fl) {
                    if (is_string($fl) && in_array($fl, ExamProctoringService::ALLOWED_FLAGS, true)) {
                        $flags[$fl] = ($flags[$fl] ?? 0) + 1;
                    }
                }
            }
            arsort($flags);

            $out['snapshots'] = [
                'by_kind'  => $this->groupCount($s(), 's.kind'),
                'by_mime'  => $this->groupCount($s(), 's.mime'),
                'flags'    => array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_keys($flags), array_values($flags)),
            ];
            $out['kpis'] += [
                'snapshots'        => $total,
                'flagged_snapshots' => $flagged,
                'flag_rate'        => $this->rate($flagged, $total),
                'attempts_covered' => (int) $s()->distinct()->count('s.exam_attempt_id'),
                'students_covered' => (int) $s()->distinct()->count('s.student_id'),
                'storage_mb'       => $this->rnd($bytes / 1048576, 1),
                'avg_kb'           => $total ? $this->rnd($bytes / $total / 1024, 1) : null,
            ];
            $out['daily'] = $this->fill($this->daily($s(), 's.captured_at'), $r);
        }

        if ($hasAttempts) {
            $a = fn () => $this->between($this->viaExam('exam_attempts', 'ea', $f), 'ea.started_at', $r);
            $attempts = (int) $a()->count();
            $withFlags = Schema::hasColumn('exam_attempts', 'proctoring_flags_count')
                ? (int) $a()->where('ea.proctoring_flags_count', '>', 0)->count() : null;
            $multiClaims = Schema::hasColumn('exam_attempts', 'session_claims_count')
                ? (int) $a()->where('ea.session_claims_count', '>', 1)->count() : null;
            $reviewed = Schema::hasColumn('exam_attempts', 'identity_reviewed_at')
                ? (int) $a()->whereNotNull('ea.identity_reviewed_at')->count() : null;
            $idRequired = (int) $a()->where('e.identity_check_required', 1)->count();

            $out['attempts'] = [
                'identity_status' => $this->groupCount($a()->where('ea.identity_status', '<>', 'none'), 'ea.identity_status'),
                'avg_flags'       => $withFlags !== null ? $this->rnd($a()->avg('ea.proctoring_flags_count'), 2) : null,
            ];
            $out['kpis'] += [
                'attempts'          => $attempts,
                'attempts_flagged'  => $withFlags,
                'attempts_flag_rate' => $withFlags !== null ? $this->rate($withFlags, $attempts) : null,
                'identity_required_attempts' => $idRequired,
                'identity_reviewed' => $reviewed,
                'multi_claim_attempts' => $multiClaims,
            ];
        }

        // أحداث الجلسات (claim/resume/takeover/blocked) — بدون IP/UA/device.
        if ($this->ready(['exam_attempt_sessions', 'exam_attempts', 'exams'])) {
            $se = fn () => $this->between($this->viaAttempt('exam_attempt_sessions', 'ss', $f), 'ss.created_at', $r);
            $multiDevice = $se()->whereNotNull('ss.device_id')->select('ss.exam_attempt_id')->groupBy('ss.exam_attempt_id')
                ->havingRaw('COUNT(DISTINCT ss.device_id) > 1')->get()->count();
            $out['sessions'] = ['by_event' => $this->groupCount($se(), 'ss.event')];
            $out['kpis'] += [
                'session_events' => (int) $se()->count(),
                'takeovers'      => (int) $se()->where('ss.event', 'takeover')->count(),
                'blocked'        => (int) $se()->where('ss.event', 'blocked')->count(),
                'multi_device_attempts' => (int) $multiDevice,
            ];
        }

        // لقطة حالية: إعدادات المراقبة على الامتحانات نفسها.
        if (Schema::hasColumn('exams', 'proctoring_mode')) {
            $ex = fn () => $this->examsQ($f);
            $out['exam_modes'] = $this->groupCount($ex(), 'e.proctoring_mode');
            $out['kpis'] += [
                'exams_proctored' => (int) $ex()->where('e.proctoring_mode', '<>', 'off')->count(),
                'exams_required'  => (int) $ex()->where('e.proctoring_mode', 'required')->count(),
                'exams_total'     => (int) $ex()->count(),
                'exams_single_session' => Schema::hasColumn('exams', 'single_session_enabled') ? (int) $ex()->where('e.single_session_enabled', 1)->count() : null,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Question pools + rubrics (لقطة حالية)
    // -----------------------------------------------------------------

    private function pools(array $f): array
    {
        $hasPools = $this->ready(['question_pools', 'question_pool_questions']);
        $hasRub = $this->ready(['exam_rubrics', 'questions']);
        if (!$hasPools && !$hasRub) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $uni = $f['university_id'] ?? null;
        $out = ['available' => true, 'kpis' => [], 'daily' => $this->fill([], $r)];

        if ($hasPools) {
            $bankHasUni = Schema::hasTable('question_banks') && Schema::hasColumn('question_banks', 'university_id');
            $p = function () use ($uni, $bankHasUni) {
                $q = DB::table('question_pools as qp')->whereNull('qp.deleted_at');
                if ($uni && $bankHasUni) {
                    $q->join('question_banks as qb', 'qb.id', '=', 'qp.question_bank_id')->where('qb.university_id', (int) $uni);
                }
                return $q;
            };

            // حجم كل pool (أسئلة غير محذوفة).
            $sizes = $p()->leftJoin('question_pool_questions as pq', 'pq.question_pool_id', '=', 'qp.id')
                ->leftJoin('questions as q', function ($j) {
                    $j->on('q.id', '=', 'pq.question_id')->whereNull('q.deleted_at');
                })
                ->select('qp.id', DB::raw('COUNT(q.id) as c'))->groupBy('qp.id')->pluck('c', 'id')->map(fn ($v) => (int) $v)->all();
            $sb = ['0' => 0, '1-9' => 0, '10-24' => 0, '25-49' => 0, '50+' => 0];
            foreach ($sizes as $n) {
                $sb[$n === 0 ? '0' : ($n < 10 ? '1-9' : ($n < 25 ? '10-24' : ($n < 50 ? '25-49' : '50+')))]++;
            }

            $out['pools'] = [
                'sizes' => array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_keys($sb), array_values($sb)),
                'difficulty' => [],
                'types' => [],
            ];
            $poolQ = fn () => $p()->join('question_pool_questions as pq', 'pq.question_pool_id', '=', 'qp.id')
                ->join('questions as q', 'q.id', '=', 'pq.question_id')->whereNull('q.deleted_at');
            $out['pools']['difficulty'] = $this->groupCount($poolQ(), 'q.difficulty');
            $out['pools']['types'] = $this->groupCount($poolQ(), 'q.type');

            $kpis = [
                'pools'          => count($sizes),
                'pool_questions' => array_sum($sizes),
                'avg_pool_size'  => $sizes ? $this->rnd(array_sum($sizes) / count($sizes), 1) : null,
                'empty_pools'    => count(array_filter($sizes, fn ($n) => $n === 0)),
            ];

            // إعدادات الامتحانات على الـ pools + هامش الأمان (حجم الـ pool ÷ المطلوب سحبه).
            if ($this->ready(['exam_question_pools', 'exams'])) {
                $cfg = $this->viaExam('exam_question_pools', 'x', $f)->select('x.question_pool_id', 'x.questions_to_select', 'x.marks_per_question')
                    ->limit(self::MAX_ROWS)->get();
                $hd = ['short' => 0, 'tight' => 0, 'comfortable' => 0, 'wide' => 0];
                $usedPools = [];
                foreach ($cfg as $c) {
                    $usedPools[(int) $c->question_pool_id] = true;
                    $size = $sizes[(int) $c->question_pool_id] ?? null;
                    $need = (int) $c->questions_to_select;
                    if ($size === null || $need <= 0) {
                        continue;
                    }
                    $ratio = $size / $need;
                    $hd[$ratio < 1 ? 'short' : ($ratio < 2 ? 'tight' : ($ratio < 5 ? 'comfortable' : 'wide'))]++;
                }
                $cnt = count($cfg);
                $totalMarks = array_sum(array_map(fn ($c) => (float) $c->marks_per_question * (int) $c->questions_to_select, $cfg->all()));
                $kpis += [
                    'exam_pool_configs' => $cnt,
                    'exams_using_pools' => (int) $this->viaExam('exam_question_pools', 'x', $f)->distinct()->count('x.exam_id'),
                    'pools_in_use'      => count($usedPools),
                    'pools_unused'      => max(0, count($sizes) - count($usedPools)),
                    'avg_select'        => $cnt ? $this->rnd(array_sum(array_map(fn ($c) => (int) $c->questions_to_select, $cfg->all())) / $cnt, 1) : null,
                    'avg_marks_per_config' => $cnt ? $this->rnd($totalMarks / $cnt, 1) : null,
                    'pools_too_small'   => $hd['short'],
                ];
                $out['pools']['headroom'] = array_map(fn ($k, $v) => ['key' => $k, 'count' => $v], array_keys($hd), array_values($hd));
                if (Schema::hasColumn('exam_question_pools', 'difficulty_distribution')) {
                    $kpis['with_difficulty_rule'] = (int) $this->viaExam('exam_question_pools', 'x', $f)->whereNotNull('x.difficulty_distribution')->count();
                }
                if (Schema::hasColumn('exam_question_pools', 'topic_distribution')) {
                    $kpis['with_topic_rule'] = (int) $this->viaExam('exam_question_pools', 'x', $f)->whereNotNull('x.topic_distribution')->count();
                }
            }

            $out['kpis'] += $kpis;
            $out['daily'] = $this->fill($this->daily($this->between($p(), 'qp.created_at', $r), 'qp.created_at'), $r);
        }

        if ($hasRub) {
            $bankHasUni = Schema::hasTable('question_banks') && Schema::hasColumn('question_banks', 'university_id');
            $rb = function () use ($uni, $bankHasUni) {
                $q = DB::table('exam_rubrics as rb')->join('questions as q', 'q.id', '=', 'rb.question_id')->whereNull('q.deleted_at');
                if ($uni && $bankHasUni) {
                    $q->join('question_banks as qb', 'qb.id', '=', 'q.question_bank_id')->where('qb.university_id', (int) $uni);
                }
                return $q;
            };
            $rubrics = (int) $rb()->count();
            $rub = ['rubrics' => $rubrics, 'questions_with_rubric' => (int) $rb()->distinct()->count('rb.question_id')];

            if (Schema::hasTable('exam_rubric_criteria')) {
                $per = $rb()->join('exam_rubric_criteria as rc', 'rc.exam_rubric_id', '=', 'rb.id')
                    ->select('rb.id', DB::raw('COUNT(rc.id) as n'), DB::raw('SUM(rc.max_points) as pts'))->groupBy('rb.id')->get();
                $rub['criteria'] = (int) $per->sum('n');
                $rub['avg_criteria'] = $per->count() ? $this->rnd($per->sum('n') / $per->count(), 1) : null;
                $rub['avg_total_points'] = $per->count() ? $this->rnd($per->sum('pts') / $per->count(), 1) : null;
            }

            if (Schema::hasColumn('questions', 'ai_grading_enabled')) {
                $aiQ = DB::table('questions as q')->whereNull('q.deleted_at')->where('q.ai_grading_enabled', 1);
                if ($uni && $bankHasUni) {
                    $aiQ->join('question_banks as qb', 'qb.id', '=', 'q.question_bank_id')->where('qb.university_id', (int) $uni);
                }
                $aiTotal = (int) (clone $aiQ)->count();
                $aiWith = (int) (clone $aiQ)->whereExists(fn ($s) => $s->select(DB::raw(1))->from('exam_rubrics as r2')->whereColumn('r2.question_id', 'q.id'))->count();
                $rub['ai_questions'] = $aiTotal;
                $rub['ai_questions_with_rubric'] = $aiWith;
                $rub['ai_rubric_coverage'] = $this->rate($aiWith, $aiTotal);
            }

            $out['rubrics'] = $rub;
            $out['kpis'] += [
                'rubrics'            => $rubrics,
                'ai_rubric_coverage' => $rub['ai_rubric_coverage'] ?? null,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Student overrides (+ cancellations + extra time + late)
    // -----------------------------------------------------------------

    private function overrides(array $f): array
    {
        $hasO = $this->ready(['exam_student_overrides', 'exams']);
        $hasA = $this->ready(['exam_attempts', 'exams']);
        if (!$hasO && !$hasA) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $out = ['available' => true, 'kpis' => [], 'daily' => $this->fill([], $r)];
        $now = Carbon::now()->toDateTimeString();

        if ($hasO) {
            $o = fn () => $this->between($this->viaExam('exam_student_overrides', 'o', $f), 'o.created_at', $r);
            $total = (int) $o()->count();
            $extra = (int) ($o()->sum('o.extra_attempts') ?? 0);
            $dist = [
                ['key' => '0',  'count' => (int) $o()->where('o.extra_attempts', 0)->count()],
                ['key' => '1',  'count' => (int) $o()->where('o.extra_attempts', 1)->count()],
                ['key' => '2',  'count' => (int) $o()->where('o.extra_attempts', 2)->count()],
                ['key' => '3+', 'count' => (int) $o()->where('o.extra_attempts', '>=', 3)->count()],
            ];
            $top = $o()->select('e.title', DB::raw('COUNT(*) as c'))->groupBy('e.id', 'e.title')->orderByDesc('c')->limit(10)->get()
                ->map(fn ($x) => ['title' => (string) $x->title, 'count' => (int) $x->c])->all();

            $out['overrides'] = ['extra_attempts_dist' => $dist, 'top_exams' => $top];
            $out['kpis'] += [
                'overrides'        => $total,
                'students'         => (int) $o()->distinct()->count('o.student_id'),
                'exams'            => (int) $o()->distinct()->count('o.exam_id'),
                'extra_attempts'   => $extra,
                'avg_extra'        => $total ? $this->rnd($extra / $total, 2) : null,
                'with_extension'   => (int) $o()->whereNotNull('o.available_until')->count(),
                'extension_active' => (int) $o()->whereNotNull('o.available_until')->where('o.available_until', '>', $now)->count(),
                'granters'         => (int) $o()->whereNotNull('o.granted_by')->distinct()->count('o.granted_by'),
                'with_reason'      => (int) $o()->whereNotNull('o.reason')->where('o.reason', '<>', '')->count(),
                'camera_waived'    => Schema::hasColumn('exam_student_overrides', 'proctoring_waived')
                    ? (int) $o()->where('o.proctoring_waived', 1)->count() : null,
            ];
            $out['daily'] = $this->fill($this->daily($o(), 'o.created_at'), $r);
        }

        if ($hasA) {
            $a = fn () => $this->between($this->viaExam('exam_attempts', 'ea', $f), 'ea.started_at', $r);
            $total = (int) $a()->count();
            $att = ['attempts' => $total];
            if (Schema::hasColumn('exam_attempts', 'cancelled_at')) {
                $att['cancelled'] = (int) $a()->whereNotNull('ea.cancelled_at')->count();
                $att['cancel_rate'] = $this->rate($att['cancelled'], $total);
            }
            if (Schema::hasColumn('exam_attempts', 'extra_time_minutes')) {
                $att['with_extra_time'] = (int) $a()->where('ea.extra_time_minutes', '>', 0)->count();
                $att['avg_extra_minutes'] = $this->rnd($a()->where('ea.extra_time_minutes', '>', 0)->avg('ea.extra_time_minutes'), 1);
            }
            if (Schema::hasColumn('exam_attempts', 'is_late')) {
                $att['late'] = (int) $a()->where('ea.is_late', 1)->count();
                $att['late_rate'] = $this->rate($att['late'], $total);
                $att['avg_late_penalty'] = Schema::hasColumn('exam_attempts', 'late_penalty_percent')
                    ? $this->rnd($a()->where('ea.is_late', 1)->whereNotNull('ea.late_penalty_percent')->avg('ea.late_penalty_percent'), 1) : null;
            }
            $out['attempts'] = $att;
            $out['kpis'] += array_diff_key($att, ['attempts' => 1]);
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @param string[] $tables */
    private function ready(array $tables): bool
    {
        foreach ($tables as $t) {
            if (!Schema::hasTable($t)) {
                return false;
            }
        }
        return true;
    }

    /** نفس examScope في DataAnalysisExamsService: مش محذوف، مش قالب، + فلاتر الجامعة/الكلية/المقرر/النوع. */
    private function scope($q, array $f)
    {
        $q->whereNull('e.deleted_at');
        if (Schema::hasColumn('exams', 'is_template')) {
            $q->where(fn ($w) => $w->whereNull('e.is_template')->orWhere('e.is_template', 0));
        }
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

    private function examsQ(array $f)
    {
        return $this->scope(DB::table('exams as e'), $f);
    }

    /** جدول عنده exam_id. */
    private function viaExam(string $table, string $alias, array $f)
    {
        return $this->scope(DB::table($table . ' as ' . $alias)->join('exams as e', 'e.id', '=', $alias . '.exam_id'), $f);
    }

    /** جدول عنده exam_attempt_id → exam_attempts (ea) → exams. */
    private function viaAttempt(string $table, string $alias, array $f, string $col = 'exam_attempt_id')
    {
        return $this->scope(
            DB::table($table . ' as ' . $alias)
                ->join('exam_attempts as ea', 'ea.id', '=', $alias . '.' . $col)
                ->join('exams as e', 'e.id', '=', 'ea.exam_id'),
            $f
        );
    }

    private function between($query, string $col, array $r)
    {
        return $query->whereBetween($col, [$r['start'], $r['end']]);
    }

    private function rate(int $a, int $b): ?float
    {
        return $b > 0 ? round($a * 100 / $b, 1) : null;
    }

    private function rnd($v, int $d = 2): ?float
    {
        return $v === null ? null : round((float) $v, $d);
    }

    private function range(array $f): array
    {
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $to = $ok($f['to'] ?? null) ? Carbon::parse($f['to']) : Carbon::today();
        $from = $ok($f['from'] ?? null) ? Carbon::parse($f['from']) : $to->copy()->subDays(self::DEFAULT_DAYS - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }
        $days = (int) round((strtotime($to->toDateString()) - strtotime($from->toDateString())) / 86400) + 1;
        if ($days > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
            $days = self::MAX_DAYS;
        }
        return [
            'from'  => $from->toDateString(),
            'to'    => $to->toDateString(),
            'start' => $from->toDateString() . ' 00:00:00',
            'end'   => $to->toDateString() . ' 23:59:59',
            'days'  => $days,
        ];
    }

    /** @return string[] */
    private function dates(array $r): array
    {
        $out = [];
        $d = Carbon::parse($r['from']);
        $end = Carbon::parse($r['to']);
        while ($d->lte($end)) {
            $out[] = $d->toDateString();
            $d->addDay();
        }
        return $out;
    }

    private function daily($query, string $col): array
    {
        return $query->select(DB::raw("DATE($col) as d"), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw("DATE($col)"))->pluck('c', 'd')
            ->map(fn ($v) => (int) $v)->all();
    }

    private function fill(array $map, array $r): array
    {
        $out = [];
        foreach ($this->dates($r) as $d) {
            $out[] = ['date' => $d, 'value' => (int) ($map[$d] ?? 0)];
        }
        return $out;
    }

    private function toMap(array $series): array
    {
        $m = [];
        foreach ($series as $p) {
            $m[$p['date']] = (int) $p['value'];
        }
        return $m;
    }

    private function groupCount($query, string $col, string $as = 'key', ?int $limit = null): array
    {
        $q = $query->select(DB::raw("$col as k"), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw($col))->orderByDesc('c');
        if ($limit) {
            $q->limit($limit);
        }
        return $q->get()->map(fn ($x) => ['key' => $x->k === null ? '' : (string) $x->k, 'count' => (int) $x->c])->all();
    }
}
