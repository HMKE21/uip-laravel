<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Analysis Portal — المشاريع بعمق (بند 4). بيدّي المحلل رؤية مجمّعة
 * (aggregates بس) للجداول اللي كانت ناقصة حوالين المشاريع:
 *
 *   approvals  → project_approvals (مراحل المراجعة، القرارات، الجولات، زمن القرار)
 *                + توزيع حالات المشاريع نفسها
 *   grading    → project_grades + project_grade_criteria + project_grade_members
 *                + project_grade_revisions (الدرجات النهائية، المعايير، تعديل الأعضاء، إعادة الفتح)
 *   team       → project_team_members (الأدوار، قبول الدعوات، حجم الفرق)
 *   deadlines  → project_deadlines (مواعيد التسليم ونوافذ المناقشة)
 *   supervisors→ supervisors + supervisor_assignments (الدعوات، النطاقات، العبء، تغطية المشاريع)
 *   readiness  → ai_readiness_scores + startup_potential (مع فصل البيانات التجريبية)
 *   ip         → patents + github_repositories (براءات الاختراع وربط GitHub)
 *
 * قراءة بس — مفيش أي كتابة على جداول المنصة.
 *
 * الخصوصية: كل الأرقام تجميعية. مفيش تعليقات مراجعين ولا تعليقات تقييم
 * ولا أسباب إعادة فتح ولا snapshots الـ AI ولا أسماء/إيميلات أشخاص ولا
 * أرقام طلبات براءات ولا عناوين براءات ولا روابط ريبوهات. الاستثناء الوحيد
 * عناوين المشاريع في قوائم "الأعلى"، وعناوين مواعيد التسليم (بيحطها الأدمن)،
 * ونصوص المعايير (criterion) اللي هي عناوين rubric مش بيانات أشخاص.
 *
 * الفلاتر: university_id (على projects.university_id أو supervisors/deadlines
 * نفسها) + from/to (افتراضي آخر 90 يوم) على تاريخ السجل. أقسام "اللقطة"
 * (deadlines/supervisors وحجم الفرق وتوزيع حالات المشاريع) أرقامها حالية
 * ومش بتتأثر بالفترة؛ بس الـ daily فيها بيتبع الفترة.
 * أي جدول/عمود ناقص بيرجّع available=false (أو بيتخطى الجزء) بدل ما يكسر.
 */
class DataAnalysisProjectsDeepService
{
    public const SECTIONS = ['approvals', 'grading', 'team', 'deadlines', 'supervisors', 'readiness', 'ip'];

    private const MAX_DAYS = 366;
    private const DEFAULT_DAYS = 90;
    private const MAX_ROWS = 20000;

    private const SCORE_BANDS = [
        ['key' => 'lt50',   'min' => 0,  'max' => 50],
        ['key' => '50-59',  'min' => 50, 'max' => 60],
        ['key' => '60-69',  'min' => 60, 'max' => 70],
        ['key' => '70-79',  'min' => 70, 'max' => 80],
        ['key' => '80-89',  'min' => 80, 'max' => 90],
        ['key' => '90-100', 'min' => 90, 'max' => 100.01],
    ];

    // -----------------------------------------------------------------
    // Filters / overview / dispatcher
    // -----------------------------------------------------------------

    public function filters(): array
    {
        $universities = [];
        if (Schema::hasTable('universities')) {
            $universities = \App\Models\University::applyListable(DB::table('universities'))
                ->select('id', 'official_name_en', 'official_name_ar')
                ->orderBy('official_name_en')->get()
                ->map(fn ($r) => [
                    'id'   => (int) $r->id,
                    'name' => ['en' => $r->official_name_en ?: $r->official_name_ar, 'ar' => $r->official_name_ar ?: $r->official_name_en],
                ])->all();
        }

        return [
            'universities' => $universities,
            'sections'     => self::SECTIONS,
            'max_days'     => self::MAX_DAYS,
            'default_days' => self::DEFAULT_DAYS,
        ];
    }

    public function overview(array $f): array
    {
        $r = $this->range($f);
        $sections = [];
        $series = [];

        foreach (self::SECTIONS as $key) {
            $data = $this->section($key, $f) ?? ['available' => false];
            $sections[$key] = [
                'available' => (bool) ($data['available'] ?? false),
                'kpis'      => $data['kpis'] ?? [],
            ];
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

        return [
            'range'    => ['from' => $r['from'], 'to' => $r['to'], 'days' => $r['days']],
            'sections' => $sections,
            'trend'    => $trend,
        ];
    }

    public function section(string $key, array $f): ?array
    {
        return match ($key) {
            'approvals'   => $this->approvals($f),
            'grading'     => $this->grading($f),
            'team'        => $this->team($f),
            'deadlines'   => $this->deadlines($f),
            'supervisors' => $this->supervisors($f),
            'readiness'   => $this->readiness($f),
            'ip'          => $this->ip($f),
            default       => null,
        };
    }

    // -----------------------------------------------------------------
    // Approvals
    // -----------------------------------------------------------------

    private function approvals(array $f): array
    {
        if (!$this->ready(['project_approvals', 'projects'])) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => $this->between($this->inScope('project_approvals', 'a', $f), 'a.created_at', $r);

        $total = (int) $base()->count();
        $byDecision = fn (string $d) => (int) $base()->where('a.decision', $d)->count();
        $pending = $byDecision('pending');
        $approved = $byDecision('approved');
        $rejected = $byDecision('rejected');
        $changes = $byDecision('changes_requested');
        $decided = $total - $pending;

        // زمن القرار (ساعات) — بنحسبه في PHP عشان يشتغل على أي قاعدة بيانات.
        $hours = [];
        $rows = $base()->whereNotNull('a.decided_at')->select('a.created_at', 'a.decided_at')->limit(self::MAX_ROWS)->get();
        foreach ($rows as $x) {
            $s = strtotime((string) $x->decided_at) - strtotime((string) $x->created_at);
            $hours[] = max(0, $s) / 3600;
        }
        $turnaround = [
            ['key' => 'lt_1d',  'count' => count(array_filter($hours, fn ($h) => $h < 24))],
            ['key' => '1_3d',   'count' => count(array_filter($hours, fn ($h) => $h >= 24 && $h < 72))],
            ['key' => '3_7d',   'count' => count(array_filter($hours, fn ($h) => $h >= 72 && $h < 168))],
            ['key' => 'gt_7d',  'count' => count(array_filter($hours, fn ($h) => $h >= 168))],
        ];

        $hasRound = Schema::hasColumn('project_approvals', 'review_round');
        $hasAck = Schema::hasColumn('project_approvals', 'ai_output_acknowledged');
        $hasSnap = Schema::hasColumn('project_approvals', 'ai_output_snapshot');

        $matrix = $base()->select('a.stage', 'a.decision', DB::raw('COUNT(*) as c'))
            ->groupBy('a.stage', 'a.decision')->get()
            ->map(fn ($x) => ['stage' => (string) $x->stage, 'decision' => (string) $x->decision, 'count' => (int) $x->c])->all();

        $kpis = [
            'reviews'           => $total,
            'pending'           => $pending,
            'approved'          => $approved,
            'rejected'          => $rejected,
            'changes_requested' => $changes,
            'approval_rate'     => $this->rate($approved, $decided),
            'avg_decision_hours' => $hours ? $this->rnd(array_sum($hours) / count($hours), 1) : null,
            'projects_reviewed' => (int) $base()->distinct()->count('a.project_id'),
            'reviewers'         => (int) $base()->distinct()->count('a.reviewer_id'),
            'ai_acknowledged'   => $hasAck ? (int) $base()->where('a.ai_output_acknowledged', 1)->count() : null,
            'with_ai_snapshot'  => $hasSnap ? (int) $base()->whereNotNull('a.ai_output_snapshot')->count() : null,
            'max_round'         => $hasRound ? (int) ($base()->max('a.review_round') ?? 0) : null,
            'resubmitted'       => $hasRound ? (int) $base()->where('a.review_round', '>', 0)->count() : null,
        ];

        // لقطة حالية لحالات المشاريع نفسها (مش بالفترة).
        $pipeline = $this->groupCount($this->projectsQ($f), 'p.status');

        return [
            'available'   => true,
            'kpis'        => $kpis,
            'daily'       => $this->fill($this->daily($base(), 'a.created_at'), $r),
            'decided_daily' => $this->fill($this->daily($this->between($this->inScope('project_approvals', 'a', $f), 'a.decided_at', $r), 'a.decided_at'), $r),
            'by_stage'    => $this->groupCount($base(), 'a.stage'),
            'by_decision' => $this->groupCount($base(), 'a.decision'),
            'stage_decision' => $matrix,
            'by_round'    => $hasRound ? $this->groupCount($base(), 'a.review_round') : [],
            'turnaround'  => $turnaround,
            'project_status' => $pipeline,
        ];
    }

    // -----------------------------------------------------------------
    // Grading (+ criteria, members, revisions)
    // -----------------------------------------------------------------

    private function grading(array $f): array
    {
        if (!$this->ready(['project_grades', 'projects'])) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $g = fn () => $this->between($this->inScope('project_grades', 'g', $f), 'g.created_at', $r);
        $finalScored = fn () => $g()->where('g.status', 'final')->where('g.max_score', '>', 0)->whereNotNull('g.total_score');

        $total = (int) $g()->count();
        $final = (int) $g()->where('g.status', 'final')->count();
        $avgPct = $finalScored()->selectRaw('AVG(g.total_score * 100.0 / g.max_score) as v')->value('v');
        $pcts = $finalScored()->selectRaw('g.total_score * 100.0 / g.max_score as pct')->limit(self::MAX_ROWS)->pluck('pct')
            ->map(fn ($v) => (float) $v)->all();

        $hasRev = Schema::hasColumn('project_grades', 'revision');

        // المعايير (rubric)
        $criteria = [];
        $criteriaKpis = null;
        if (Schema::hasTable('project_grade_criteria')) {
            $cq = fn () => $this->between(
                DB::table('project_grade_criteria as c')->join('project_grades as g', 'g.id', '=', 'c.project_grade_id')
                    ->join('projects as p', 'p.id', '=', 'g.project_id')->whereNull('p.deleted_at')
                    ->when(!empty($f['university_id']), fn ($q) => $q->where('p.university_id', (int) $f['university_id'])),
                'g.created_at', $r
            )->whereNotNull('c.score')->where('c.max_score', '>', 0);

            $criteria = $cq()->selectRaw('c.criterion as k, COUNT(*) as n, AVG(c.score * 100.0 / c.max_score) as avg_pct')
                ->groupBy('c.criterion')->orderByDesc('n')->limit(12)->get()
                ->map(fn ($x) => ['key' => (string) $x->k, 'count' => (int) $x->n, 'avg_pct' => $this->rnd($x->avg_pct, 1)])->all();
            $criteriaKpis = [
                'scored_criteria' => (int) $cq()->count(),
                'avg_pct'         => $this->rnd($cq()->selectRaw('AVG(c.score * 100.0 / c.max_score) as v')->value('v'), 1),
            ];
        }

        // درجات الأعضاء (تعديل فردي على درجة المشروع)
        $members = null;
        if (Schema::hasTable('project_grade_members')) {
            $mq = fn () => $this->between($this->inScope('project_grade_members', 'm', $f), 'm.created_at', $r);
            $members = [
                'members'      => (int) $mq()->count(),
                'adjusted'     => (int) $mq()->where('m.adjustment', '<>', 0)->count(),
                'raised'       => (int) $mq()->where('m.adjustment', '>', 0)->count(),
                'lowered'      => (int) $mq()->where('m.adjustment', '<', 0)->count(),
                'avg_adjustment' => $this->rnd($mq()->avg('m.adjustment'), 2),
                'avg_final_pct'  => $this->rnd($mq()->whereNotNull('m.final_percentage')->avg('m.final_percentage'), 1),
                'by_letter'    => $this->groupCount($mq()->whereNotNull('m.letter_grade'), 'm.letter_grade'),
            ];
        }

        // سجل المراجعات (finalized / reopened) — الأسباب مش بتتعرض.
        $revisions = null;
        if (Schema::hasTable('project_grade_revisions')) {
            $rq = fn () => $this->between($this->inScope('project_grade_revisions', 'rv', $f), 'rv.created_at', $r);
            $revisions = [
                'total'      => (int) $rq()->count(),
                'by_action'  => $this->groupCount($rq(), 'rv.action'),
                'by_actor_role' => $this->groupCount($rq(), 'rv.actor_role'),
            ];
        }

        return [
            'available' => true,
            'kpis' => [
                'grades'          => $total,
                'final'           => $final,
                'draft'           => (int) $g()->where('g.status', 'draft')->count(),
                'projects_graded' => (int) $g()->distinct()->count('g.project_id'),
                'graders'         => (int) $g()->distinct()->count('g.graded_by'),
                'avg_pct'         => $this->rnd($avgPct, 1),
                'pass_rate'       => $pcts ? $this->rate(count(array_filter($pcts, fn ($p) => $p >= 50)), count($pcts)) : null,
                'reopened'        => $hasRev ? (int) $g()->whereNotNull('g.reopened_at')->count() : null,
                'avg_revision'    => $hasRev ? $this->rnd($g()->avg('g.revision'), 2) : null,
                'criteria_scored' => $criteriaKpis['scored_criteria'] ?? null,
            ],
            'daily'     => $this->fill($this->daily($g(), 'g.created_at'), $r),
            'by_status' => $this->groupCount($g(), 'g.status'),
            'by_letter' => $this->groupCount($g()->whereNotNull('g.letter_grade'), 'g.letter_grade'),
            'score_bands' => $this->bandCounts($pcts),
            'criteria'  => $criteria,
            'criteria_avg_pct' => $criteriaKpis['avg_pct'] ?? null,
            'members'   => $members,
            'revisions' => $revisions,
        ];
    }

    // -----------------------------------------------------------------
    // Team members
    // -----------------------------------------------------------------

    private function team(array $f): array
    {
        if (!$this->ready(['project_team_members', 'projects'])) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $m = fn () => $this->between($this->inScope('project_team_members', 'm', $f), 'm.invited_at', $r);
        $st = fn (string $s) => (int) $m()->where('m.status', $s)->count();

        $total = (int) $m()->count();
        $accepted = $st('accepted');
        $rejected = $st('rejected');

        $resp = [];
        $rows = $m()->whereNotNull('m.responded_at')->select('m.invited_at', 'm.responded_at')->limit(self::MAX_ROWS)->get();
        foreach ($rows as $x) {
            $resp[] = max(0, strtotime((string) $x->responded_at) - strtotime((string) $x->invited_at)) / 3600;
        }

        // لقطة حالية: حجم الفرق (الأعضاء المقبولين لكل مشروع) — مش بالفترة.
        $sizes = $this->inScope('project_team_members', 'm', $f)->where('m.status', 'accepted')
            ->select('m.project_id', DB::raw('COUNT(*) as c'))->groupBy('m.project_id')->pluck('c')
            ->map(fn ($v) => (int) $v)->all();
        $buckets = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5+' => 0];
        foreach ($sizes as $n) {
            $buckets[$n >= 5 ? '5+' : (string) max(1, $n)]++;
        }
        $projectsTotal = (int) $this->projectsQ($f)->count();
        $withTeam = count($sizes);

        return [
            'available' => true,
            'kpis' => [
                'invitations'      => $total,
                'accepted'         => $accepted,
                'pending'          => $st('pending'),
                'rejected'         => $rejected,
                'removed'          => $st('removed'),
                'accept_rate'      => $this->rate($accepted, $accepted + $rejected),
                'avg_response_hours' => $resp ? $this->rnd(array_sum($resp) / count($resp), 1) : null,
                'projects'         => (int) $m()->distinct()->count('m.project_id'),
                'inviters'         => (int) $m()->distinct()->count('m.invited_by'),
                'unregistered_invites' => (int) $m()->whereNull('m.user_id')->count(),
                'projects_with_team'    => $withTeam,
                'projects_without_team' => max(0, $projectsTotal - $withTeam),
                'avg_team_size'    => $sizes ? $this->rnd(array_sum($sizes) / count($sizes), 2) : null,
            ],
            'daily'     => $this->fill($this->daily($m(), 'm.invited_at'), $r),
            'by_role'   => $this->groupCount($m(), 'm.role'),
            'by_status' => $this->groupCount($m(), 'm.status'),
            'by_academic_year' => $this->groupCount($m()->whereNotNull('m.academic_year'), 'm.academic_year'),
            'team_sizes' => array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_keys($buckets), array_values($buckets)),
        ];
    }

    // -----------------------------------------------------------------
    // Deadlines (لقطة حالية)
    // -----------------------------------------------------------------

    private function deadlines(array $f): array
    {
        if (!Schema::hasTable('project_deadlines')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $uni = $f['university_id'] ?? null;
        $d = fn () => DB::table('project_deadlines as d')->when($uni, fn ($q) => $q->where('d.university_id', (int) $uni));
        $now = Carbon::now()->toDateTimeString();
        $today = Carbon::today()->toDateString();

        $upcomingQ = fn () => $d()->where('d.is_active', 1)->whereNotNull('d.submission_deadline')->where('d.submission_deadline', '>=', $now);

        $upcoming = $upcomingQ()->orderBy('d.submission_deadline')->limit(10)
            ->select('d.title', 'd.university_id', 'd.faculty_id', 'd.submission_deadline', 'd.defense_starts_at', 'd.defense_ends_at')->get();
        $names = $this->universityNames($upcoming->pluck('university_id')->all());
        $upcomingList = $upcoming->map(fn ($x) => [
            'title'      => (string) $x->title,
            'university' => $names[(int) $x->university_id] ?? null,
            'faculty_specific' => $x->faculty_id !== null,
            'submission_deadline' => $x->submission_deadline,
            'days_left'  => (int) ceil((strtotime((string) $x->submission_deadline) - time()) / 86400),
            'defense_starts_at' => $x->defense_starts_at,
            'defense_ends_at'   => $x->defense_ends_at,
        ])->all();

        // عدد المواعيد لكل شهر (للجدول الزمني).
        $monthly = [];
        foreach ($d()->whereNotNull('d.submission_deadline')->limit(self::MAX_ROWS)->pluck('d.submission_deadline') as $v) {
            $k = substr((string) $v, 0, 7);
            $monthly[$k] = ($monthly[$k] ?? 0) + 1;
        }
        ksort($monthly);

        $byUni = [];
        if (!$uni) {
            $byUni = $d()->select('d.university_id', DB::raw('COUNT(*) as c'))->groupBy('d.university_id')->orderByDesc('c')->limit(15)->get()
                ->map(fn ($x) => ['university_id' => (int) $x->university_id, 'count' => (int) $x->c])->all();
        }

        return [
            'available' => true,
            'kpis' => [
                'deadlines'            => (int) $d()->count(),
                'active'               => (int) $d()->where('d.is_active', 1)->count(),
                'inactive'             => (int) $d()->where('d.is_active', 0)->count(),
                'upcoming_submissions' => (int) $upcomingQ()->count(),
                'past_submissions'     => (int) $d()->whereNotNull('d.submission_deadline')->where('d.submission_deadline', '<', $now)->count(),
                'defense_upcoming'     => (int) $d()->whereNotNull('d.defense_starts_at')->where('d.defense_starts_at', '>', $today)->count(),
                'defense_now'          => (int) $d()->whereNotNull('d.defense_starts_at')->where('d.defense_starts_at', '<=', $today)
                    ->whereNotNull('d.defense_ends_at')->where('d.defense_ends_at', '>=', $today)->count(),
                'university_wide'      => (int) $d()->whereNull('d.faculty_id')->count(),
                'faculty_specific'     => (int) $d()->whereNotNull('d.faculty_id')->count(),
                'without_deadline'     => (int) $d()->whereNull('d.submission_deadline')->count(),
            ],
            'daily'      => $this->fill($this->daily($this->between($d(), 'd.created_at', $r), 'd.created_at'), $r),
            'upcoming'   => $upcomingList,
            'monthly'    => array_map(fn ($k, $v) => ['key' => $k, 'count' => $v], array_keys($monthly), array_values($monthly)),
            'by_university' => $byUni,
            'university_names' => $this->universityNames(array_column($byUni, 'university_id')),
        ];
    }

    // -----------------------------------------------------------------
    // Supervisors (+ assignments) (لقطة حالية)
    // -----------------------------------------------------------------

    private function supervisors(array $f): array
    {
        if (!Schema::hasTable('supervisors')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $uni = $f['university_id'] ?? null;
        $s = fn () => DB::table('supervisors as s')->when($uni, fn ($q) => $q->where('s.university_id', (int) $uni));
        $now = Carbon::now()->toDateTimeString();
        $hasInv = Schema::hasColumn('supervisors', 'invitation_status');

        $total = (int) $s()->count();
        $kpis = [
            'supervisors' => $total,
            'active'      => (int) $s()->where('s.status', 'active')->count(),
            'inactive'    => (int) $s()->where('s.status', 'inactive')->count(),
            'activated'   => Schema::hasColumn('supervisors', 'user_id') ? (int) $s()->whereNotNull('s.user_id')->count() : null,
        ];
        if ($hasInv) {
            $acc = (int) $s()->where('s.invitation_status', 'accepted')->count();
            $kpis += [
                'invites_pending'  => (int) $s()->where('s.invitation_status', 'pending')->count(),
                'invites_accepted' => $acc,
                'invites_expired'  => (int) $s()->where('s.invitation_status', 'expired')->count(),
                'invites_overdue'  => (int) $s()->where('s.invitation_status', 'pending')->whereNotNull('s.expires_at')->where('s.expires_at', '<', $now)->count(),
                'acceptance_rate'  => $this->rate($acc, $total),
            ];
        }

        $assignments = null;
        $workload = [];
        $supervisedProjects = null;
        if (Schema::hasTable('supervisor_assignments')) {
            $a = fn () => DB::table('supervisor_assignments as sa')->join('supervisors as s', 's.id', '=', 'sa.supervisor_id')
                ->when($uni, fn ($q) => $q->where('s.university_id', (int) $uni));
            $count = (int) $a()->count();
            $withAssign = (int) $a()->distinct()->count('sa.supervisor_id');
            $assignments = [
                'total'        => $count,
                'supervisors_with_assignments' => $withAssign,
                'avg_per_supervisor' => $withAssign ? $this->rnd($count / $withAssign, 2) : null,
                'project_level' => (int) $a()->whereNotNull('sa.project_id')->count(),
                'by_scope_type' => $this->groupCount($a(), 'sa.scope_type'),
            ];

            // توزيع العبء: عدد التكليفات لكل مشرف.
            $perSup = $s()->leftJoin('supervisor_assignments as sa', 'sa.supervisor_id', '=', 's.id')
                ->select('s.id', DB::raw('COUNT(sa.id) as c'))->groupBy('s.id')->pluck('c')->map(fn ($v) => (int) $v)->all();
            $wb = ['0' => 0, '1' => 0, '2-3' => 0, '4-5' => 0, '6+' => 0];
            foreach ($perSup as $n) {
                $wb[$n === 0 ? '0' : ($n === 1 ? '1' : ($n <= 3 ? '2-3' : ($n <= 5 ? '4-5' : '6+')))]++;
            }
            $workload = array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_keys($wb), array_values($wb));
        }

        // تغطية المشاريع بمشرف (عضو فريق بدور supervisor مقبول أو تكليف على مستوى مشروع).
        if (Schema::hasTable('project_team_members') && Schema::hasTable('projects')) {
            $ids = $this->inScope('project_team_members', 'm', $f)->where('m.role', 'supervisor')->where('m.status', 'accepted')
                ->distinct()->limit(self::MAX_ROWS)->pluck('m.project_id')->map(fn ($v) => (int) $v)->all();
            if (Schema::hasTable('supervisor_assignments')) {
                $more = $this->inScope('supervisor_assignments', 'sa', $f)->whereNotNull('sa.project_id')
                    ->distinct()->limit(self::MAX_ROWS)->pluck('sa.project_id')->map(fn ($v) => (int) $v)->all();
                $ids = array_merge($ids, $more);
            }
            $covered = count(array_unique($ids));
            $projectsTotal = (int) $this->projectsQ($f)->count();
            $supervisedProjects = [
                'covered'   => $covered,
                'projects'  => $projectsTotal,
                'coverage'  => $this->rate($covered, $projectsTotal),
            ];
        }

        $kpis['assignments'] = $assignments['total'] ?? null;
        $kpis['avg_assignments'] = $assignments['avg_per_supervisor'] ?? null;
        $kpis['project_coverage'] = $supervisedProjects['coverage'] ?? null;

        return [
            'available'   => true,
            'kpis'        => $kpis,
            'daily'       => Schema::hasColumn('supervisors', 'invited_at')
                ? $this->fill($this->daily($this->between($s(), 's.invited_at', $r), 's.invited_at'), $r) : $this->fill([], $r),
            'by_status'   => $this->groupCount($s(), 's.status'),
            'by_invitation' => $hasInv ? $this->groupCount($s(), 's.invitation_status') : [],
            'by_title'    => $this->groupCount($s()->whereNotNull('s.title')->where('s.title', '<>', ''), 's.title', 'key', 8),
            'by_department' => $this->groupCount($s()->whereNotNull('s.department')->where('s.department', '<>', ''), 's.department', 'key', 10),
            'assignments' => $assignments,
            'workload'    => $workload,
            'coverage'    => $supervisedProjects,
        ];
    }

    // -----------------------------------------------------------------
    // AI readiness + startup potential
    // -----------------------------------------------------------------

    private function readiness(array $f): array
    {
        $hasR = $this->ready(['ai_readiness_scores', 'projects']);
        $hasS = $this->ready(['startup_potential', 'projects']);
        if (!$hasR && !$hasS) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $out = ['available' => true, 'kpis' => [], 'daily' => $this->fill([], $r)];

        if ($hasR) {
            $q = fn () => $this->between($this->inScope('ai_readiness_scores', 'r', $f), 'r.created_at', $r);
            $overall = $q()->pluck('r.overall_score')->map(fn ($v) => (float) $v)->all();
            $avg = fn (string $c) => $this->rnd($q()->whereNotNull("r.$c")->avg("r.$c"), 1);
            $out['readiness'] = [
                'scores'     => count($overall),
                'avg_overall' => $this->rnd($q()->avg('r.overall_score'), 1),
                'dimensions' => [
                    ['key' => 'technical',    'value' => $avg('technical_score')],
                    ['key' => 'market',       'value' => $avg('market_score')],
                    ['key' => 'innovation',   'value' => $avg('innovation_score')],
                    ['key' => 'presentation', 'value' => $avg('presentation_score')],
                ],
                'bands'  => $this->bandCounts($overall),
                'demo'   => (int) $q()->where('r.is_demo_data', 1)->count(),
                'real'   => (int) $q()->where('r.is_demo_data', 0)->count(),
                'top_projects' => $this->between($this->inScope('ai_readiness_scores', 'r', $f), 'r.created_at', $r)
                    ->where('r.is_demo_data', 0)
                    ->select('p.title_en', 'p.title_ar', DB::raw('MAX(r.overall_score) as score'))
                    ->groupBy('p.id', 'p.title_en', 'p.title_ar')->orderByDesc('score')->limit(10)->get()
                    ->map(fn ($x) => ['title' => $x->title_en ?: $x->title_ar, 'score' => $this->rnd($x->score, 1)])->all(),
            ];
            $out['kpis'] += [
                'readiness_scores' => count($overall),
                'avg_readiness'    => $out['readiness']['avg_overall'],
                'projects_scored'  => (int) $q()->distinct()->count('r.project_id'),
                'high_readiness'   => count(array_filter($overall, fn ($v) => $v >= 70)),
                'low_readiness'    => count(array_filter($overall, fn ($v) => $v < 40)),
            ];
            $out['daily'] = $this->fill($this->daily($q(), 'r.created_at'), $r);
        }

        if ($hasS) {
            $q = fn () => $this->between($this->inScope('startup_potential', 'sp', $f), 'sp.created_at', $r);
            $scores = $q()->pluck('sp.potential_score')->map(fn ($v) => (float) $v)->all();

            // عوامل الخطر: JSON → تجميع التكرار (أعلى 10).
            $risks = [];
            if (Schema::hasColumn('startup_potential', 'risk_factors')) {
                foreach ($q()->whereNotNull('sp.risk_factors')->limit(self::MAX_ROWS)->pluck('sp.risk_factors') as $raw) {
                    $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
                    foreach ((array) $arr as $item) {
                        $label = is_array($item) ? ($item['name'] ?? $item['factor'] ?? $item['title'] ?? null) : $item;
                        if (is_string($label) && $label !== '') {
                            $label = mb_substr(trim($label), 0, 80);
                            $risks[$label] = ($risks[$label] ?? 0) + 1;
                        }
                    }
                }
                arsort($risks);
            }

            $out['startup'] = [
                'scores'     => count($scores),
                'avg_potential' => $this->rnd($q()->avg('sp.potential_score'), 1),
                'bands'      => $this->bandCounts($scores),
                'demo'       => (int) $q()->where('sp.is_demo_data', 1)->count(),
                'real'       => (int) $q()->where('sp.is_demo_data', 0)->count(),
                'market_size' => $this->groupCount($q()->whereNotNull('sp.market_size_estimate')->where('sp.market_size_estimate', '<>', ''), 'sp.market_size_estimate', 'key', 8),
                'risk_factors' => array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_slice(array_keys($risks), 0, 10), array_slice(array_values($risks), 0, 10)),
            ];
            $out['kpis'] += [
                'startup_scores' => count($scores),
                'avg_potential'  => $out['startup']['avg_potential'],
                'high_potential' => count(array_filter($scores, fn ($v) => $v >= 70)),
            ];
            if (!$hasR) {
                $out['daily'] = $this->fill($this->daily($q(), 'sp.created_at'), $r);
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // IP: patents + GitHub repositories
    // -----------------------------------------------------------------

    private function ip(array $f): array
    {
        $hasP = Schema::hasTable('patents');
        $hasG = $this->ready(['github_repositories', 'projects']);
        if (!$hasP && !$hasG) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $uni = $f['university_id'] ?? null;
        $out = ['available' => true, 'kpis' => [], 'daily' => $this->fill([], $r)];

        if ($hasP) {
            // براءة ممكن تكون من غير مشروع (project_id null) → leftJoin، والفلتر بالجامعة على المشروع بس.
            $p = function () use ($r, $uni) {
                $q = DB::table('patents as pt')->leftJoin('projects as p', 'p.id', '=', 'pt.project_id')
                    ->whereBetween('pt.created_at', [$r['start'], $r['end']]);
                if ($uni) {
                    $q->where('p.university_id', (int) $uni)->whereNull('p.deleted_at');
                }
                return $q;
            };
            $total = (int) $p()->count();
            $granted = (int) $p()->where('pt.status', 'granted')->count();
            $rejected = (int) $p()->where('pt.status', 'rejected')->count();
            $out['patents'] = [
                'by_status' => $this->groupCount($p(), 'pt.status'),
                'filed_daily' => $this->fill($this->daily($p()->whereNotNull('pt.filed_at'), 'pt.filed_at'), $r),
            ];
            $out['kpis'] += [
                'patents'          => $total,
                'patents_granted'  => $granted,
                'patents_filed'    => (int) $p()->whereNotNull('pt.filed_at')->count(),
                'grant_rate'       => $this->rate($granted, $granted + $rejected),
                'patents_standalone' => $uni ? null : (int) $p()->whereNull('pt.project_id')->count(),
                'patent_applicants' => (int) $p()->distinct()->count('pt.submitted_by'),
            ];
            $out['daily'] = $this->fill($this->daily($p(), 'pt.created_at'), $r);
        }

        if ($hasG) {
            $gq = fn () => $this->between($this->inScope('github_repositories', 'gh', $f), 'gh.created_at', $r);
            $staleBefore = Carbon::now()->subDays(7)->toDateTimeString();
            $connected = (int) $gq()->where('gh.sync_status', 'connected')->count();

            // الدومين بس (مش owner/repo) عشان نفرّق github.com عن غيره.
            $hosts = [];
            foreach ($gq()->limit(self::MAX_ROWS)->pluck('gh.repo_url') as $u) {
                $h = strtolower((string) (parse_url((string) $u, PHP_URL_HOST) ?: 'unknown'));
                $hosts[$h] = ($hosts[$h] ?? 0) + 1;
            }
            arsort($hosts);

            $projectsTotal = (int) $this->projectsQ($f)->count();
            $withRepo = (int) $this->inScope('github_repositories', 'gh', $f)->distinct()->count('gh.project_id');

            $out['github'] = [
                'by_sync_status' => $this->groupCount($gq(), 'gh.sync_status'),
                'by_branch' => $this->groupCount($gq()->whereNotNull('gh.default_branch'), 'gh.default_branch', 'key', 6),
                'by_host'   => array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_slice(array_keys($hosts), 0, 5), array_slice(array_values($hosts), 0, 5)),
                'projects_with_repo' => $withRepo,
                'projects'  => $projectsTotal,
                'adoption'  => $this->rate($withRepo, $projectsTotal),
            ];
            $out['kpis'] += [
                'repositories'   => (int) $gq()->count(),
                'repos_connected' => $connected,
                'repos_error'    => (int) $gq()->where('gh.sync_status', 'error')->count(),
                'repos_stale'    => (int) $gq()->where('gh.sync_status', 'connected')
                    ->where(fn ($q) => $q->whereNull('gh.last_synced_at')->orWhere('gh.last_synced_at', '<', $staleBefore))->count(),
                'repo_adoption'  => $out['github']['adoption'],
            ];
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

    /** المشاريع (مش محذوفة) بفلتر الجامعة. */
    private function projectsQ(array $f)
    {
        $q = DB::table('projects as p')->whereNull('p.deleted_at');
        if (!empty($f['university_id'])) {
            $q->where('p.university_id', (int) $f['university_id']);
        }
        return $q;
    }

    /** جدول فرعي متربط بـ projects (مش محذوفة) بفلتر الجامعة. */
    private function inScope(string $table, string $alias, array $f, string $fk = 'project_id')
    {
        $q = DB::table($table . ' as ' . $alias)->join('projects as p', 'p.id', '=', $alias . '.' . $fk)->whereNull('p.deleted_at');
        if (!empty($f['university_id'])) {
            $q->where('p.university_id', (int) $f['university_id']);
        }
        return $q;
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

    /** @return array<int,array{key:string,count:int}> */
    private function bandCounts(array $values): array
    {
        return array_map(fn ($b) => [
            'key'   => $b['key'],
            'count' => count(array_filter($values, fn ($v) => $v >= $b['min'] && $v < $b['max'])),
        ], self::SCORE_BANDS);
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

    /** @return string[] every date in the range */
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

    /** يرجّع [date => count] */
    private function daily($query, string $col): array
    {
        return $query->select(DB::raw("DATE($col) as d"), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw("DATE($col)"))->pluck('c', 'd')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** يملا الأيام الفاضية بصفر → [{date,value}] */
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

    /** GROUP BY عمود → [{key,count}] مرتّب تنازليًا. */
    private function groupCount($query, string $col, string $as = 'key', ?int $limit = null): array
    {
        $q = $query->select(DB::raw("$col as k"), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw($col))->orderByDesc('c');
        if ($limit) {
            $q->limit($limit);
        }
        return $q->get()->map(fn ($x) => ['key' => $x->k === null ? '' : (string) $x->k, 'count' => (int) $x->c])->all();
    }

    private function universityNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !Schema::hasTable('universities')) {
            return [];
        }
        $out = [];
        foreach (DB::table('universities')->whereIn('id', $ids)->select('id', 'official_name_en', 'official_name_ar')->get() as $u) {
            $out[(int) $u->id] = ['en' => $u->official_name_en ?: $u->official_name_ar, 'ar' => $u->official_name_ar ?: $u->official_name_en];
        }
        return $out;
    }
}
