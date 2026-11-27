<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بند 8 من نواقص بورتال الأمان — "أمان الامتحانات".
 *
 * الـ backend بيسجل أحداث أمان لكل محاولة امتحان في exam_security_events (تبديل نافذة، نسخ/لصق، فتح DevTools،
 * استحواذ على جلسة، إشارات الكاميرا/الهوية...). الخدمة دي بتجمّع الأحداث دي عبر كل الامتحانات للجنة الأمن:
 * مين المحاولات المشبوهة، مين الطلاب اللي بيتكرر معاهم الموضوع في أكتر من امتحان، وإيه الشبكات/الأجهزة المشتركة
 * بين طلاب مختلفين في نفس الامتحان.
 *
 * قراءة بس: مفيش أي كتابة على جداول الامتحانات، والخدمة عمرها ما بتغيّر درجة أو حالة محاولة.
 *
 * "درجة الخطورة" (risk score) = مجموع أوزان الأحداث المشبوهة في المحاولة (WEIGHTS). هي إشارة للمراجعة البشرية
 * مش حكم: أحداث browser-level بتتأثر بأعطال الشبكة/الجهاز، وIP/الجهاز المشترك ممكن يبقى شبكة جامعة (NAT) —
 * الشاشة بتوضّح ده للمراجع.
 */
class SecurityExamMonitoringService
{
    /** وزن كل حدث في درجة خطورة المحاولة. الأحداث الإعلامية (question_changed, answer_saved, window_focus...) خارج الحساب عمدًا. */
    public const WEIGHTS = [
        'tab_switch'        => 1,
        'window_blur'       => 1,
        'fullscreen_exited' => 1,
        'devtools_attempt'  => 1,
        'copy_attempt'      => 2,
        'paste_attempt'     => 2,
        'cut_attempt'       => 2,
        'camera_stopped'    => 2,
        'proctoring_gap'    => 2,
        'camera_denied'     => 3,
        'session_conflict'  => 3,
        'proctoring_flag'   => 4,
        'devtools_opened'   => 5,
        'session_takeover'  => 8,
        'identity_rejected' => 10,
    ];

    /** حدود مستويات الخطورة (أقل درجة للمستوى). */
    public const RISK_LEVELS = ['critical' => 30, 'high' => 15, 'medium' => 6, 'low' => 1];

    public const MAX_EXPORT_ROWS = 5000;

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * @return array{from:Carbon,to:Carbon,days:int,exam_id:?int,university_id:?int,student_id:?int,event_type:?string,risk:?string,q:string}
     */
    public function filters(array $in): array
    {
        $to = Carbon::now()->endOfDay();
        $from = null;

        $df = trim((string) ($in['date_from'] ?? ''));
        $dt = trim((string) ($in['date_to'] ?? ''));
        try {
            if ($df !== '') {
                $from = Carbon::parse($df)->startOfDay();
            }
            if ($dt !== '') {
                $to = Carbon::parse($dt)->endOfDay();
            }
        } catch (\Throwable $e) {
            $from = null;
        }

        $days = (int) ($in['days'] ?? 30);
        if ($days < 1 || $days > 365) {
            $days = 30;
        }
        if ($from === null) {
            $from = (clone $to)->subDays($days - 1)->startOfDay();
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $type = trim((string) ($in['event_type'] ?? ''));
        $risk = trim((string) ($in['risk'] ?? ''));

        return [
            'from'          => $from,
            'to'            => $to,
            'days'          => max(1, (int) $from->diffInDays($to) + 1),
            'exam_id'       => ($v = (int) ($in['exam_id'] ?? 0)) > 0 ? $v : null,
            'university_id' => ($v = (int) ($in['university_id'] ?? 0)) > 0 ? $v : null,
            'student_id'    => ($v = (int) ($in['student_id'] ?? 0)) > 0 ? $v : null,
            'event_type'    => isset(self::WEIGHTS[$type]) ? $type : null,
            'risk'          => isset(self::RISK_LEVELS[$risk]) ? $risk : null,
            'q'             => mb_substr(trim((string) ($in['q'] ?? '')), 0, 100),
        ];
    }

    public static function riskLevel(int $score): string
    {
        foreach (self::RISK_LEVELS as $level => $min) {
            if ($score >= $min) {
                return $level;
            }
        }
        return 'none';
    }

    // ------------------------------------------------------------------
    // Query building blocks
    // ------------------------------------------------------------------

    /** CASE بتجمع الأوزان — القيم كلها من ثوابت الكلاس (مفيش input من المستخدم جوّه الـ SQL). */
    private function scoreExpr(string $col = 'ev.event_type'): string
    {
        $when = '';
        foreach (self::WEIGHTS as $type => $weight) {
            $when .= " WHEN '{$type}' THEN {$weight}";
        }
        return "SUM(CASE {$col}{$when} ELSE 0 END)";
    }

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /** أحداث أمان مشبوهة ضمن المدى والفلاتر، منضمّة لـ exams (عشان الجامعة/الامتحان المحذوف). */
    private function baseEvents(array $f)
    {
        $q = DB::table('exam_security_events as ev')
            ->join('exams as ex', 'ex.id', '=', 'ev.exam_id')
            ->whereNull('ex.deleted_at')
            ->whereIn('ev.event_type', array_keys(self::WEIGHTS))
            ->whereBetween('ev.occurred_at', [$f['from'], $f['to']]);

        if ($f['exam_id']) {
            $q->where('ev.exam_id', $f['exam_id']);
        }
        if ($f['university_id']) {
            $q->where('ex.university_id', $f['university_id']);
        }
        if ($f['student_id']) {
            $q->where('ev.student_id', $f['student_id']);
        }
        if ($f['event_type']) {
            // المحاولة لازم يكون فيها النوع ده؛ الدرجة بتفضل محسوبة على كل أحداثها المشبوهة.
            $type = $f['event_type'];
            $from = $f['from'];
            $to = $f['to'];
            $q->whereIn('ev.exam_attempt_id', function ($sub) use ($type, $from, $to) {
                $sub->select('x.exam_attempt_id')->from('exam_security_events as x')
                    ->where('x.event_type', $type)->whereBetween('x.occurred_at', [$from, $to]);
            });
        }
        if ($f['q'] !== '') {
            $like = '%' . $this->escapeLike($f['q']) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('ex.title', 'like', $like)
                    ->orWhereIn('ev.student_id', function ($sub) use ($like) {
                        $sub->select('s.id')->from('students as s')
                            ->join('users as u', 'u.id', '=', 's.user_id')
                            ->where(function ($x) use ($like) {
                                $x->where('u.full_name', 'like', $like)
                                    ->orWhere('u.email', 'like', $like)
                                    ->orWhere('s.student_number', 'like', $like);
                            });
                    });
            });
        }

        return $q;
    }

    /** تجميع لكل محاولة: عدد الأحداث/المخالفات/الدرجة. بيرجع Builder جاهز للـ fromSub أو الـ get. */
    private function perAttempt(array $f)
    {
        $q = $this->baseEvents($f)
            ->selectRaw(
                'ev.exam_attempt_id, ev.exam_id, ev.student_id, COUNT(*) AS events, '
                . 'SUM(CASE WHEN ev.is_violation = 1 THEN 1 ELSE 0 END) AS violations, '
                . 'MIN(ev.occurred_at) AS first_event_at, MAX(ev.occurred_at) AS last_event_at, '
                . $this->scoreExpr() . ' AS score'
            )
            ->groupBy('ev.exam_attempt_id', 'ev.exam_id', 'ev.student_id');

        if ($f['risk']) {
            $min = self::RISK_LEVELS[$f['risk']];
            $q->havingRaw($this->scoreExpr() . ' >= ?', [$min]);
            $higher = $this->nextLevelMin($f['risk']);
            if ($higher !== null) {
                $q->havingRaw($this->scoreExpr() . ' < ?', [$higher]);
            }
        }

        return $q;
    }

    private function nextLevelMin(string $level): ?int
    {
        $mins = array_values(self::RISK_LEVELS); // الأعلى أولًا
        $keys = array_keys(self::RISK_LEVELS);
        $i = array_search($level, $keys, true);
        return ($i !== false && $i > 0) ? $mins[$i - 1] : null;
    }

    /** @return array<int,array{id:int,name:?string,email:?string,student_number:?string}> students.id => بيانات العرض */
    private function studentCards(array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (!$studentIds) {
            return [];
        }
        $out = [];
        $rows = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.id', $studentIds)
            ->get(['s.id as student_id', 'u.id as user_id', 'u.full_name', 'u.email', 's.student_number']);
        foreach ($rows as $r) {
            $out[(int) $r->student_id] = [
                'student_id'     => (int) $r->student_id,
                'user_id'        => (int) $r->user_id,
                'full_name'      => $r->full_name,
                'email'          => $r->email,
                'student_number' => $r->student_number,
            ];
        }
        return $out;
    }

    private function card(array $cards, $studentId): array
    {
        return $cards[(int) $studentId] ?? ['student_id' => (int) $studentId, 'user_id' => null, 'full_name' => null, 'email' => null, 'student_number' => null];
    }

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    public function overview(array $f): array
    {
        return [
            'kpis'        => $this->kpis($f),
            'by_type'     => $this->byType($f),
            'trend'       => $this->trend($f),
            'top_exams'   => $this->topExams($f),
            'top_students' => $this->topStudents($f, 10, 1),
            'shared'      => $this->sharedNetworks($f),
            'similarity'  => $this->similaritySummary($f),
        ];
    }

    private function kpis(array $f): array
    {
        $agg = DB::query()->fromSub($this->perAttempt($f), 't')->selectRaw(
            'COUNT(*) AS attempts, COUNT(DISTINCT student_id) AS students, COUNT(DISTINCT exam_id) AS exams, '
            . 'COALESCE(SUM(events),0) AS events, COALESCE(SUM(violations),0) AS violations, '
            . 'COALESCE(SUM(CASE WHEN score >= 30 THEN 1 ELSE 0 END),0) AS critical, '
            . 'COALESCE(SUM(CASE WHEN score >= 15 AND score < 30 THEN 1 ELSE 0 END),0) AS high, '
            . 'COALESCE(SUM(CASE WHEN score >= 6 AND score < 15 THEN 1 ELSE 0 END),0) AS medium, '
            . 'COALESCE(SUM(CASE WHEN score < 6 THEN 1 ELSE 0 END),0) AS low'
        )->first();

        $counts = $this->baseEvents($f)
            ->selectRaw('ev.event_type, COUNT(*) AS c')->groupBy('ev.event_type')->pluck('c', 'event_type');

        // محاولات اتسلّمت أوتوماتيك لأنها وصلت الحد الأقصى للمخالفات (مش بسبب انتهاء الوقت).
        $autoQ = DB::table('exam_attempts as a')->join('exams as ex', 'ex.id', '=', 'a.exam_id')
            ->whereNull('ex.deleted_at')
            ->where('a.auto_submitted', 1)
            ->whereNotNull('ex.max_violations')
            ->whereColumn('a.violations_count', '>=', 'ex.max_violations')
            ->where('a.violations_count', '>', 0)
            ->whereBetween('a.started_at', [$f['from'], $f['to']]);
        if ($f['exam_id']) {
            $autoQ->where('a.exam_id', $f['exam_id']);
        }
        if ($f['university_id']) {
            $autoQ->where('ex.university_id', $f['university_id']);
        }
        if ($f['student_id']) {
            $autoQ->where('a.student_id', $f['student_id']);
        }

        return [
            'events'                   => (int) $agg->events,
            'violations'               => (int) $agg->violations,
            'attempts_flagged'         => (int) $agg->attempts,
            'students_flagged'         => (int) $agg->students,
            'exams_affected'           => (int) $agg->exams,
            'risk'                     => ['critical' => (int) $agg->critical, 'high' => (int) $agg->high, 'medium' => (int) $agg->medium, 'low' => (int) $agg->low],
            'auto_submitted_violations' => (int) $autoQ->count(),
            'session_takeovers'        => (int) ($counts['session_takeover'] ?? 0),
            'devtools_opened'          => (int) ($counts['devtools_opened'] ?? 0),
            'identity_rejected'        => (int) ($counts['identity_rejected'] ?? 0),
            'proctoring_flags'         => (int) ($counts['proctoring_flag'] ?? 0),
        ];
    }

    private function byType(array $f): array
    {
        return $this->baseEvents($f)
            ->selectRaw('ev.event_type, COUNT(*) AS total, COUNT(DISTINCT ev.exam_attempt_id) AS attempts')
            ->groupBy('ev.event_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'event_type' => $r->event_type,
                'total'      => (int) $r->total,
                'attempts'   => (int) $r->attempts,
                'weight'     => self::WEIGHTS[$r->event_type] ?? 0,
            ])->all();
    }

    /** @return array{labels:string[],events:int[],violations:int[],attempts:int[]} يوم بيوم، الأيام الفاضية بتتملي بصفر. */
    private function trend(array $f): array
    {
        $rows = $this->baseEvents($f)
            ->selectRaw('DATE(ev.occurred_at) AS day, COUNT(*) AS events, '
                . 'SUM(CASE WHEN ev.is_violation = 1 THEN 1 ELSE 0 END) AS violations, '
                . 'COUNT(DISTINCT ev.exam_attempt_id) AS attempts')
            ->groupByRaw('DATE(ev.occurred_at)')
            ->get()->keyBy(fn ($r) => (string) $r->day);

        $labels = []; $events = []; $viol = []; $att = [];
        $cursor = $f['from']->copy()->startOfDay();
        $guard = 0;
        while ($cursor->lte($f['to']) && $guard++ < 400) {
            $key = $cursor->format('Y-m-d');
            $r = $rows->get($key);
            $labels[] = $key;
            $events[] = $r ? (int) $r->events : 0;
            $viol[] = $r ? (int) $r->violations : 0;
            $att[] = $r ? (int) $r->attempts : 0;
            $cursor->addDay();
        }

        return ['labels' => $labels, 'events' => $events, 'violations' => $viol, 'attempts' => $att];
    }

    private function topExams(array $f, int $limit = 10): array
    {
        $rows = $this->baseEvents($f)
            ->selectRaw('ev.exam_id, ex.title, ex.university_id, COUNT(DISTINCT ev.exam_attempt_id) AS flagged_attempts, '
                . 'COUNT(DISTINCT ev.student_id) AS students, COUNT(*) AS events, '
                . 'SUM(CASE WHEN ev.is_violation = 1 THEN 1 ELSE 0 END) AS violations, ' . $this->scoreExpr() . ' AS score')
            ->groupBy('ev.exam_id', 'ex.title', 'ex.university_id')
            ->orderByDesc('score')->limit($limit)->get();

        $totals = [];
        if ($rows->isNotEmpty()) {
            $totals = DB::table('exam_attempts')->whereIn('exam_id', $rows->pluck('exam_id')->all())
                ->whereBetween('started_at', [$f['from'], $f['to']])
                ->selectRaw('exam_id, COUNT(*) AS c')->groupBy('exam_id')->pluck('c', 'exam_id')->all();
        }

        return $rows->map(function ($r) use ($totals) {
            $total = max((int) ($totals[$r->exam_id] ?? 0), (int) $r->flagged_attempts);
            return [
                'exam_id'          => (int) $r->exam_id,
                'title'            => $r->title,
                'flagged_attempts' => (int) $r->flagged_attempts,
                'attempts_total'   => $total,
                'flag_rate'        => $total > 0 ? round(((int) $r->flagged_attempts / $total) * 100, 1) : 0.0,
                'students'         => (int) $r->students,
                'events'           => (int) $r->events,
                'violations'       => (int) $r->violations,
                'score'            => (int) $r->score,
            ];
        })->all();
    }

    /**
     * طلاب لهم أحداث مشبوهة، مرتبين بمجموع الدرجة. $minExams = 2 يطلّع المتكرر عبر أكتر من امتحان.
     * @return array<int,array<string,mixed>>
     */
    private function topStudents(array $f, int $limit, int $minExams): array
    {
        $q = $this->baseEvents($f)
            ->selectRaw('ev.student_id, COUNT(DISTINCT ev.exam_id) AS exams, COUNT(DISTINCT ev.exam_attempt_id) AS attempts, '
                . 'COUNT(*) AS events, SUM(CASE WHEN ev.is_violation = 1 THEN 1 ELSE 0 END) AS violations, '
                . $this->scoreExpr() . ' AS score, MAX(ev.occurred_at) AS last_event_at')
            ->groupBy('ev.student_id');
        if ($minExams > 1) {
            $q->havingRaw('COUNT(DISTINCT ev.exam_id) >= ?', [$minExams]);
        }
        $rows = $q->orderByDesc('score')->limit($limit)->get();

        return $this->hydrateStudentRows($rows);
    }

    private function hydrateStudentRows($rows): array
    {
        $cards = $this->studentCards($rows->pluck('student_id')->all());
        return $rows->map(fn ($r) => array_merge($this->card($cards, $r->student_id), [
            'exams'         => (int) $r->exams,
            'attempts'      => (int) $r->attempts,
            'events'        => (int) $r->events,
            'violations'    => (int) $r->violations,
            'score'         => (int) $r->score,
            'risk'          => self::riskLevel((int) $r->score),
            'last_event_at' => $r->last_event_at,
        ]))->all();
    }

    /**
     * IP أو جهاز (device id) واحد ظهر لأكتر من طالب في نفس الامتحان.
     * @return array<int,array<string,mixed>>
     */
    private function sharedNetworks(array $f): array
    {
        $out = [];
        foreach (['session_ip' => 'ip', 'session_device_id' => 'device'] as $col => $kind) {
            if (!Schema::hasColumn('exam_attempts', $col)) {
                continue;
            }
            $q = DB::table('exam_attempts as a')->join('exams as ex', 'ex.id', '=', 'a.exam_id')
                ->whereNull('ex.deleted_at')
                ->whereBetween('a.started_at', [$f['from'], $f['to']])
                ->whereNotNull("a.{$col}")->where("a.{$col}", '!=', '');
            if ($f['exam_id']) {
                $q->where('a.exam_id', $f['exam_id']);
            }
            if ($f['university_id']) {
                $q->where('ex.university_id', $f['university_id']);
            }
            $groups = $q->selectRaw("a.exam_id, ex.title, a.{$col} AS net_value, COUNT(DISTINCT a.student_id) AS students, COUNT(*) AS attempts")
                ->groupBy('a.exam_id', 'ex.title', "a.{$col}")
                ->havingRaw('COUNT(DISTINCT a.student_id) >= 2')
                ->orderByDesc('students')->limit(15)->get();
            if ($groups->isEmpty()) {
                continue;
            }

            // أسماء الطلاب لكل مجموعة — استعلام واحد بدل واحد لكل مجموعة.
            $members = DB::table('exam_attempts')->whereIn('exam_id', $groups->pluck('exam_id')->unique()->all())
                ->whereIn($col, $groups->pluck('net_value')->unique()->all())
                ->whereBetween('started_at', [$f['from'], $f['to']])
                ->get(['exam_id', $col . ' as net_value', 'student_id']);
            $byKey = [];
            foreach ($members as $m) {
                $byKey[$m->exam_id . '|' . $m->net_value][(int) $m->student_id] = true;
            }
            $allIds = [];
            foreach ($byKey as $set) {
                foreach (array_keys($set) as $sid) {
                    $allIds[] = $sid;
                }
            }
            $cards = $this->studentCards($allIds);

            foreach ($groups as $g) {
                $ids = array_keys($byKey[$g->exam_id . '|' . $g->net_value] ?? []);
                $out[] = [
                    'kind'     => $kind,
                    'exam_id'  => (int) $g->exam_id,
                    'title'    => $g->title,
                    'value'    => $g->net_value,
                    'students' => (int) $g->students,
                    'attempts' => (int) $g->attempts,
                    'members'  => array_map(fn ($id) => $this->card($cards, $id), array_slice($ids, 0, 6)),
                ];
            }
        }

        usort($out, fn ($a, $b) => [$b['kind'] === 'device', $b['students']] <=> [$a['kind'] === 'device', $a['students']]);
        return array_slice($out, 0, 25);
    }

    private function similaritySummary(array $f): array
    {
        $empty = ['pending' => 0, 'confirmed' => 0, 'dismissed' => 0];
        if (!Schema::hasTable('exam_similarity_flags')) {
            return $empty;
        }
        $q = DB::table('exam_similarity_flags as sf')->join('exams as ex', 'ex.id', '=', 'sf.exam_id')
            ->whereNull('ex.deleted_at')->whereBetween('sf.created_at', [$f['from'], $f['to']]);
        if ($f['exam_id']) {
            $q->where('sf.exam_id', $f['exam_id']);
        }
        if ($f['university_id']) {
            $q->where('ex.university_id', $f['university_id']);
        }
        $rows = $q->selectRaw('sf.status, COUNT(*) AS c')->groupBy('sf.status')->pluck('c', 'status')->all();

        return [
            'pending'   => (int) ($rows['pending'] ?? 0),
            'confirmed' => (int) ($rows['confirmed'] ?? 0),
            'dismissed' => (int) ($rows['dismissed'] ?? 0),
        ];
    }

    /** قوائم الفلاتر: امتحانات ليها أحداث مشبوهة في المدى + الجامعات. */
    public function filterOptions(array $f): array
    {
        $scope = $f;
        $scope['exam_id'] = null;
        $scope['q'] = '';
        $exams = $this->baseEvents($scope)
            ->selectRaw('ev.exam_id AS id, ex.title, MAX(ev.occurred_at) AS last_event_at')
            ->groupBy('ev.exam_id', 'ex.title')->orderByDesc('last_event_at')->limit(200)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title])->all();

        $unis = [];
        try {
            $unis = DB::table('universities')->orderBy('id')->limit(500)
                ->get(['id', 'official_name_en', 'official_name_ar'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name_en' => $u->official_name_en, 'name_ar' => $u->official_name_ar])->all();
        } catch (\Throwable $e) {
            $unis = [];
        }

        return [
            'exams'        => $exams,
            'universities' => $unis,
            'event_types'  => array_map(fn ($t, $w) => ['type' => $t, 'weight' => $w], array_keys(self::WEIGHTS), array_values(self::WEIGHTS)),
            'risk_levels'  => self::RISK_LEVELS,
        ];
    }

    // ------------------------------------------------------------------
    // Suspicious attempts
    // ------------------------------------------------------------------

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function attempts(array $f, int $page, int $perPage): array
    {
        $total = (int) DB::query()->fromSub($this->perAttempt($f), 't')->count();

        $rows = $this->perAttempt($f)
            ->orderByRaw('score DESC')->orderByRaw('last_event_at DESC')
            ->forPage($page, $perPage)->get();

        return ['rows' => $this->hydrateAttemptRows($rows, $f), 'total' => $total];
    }

    /** نفس attempts() من غير pagination (حد أقصى MAX_EXPORT_ROWS) — للتصدير. */
    public function attemptsAll(array $f): array
    {
        $rows = $this->perAttempt($f)->orderByRaw('score DESC')->orderByRaw('last_event_at DESC')
            ->limit(self::MAX_EXPORT_ROWS)->get();
        return $this->hydrateAttemptRows($rows, $f);
    }

    private function hydrateAttemptRows($rows, array $f): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('exam_attempt_id')->map(fn ($v) => (int) $v)->all();

        $attempts = DB::table('exam_attempts as a')->join('exams as ex', 'ex.id', '=', 'a.exam_id')
            ->whereIn('a.id', $ids)
            ->get([
                'a.id', 'a.status', 'a.attempt_number', 'a.violations_count', 'a.auto_submitted', 'a.started_at', 'a.submitted_at',
                'a.identity_status', 'a.proctoring_flags_count', 'a.session_ip',
                'ex.title as exam_title', 'ex.max_violations', 'ex.university_id',
            ])->keyBy('id');

        $signals = [];
        foreach (DB::table('exam_security_events')->whereIn('exam_attempt_id', $ids)
            ->whereIn('event_type', array_keys(self::WEIGHTS))->whereBetween('occurred_at', [$f['from'], $f['to']])
            ->selectRaw('exam_attempt_id, event_type, COUNT(*) AS c')->groupBy('exam_attempt_id', 'event_type')->get() as $s) {
            $signals[(int) $s->exam_attempt_id][$s->event_type] = (int) $s->c;
        }

        $similar = $this->similarityCounts($ids);
        $cards = $this->studentCards($rows->pluck('student_id')->all());

        return $rows->map(function ($r) use ($attempts, $signals, $similar, $cards) {
            $id = (int) $r->exam_attempt_id;
            $a = $attempts->get($id);
            $score = (int) $r->score;
            $byViolations = $a && $a->auto_submitted && $a->max_violations !== null
                && (int) $a->violations_count >= (int) $a->max_violations && (int) $a->violations_count > 0;
            $sig = $signals[$id] ?? [];
            arsort($sig);

            return array_merge($this->card($cards, $r->student_id), [
                'attempt_id'     => $id,
                'exam_id'        => (int) $r->exam_id,
                'exam_title'     => $a->exam_title ?? null,
                'attempt_number' => $a ? (int) $a->attempt_number : null,
                'status'         => $a->status ?? null,
                'started_at'     => $a->started_at ?? null,
                'submitted_at'   => $a->submitted_at ?? null,
                'events'         => (int) $r->events,
                'violations'     => (int) $r->violations,
                'max_violations' => $a && $a->max_violations !== null ? (int) $a->max_violations : null,
                'score'          => $score,
                'risk'           => self::riskLevel($score),
                'signals'        => $sig,
                'auto_submitted_by_violations' => $byViolations,
                'identity_status' => $a->identity_status ?? null,
                'proctoring_flags' => $a ? (int) $a->proctoring_flags_count : 0,
                'similarity_flags' => $similar[$id] ?? 0,
                'session_ip'     => $a->session_ip ?? null,
                'first_event_at' => $r->first_event_at,
                'last_event_at'  => $r->last_event_at,
            ]);
        })->all();
    }

    /** @return array<int,int> attempt_id => عدد إشارات التشابه غير المستبعدة. */
    private function similarityCounts(array $attemptIds): array
    {
        if (!$attemptIds || !Schema::hasTable('exam_similarity_flags')) {
            return [];
        }
        $out = [];
        foreach (['attempt_a_id', 'attempt_b_id'] as $col) {
            foreach (DB::table('exam_similarity_flags')->whereIn($col, $attemptIds)->where('status', '!=', 'dismissed')
                ->selectRaw("{$col} AS attempt_id, COUNT(*) AS c")->groupBy($col)->get() as $r) {
                $out[(int) $r->attempt_id] = ($out[(int) $r->attempt_id] ?? 0) + (int) $r->c;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Attempt detail (timeline)
    // ------------------------------------------------------------------

    public function attemptDetail(int $attemptId): ?array
    {
        $a = DB::table('exam_attempts as a')->join('exams as ex', 'ex.id', '=', 'a.exam_id')
            ->where('a.id', $attemptId)->whereNull('ex.deleted_at')
            ->first([
                'a.id', 'a.exam_id', 'a.student_id', 'a.status', 'a.attempt_number', 'a.violations_count', 'a.auto_submitted',
                'a.started_at', 'a.submitted_at', 'a.score', 'a.percentage', 'a.identity_status', 'a.identity_review_note',
                'a.proctoring_flags_count', 'a.session_ip', 'a.session_user_agent', 'a.session_claims_count',
                'ex.title as exam_title', 'ex.max_violations', 'ex.secure_mode_enabled', 'ex.proctoring_mode',
            ]);
        if (!$a) {
            return null;
        }

        // answer_saved/question_changed بس ضوضاء في التايم لاين — الباقي كله (بما فيه الإعلامي) بيظهر بالترتيب.
        $events = DB::table('exam_security_events')->where('exam_attempt_id', $attemptId)
            ->whereNotIn('event_type', ['answer_saved', 'question_changed'])
            ->orderBy('occurred_at')->orderBy('id')->limit(600)
            ->get(['id', 'event_type', 'is_violation', 'metadata', 'occurred_at'])
            ->map(fn ($e) => [
                'id'           => (int) $e->id,
                'event_type'   => $e->event_type,
                'is_violation' => (bool) $e->is_violation,
                'weight'       => self::WEIGHTS[$e->event_type] ?? 0,
                'metadata'     => is_string($e->metadata) ? json_decode($e->metadata, true) : $e->metadata,
                'occurred_at'  => $e->occurred_at,
            ])->all();

        $signals = []; $score = 0;
        foreach ($events as $e) {
            if ($e['weight'] > 0) {
                $signals[$e['event_type']] = ($signals[$e['event_type']] ?? 0) + 1;
                $score += $e['weight'];
            }
        }
        arsort($signals);

        $sessions = [];
        if (Schema::hasTable('exam_attempt_sessions')) {
            $sessions = DB::table('exam_attempt_sessions')->where('exam_attempt_id', $attemptId)
                ->orderBy('created_at')->limit(100)
                ->get(['event', 'ip', 'user_agent', 'device_label', 'created_at'])->map(fn ($s) => (array) $s)->all();
        }

        $snapshots = Schema::hasTable('exam_proctoring_snapshots')
            ? (int) DB::table('exam_proctoring_snapshots')->where('exam_attempt_id', $attemptId)->count() : 0;

        $similar = [];
        if (Schema::hasTable('exam_similarity_flags')) {
            $flags = DB::table('exam_similarity_flags')
                ->where(fn ($w) => $w->where('attempt_a_id', $attemptId)->orWhere('attempt_b_id', $attemptId))
                ->orderByDesc('similarity')->limit(20)
                ->get(['id', 'attempt_a_id', 'attempt_b_id', 'similarity', 'matched_words', 'status']);
            $otherIds = $flags->map(fn ($x) => (int) $x->attempt_a_id === $attemptId ? (int) $x->attempt_b_id : (int) $x->attempt_a_id)->all();
            $others = $otherIds ? DB::table('exam_attempts')->whereIn('id', $otherIds)->pluck('student_id', 'id')->all() : [];
            $cards = $this->studentCards(array_values($others));
            foreach ($flags as $x) {
                $other = (int) $x->attempt_a_id === $attemptId ? (int) $x->attempt_b_id : (int) $x->attempt_a_id;
                $similar[] = [
                    'id'            => (int) $x->id,
                    'similarity'    => (float) $x->similarity,
                    'matched_words' => (int) $x->matched_words,
                    'status'        => $x->status,
                    'other_attempt' => $other,
                    'other_student' => isset($others[$other]) ? $this->card($cards, $others[$other]) : null,
                ];
            }
        }

        $cards = $this->studentCards([(int) $a->student_id]);
        $byViolations = $a->auto_submitted && $a->max_violations !== null
            && (int) $a->violations_count >= (int) $a->max_violations && (int) $a->violations_count > 0;

        return [
            'attempt' => array_merge($this->card($cards, $a->student_id), [
                'attempt_id'     => (int) $a->id,
                'exam_id'        => (int) $a->exam_id,
                'exam_title'     => $a->exam_title,
                'attempt_number' => (int) $a->attempt_number,
                'status'         => $a->status,
                'started_at'     => $a->started_at,
                'submitted_at'   => $a->submitted_at,
                'violations_count' => (int) $a->violations_count,
                'max_violations' => $a->max_violations !== null ? (int) $a->max_violations : null,
                'auto_submitted_by_violations' => $byViolations,
                'secure_mode'    => (bool) $a->secure_mode_enabled,
                'proctoring_mode' => $a->proctoring_mode,
                'identity_status' => $a->identity_status,
                'identity_review_note' => $a->identity_review_note,
                'proctoring_flags' => (int) $a->proctoring_flags_count,
                'session_ip'     => $a->session_ip,
                'session_user_agent' => $a->session_user_agent,
                'session_claims' => (int) $a->session_claims_count,
                'snapshots'      => $snapshots,
                'score'          => $score,
                'risk'           => self::riskLevel($score),
            ]),
            'signals'  => $signals,
            'events'   => $events,
            'sessions' => $sessions,
            'similarity' => $similar,
        ];
    }

    // ------------------------------------------------------------------
    // Repeat students
    // ------------------------------------------------------------------

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public function students(array $f, int $page, int $perPage, int $minExams): array
    {
        $base = function () use ($f, $minExams) {
            $q = $this->baseEvents($f)
                ->selectRaw('ev.student_id, COUNT(DISTINCT ev.exam_id) AS exams, COUNT(DISTINCT ev.exam_attempt_id) AS attempts, '
                    . 'COUNT(*) AS events, SUM(CASE WHEN ev.is_violation = 1 THEN 1 ELSE 0 END) AS violations, '
                    . $this->scoreExpr() . ' AS score, MAX(ev.occurred_at) AS last_event_at')
                ->groupBy('ev.student_id');
            if ($minExams > 1) {
                $q->havingRaw('COUNT(DISTINCT ev.exam_id) >= ?', [$minExams]);
            }
            return $q;
        };

        $total = (int) DB::query()->fromSub($base(), 't')->count();
        $rows = $base()->orderByRaw('score DESC')->orderByRaw('last_event_at DESC')->forPage($page, $perPage)->get();

        return ['rows' => $this->hydrateStudentRows($rows), 'total' => $total];
    }
}
