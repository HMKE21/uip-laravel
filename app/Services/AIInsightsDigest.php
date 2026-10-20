<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بيبني الـ digest الواسع اللي AIInsightsService بيبعته للموديل.
 *
 * قواعد الدقة اللي الكلاس ده بيطبّقها في الكود (مش بنسيبها للموديل):
 *  - مفيش مقارنة بشهر ميلادي ناقص. أي مقارنة بين فترتين بتتحسب على نافذتين
 *    متساويتين الطول (آخر 30 يوم مقابل الـ 30 يوم اللي قبلها).
 *  - الشهر الحالي في سلسلة النمو بيتعلّم is_partial مع الأيام المنقضية
 *    والمتوقع لآخر الشهر، والتغيّر بيتحسب بين الشهور الكاملة بس.
 *  - كل النسب والفروق بتتحسب هنا؛ الموديل بيشرح بس.
 *  - كل قسم معزول: لو جدول/عمود مش موجود أو الاستعلام فشل، القسم بيتحط
 *    كـ null وبيتسجّل في data_gaps بدل ما التوليد كله يقع.
 */
class AIInsightsDigest
{
    /** @var string[] أقسام ماقدرناش نجمعها — بتتبعت للموديل كـ data_gaps عشان يذكرها صراحة. */
    private array $gaps = [];

    public function __construct(private AnalyticsService $analytics) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $this->gaps = [];

        $digest = [
            'as_of'              => now()->toDateString(),
            'comparison_rule'    => 'Every "change" below compares two equal-length rolling windows (last 30 days vs the 30 days before). Calendar months are only compared when both are complete.',
            'users'              => $this->section('users', fn () => $this->users()),
            'user_growth'        => $this->section('user_growth', fn () => $this->userGrowth()),
            'projects'           => $this->section('projects', fn () => $this->projects()),
            'universities'       => $this->section('universities', fn () => $this->universities()),
            'security'           => $this->section('security', fn () => $this->security()),
            'ai_usage'           => $this->section('ai_usage', fn () => $this->aiUsage()),
            'visitors'           => $this->section('visitors', fn () => $this->visitors()),
        ];
        $digest['data_gaps'] = $this->gaps;

        return $digest;
    }

    private function section(string $name, callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            $this->gaps[] = $name;
            return null;
        }
    }

    /** نسبة التغيّر بين نافذتين؛ null لو الأساس صفر (مفيش نسبة منطقية). */
    private function pct(float|int $current, float|int $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    private function windows(): array
    {
        return [now()->subDays(30), now()->subDays(60)];
    }

    // ---------------------------------------------------------------- users

    private function users(): array
    {
        [$from30, $from60] = $this->windows();
        $total = (int) DB::table('users')->whereNull('deleted_at')->count();

        $byStatus = DB::table('users')->whereNull('deleted_at')
            ->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();

        $byRole = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->whereNull('u.deleted_at')
            ->select('r.slug', DB::raw('COUNT(DISTINCT ur.user_id) as c'))->groupBy('r.slug')
            ->orderByDesc('c')->pluck('c', 'slug')->map(fn ($v) => (int) $v)->all();

        $new30 = (int) DB::table('users')->whereNull('deleted_at')->where('created_at', '>=', $from30)->count();
        $newPrev30 = (int) DB::table('users')->whereNull('deleted_at')->where('created_at', '>=', $from60)->where('created_at', '<', $from30)->count();

        $active7 = (int) DB::table('users')->whereNull('deleted_at')->where('last_login_at', '>=', now()->subDays(7))->count();
        $active30 = (int) DB::table('users')->whereNull('deleted_at')->where('last_login_at', '>=', $from30)->count();
        $neverLoggedIn = (int) DB::table('users')->whereNull('deleted_at')->whereNull('last_login_at')->count();
        $with2fa = (int) DB::table('users')->whereNull('deleted_at')->where('two_factor_enabled', 1)->count();
        $locked = (int) DB::table('users')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('lock_permanent', 1)->orWhere('locked_until', '>', now()))->count();
        $unverifiedEmail = (int) DB::table('users')->whereNull('deleted_at')->whereNull('email_verified_at')->count();

        return [
            'total'                      => $total,
            'by_status'                  => $byStatus,
            'by_role'                    => $byRole,
            'new_last_30d'               => $new30,
            'new_previous_30d'           => $newPrev30,
            'new_change_pct'             => $this->pct($new30, $newPrev30),
            'active_last_7d'             => $active7,
            'active_last_30d'            => $active30,
            'active_30d_pct_of_total'    => $total ? round($active30 / $total * 100, 1) : null,
            'never_logged_in'            => $neverLoggedIn,
            'unverified_email'           => $unverifiedEmail,
            'two_factor_enabled'         => $with2fa,
            'two_factor_pct'             => $total ? round($with2fa / $total * 100, 1) : null,
            'locked_accounts'            => $locked,
        ];
    }

    /** سلسلة شهرية: الشهور الكاملة + الشهر الحالي معلّم partial. */
    private function userGrowth(): array
    {
        $rows = DB::table('users')->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('month')->orderBy('month')->pluck('total', 'month');

        $currentKey = now()->format('Y-m');
        $daysElapsed = (int) now()->format('j');
        $daysInMonth = (int) now()->format('t');

        $series = [];
        $complete = [];
        for ($i = 6; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $val = (int) ($rows[$key] ?? 0);
            if ($key === $currentKey) {
                $series[] = [
                    'month' => $key, 'new_users' => $val, 'is_partial' => true,
                    'days_elapsed' => $daysElapsed, 'days_in_month' => $daysInMonth,
                    'projected_full_month' => $daysElapsed > 0 ? (int) round($val / $daysElapsed * $daysInMonth) : $val,
                ];
            } else {
                $series[] = ['month' => $key, 'new_users' => $val, 'is_partial' => false];
                $complete[] = $val;
            }
        }

        $last = $complete ? end($complete) : null;
        $before = count($complete) > 1 ? $complete[count($complete) - 2] : null;

        return [
            'note'                         => 'The entry with is_partial=true is the current, unfinished month — never describe it as a drop or compare it to a full month; use projected_full_month or the 30-day windows instead.',
            'months'                       => $series,
            'last_complete_month_change_pct' => ($last !== null && $before !== null) ? $this->pct($last, $before) : null,
            'avg_new_per_complete_month'   => $complete ? round(array_sum($complete) / count($complete), 1) : null,
        ];
    }

    // ------------------------------------------------------------- projects

    private function projects(): array
    {
        [$from30, $from60] = $this->windows();
        $base = fn () => DB::table('projects')->whereNull('deleted_at');

        $byStatus = $base()->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $total = array_sum($byStatus);

        $created30 = (int) $base()->where('created_at', '>=', $from30)->count();
        $createdPrev = (int) $base()->where('created_at', '>=', $from60)->where('created_at', '<', $from30)->count();
        $published30 = (int) $base()->where('published_at', '>=', $from30)->count();
        $publishedPrev = (int) $base()->where('published_at', '>=', $from60)->where('published_at', '<', $from30)->count();

        $decided = ($byStatus['published'] ?? 0) + ($byStatus['approved'] ?? 0) + ($byStatus['rejected'] ?? 0);
        $accepted = ($byStatus['published'] ?? 0) + ($byStatus['approved'] ?? 0);
        $stuck = (int) $base()->whereIn('status', ['submitted', 'under_review'])->where('updated_at', '<', now()->subDays(14))->count();

        $topCategories = $base()->where('status', '!=', 'draft')->select('category', DB::raw('COUNT(*) as c'))
            ->groupBy('category')->orderByDesc('c')->limit(8)->get()
            ->map(fn ($r) => ['category' => $r->category, 'projects' => (int) $r->c])->all();

        return [
            'total'                  => $total,
            'by_status'              => $byStatus,
            'draft_share_pct'        => $total ? round(($byStatus['draft'] ?? 0) / $total * 100, 1) : null,
            'created_last_30d'       => $created30,
            'created_previous_30d'   => $createdPrev,
            'created_change_pct'     => $this->pct($created30, $createdPrev),
            'published_last_30d'     => $published30,
            'published_previous_30d' => $publishedPrev,
            'approval_rate_pct'      => $decided ? round($accepted / $decided * 100, 1) : null,
            'pending_review_over_14d' => $stuck,
            'top_categories'         => $topCategories,
            'total_views'            => (int) $base()->sum('views_count'),
        ];
    }

    private function universities(): array
    {
        $uni = DB::table('universities');
        if (Schema::hasColumn('universities', 'deleted_at')) {
            $uni->whereNull('deleted_at');
        }
        $byVerification = (clone $uni)->select('verification_status', DB::raw('COUNT(*) as c'))
            ->groupBy('verification_status')->pluck('c', 'verification_status')->map(fn ($v) => (int) $v)->all();

        $withProjects = (int) DB::table('projects')->whereNull('deleted_at')->where('status', '!=', 'draft')
            ->distinct()->count('university_id');
        $total = array_sum($byVerification);

        $leaderboard = array_map(fn ($r) => ['university' => $r['university']['en'], 'projects' => $r['projects']], $this->analytics->universityLeaderboard(8));
        $nonZero = array_sum(array_column($leaderboard, 'projects'));
        $allProjects = (int) DB::table('projects')->whereNull('deleted_at')->where('status', '!=', 'draft')->count();

        return [
            'total'                     => $total,
            'by_verification_status'    => $byVerification,
            'with_non_draft_projects'   => $withProjects,
            'without_projects'          => max(0, $total - $withProjects),
            'top_universities'          => $leaderboard,
            'top_8_share_of_projects_pct' => $allProjects ? round($nonZero / $allProjects * 100, 1) : null,
        ];
    }

    // ------------------------------------------------------------- security

    private function security(): array
    {
        $out = [];
        $from30 = now()->subDays(30);

        if (Schema::hasTable('security_incidents')) {
            $out['incidents_open_by_severity'] = DB::table('security_incidents')->whereIn('status', ['open', 'investigating', 'contained'])
                ->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity')->map(fn ($v) => (int) $v)->all();
            $out['incidents_opened_last_30d'] = (int) DB::table('security_incidents')->where('created_at', '>=', $from30)->count();
        }
        if (Schema::hasTable('security_alerts')) {
            $out['alerts_open_by_severity'] = DB::table('security_alerts')->whereIn('status', ['open', 'escalated'])
                ->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity')->map(fn ($v) => (int) $v)->all();
            $out['alerts_last_30d_by_type'] = DB::table('security_alerts')->where('created_at', '>=', $from30)
                ->select('type', DB::raw('COUNT(*) as c'))->groupBy('type')->orderByDesc('c')->limit(6)->pluck('c', 'type')->map(fn ($v) => (int) $v)->all();
        }
        if (Schema::hasTable('vulnerabilities')) {
            $open = DB::table('vulnerabilities')->whereIn('status', ['open', 'in_progress']);
            $out['vulnerabilities_open_by_severity'] = (clone $open)->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity')->map(fn ($v) => (int) $v)->all();
            $out['vulnerabilities_past_deadline'] = (int) (clone $open)->whereNotNull('deadline')->where('deadline', '<', now()->toDateString())->count();
        }

        return $out;
    }

    // ------------------------------------------------------------- AI usage

    private function aiUsage(): array
    {
        [$from30, $from60] = $this->windows();
        $win = fn ($a, $b) => DB::table('ai_usage_logs')->where('created_at', '>=', $a)->when($b, fn ($q) => $q->where('created_at', '<', $b));

        $c30 = (int) $win($from30, null)->count();
        $cPrev = (int) $win($from60, $from30)->count();
        $err30 = (int) $win($from30, null)->where('was_error', 1)->count();

        return [
            'requests_last_30d'      => $c30,
            'requests_previous_30d'  => $cPrev,
            'requests_change_pct'    => $this->pct($c30, $cPrev),
            'error_rate_pct'         => $c30 ? round($err30 / $c30 * 100, 1) : null,
            'avg_latency_ms'         => $c30 ? (int) round((float) $win($from30, null)->avg('latency_ms')) : null,
            'tokens_last_30d'        => (int) $win($from30, null)->sum(DB::raw('prompt_tokens + completion_tokens')),
            'distinct_users_30d'     => (int) $win($from30, null)->distinct()->count('user_id'),
            'by_portal_30d'          => $win($from30, null)->select('portal', DB::raw('COUNT(*) as c'))->groupBy('portal')->orderByDesc('c')->limit(6)->pluck('c', 'portal')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    // -------------------------------------------------------------- visitors

    private function visitors(): array
    {
        [$from30, $from60] = $this->windows();
        $uniq = fn ($a, $b) => (int) DB::table('site_visitor_days')->where('visit_date', '>=', $a->toDateString())
            ->when($b, fn ($q) => $q->where('visit_date', '<', $b->toDateString()))->distinct()->count('visitor_hash');

        $v30 = $uniq($from30, null);
        $vPrev = $uniq($from60, $from30);

        return [
            'unique_visitors_last_30d'     => $v30,
            'unique_visitors_previous_30d' => $vPrev,
            'change_pct'                   => $this->pct($v30, $vPrev),
        ];
    }
}
