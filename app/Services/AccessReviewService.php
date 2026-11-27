<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * مراجعة الصلاحيات الدورية — "مين يقدر يعمل إيه" (بند 6).
 *  matrix()  : الأدوار × الصلاحيات + استثناءات المستخدمين (grant/revoke).
 *  start()   : بيفتح مراجعة بلقطة (snapshot) لكل مستخدم في النطاق: أدواره وصلاحياته الفعلية وآخر دخول وأعلام تنبيه.
 *  decide()  : keep / revoke لكل مستخدم (revoke لازم له سبب)، و remediate() لما التنفيذ الفعلي يتم من صفحة المستخدمين.
 *  complete(): مينفعش قبل ما كل البنود يتقرر فيها. المراجعة نفسها مبتغيّرش صلاحيات أي حد (تنفيذ الإلغاء إجراء بشري منفصل).
 */
class AccessReviewService
{
    public const PRIVILEGED_ROLES = ['admin', 'security_admin', 'security_officer'];
    private const BROAD_PERMISSIONS = 20;

    public function __construct(
        private ComplianceSettings $settings,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array<string,mixed> */
    public function matrix(): array
    {
        $roles = DB::table('roles')->orderBy('id')->get(['id', 'slug', 'name_en', 'name_ar', 'portal_prefix', 'is_system']);
        $perms = DB::table('permissions')->orderBy('module')->orderBy('slug')->get(['id', 'slug', 'module', 'description']);
        $grants = DB::table('role_permission')->get(['role_id', 'permission_id']);
        $slugById = $perms->pluck('slug', 'id');
        $byRole = [];
        foreach ($grants as $g) {
            if (isset($slugById[$g->permission_id])) {
                $byRole[$g->role_id][] = $slugById[$g->permission_id];
            }
        }
        $userCounts = DB::table('user_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')->whereNull('u.deleted_at')
            ->select('ur.role_id', DB::raw('COUNT(*) as c'))->groupBy('ur.role_id')->pluck('c', 'role_id');

        $overrides = DB::table('user_permission_overrides as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')->join('permissions as p', 'p.id', '=', 'o.permission_id')
            ->leftJoin('users as g', 'g.id', '=', 'o.granted_by')->whereNull('u.deleted_at')
            ->orderByDesc('o.created_at')->limit(300)
            ->get(['o.user_id', 'u.full_name', 'u.email', 'p.slug as permission', 'o.effect', 'g.full_name as granted_by_name', 'o.created_at']);

        return [
            'roles' => $roles->map(fn ($r) => [
                'id' => (int) $r->id, 'slug' => $r->slug, 'name_en' => $r->name_en, 'name_ar' => $r->name_ar,
                'portal_prefix' => $r->portal_prefix, 'is_system' => (bool) $r->is_system,
                'users_count' => (int) ($userCounts[$r->id] ?? 0), 'permissions' => $byRole[$r->id] ?? [],
            ])->all(),
            'permissions' => $perms->map(fn ($p) => ['slug' => $p->slug, 'module' => $p->module, 'description' => $p->description])->all(),
            'overrides' => $overrides->map(fn ($o) => (array) $o)->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $cfg = $this->settings->get('access_review');
        $interval = (int) $cfg['interval_days'];
        $open = DB::table('access_reviews')->where('status', 'open')->orderByDesc('id')->first(['id', 'title', 'due_at']);
        $last = DB::table('access_reviews')->where('status', 'completed')->orderByDesc('completed_at')->first(['id', 'completed_at']);
        $next = $last ? date('Y-m-d', strtotime($last->completed_at . " +{$interval} days")) : null;
        $pendingRemediation = (int) DB::table('access_review_items as i')->join('access_reviews as r', 'r.id', '=', 'i.review_id')
            ->where('r.status', 'completed')->where('i.decision', 'revoke')->whereNull('i.remediated_at')->count();

        return [
            'settings'            => $cfg,
            'open_review'         => $open ? (array) $open : null,
            'last_completed_at'   => $last->completed_at ?? null,
            'next_due'            => $next,
            'overdue'             => !$open && ($next === null || $next < date('Y-m-d')),
            'pending_remediation' => $pendingRemediation,
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function updateSettings(array $in, ?int $userId): array
    {
        $old = $this->settings->get('access_review');
        $new = [
            'interval_days' => max(7, min(365, (int) ($in['interval_days'] ?? $old['interval_days']))),
            'dormant_days'  => max(14, min(730, (int) ($in['dormant_days'] ?? $old['dormant_days']))),
        ];
        $this->settings->put('access_review', $new, $userId);
        $this->auditLog->record($userId, 'compliance.access_review.settings', 'ComplianceSettings', null, $old, $new);

        return $new;
    }

    /** @return array<int,array<string,mixed>> */
    public function reviews(int $limit = 30): array
    {
        $rows = DB::table('access_reviews as r')->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->orderByDesc('r.id')->limit($limit)->get(['r.*', 'u.full_name as created_by_name']);
        $counts = DB::table('access_review_items')->whereIn('review_id', $rows->pluck('id'))
            ->select('review_id', DB::raw('COUNT(*) as total'), DB::raw('SUM(decision IS NOT NULL) as decided'),
                DB::raw("SUM(decision = 'revoke') as revoke"), DB::raw("SUM(decision = 'revoke' AND remediated_at IS NULL) as open_revoke"))
            ->groupBy('review_id')->get()->keyBy('review_id');

        return $rows->map(function ($r) use ($counts) {
            $c = $counts[$r->id] ?? null;
            $a = (array) $r;
            $a['summary'] = json_decode((string) ($r->summary ?? 'null'), true);
            $a['total'] = (int) ($c->total ?? 0);
            $a['decided'] = (int) ($c->decided ?? 0);
            $a['revoke'] = (int) ($c->revoke ?? 0);
            $a['open_revoke'] = (int) ($c->open_revoke ?? 0);

            return $a;
        })->all();
    }

    /** @return array<string,mixed>|null */
    public function show(int $id): ?array
    {
        $r = DB::table('access_reviews as r')->leftJoin('users as u', 'u.id', '=', 'r.created_by')->where('r.id', $id)->first(['r.*', 'u.full_name as created_by_name']);
        if (!$r) {
            return null;
        }
        $items = DB::table('access_review_items as i')->leftJoin('users as d', 'd.id', '=', 'i.decided_by')
            ->where('i.review_id', $id)->orderBy('i.id')->get(['i.*', 'd.full_name as decided_by_name']);
        $a = (array) $r;
        $a['summary'] = json_decode((string) ($r->summary ?? 'null'), true);
        $a['items'] = $items->map(function ($i) {
            $x = (array) $i;
            $x['snapshot'] = json_decode((string) $i->snapshot, true);
            $x['flags'] = json_decode((string) ($i->flags ?? '[]'), true) ?: [];
            $x['self_review'] = $i->decided_by !== null && (int) $i->decided_by === (int) $i->user_id;

            return $x;
        })->all();

        return $a;
    }

    /**
     * @throws \RuntimeException
     * @return int id المراجعة الجديدة
     */
    public function start(string $title, string $scope, ?string $dueAt, ?int $userId): int
    {
        if (!in_array($scope, ['privileged', 'staff', 'all'], true)) {
            throw new \RuntimeException('Unknown review scope.');
        }
        if (DB::table('access_reviews')->where('status', 'open')->exists()) {
            throw new \RuntimeException('There is already an open access review. Complete or cancel it first.');
        }
        $items = $this->candidates($scope);
        if (!$items) {
            throw new \RuntimeException('No accounts match this scope.');
        }
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 160) : 'Access review ' . date('Y-m-d');

        return DB::transaction(function () use ($title, $scope, $dueAt, $userId, $items) {
            $id = DB::table('access_reviews')->insertGetId([
                'title' => $title, 'scope' => $scope, 'status' => 'open',
                'due_at' => $dueAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt) ? $dueAt : date('Y-m-d', strtotime('+14 days')),
                'created_by' => $userId, 'created_at' => now(),
            ]);
            foreach (array_chunk($items, 200) as $chunk) {
                DB::table('access_review_items')->insert(array_map(fn ($it) => [
                    'review_id' => $id, 'user_id' => $it['user_id'],
                    'snapshot' => json_encode($it['snapshot'], JSON_UNESCAPED_UNICODE), 'flags' => json_encode($it['flags']),
                ], $chunk));
            }
            $this->auditLog->record($userId, 'compliance.access_review.started', 'AccessReview', $id, null, ['scope' => $scope, 'accounts' => count($items)]);

            return $id;
        });
    }

    /** @throws \RuntimeException */
    public function decide(int $reviewId, int $itemId, string $decision, string $note, ?int $userId): void
    {
        $review = DB::table('access_reviews')->where('id', $reviewId)->first(['status']);
        if (!$review || $review->status !== 'open') {
            throw new \RuntimeException('This review is not open.');
        }
        if (!in_array($decision, ['keep', 'revoke'], true)) {
            throw new \RuntimeException('Decision must be keep or revoke.');
        }
        $note = trim($note);
        if ($decision === 'revoke' && $note === '') {
            throw new \RuntimeException('Explain what access should be removed.');
        }
        $n = DB::table('access_review_items')->where('review_id', $reviewId)->where('id', $itemId)->update([
            'decision' => $decision, 'note' => mb_substr($note, 0, 500), 'decided_by' => $userId, 'decided_at' => now(),
            'remediated_at' => null, 'remediated_by' => null,
        ]);
        if (!$n) {
            throw new \RuntimeException('Review item not found.');
        }
    }

    /** @return array<string,mixed> */
    public function complete(int $reviewId, ?int $userId): array
    {
        $review = DB::table('access_reviews')->where('id', $reviewId)->first();
        if (!$review || $review->status !== 'open') {
            throw new \RuntimeException('This review is not open.');
        }
        $items = DB::table('access_review_items')->where('review_id', $reviewId)->get(['decision', 'decided_by', 'user_id']);
        $pending = $items->whereNull('decision')->count();
        if ($pending > 0) {
            throw new \RuntimeException($pending . ' account(s) still need a decision.');
        }
        $summary = [
            'total'         => $items->count(),
            'kept'          => $items->where('decision', 'keep')->count(),
            'revoke'        => $items->where('decision', 'revoke')->count(),
            'self_reviewed' => $items->filter(fn ($i) => (int) $i->decided_by === (int) $i->user_id)->count(),
        ];
        DB::table('access_reviews')->where('id', $reviewId)->update([
            'status' => 'completed', 'completed_at' => now(), 'completed_by' => $userId, 'summary' => json_encode($summary),
        ]);
        $this->auditLog->record($userId, 'compliance.access_review.completed', 'AccessReview', $reviewId, null, $summary);

        return $summary;
    }

    public function cancel(int $reviewId, ?int $userId): void
    {
        $n = DB::table('access_reviews')->where('id', $reviewId)->where('status', 'open')->update(['status' => 'cancelled']);
        if (!$n) {
            throw new \RuntimeException('This review is not open.');
        }
        $this->auditLog->record($userId, 'compliance.access_review.cancelled', 'AccessReview', $reviewId);
    }

    /** @throws \RuntimeException */
    public function remediate(int $reviewId, int $itemId, ?int $userId, bool $done = true): void
    {
        $n = DB::table('access_review_items')->where('review_id', $reviewId)->where('id', $itemId)->where('decision', 'revoke')
            ->update($done ? ['remediated_at' => now(), 'remediated_by' => $userId] : ['remediated_at' => null, 'remediated_by' => null]);
        if (!$n) {
            throw new \RuntimeException('Only "revoke" decisions can be marked as remediated.');
        }
        $this->auditLog->record($userId, 'compliance.access_review.remediated', 'AccessReview', $reviewId, null, ['item' => $itemId, 'done' => $done]);
    }

    /** @return array<int,array{user_id:int,snapshot:array,flags:string[]}> */
    private function candidates(string $scope): array
    {
        $cfg = $this->settings->get('access_review');
        $dormantBefore = strtotime('-' . (int) $cfg['dormant_days'] . ' days');

        $users = DB::table('users')->whereNull('deleted_at')->get(['id', 'full_name', 'email', 'status', 'last_login_at', 'created_at'])->keyBy('id');
        $roleRows = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->get(['ur.user_id', 'ur.role_id', 'r.slug']);
        $rolesByUser = [];
        foreach ($roleRows as $r) {
            $rolesByUser[$r->user_id][] = ['id' => (int) $r->role_id, 'slug' => $r->slug];
        }
        $permsByRole = [];
        foreach (DB::table('role_permission as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->get(['rp.role_id', 'p.slug']) as $g) {
            $permsByRole[$g->role_id][] = $g->slug;
        }
        $ovByUser = [];
        foreach (DB::table('user_permission_overrides as o')->join('permissions as p', 'p.id', '=', 'o.permission_id')->get(['o.user_id', 'p.slug', 'o.effect']) as $o) {
            $ovByUser[$o->user_id][] = ['slug' => $o->slug, 'effect' => $o->effect];
        }

        $out = [];
        foreach ($rolesByUser as $uid => $roles) {
            $u = $users[$uid] ?? null;
            if (!$u) {
                continue;
            }
            $slugs = array_column($roles, 'slug');
            $ov = $ovByUser[$uid] ?? [];
            $hasGrant = (bool) array_filter($ov, fn ($o) => $o['effect'] === 'grant');
            $include = match ($scope) {
                'privileged' => (bool) array_intersect($slugs, self::PRIVILEGED_ROLES) || $hasGrant,
                'staff'      => (bool) array_diff($slugs, ['student']),
                default      => true,
            };
            if (!$include) {
                continue;
            }
            $eff = [];
            foreach ($roles as $r) {
                foreach ($permsByRole[$r['id']] ?? [] as $s) {
                    $eff[$s] = true;
                }
            }
            foreach ($ov as $o) {
                if ($o['effect'] === 'grant') {
                    $eff[$o['slug']] = true;
                } else {
                    unset($eff[$o['slug']]);
                }
            }
            $isAdmin = in_array('admin', $slugs, true);
            $flags = [];
            $last = $u->last_login_at ? strtotime((string) $u->last_login_at) : null;
            if ($last === null || $last < $dormantBefore) {
                $flags[] = 'dormant';
            }
            if ($u->status !== 'active') {
                $flags[] = 'inactive_account';
            }
            if ($hasGrant) {
                $flags[] = 'extra_grants';
            }
            if (!$isAdmin && count($eff) >= self::BROAD_PERMISSIONS) {
                $flags[] = 'broad_access';
            }
            if (count($slugs) > 1) {
                $flags[] = 'multiple_roles';
            }
            $out[] = [
                'user_id' => (int) $uid,
                'snapshot' => [
                    'full_name' => $u->full_name, 'email' => $u->email, 'status' => $u->status, 'last_login_at' => $u->last_login_at,
                    'roles' => $slugs, 'permissions_count' => $isAdmin ? 'all' : count($eff),
                    'permissions' => $isAdmin ? [] : array_keys($eff), 'overrides' => $ov,
                ],
                'flags' => $flags,
            ];
        }
        // الأكثر إثارة للانتباه (أعلام أكتر) الأول.
        usort($out, fn ($a, $b) => count($b['flags']) <=> count($a['flags']));

        return $out;
    }
}
