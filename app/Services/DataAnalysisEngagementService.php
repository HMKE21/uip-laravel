<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Analysis Portal — التفاعل والنشاط (بند 6). بيدّي المحلل رؤية
 * مجمّعة (aggregates بس) لكل جداول التفاعل في المنصة: الـ feed
 * وتفاعلاته، الإعلانات، الإشعارات (+ طابور الإيميل + إشعارات الأمان)،
 * user_activity_events، login_events، زيارات الموقع، الاجتماعات،
 * المكالمات، والفورمات وردودها.
 *
 * قراءة بس — مفيش أي كتابة على جداول المنصة.
 *
 * الخصوصية: كل الأرقام تجميعية. مفيش نص تعليقات ولا نص رسائل ولا
 * إيميلات/IPs/أرقام قومية ولا إجابات فورمات ولا أسماء مستخدمين. الاستثناء
 * الوحيد عناوين المنشورات/الفورمات العامة في قوائم "الأعلى تفاعلًا"،
 * ومسارات الصفحات (path) ونقاط الـ API (endpoint) في تحليلات النشاط.
 *
 * التجميع بيتم في SQL بدوال portable (DATE / COUNT / AVG / SUM) عشان
 * يشتغل على MySQL وSQLite. كل قسم بيتحمّل لوحده، وأي جدول ناقص بيرجّع
 * available=false بدل ما يكسر الطلب.
 */
class DataAnalysisEngagementService
{
    public const SECTIONS = [
        'feed', 'announcements', 'notifications', 'activity',
        'logins', 'visits', 'meetings', 'calls', 'forms', 'ai',
    ];

    private const MAX_DAYS = 366;
    private const DEFAULT_DAYS = 30;
    private const MAX_ROWS = 20000;

    // -----------------------------------------------------------------
    // Filters
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

    // -----------------------------------------------------------------
    // Overview — headline KPIs of every section + one combined trend
    // -----------------------------------------------------------------

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
            'feed'          => $this->feed($f),
            'announcements' => $this->announcements($f),
            'notifications' => $this->notifications($f),
            'activity'      => $this->activity($f),
            'logins'        => $this->logins($f),
            'visits'        => $this->visits($f),
            'meetings'      => $this->meetings($f),
            'calls'         => $this->calls($f),
            'forms'         => $this->forms($f),
            'ai'            => $this->ai($f),
            default         => null,
        };
    }

    // -----------------------------------------------------------------
    // Feed
    // -----------------------------------------------------------------

    private function feed(array $f): array
    {
        if (!Schema::hasTable('feed_posts')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $uni = $f['university_id'] ?? null;

        $posts = function () use ($r, $uni) {
            $q = DB::table('feed_posts')->whereNull('deleted_at')->whereBetween('created_at', [$r['start'], $r['end']]);
            return $uni ? $q->where('university_id', $uni) : $q;
        };
        $inScope = function (string $table, bool $softDeleted = false) use ($r, $uni) {
            $q = DB::table($table . ' as x')->join('feed_posts as p', 'p.id', '=', 'x.post_id')
                ->whereBetween('x.created_at', [$r['start'], $r['end']]);
            if ($softDeleted) {
                $q->whereNull('x.deleted_at');
            }
            return $uni ? $q->where('p.university_id', $uni) : $q;
        };

        $postCount = (int) $posts()->count();
        $likes = Schema::hasTable('feed_post_likes') ? (int) $inScope('feed_post_likes')->count() : 0;
        $comments = Schema::hasTable('feed_post_comments') ? (int) $inScope('feed_post_comments', true)->count() : 0;
        $shares = Schema::hasTable('feed_post_shares') ? (int) $inScope('feed_post_shares')->count() : 0;
        $saves = Schema::hasTable('feed_post_saves') ? (int) $inScope('feed_post_saves')->count() : 0;
        $interactions = $likes + $comments + $shares + $saves;

        $engaged = 0;
        if (Schema::hasTable('feed_post_likes') && Schema::hasTable('feed_post_comments')) {
            $u = $inScope('feed_post_likes')->select('x.user_id')
                ->union($inScope('feed_post_comments', true)->select('x.user_id'));
            $engaged = (int) DB::query()->fromSub($u, 'u')->count();
        }

        $reports = [];
        if (Schema::hasTable('feed_post_reports')) {
            $reports = $this->groupCount(
                DB::table('feed_post_reports')->whereBetween('created_at', [$r['start'], $r['end']]),
                'status'
            );
        }

        $attachments = [];
        if (Schema::hasTable('feed_post_attachments')) {
            $attachments = $this->groupCount(
                DB::table('feed_post_attachments as a')->join('feed_posts as p', 'p.id', '=', 'a.post_id')
                    ->whereNull('p.deleted_at')->whereBetween('p.created_at', [$r['start'], $r['end']])
                    ->when($uni, fn ($q) => $q->where('p.university_id', $uni)),
                'a.kind', 'kind'
            );
        }

        $top = $posts()
            ->select('id', 'title', 'is_event', 'is_pinned', 'likes_count', 'comments_count', 'shares_count', 'saves_count', 'created_at')
            ->orderByRaw('(likes_count + comments_count + shares_count + saves_count) DESC')
            ->limit(10)->get()
            ->map(fn ($p) => [
                'id'           => (int) $p->id,
                'title'        => $p->title,
                'is_event'     => (bool) $p->is_event,
                'is_pinned'    => (bool) $p->is_pinned,
                'likes'        => (int) $p->likes_count,
                'comments'     => (int) $p->comments_count,
                'shares'       => (int) $p->shares_count,
                'saves'        => (int) $p->saves_count,
                'interactions' => (int) ($p->likes_count + $p->comments_count + $p->shares_count + $p->saves_count),
                'created_at'   => $p->created_at,
            ])->all();

        $byUniversity = [];
        if (!$uni) {
            $byUniversity = $posts()->select('university_id as key', DB::raw('COUNT(*) as count'))
                ->groupBy('university_id')->orderByDesc('count')->limit(10)->get()
                ->map(fn ($x) => ['university_id' => (int) $x->key, 'count' => (int) $x->count])->all();
        }

        $daily = $this->daily($posts(), 'created_at');
        $interactionsDaily = [];
        foreach ([['feed_post_likes', false], ['feed_post_comments', true], ['feed_post_shares', false], ['feed_post_saves', false]] as [$t, $soft]) {
            if (!Schema::hasTable($t)) {
                continue;
            }
            foreach ($this->daily($inScope($t, $soft), 'x.created_at') as $d => $c) {
                $interactionsDaily[$d] = ($interactionsDaily[$d] ?? 0) + $c;
            }
        }

        return [
            'available' => true,
            'kpis' => [
                'posts'                 => $postCount,
                'events'                => (int) $posts()->where('is_event', 1)->count(),
                'pinned'                => (int) $posts()->where('is_pinned', 1)->count(),
                'authors'               => (int) $posts()->distinct()->count('author_user_id'),
                'likes'                 => $likes,
                'comments'              => $comments,
                'shares'                => $shares,
                'saves'                 => $saves,
                'interactions'          => $interactions,
                'engaged_users'         => $engaged,
                'avg_interactions_post' => $postCount ? round($interactions / $postCount, 2) : null,
                'targeted_posts'        => (int) $posts()->where(function ($q) {
                    $q->whereNotNull('target_faculty_id')->orWhereNotNull('target_department_id')->orWhereNotNull('target_academic_year');
                })->count(),
                'edited_posts'          => (int) $posts()->where('is_edited', 1)->count(),
                'removed_posts'         => (int) DB::table('feed_posts')->whereNotNull('deleted_at')
                    ->whereBetween('created_at', [$r['start'], $r['end']])
                    ->when($uni, fn ($q) => $q->where('university_id', $uni))->count(),
            ],
            'daily'             => $this->fill($daily, $r),
            'interactions_daily' => $this->fill($interactionsDaily, $r),
            'interaction_mix'   => [
                ['key' => 'likes', 'count' => $likes], ['key' => 'comments', 'count' => $comments],
                ['key' => 'shares', 'count' => $shares], ['key' => 'saves', 'count' => $saves],
            ],
            'reports'        => $reports,
            'attachments'    => $attachments,
            'by_university'  => $byUniversity,
            'top_posts'      => $top,
            'university_names' => $this->universityNames(array_column($byUniversity, 'university_id')),
        ];
    }

    // -----------------------------------------------------------------
    // Announcements
    // -----------------------------------------------------------------

    private function announcements(array $f): array
    {
        if (!Schema::hasTable('announcements')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $uni = $f['university_id'] ?? null;
        $now = Carbon::now()->toDateTimeString();

        $base = function () use ($r, $uni) {
            $q = DB::table('announcements')->whereNull('deleted_at')->whereBetween('created_at', [$r['start'], $r['end']]);
            return $uni ? $q->where('university_id', $uni) : $q;
        };

        $attachments = [];
        if (Schema::hasTable('announcement_attachments')) {
            $attachments = $this->groupCount(
                DB::table('announcement_attachments as a')->join('announcements as n', 'n.id', '=', 'a.announcement_id')
                    ->whereNull('n.deleted_at')->whereBetween('n.created_at', [$r['start'], $r['end']])
                    ->when($uni, fn ($q) => $q->where('n.university_id', $uni)),
                'a.kind', 'kind'
            );
        }

        $total = (int) $base()->count();
        $published = (int) $base()->whereNotNull('published_at')->count();

        return [
            'available' => true,
            'kpis' => [
                'announcements' => $total,
                'published'     => $published,
                'scheduled'     => (int) $base()->whereNull('published_at')->whereNotNull('publish_at')->where('publish_at', '>', $now)->count(),
                'drafts'        => (int) $base()->whereNull('published_at')->whereNull('publish_at')->count(),
                'expired'       => (int) $base()->whereNotNull('expires_at')->where('expires_at', '<', $now)->count(),
                'notified'      => (int) $base()->whereNotNull('notified_at')->count(),
                'authors'       => (int) $base()->distinct()->count('author_user_id'),
                'targeted'      => (int) $base()->where(function ($q) {
                    $q->whereNotNull('target_faculty_id')->orWhereNotNull('target_department_id')->orWhereNotNull('target_academic_year');
                })->count(),
                'removed'       => (int) DB::table('announcements')->whereNotNull('deleted_at')
                    ->whereBetween('created_at', [$r['start'], $r['end']])
                    ->when($uni, fn ($q) => $q->where('university_id', $uni))->count(),
            ],
            'daily'       => $this->fill($this->daily($base(), 'created_at'), $r),
            'by_category' => $this->groupCount($base(), 'category'),
            'attachments' => $attachments,
        ];
    }

    // -----------------------------------------------------------------
    // Notifications (+ email queue + security notifications)
    // -----------------------------------------------------------------

    private function notifications(array $f): array
    {
        if (!Schema::hasTable('notifications')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => DB::table('notifications')->whereBetween('created_at', [$r['start'], $r['end']]);

        $total = (int) $base()->count();
        $read = (int) $base()->where('is_read', 1)->count();

        $email = ['by_status' => [], 'sent' => 0, 'failed' => 0, 'avg_attempts' => null];
        if (Schema::hasTable('notification_email_queue')) {
            $eq = fn () => DB::table('notification_email_queue')->whereBetween('created_at', [$r['start'], $r['end']]);
            $email['by_status'] = $this->groupCount($eq(), 'status');
            foreach ($email['by_status'] as $row) {
                if ($row['key'] === 'sent') {
                    $email['sent'] = $row['count'];
                }
                if ($row['key'] === 'failed') {
                    $email['failed'] = $row['count'];
                }
            }
            $avg = $eq()->avg('attempts');
            $email['avg_attempts'] = $avg !== null ? round((float) $avg, 2) : null;
        }

        $security = [];
        if (Schema::hasTable('security_notifications')) {
            $security = $this->groupCount(
                DB::table('security_notifications')->whereBetween('created_at', [$r['start'], $r['end']]),
                'severity'
            );
        }

        return [
            'available' => true,
            'kpis' => [
                'notifications'  => $total,
                'recipients'     => (int) $base()->distinct()->count('user_id'),
                'read'           => $read,
                'unread'         => $total - $read,
                'read_rate'      => $total ? round($read / $total * 100, 2) : null,
                'important'      => (int) $base()->where('is_important', 1)->count(),
                'archived'       => (int) $base()->where('is_archived', 1)->count(),
                'high_priority'  => (int) $base()->whereIn('priority', ['high', 'urgent'])->count(),
                'emails_queued'  => array_sum(array_column($email['by_status'], 'count')),
                'emails_failed'  => $email['failed'],
            ],
            'daily'       => $this->fill($this->daily($base(), 'created_at'), $r),
            'by_type'     => $this->groupCount($base(), 'type', 'key', 10),
            'by_category' => $this->groupCount($base()->whereNotNull('category'), 'category', 'key', 10),
            'by_priority' => $this->groupCount($base(), 'priority'),
            'email_queue' => $email,
            'security'    => $security,
        ];
    }

    // -----------------------------------------------------------------
    // User activity events (client + server tracker)
    // -----------------------------------------------------------------

    private function activity(array $f): array
    {
        if (!Schema::hasTable('user_activity_events')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => DB::table('user_activity_events')->whereBetween('occurred_at', [$r['start'], $r['end']]);

        $errTypes = ['api_error', 'network_error', 'js_error'];
        $total = (int) $base()->count();
        $errors = (int) $base()->whereIn('type', $errTypes)->count();
        $avgMs = $base()->whereNotNull('duration_ms')->avg('duration_ms');

        $topErrors = $base()->whereIn('type', ['api_error', 'network_error'])->whereNotNull('endpoint')
            ->select('endpoint', DB::raw('COUNT(*) as c'), DB::raw('COUNT(DISTINCT user_id) as users'))
            ->groupBy('endpoint')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($x) => ['endpoint' => $x->endpoint, 'count' => (int) $x->c, 'users' => (int) $x->users])->all();

        $slow = $base()->whereNotNull('duration_ms')->whereNotNull('endpoint')
            ->select('endpoint', DB::raw('COUNT(*) as c'), DB::raw('AVG(duration_ms) as avg_ms'))
            ->groupBy('endpoint')->havingRaw('COUNT(*) >= 5')->orderByDesc('avg_ms')->limit(10)->get()
            ->map(fn ($x) => ['endpoint' => $x->endpoint, 'count' => (int) $x->c, 'avg_ms' => (int) round((float) $x->avg_ms)])->all();

        $topPages = $base()->where('type', 'page_view')->whereNotNull('path')
            ->select('path', DB::raw('COUNT(*) as c'), DB::raw('COUNT(DISTINCT user_id) as users'))
            ->groupBy('path')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($x) => ['path' => $x->path, 'count' => (int) $x->c, 'users' => (int) $x->users])->all();

        return [
            'available' => true,
            'kpis' => [
                'events'         => $total,
                'active_users'   => (int) $base()->distinct()->count('user_id'),
                'sessions'       => (int) $base()->whereNotNull('session_id')->distinct()->count('session_id'),
                'page_views'     => (int) $base()->where('type', 'page_view')->count(),
                'errors'         => $errors,
                'error_rate'     => $total ? round($errors / $total * 100, 2) : null,
                'rage_clicks'    => (int) $base()->where('type', 'rage_click')->count(),
                'js_errors'      => (int) $base()->where('type', 'js_error')->count(),
                'avg_duration_ms' => $avgMs !== null ? (int) round((float) $avgMs) : null,
            ],
            'daily'       => $this->fill($this->daily($base(), 'occurred_at'), $r),
            'errors_daily' => $this->fill($this->daily($base()->whereIn('type', $errTypes), 'occurred_at'), $r),
            'by_type'     => $this->groupCount($base(), 'type'),
            'by_severity' => $this->groupCount($base(), 'severity'),
            'by_source'   => $this->groupCount($base(), 'source'),
            'by_device'   => $this->groupCount($base()->whereNotNull('device'), 'device'),
            'by_browser'  => $this->groupCount($base()->whereNotNull('browser'), 'browser', 'key', 8),
            'top_pages'   => $topPages,
            'top_error_endpoints' => $topErrors,
            'slowest_endpoints'   => $slow,
        ];
    }

    // -----------------------------------------------------------------
    // Login events
    // -----------------------------------------------------------------

    private function logins(array $f): array
    {
        if (!Schema::hasTable('login_events')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => DB::table('login_events')->whereBetween('created_at', [$r['start'], $r['end']]);

        $total = (int) $base()->count();
        $ok = (int) $base()->where('success', 1)->count();

        return [
            'available' => true,
            'kpis' => [
                'attempts'         => $total,
                'successful'       => $ok,
                'failed'           => $total - $ok,
                'success_rate'     => $total ? round($ok / $total * 100, 2) : null,
                'unique_users'     => (int) $base()->where('success', 1)->whereNotNull('user_id')->distinct()->count('user_id'),
                'new_devices'      => (int) $base()->where('success', 1)->where('is_new_device', 1)->count(),
                'unique_ips'       => (int) $base()->whereNotNull('ip_address')->distinct()->count('ip_address'),
                'failed_unknown_account' => (int) $base()->where('success', 0)->whereNull('user_id')->count(),
            ],
            'daily'          => $this->fill($this->daily($base()->where('success', 1), 'created_at'), $r),
            'failed_daily'   => $this->fill($this->daily($base()->where('success', 0), 'created_at'), $r),
            'by_method'      => $this->groupCount($base(), 'method'),
            'failure_reasons' => $this->groupCount($base()->where('success', 0)->whereNotNull('failure_reason'), 'failure_reason', 'key', 10),
            'by_device'      => $this->groupCount($base()->where('success', 1)->whereNotNull('device_label'), 'device_label', 'key', 8),
            'by_location'    => $this->groupCount($base()->where('success', 1)->whereNotNull('location_label'), 'location_label', 'key', 8),
        ];
    }

    // -----------------------------------------------------------------
    // Site visits (public landing visitors)
    // -----------------------------------------------------------------

    private function visits(array $f): array
    {
        if (!Schema::hasTable('site_visitor_days') && !Schema::hasTable('site_visitors')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $kpis = [];
        $daily = [];

        if (Schema::hasTable('site_visitor_days')) {
            $days = fn () => DB::table('site_visitor_days')->whereBetween('visit_date', [$r['from'], $r['to']]);
            $daily = $this->daily($days(), 'visit_date');
            $kpis['unique_visitors'] = (int) $days()->distinct()->count('visitor_hash');
            $kpis['visitor_days'] = (int) $days()->count();
            $kpis['avg_daily_visitors'] = $r['days'] ? round($kpis['visitor_days'] / $r['days'], 2) : null;
        }

        if (Schema::hasTable('site_visitors')) {
            $kpis['new_visitors'] = (int) DB::table('site_visitors')->whereBetween('first_visit_at', [$r['start'], $r['end']])->count();
            $kpis['returning_visitors'] = (int) DB::table('site_visitors')
                ->whereBetween('last_visit_at', [$r['start'], $r['end']])
                ->where('first_visit_at', '<', $r['start'])->count();
            $kpis['all_time_visitors'] = (int) DB::table('site_visitors')->count();
            $kpis['avg_visits_per_visitor'] = round((float) (DB::table('site_visitors')->avg('visits_count') ?? 0), 2);
        }

        $counters = Schema::hasTable('site_counters')
            ? DB::table('site_counters')->orderBy('name')->get()->map(fn ($c) => ['name' => $c->name, 'value' => (int) $c->value])->all()
            : [];

        $newDaily = Schema::hasTable('site_visitors')
            ? $this->daily(DB::table('site_visitors')->whereBetween('first_visit_at', [$r['start'], $r['end']]), 'first_visit_at')
            : [];

        return [
            'available'  => true,
            'kpis'       => $kpis,
            'daily'      => $this->fill($daily, $r),
            'new_daily'  => $this->fill($newDaily, $r),
            'counters'   => $counters,
        ];
    }

    // -----------------------------------------------------------------
    // Meetings
    // -----------------------------------------------------------------

    private function meetings(array $f): array
    {
        if (!Schema::hasTable('meetings')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => DB::table('meetings')->whereNull('deleted_at')->whereBetween('created_at', [$r['start'], $r['end']]);

        $total = (int) $base()->count();

        // مدة الاجتماع الفعلية (started_at → ended_at) بتتحسب في PHP عشان portable.
        $minutes = [];
        $base()->whereNotNull('started_at')->whereNotNull('ended_at')->select('started_at', 'ended_at')
            ->limit(self::MAX_ROWS)->get()
            ->each(function ($m) use (&$minutes) {
                $mins = Carbon::parse($m->started_at)->diffInMinutes(Carbon::parse($m->ended_at), false);
                if ($mins >= 0) {
                    $minutes[] = $mins;
                }
            });

        $participants = ['total' => 0, 'unique' => 0, 'by_status' => [], 'by_role' => []];
        if (Schema::hasTable('meeting_participants')) {
            $pq = fn () => DB::table('meeting_participants as p')->join('meetings as m', 'm.id', '=', 'p.meeting_id')
                ->whereNull('m.deleted_at')->whereBetween('m.created_at', [$r['start'], $r['end']]);
            $participants = [
                'total'     => (int) $pq()->count(),
                'unique'    => (int) $pq()->distinct()->count('p.user_id'),
                'by_status' => $this->groupCount($pq(), 'p.status', 'key'),
                'by_role'   => $this->groupCount($pq(), 'p.role', 'key'),
            ];
        }

        $invitations = [];
        if (Schema::hasTable('meeting_invitations')) {
            $invitations = $this->groupCount(
                DB::table('meeting_invitations')->whereBetween('created_at', [$r['start'], $r['end']]), 'status'
            );
        }

        $joined = 0;
        foreach ($participants['by_status'] as $row) {
            if (in_array($row['key'], ['joined', 'left'], true)) {
                $joined += $row['count'];
            }
        }

        $extras = [
            'chat_messages' => $this->countIn('meeting_chat_messages', $r),
            'files'         => $this->countIn('meeting_files', $r),
            'notes'         => $this->countIn('meeting_notes', $r),
            'action_items'  => $this->countIn('meeting_action_items', $r),
            'polls'         => $this->countIn('meeting_polls', $r),
            'recordings'    => $this->countIn('meeting_recordings', $r),
            'join_requests' => $this->countIn('meeting_join_requests', $r),
        ];

        return [
            'available' => true,
            'kpis' => [
                'meetings'              => $total,
                'hosts'                 => (int) $base()->distinct()->count('host_user_id'),
                'live_or_ended'         => (int) $base()->whereIn('status', ['live', 'ended'])->count(),
                'cancelled'             => (int) $base()->where('status', 'cancelled')->count(),
                'avg_duration_min'      => $minutes ? round(array_sum($minutes) / count($minutes), 1) : null,
                'total_hours'           => $minutes ? round(array_sum($minutes) / 60, 1) : 0,
                'participants'          => $participants['total'],
                'unique_participants'   => $participants['unique'],
                'attended'              => $joined,
                'avg_participants'      => $total ? round($participants['total'] / $total, 2) : null,
                'password_protected'    => (int) $base()->whereNotNull('password_hash')->count(),
                'guests_allowed'        => (int) $base()->where('allow_guests', 1)->count(),
            ],
            'daily'        => $this->fill($this->daily($base(), 'created_at'), $r),
            'by_status'    => $this->groupCount($base(), 'status'),
            'by_type'      => $this->groupCount($base(), 'type'),
            'participants' => $participants,
            'invitations'  => $invitations,
            'extras'       => $extras,
        ];
    }

    // -----------------------------------------------------------------
    // Calls (1:1 audio / video)
    // -----------------------------------------------------------------

    private function calls(array $f): array
    {
        if (!Schema::hasTable('calls')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => DB::table('calls')->whereBetween('created_at', [$r['start'], $r['end']]);

        $total = (int) $base()->count();
        $answered = (int) $base()->whereNotNull('answered_at')->count();
        $avgSec = $base()->whereNotNull('answered_at')->whereNotNull('duration_seconds')->avg('duration_seconds');
        $sumSec = (int) $base()->whereNotNull('answered_at')->sum('duration_seconds');

        $users = DB::query()->fromSub(
            $base()->select('caller_id as uid')->union($base()->select('callee_id as uid')),
            'u'
        )->count();

        return [
            'available' => true,
            'kpis' => [
                'calls'            => $total,
                'answered'         => $answered,
                'answer_rate'      => $total ? round($answered / $total * 100, 2) : null,
                'missed'           => $total - $answered,
                'video'            => (int) $base()->where('type', 'video')->count(),
                'audio'            => (int) $base()->where('type', 'audio')->count(),
                'avg_duration_sec' => $avgSec !== null ? (int) round((float) $avgSec) : null,
                'total_minutes'    => (int) round($sumSec / 60),
                'unique_callers'   => (int) $base()->distinct()->count('caller_id'),
                'unique_users'     => (int) $users,
            ],
            'daily'       => $this->fill($this->daily($base(), 'created_at'), $r),
            'by_status'   => $this->groupCount($base(), 'status'),
            'by_type'     => $this->groupCount($base(), 'type'),
            'end_reasons' => $this->groupCount($base()->whereNotNull('end_reason'), 'end_reason', 'key', 10),
        ];
    }

    // -----------------------------------------------------------------
    // Smart forms + responses (aggregates only — never answers / PII)
    // -----------------------------------------------------------------

    private function forms(array $f): array
    {
        if (!Schema::hasTable('forms')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $forms = fn () => DB::table('forms')->whereNull('deleted_at');
        $created = fn () => $forms()->whereBetween('created_at', [$r['start'], $r['end']]);

        $hasResponses = Schema::hasTable('form_responses');
        $resp = fn () => DB::table('form_responses as r')->join('forms as f', 'f.id', '=', 'r.form_id')
            ->whereNull('f.deleted_at')->whereBetween('r.created_at', [$r['start'], $r['end']]);

        $responses = $hasResponses ? (int) $resp()->count() : 0;
        $submitted = $hasResponses ? (int) $resp()->whereIn('r.status', ['submitted', 'graded', 'auto_submitted'])->count() : 0;
        $avgDur = $hasResponses ? $resp()->whereNotNull('r.duration_seconds')->avg('r.duration_seconds') : null;
        $avgScore = $hasResponses
            ? $resp()->where('f.mode', 'exam')->where('r.max_score', '>', 0)->whereNotNull('r.score')
                ->avg(DB::raw('r.score * 100.0 / r.max_score'))
            : null;

        $topForms = [];
        if ($hasResponses) {
            $topForms = $resp()
                ->select('f.id', 'f.title', 'f.mode', 'f.status', DB::raw('COUNT(*) as c'),
                    DB::raw("SUM(CASE WHEN r.status IN ('submitted','graded','auto_submitted') THEN 1 ELSE 0 END) as done"))
                ->groupBy('f.id', 'f.title', 'f.mode', 'f.status')->orderByDesc('c')->limit(10)->get()
                ->map(fn ($x) => [
                    'id' => (int) $x->id, 'title' => $x->title, 'mode' => $x->mode, 'status' => $x->status,
                    'responses' => (int) $x->c, 'submitted' => (int) $x->done,
                    'completion_rate' => $x->c ? round($x->done / $x->c * 100, 1) : null,
                ])->all();
        }

        $events = [];
        if (Schema::hasTable('form_events')) {
            $events = $this->groupCount(
                DB::table('form_events')->whereBetween('created_at', [$r['start'], $r['end']]), 'type', 'key', 10
            );
        }

        return [
            'available' => true,
            'kpis' => [
                'forms'            => (int) $forms()->count(),
                'published'        => (int) $forms()->where('status', 'published')->count(),
                'created'          => (int) $created()->count(),
                'owners'           => (int) $forms()->distinct()->count('owner_user_id'),
                'responses'        => $responses,
                'submitted'        => $submitted,
                'completion_rate'  => $responses ? round($submitted / $responses * 100, 2) : null,
                'respondents'      => $hasResponses ? (int) $resp()->whereNotNull('r.user_id')->distinct()->count('r.user_id') : 0,
                'anonymous'        => $hasResponses ? (int) $resp()->whereNull('r.user_id')->count() : 0,
                'avg_duration_sec' => $avgDur !== null ? (int) round((float) $avgDur) : null,
                'avg_exam_score_pct' => $avgScore !== null ? round((float) $avgScore, 2) : null,
                'needs_manual'     => $hasResponses ? (int) $resp()->where('r.needs_manual', 1)->count() : 0,
                'violations'       => $hasResponses ? (int) $resp()->sum('r.violations') : 0,
            ],
            'daily'         => $this->fill($hasResponses ? $this->daily($resp(), 'r.created_at') : [], $r),
            'by_status'     => $this->groupCount($forms(), 'status'),
            'by_mode'       => $this->groupCount($forms(), 'mode'),
            'response_status' => $hasResponses ? $this->groupCount($resp(), 'r.status', 'key') : [],
            'top_forms'     => $topForms,
            'events'        => $events,
        ];
    }

    // -----------------------------------------------------------------
    // AI usage (ai_usage_logs + conversation/message COUNTS only)
    //
    // خصوصية: مفيش محتوى رسائل، مفيش عناوين محادثات، مفيش error_message
    // (ممكن يحتوي أجزاء من الـ prompt)، ومفيش قائمة مستخدمين ولا IDs —
    // التركّز على المستخدمين بيتعرض كشرائح (عدد مستخدمين في كل شريحة).
    // -----------------------------------------------------------------

    private function ai(array $f): array
    {
        if (!Schema::hasTable('ai_usage_logs')) {
            return ['available' => false];
        }
        $r = $this->range($f);
        $base = fn () => DB::table('ai_usage_logs')->whereBetween('created_at', [$r['start'], $r['end']]);

        $totals = $base()->selectRaw(
            'COUNT(*) AS calls, COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct,
             COALESCE(SUM(was_error),0) AS errs, COUNT(DISTINCT user_id) AS users, AVG(latency_ms) AS lat'
        )->first();
        $calls = (int) ($totals->calls ?? 0);
        $pt = (int) ($totals->pt ?? 0);
        $ct = (int) ($totals->ct ?? 0);
        $errs = (int) ($totals->errs ?? 0);
        $users = (int) ($totals->users ?? 0);

        // Percentiles على آخر MAX_ROWS استدعاء (portable — بدون PERCENTILE_CONT).
        $lat = $base()->whereNotNull('latency_ms')->orderByDesc('id')->limit(self::MAX_ROWS)->pluck('latency_ms')
            ->map(fn ($v) => (int) $v)->sort()->values()->all();
        $pctl = function (float $p) use ($lat) {
            $n = count($lat);
            return $n ? $lat[(int) min($n - 1, max(0, ceil($p * $n) - 1))] : null;
        };

        // شرائح تركّز الاستخدام: كام مستخدم عمل 1 / 2-5 / 6-20 / 21-100 / 100+ استدعاء.
        $buckets = ['1' => 0, '2-5' => 0, '6-20' => 0, '21-100' => 0, '100+' => 0];
        $perUser = $base()->select('user_id', DB::raw('COUNT(*) as c'))->groupBy('user_id')->limit(self::MAX_ROWS)->pluck('c');
        foreach ($perUser as $c) {
            $c = (int) $c;
            $k = $c <= 1 ? '1' : ($c <= 5 ? '2-5' : ($c <= 20 ? '6-20' : ($c <= 100 ? '21-100' : '100+')));
            $buckets[$k]++;
        }

        $mapAgg = fn ($rows) => $rows->map(fn ($x) => [
            'key'        => $x->k === null ? '' : (string) $x->k,
            'calls'      => (int) $x->calls,
            'tokens'     => (int) $x->tokens,
            'errors'     => (int) $x->errs,
            'avg_latency_ms' => $x->lat !== null ? (int) round((float) $x->lat) : null,
        ])->all();
        $agg = fn ($col) => $base()->select(
            DB::raw("$col as k"), DB::raw('COUNT(*) as calls'),
            DB::raw('COALESCE(SUM(prompt_tokens + completion_tokens),0) as tokens'),
            DB::raw('COALESCE(SUM(was_error),0) as errs'), DB::raw('AVG(latency_ms) as lat')
        )->groupBy(DB::raw($col))->orderByDesc('calls')->limit(10)->get();

        // تجميعات المحادثات/الرسائل (أعداد بس).
        $conv = null;
        if (Schema::hasTable('ai_conversations')) {
            $cq = fn () => DB::table('ai_conversations')->whereBetween('created_at', [$r['start'], $r['end']]);
            $avgMsgs = $cq()->avg('message_count');
            $conv = [
                'created'      => (int) $cq()->count(),
                'users'        => (int) $cq()->distinct()->count('user_id'),
                'avg_messages' => $avgMsgs !== null ? round((float) $avgMsgs, 2) : null,
                'pinned'       => (int) $cq()->where('is_pinned', 1)->count(),
                'archived'     => (int) $cq()->where('is_archived', 1)->count(),
                'shared'       => (int) $cq()->whereNotNull('share_token')->count(),
                'by_portal'    => $this->groupCount($cq(), 'portal', 'key', 10),
            ];
        }

        $msgs = null;
        if (Schema::hasTable('ai_messages')) {
            $mq = fn () => DB::table('ai_messages')->where('is_deleted', 0)->whereBetween('created_at', [$r['start'], $r['end']]);
            $assistant = (int) $mq()->where('role', 'assistant')->count();
            $predefined = (int) $mq()->where('role', 'assistant')->where('answer_source', 'predefined')->count();
            $msgs = [
                'total'          => (int) $mq()->count(),
                'by_role'        => $this->groupCount($mq(), 'role'),
                'by_status'      => $this->groupCount($mq()->where('role', 'assistant'), 'status'),
                'by_source'      => $this->groupCount($mq()->where('role', 'assistant')->whereNotNull('answer_source'), 'answer_source'),
                'bookmarked'     => (int) $mq()->where('is_bookmarked', 1)->count(),
                'predefined_rate' => $assistant ? round($predefined / $assistant * 100, 2) : null,
            ];
        }

        $tokensDaily = $base()->select(DB::raw('DATE(created_at) as d'), DB::raw('COALESCE(SUM(prompt_tokens + completion_tokens),0) as s'))
            ->groupBy(DB::raw('DATE(created_at)'))->pluck('s', 'd')->map(fn ($v) => (int) $v)->all();

        return [
            'available' => true,
            'kpis' => [
                'calls'             => $calls,
                'active_users'      => $users,
                'calls_per_user'    => $users ? round($calls / $users, 2) : null,
                'prompt_tokens'     => $pt,
                'completion_tokens' => $ct,
                'total_tokens'      => $pt + $ct,
                'avg_tokens_call'   => $calls ? (int) round(($pt + $ct) / $calls) : null,
                'errors'            => $errs,
                'error_rate'        => $calls ? round($errs / $calls * 100, 2) : null,
                'avg_latency_ms'    => $totals->lat !== null ? (int) round((float) $totals->lat) : null,
                'p50_latency_ms'    => $pctl(0.50),
                'p95_latency_ms'    => $pctl(0.95),
            ],
            'daily'         => $this->fill($this->daily($base(), 'created_at'), $r),
            'tokens_daily'  => $this->fill($tokensDaily, $r),
            'errors_daily'  => $this->fill($this->daily($base()->where('was_error', 1), 'created_at'), $r),
            'by_portal'     => $mapAgg($agg('portal')),
            'by_model'      => $mapAgg($agg('model')),
            'user_buckets'  => array_map(fn ($k, $v) => ['key' => (string) $k, 'count' => $v], array_keys($buckets), array_values($buckets)),
            'conversations' => $conv,
            'messages'      => $msgs,
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{from:string,to:string,start:string,end:string,days:int} */
    private function range(array $f): array
    {
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $to = $ok($f['to'] ?? null) ? Carbon::parse($f['to']) : Carbon::today();
        $from = $ok($f['from'] ?? null) ? Carbon::parse($f['from']) : $to->copy()->subDays(self::DEFAULT_DAYS - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [
            'from'  => $from->toDateString(),
            'to'    => $to->toDateString(),
            'start' => $from->toDateString() . ' 00:00:00',
            'end'   => $to->toDateString() . ' 23:59:59',
            'days'  => (int) ($from->diffInDays($to) + 1),
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

    /**
     * GROUP BY عمود → [{key,count}] مرتّب تنازليًا. $col ممكن يكون
     * "alias.col"؛ $as هو اسم الـ alias في النتيجة (الافتراضي key).
     */
    private function groupCount($query, string $col, string $as = 'key', ?int $limit = null): array
    {
        $q = $query->select(DB::raw("$col as k"), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw($col))->orderByDesc('c');
        if ($limit) {
            $q->limit($limit);
        }
        return $q->get()->map(fn ($x) => ['key' => $x->k === null ? '' : (string) $x->k, 'count' => (int) $x->c])->all();
    }

    private function countIn(string $table, array $r, string $col = 'created_at'): ?int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $col)) {
            return null;
        }
        return (int) DB::table($table)->whereBetween($col, [$r['start'], $r['end']])->count();
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
