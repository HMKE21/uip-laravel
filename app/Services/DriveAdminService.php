<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DriveNode;
use App\Models\DriveNodeVersion;
use App\Models\DriveShare;
use App\Models\DriveUserLimit;
use App\Support\DriveException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Admin → My PC Control. منطق الأدمن على مستوى المنصة لـ"جهازي": أرقام عامة، المستخدمين والحصص، تصفح ملفات أي مستخدم
 * وتنزيلها، روابط المشاركة، سجل النشاط وصيانة التخزين.
 *
 * أغلب الإجراءات بتعدّي على DriveService/DriveShareService بـ user_id صاحب الملف — يعني نفس القواعد (سلة، نسخ قديمة، فهرس
 * البحث، روابط) بتتطبّق زي ما لو المستخدم نفسه عملها. الخصوصية: الأدمن بيقدر يقرا/ينزّل (لو admin_can_download شغّال) وكل
 * فتح/تنزيل بيتسجّل في audit_logs بـ admin.drive.* (راجع AdminDriveControlApiController).
 */
class DriveAdminService
{
    public function __construct(private DriveService $drive, private DriveShareService $shares)
    {
    }

    private function disk()
    {
        return Storage::disk((string) config('drive.disk'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** تصنيف الملف من الـ mime (SQL portable). */
    private static function categorySql(): string
    {
        return "CASE"
            . " WHEN mime_type LIKE 'image/%' THEN 'images'"
            . " WHEN mime_type LIKE 'video/%' THEN 'video'"
            . " WHEN mime_type LIKE 'audio/%' THEN 'audio'"
            . " WHEN mime_type LIKE 'text/%' OR mime_type = 'application/pdf' OR mime_type LIKE '%officedocument%' OR mime_type = 'application/msword' OR mime_type LIKE 'application/vnd.ms-%' THEN 'documents'"
            . " WHEN mime_type LIKE '%zip%' OR mime_type LIKE '%rar%' OR mime_type LIKE '%tar' OR mime_type LIKE '%7z%' OR mime_type LIKE '%gzip%' THEN 'archives'"
            . " ELSE 'other' END";
    }

    public const CATEGORIES = ['images', 'video', 'audio', 'documents', 'archives', 'other'];

    private static function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%';
    }

    private static function meta($p): array
    {
        return ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()];
    }

    private function userBrief(int $uid): ?array
    {
        $u = DB::table('users')->where('id', $uid)->first(['id', 'full_name', 'email', 'status', 'last_login_at', 'created_at', 'deleted_at']);

        return $u ? [
            'id' => (int) $u->id, 'name' => $u->full_name, 'email' => $u->email, 'account_status' => $u->status,
            'last_login_at' => $u->last_login_at, 'created_at' => $u->created_at, 'deleted' => $u->deleted_at !== null,
            'roles' => $this->rolesOf([(int) $u->id])[(int) $u->id] ?? [],
        ] : null;
    }

    /** @param int[] $uids @return array<int,string[]> أول دور = الأساسي */
    private function rolesOf(array $uids): array
    {
        if (!$uids) {
            return [];
        }
        $out = [];
        foreach (DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereIn('user_roles.user_id', $uids)
            ->orderBy('user_roles.assigned_at')->get(['user_roles.user_id', 'roles.slug']) as $r) {
            $out[(int) $r->user_id][] = (string) $r->slug;
        }

        return $out;
    }

    public function userExists(int $uid): bool
    {
        return DB::table('users')->where('id', $uid)->exists();
    }

    /** صف مستخدم بأرقام التخزين والحصة المحسوبة. @param object $r صف فيه used_bytes/trash_bytes... */
    private function usageRow(int $uid, $r): array
    {
        $lim = DriveAdminSettings::limitsFor($uid);
        $used = (int) ($r->used_bytes ?? 0);
        $unlimited = $lim['quota_mb'] <= 0;
        $quota = $unlimited ? 0 : $lim['quota_mb'] * 1048576;

        return [
            'files'        => (int) ($r->files ?? 0),
            'folders'      => (int) ($r->folders ?? 0),
            'used_bytes'   => $used,
            'used_human'   => DriveNode::humanSize($used),
            'trash_bytes'  => (int) ($r->trash_bytes ?? 0),
            'trash_human'  => DriveNode::humanSize((int) ($r->trash_bytes ?? 0)),
            'quota_mb'     => $lim['quota_mb'],
            'quota_bytes'  => $quota,
            'quota_human'  => $unlimited ? 'Unlimited' : DriveNode::humanSize($quota),
            'unlimited'    => $unlimited,
            'quota_source' => $lim['quota_source'],
            'percent'      => $quota > 0 ? round($used * 100 / $quota, 1) : 0,
            'status'       => $lim['status'],
            'has_override' => $lim['has_override'],
            'sharing_blocked' => $lim['sharing_blocked'],
            'uploads_blocked' => $lim['uploads_blocked'],
            'last_activity' => $r->last_activity ?? null,
        ];
    }

    // ------------------------------------------------------------------
    // نظرة عامة
    // ------------------------------------------------------------------

    public function overview(): array
    {
        $now = now();
        $agg = DB::table('drive_nodes')->selectRaw(
            "SUM(CASE WHEN type='file' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS files,"
            . " SUM(CASE WHEN type='folder' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS folders,"
            . " SUM(CASE WHEN type='file' AND deleted_at IS NULL THEN size_bytes ELSE 0 END) AS live_bytes,"
            . " SUM(CASE WHEN type='file' AND deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS trash_files,"
            . " SUM(CASE WHEN type='file' AND deleted_at IS NOT NULL THEN size_bytes ELSE 0 END) AS trash_bytes,"
            . " COUNT(DISTINCT user_id) AS users"
        )->first();

        $versions = DriveNodeVersion::query()->selectRaw('COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS b')->first();

        $cat = [];
        foreach (self::CATEGORIES as $c) {
            $cat[$c] = ['category' => $c, 'files' => 0, 'bytes' => 0];
        }
        foreach (DB::table('drive_nodes')->where('type', 'file')->selectRaw(self::categorySql() . ' AS cat, COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS b')->groupBy('cat')->get() as $r) {
            $cat[$r->cat] = ['category' => $r->cat, 'files' => (int) $r->c, 'bytes' => (int) $r->b];
        }

        $since = $now->copy()->subDays(29)->startOfDay();
        $perDay = [];
        foreach (DB::table('drive_nodes')->where('type', 'file')->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS b')->groupBy('d')->get() as $r) {
            $perDay[(string) $r->d] = ['files' => (int) $r->c, 'bytes' => (int) $r->b];
        }
        $days = [];
        for ($i = 0; $i < 30; $i++) {
            $d = $since->copy()->addDays($i)->toDateString();
            $days[] = ['date' => $d] + ($perDay[$d] ?? ['files' => 0, 'bytes' => 0]);
        }

        // أكبر 500 مستخدم استهلاكًا -> أعلى 10 + اللي قرّب يخلّص حصته.
        $top = DB::table('drive_nodes')->where('type', 'file')->selectRaw('user_id, COUNT(*) AS files, SUM(size_bytes) AS used_bytes, SUM(CASE WHEN deleted_at IS NOT NULL THEN size_bytes ELSE 0 END) AS trash_bytes')
            ->groupBy('user_id')->orderByDesc('used_bytes')->limit(500)->get();
        $uids = $top->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        DriveAdminSettings::prime($uids);
        $names = $uids ? DB::table('users')->whereIn('id', $uids)->get(['id', 'full_name', 'email'])->keyBy('id') : collect();
        $rows = [];
        foreach ($top as $r) {
            $u = $names[$r->user_id] ?? null;
            $rows[] = ['id' => (int) $r->user_id, 'name' => $u->full_name ?? null, 'email' => $u->email ?? null] + $this->usageRow((int) $r->user_id, $r);
        }
        $nearQuota = array_values(array_filter($rows, fn ($r) => !$r['unlimited'] && $r['percent'] >= 90));
        usort($nearQuota, fn ($a, $b) => $b['percent'] <=> $a['percent']);

        $shareAgg = DB::table('drive_shares')->selectRaw(
            "COUNT(*) AS total,"
            . " SUM(CASE WHEN revoked_at IS NULL AND paused_at IS NULL AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END) AS active,"
            . " SUM(CASE WHEN revoked_at IS NULL AND paused_at IS NOT NULL THEN 1 ELSE 0 END) AS paused,"
            . " SUM(CASE WHEN revoked_at IS NULL AND paused_at IS NULL AND expires_at IS NOT NULL AND expires_at <= ? THEN 1 ELSE 0 END) AS expired,"
            . " SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked,"
            . " COALESCE(SUM(views),0) AS views, COALESCE(SUM(downloads),0) AS downloads",
            [$now, $now]
        )->first();

        $byStatus = DriveUserLimit::query()->select('status', DB::raw('COUNT(*) AS c'))->groupBy('status')->pluck('c', 'status')->all();
        $usedAll = (int) ($agg->live_bytes ?? 0) + (int) ($agg->trash_bytes ?? 0);

        return [
            'system_enabled'      => DriveAdminSettings::on('drive'),
            'maintenance_message' => (string) DriveAdminSettings::get('maintenance_message'),
            'features'            => DriveAdminSettings::featureMap(),
            'disk'                => ['name' => (string) config('drive.disk'), 'driver' => (string) config('filesystems.disks.' . config('drive.disk') . '.driver'), 'direct' => $this->drive->directEnabled()],
            'global_quota_mb'     => (int) config('drive.quota_mb'),
            'totals' => [
                'users_with_files' => (int) ($agg->users ?? 0),
                'files'            => (int) ($agg->files ?? 0),
                'folders'          => (int) ($agg->folders ?? 0),
                'live_bytes'       => (int) ($agg->live_bytes ?? 0),
                'live_human'       => DriveNode::humanSize((int) ($agg->live_bytes ?? 0)),
                'trash_files'      => (int) ($agg->trash_files ?? 0),
                'trash_bytes'      => (int) ($agg->trash_bytes ?? 0),
                'trash_human'      => DriveNode::humanSize((int) ($agg->trash_bytes ?? 0)),
                'versions'         => (int) $versions->c,
                'versions_bytes'   => (int) $versions->b,
                'versions_human'   => DriveNode::humanSize((int) $versions->b),
                'used_bytes'       => $usedAll,
                'used_human'       => DriveNode::humanSize($usedAll),
            ],
            'shares'       => [
                'total' => (int) $shareAgg->total, 'active' => (int) $shareAgg->active, 'paused' => (int) $shareAgg->paused,
                'expired' => (int) $shareAgg->expired, 'revoked' => (int) $shareAgg->revoked,
                'views' => (int) $shareAgg->views, 'downloads' => (int) $shareAgg->downloads,
            ],
            'users_by_status' => ['active_overrides' => (int) ($byStatus['active'] ?? 0), 'readonly' => (int) ($byStatus['readonly'] ?? 0), 'blocked' => (int) ($byStatus['blocked'] ?? 0)],
            'by_category'  => array_values($cat),
            'uploads_30d'  => $days,
            'top_users'    => array_slice($rows, 0, 10),
            'near_quota'   => array_slice($nearQuota, 0, 10),
        ];
    }

    // ------------------------------------------------------------------
    // المستخدمين والحصص
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f q, scope(with_files|all), role, status(active|readonly|blocked), sort(used|files|name|activity|trash), dir, page, per_page */
    public function users(array $f): array
    {
        $sub = DB::table('drive_nodes')->selectRaw(
            "user_id,"
            . " SUM(CASE WHEN type='file' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS files,"
            . " SUM(CASE WHEN type='folder' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS folders,"
            . " SUM(CASE WHEN type='file' THEN size_bytes ELSE 0 END) AS used_bytes,"
            . " SUM(CASE WHEN type='file' AND deleted_at IS NOT NULL THEN size_bytes ELSE 0 END) AS trash_bytes,"
            . " MAX(updated_at) AS last_activity"
        )->groupBy('user_id');

        $q = DB::table('users as u')
            ->leftJoinSub($sub, 'd', 'd.user_id', '=', 'u.id')
            ->leftJoin('drive_user_limits as l', 'l.user_id', '=', 'u.id')
            ->whereNull('u.deleted_at');

        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $like = self::like($term);
            $q->where(function ($w) use ($like, $term) {
                $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like);
                if (ctype_digit($term)) {
                    $w->orWhere('u.id', (int) $term);
                }
            });
        } elseif (($f['scope'] ?? 'with_files') !== 'all') {
            $q->where(fn ($w) => $w->whereNotNull('d.user_id')->orWhereNotNull('l.id'));
        }
        if (!empty($f['role'])) {
            $role = (string) $f['role'];
            $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->whereColumn('ur.user_id', 'u.id')->where('r.slug', $role));
        }
        if (!empty($f['status']) && in_array($f['status'], DriveAdminSettings::STATUSES, true)) {
            if ($f['status'] === 'active') {
                $q->where(fn ($w) => $w->whereNull('l.status')->orWhere('l.status', 'active'));
            } else {
                $q->where('l.status', $f['status']);
            }
        }

        $dir = strtolower((string) ($f['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        switch ($f['sort'] ?? 'used') {
            case 'files':
                $q->orderByRaw('COALESCE(d.files, 0) ' . $dir);
                break;
            case 'name':
                $q->orderBy('u.full_name', $dir);
                break;
            case 'activity':
                $q->orderByRaw('d.last_activity IS NULL, d.last_activity ' . $dir);
                break;
            case 'trash':
                $q->orderByRaw('COALESCE(d.trash_bytes, 0) ' . $dir);
                break;
            default:
                $q->orderByRaw('COALESCE(d.used_bytes, 0) ' . $dir);
        }
        $q->orderBy('u.id');

        $page = $q->select('u.id', 'u.full_name', 'u.email', 'u.status as account_status', 'd.files', 'd.folders', 'd.used_bytes', 'd.trash_bytes', 'd.last_activity')
            ->paginate(max(10, min(100, (int) ($f['per_page'] ?? 25))), ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        $uids = $page->getCollection()->pluck('id')->map(fn ($i) => (int) $i)->all();
        DriveAdminSettings::prime($uids);
        $roles = $this->rolesOf($uids);
        $shares = $uids ? DB::table('drive_shares')->whereIn('user_id', $uids)->whereNull('revoked_at')->select('user_id', DB::raw('COUNT(*) AS c'))->groupBy('user_id')->pluck('c', 'user_id')->all() : [];

        $items = $page->getCollection()->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => $r->full_name, 'email' => $r->email, 'account_status' => $r->account_status,
            'role' => ($roles[(int) $r->id][0] ?? null), 'roles' => $roles[(int) $r->id] ?? [],
            'shares' => (int) ($shares[$r->id] ?? 0),
        ] + $this->usageRow((int) $r->id, $r))->all();

        return ['items' => $items, 'meta' => self::meta($page)];
    }

    public function userDetail(int $uid): ?array
    {
        $user = $this->userBrief($uid);
        if (!$user) {
            return null;
        }
        $r = DB::table('drive_nodes')->where('user_id', $uid)->selectRaw(
            "SUM(CASE WHEN type='file' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS files,"
            . " SUM(CASE WHEN type='folder' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS folders,"
            . " COALESCE(SUM(CASE WHEN type='file' THEN size_bytes ELSE 0 END),0) AS used_bytes,"
            . " COALESCE(SUM(CASE WHEN type='file' AND deleted_at IS NOT NULL THEN size_bytes ELSE 0 END),0) AS trash_bytes,"
            . " MAX(updated_at) AS last_activity"
        )->first();

        $cat = [];
        foreach (self::CATEGORIES as $c) {
            $cat[$c] = ['category' => $c, 'files' => 0, 'bytes' => 0];
        }
        foreach (DB::table('drive_nodes')->where('user_id', $uid)->where('type', 'file')->whereNull('deleted_at')
            ->selectRaw(self::categorySql() . ' AS cat, COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS b')->groupBy('cat')->get() as $x) {
            $cat[$x->cat] = ['category' => $x->cat, 'files' => (int) $x->c, 'bytes' => (int) $x->b];
        }

        $fileRow = fn ($n) => $n->toRowArray() + ['owner_id' => $uid];
        $largest = DriveNode::query()->where('user_id', $uid)->where('type', 'file')->whereNull('deleted_at')->orderByDesc('size_bytes')->limit(10)->get()->map($fileRow)->all();
        $recent = DriveNode::query()->where('user_id', $uid)->where('type', 'file')->whereNull('deleted_at')->orderByDesc('updated_at')->limit(10)->get()->map($fileRow)->all();

        $vers = DriveNodeVersion::query()->where('user_id', $uid)->selectRaw('COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS b')->first();
        $sh = DriveShare::query()->where('user_id', $uid)->selectRaw('COUNT(*) AS total, SUM(CASE WHEN revoked_at IS NULL THEN 1 ELSE 0 END) AS open_links, COALESCE(SUM(views),0) AS views, COALESCE(SUM(downloads),0) AS downloads')->first();

        $lim = DriveAdminSettings::limitsFor($uid);
        $history = AuditLog::query()->where('action', 'like', 'admin.drive.%')->where('subject_type', 'DriveUser')->where('subject_id', $uid)->orderByDesc('id')->limit(15)->get()
            ->map(fn (AuditLog $a) => ['id' => (int) $a->id, 'action' => $a->action, 'admin_id' => $a->user_id, 'old' => $a->old_values, 'new' => $a->new_values, 'created_at' => $a->created_at])->all();

        return [
            'user'    => $user,
            'usage'   => $this->usageRow($uid, $r),
            'limits'  => [
                'status' => $lim['status'], 'reason' => $lim['reason'], 'note' => $lim['note'],
                'quota_mb_override' => $lim['override']['quota_mb'], 'max_file_mb_override' => $lim['override']['max_file_mb'],
                'effective_quota_mb' => $lim['quota_mb'], 'effective_max_file_mb' => $lim['max_file_mb'], 'quota_source' => $lim['quota_source'],
                'sharing_blocked' => $lim['sharing_blocked'], 'uploads_blocked' => $lim['uploads_blocked'], 'has_override' => $lim['has_override'],
            ],
            'by_category' => array_values($cat),
            'versions'    => ['count' => (int) $vers->c, 'bytes' => (int) $vers->b, 'human' => DriveNode::humanSize((int) $vers->b)],
            'shares'      => ['total' => (int) $sh->total, 'open' => (int) $sh->open_links, 'views' => (int) $sh->views, 'downloads' => (int) $sh->downloads],
            'largest'     => $largest,
            'recent'      => $recent,
            'history'     => $history,
        ];
    }

    /**
     * @param array<string,mixed> $in quota_mb|max_file_mb (null = وراثة) · status · reason · note · sharing_blocked · uploads_blocked
     * @return array{errors:array<string,string>, old:array, new:array}
     */
    public function setLimits(int $uid, array $in, int $adminId): array
    {
        $errors = [];
        $set = [];
        foreach (['quota_mb' => [0, 10000000], 'max_file_mb' => [1, 102400]] as $k => [$min, $max]) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            if ($in[$k] === null || $in[$k] === '') {
                $set[$k] = null;
            } elseif (!is_numeric($in[$k]) || (string) (int) $in[$k] !== (string) (int) $in[$k] || (int) $in[$k] < $min || (int) $in[$k] > $max) {
                $errors[$k] = "Must be a whole number between {$min} and {$max}.";
            } else {
                $set[$k] = (int) $in[$k];
            }
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], DriveAdminSettings::STATUSES, true)) {
                $errors['status'] = 'Status must be active, readonly or blocked.';
            } else {
                $set['status'] = (string) $in['status'];
            }
        }
        foreach (['reason' => 255, 'note' => 2000] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k] === null ? null : trim((string) $in[$k]);
                if ($v !== null && mb_strlen($v) > $max) {
                    $errors[$k] = "Maximum length is {$max}.";
                } else {
                    $set[$k] = ($v === '' ? null : $v);
                }
            }
        }
        foreach (['sharing_blocked', 'uploads_blocked'] as $k) {
            if (array_key_exists($k, $in)) {
                $b = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    $errors[$k] = 'Must be true or false.';
                } else {
                    $set[$k] = $b;
                }
            }
        }
        $row = DriveUserLimit::query()->where('user_id', $uid)->first();
        $mb = array_key_exists('max_file_mb', $set) ? $set['max_file_mb'] : $row?->max_file_mb;
        $qt = array_key_exists('quota_mb', $set) ? $set['quota_mb'] : $row?->quota_mb;
        if ($mb !== null && $qt !== null && $qt > 0 && $mb > $qt) {
            $errors['max_file_mb'] = 'Maximum file size cannot exceed the user quota.';
        }
        if ($errors) {
            return ['errors' => $errors, 'old' => [], 'new' => []];
        }

        $before = $this->limitsLite($uid);
        $row = $row ?: new DriveUserLimit(['user_id' => $uid, 'status' => 'active']);
        $row->fill($set);
        // "active" مفيش فيه شيء مخصّص -> مسح الصف كله لو كل الباقي وراثة.
        if ($row->quota_mb === null && $row->max_file_mb === null && $row->status === 'active' && !$row->sharing_blocked && !$row->uploads_blocked
            && trim((string) $row->note) === '') {
            if ($row->exists) {
                $row->delete();
            }
        } else {
            if ($row->status === 'active') {
                $row->reason = null; // السبب بيخص القفل/القراءة فقط
            }
            $row->updated_by = $adminId;
            $row->save();
        }
        DriveAdminSettings::flush();

        return ['errors' => [], 'old' => $before, 'new' => $this->limitsLite($uid)];
    }

    public function resetLimits(int $uid): array
    {
        $before = $this->limitsLite($uid);
        DriveUserLimit::query()->where('user_id', $uid)->delete();
        DriveAdminSettings::flush();

        return ['old' => $before, 'new' => $this->limitsLite($uid)];
    }

    private function limitsLite(int $uid): array
    {
        DriveAdminSettings::flush();
        $l = DriveAdminSettings::limitsFor($uid);

        return [
            'quota_mb' => $l['override']['quota_mb'], 'max_file_mb' => $l['override']['max_file_mb'], 'status' => $l['status'], 'reason' => $l['reason'],
            'sharing_blocked' => $l['sharing_blocked'], 'uploads_blocked' => $l['uploads_blocked'],
        ];
    }

    public function searchUsers(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = self::like($q);

        return DB::table('users')->whereNull('deleted_at')->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('full_name')->limit(15)->get(['id', 'full_name', 'email'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->full_name, 'email' => $u->email])->all();
    }

    // ------------------------------------------------------------------
    // الملفات (تصفح / تفاصيل / بحث / تنزيل)
    // ------------------------------------------------------------------

    /** محتوى مجلد (أو الجذر) عند مستخدم، أو سلة المحذوفات (trashed=true). */
    public function listFolder(int $uid, ?int $parentId, bool $trashed = false): array
    {
        if ($trashed) {
            return ['trashed' => true, 'items' => $this->drive->trashList($uid), 'breadcrumb' => [], 'folder' => null, 'usage' => $this->drive->usage($uid)];
        }
        $parent = null;
        if ($parentId !== null) {
            $parent = DriveNode::query()->where('user_id', $uid)->whereNull('deleted_at')->where('id', $parentId)->first();
            if (!$parent || !$parent->isFolder()) {
                throw new DriveException('Folder not found.', 404);
            }
        }

        return ['trashed' => false] + $this->drive->listFolder($uid, $parentId) + ['folder' => $parent?->toRowArray()];
    }

    private function nodeAny(int $uid, int $id): DriveNode
    {
        $n = DriveNode::query()->where('user_id', $uid)->where('id', $id)->first();
        if (!$n) {
            throw new DriveException('Item not found.', 404);
        }

        return $n;
    }

    public function nodeDetail(int $uid, int $nodeId): array
    {
        $n = $this->nodeAny($uid, $nodeId);
        $path = [];
        $cur = $n->parent_id !== null ? (int) $n->parent_id : null;
        $g = 0;
        while ($cur !== null && $g++ < 64) {
            $p = DriveNode::query()->where('user_id', $uid)->where('id', $cur)->first(['id', 'name', 'parent_id']);
            if (!$p) {
                break;
            }
            array_unshift($path, ['id' => (int) $p->id, 'name' => $p->name]);
            $cur = $p->parent_id !== null ? (int) $p->parent_id : null;
        }
        $exists = null;
        if (!$n->isFolder() && $n->storage_path) {
            try {
                $exists = (bool) $this->disk()->exists((string) $n->storage_path);
            } catch (\Throwable $e) {
                $exists = null;
            }
        }
        $versions = $n->isFolder() ? [] : DriveNodeVersion::query()->where('user_id', $uid)->where('node_id', $n->id)->orderByDesc('id')->get()->map(fn (DriveNodeVersion $v) => $v->toRowArray())->all();
        $shares = DriveShare::query()->where('user_id', $uid)->where('node_id', $n->id)->orderByDesc('id')->get()->map(fn (DriveShare $s) => $s->toRowArray())->all();

        return [
            'node'      => $n->toRowArray() + ['sha1' => $n->sha1, 'trashed' => $n->deleted_at !== null],
            'owner'     => $this->userBrief($uid),
            'path'      => $path,
            'in_storage' => $exists,
            'versions'  => $versions,
            'shares'    => $shares,
        ];
    }

    /** @return array{0:DriveNode,1:resource} ملف (حتى لو في السلة) للقراءة/التنزيل. */
    public function openFile(int $uid, int $nodeId): array
    {
        $n = $this->nodeAny($uid, $nodeId);
        if ($n->isFolder() || !$n->storage_path) {
            throw new DriveException('Item not found.', 404);
        }
        $s = $this->disk()->readStream((string) $n->storage_path);
        if (!$s) {
            throw new DriveException('The file is missing from storage.', 404);
        }

        return [$n, $s];
    }

    public function openVersion(int $uid, int $nodeId, int $versionId): array
    {
        $this->nodeAny($uid, $nodeId);

        return $this->drive->openVersionStream($uid, $nodeId, $versionId);
    }

    /** ZIP لملفات المستخدم (ids) أو كل ملفاته الحية. @return array{0:string,1:string} [tmp path, name] */
    public function archive(int $uid, array $ids, bool $all): array
    {
        if ($all || !$ids) {
            $ids = DriveNode::query()->where('user_id', $uid)->whereNull('deleted_at')->whereNull('parent_id')->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (!$ids) {
                throw new DriveException('There are no files to download.', 404);
            }
            [$tmp] = $this->drive->buildZip($uid, $ids);

            return [$tmp, 'UIP-PC-user-' . $uid . '.zip'];
        }

        return $this->drive->buildZip($uid, $ids);
    }

    /** بحث/ترتيب على كل ملفات المنصة. @param array<string,mixed> $f q,user_id,category,min_mb,state(live|trash|any),sort(size|new|old|name),page,per_page */
    public function searchFiles(array $f): array
    {
        $q = DB::table('drive_nodes as n')->join('users as u', 'u.id', '=', 'n.user_id')->where('n.type', 'file');
        $state = (string) ($f['state'] ?? 'live');
        if ($state === 'live') {
            $q->whereNull('n.deleted_at');
        } elseif ($state === 'trash') {
            $q->whereNotNull('n.deleted_at');
        }
        if (!empty($f['q'])) {
            $q->where('n.name', 'like', self::like((string) $f['q']));
        }
        if (!empty($f['user_id'])) {
            $q->where('n.user_id', (int) $f['user_id']);
        }
        if (!empty($f['category']) && in_array($f['category'], self::CATEGORIES, true)) {
            $q->whereRaw('(' . str_replace('mime_type', 'n.mime_type', self::categorySql()) . ') = ?', [$f['category']]);
        }
        if (!empty($f['min_mb'])) {
            $q->where('n.size_bytes', '>=', (int) $f['min_mb'] * 1048576);
        }
        switch ($f['sort'] ?? 'size') {
            case 'new':
                $q->orderByDesc('n.id');
                break;
            case 'old':
                $q->orderBy('n.id');
                break;
            case 'name':
                $q->orderBy('n.name');
                break;
            default:
                $q->orderByDesc('n.size_bytes')->orderByDesc('n.id');
        }
        $page = $q->select('n.id', 'n.user_id', 'n.parent_id', 'n.name', 'n.mime_type', 'n.size_bytes', 'n.created_at', 'n.updated_at', 'n.deleted_at', 'u.full_name', 'u.email')
            ->paginate(max(10, min(100, (int) ($f['per_page'] ?? 30))), ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        $items = $page->getCollection()->map(fn ($r) => [
            'id' => (int) $r->id, 'owner_id' => (int) $r->user_id, 'owner_name' => $r->full_name, 'owner_email' => $r->email,
            'parent_id' => $r->parent_id !== null ? (int) $r->parent_id : null, 'name' => $r->name,
            'extension' => strtolower((string) pathinfo((string) $r->name, PATHINFO_EXTENSION)), 'mime_type' => $r->mime_type,
            'size_bytes' => (int) $r->size_bytes, 'size_human' => DriveNode::humanSize((int) $r->size_bytes),
            'created_at' => $r->created_at, 'updated_at' => $r->updated_at, 'trashed' => $r->deleted_at !== null,
        ])->all();

        return ['items' => $items, 'meta' => self::meta($page)];
    }

    // ------------------------------------------------------------------
    // إجراءات على ملفات مستخدم
    // ------------------------------------------------------------------

    public function trash(int $uid, array $ids): int
    {
        return $this->drive->trash($uid, $ids);
    }

    public function restore(int $uid, array $ids): array
    {
        return $this->drive->restore($uid, $ids);
    }

    public function purge(int $uid, array $ids): int
    {
        return $this->drive->purge($uid, $ids);
    }

    public function emptyTrash(int $uid): int
    {
        return $this->drive->emptyTrash($uid);
    }

    /** مسح كل "جهازي" للمستخدم نهائيًا (الملفات + النسخ القديمة + الفهرس + الروابط). @return int عدد الجذور */
    public function wipe(int $uid): int
    {
        $roots = $this->drive->softResetAll($uid);
        $this->drive->finalizeReset($uid, $roots);
        $this->drive->emptyTrash($uid);

        return count($roots);
    }

    // ------------------------------------------------------------------
    // روابط المشاركة
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f status(active|paused|expired|revoked),q,user_id,sort(new|views|downloads),page,per_page */
    public function shares(array $f): array
    {
        $now = now();
        $q = DB::table('drive_shares as s')->join('users as u', 'u.id', '=', 's.user_id')->leftJoin('drive_nodes as n', 'n.id', '=', 's.node_id');
        switch ($f['status'] ?? '') {
            case 'revoked':
                $q->whereNotNull('s.revoked_at');
                break;
            case 'paused':
                $q->whereNull('s.revoked_at')->whereNotNull('s.paused_at');
                break;
            case 'expired':
                $q->whereNull('s.revoked_at')->whereNull('s.paused_at')->whereNotNull('s.expires_at')->where('s.expires_at', '<=', $now);
                break;
            case 'active':
                $q->whereNull('s.revoked_at')->whereNull('s.paused_at')->where(fn ($w) => $w->whereNull('s.expires_at')->orWhere('s.expires_at', '>', $now));
                break;
        }
        if (!empty($f['user_id'])) {
            $q->where('s.user_id', (int) $f['user_id']);
        }
        if (!empty($f['q'])) {
            $like = self::like((string) $f['q']);
            $q->where(fn ($w) => $w->where('n.name', 'like', $like)->orWhere('s.title', 'like', $like)->orWhere('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like));
        }
        switch ($f['sort'] ?? 'new') {
            case 'views':
                $q->orderByDesc('s.views');
                break;
            case 'downloads':
                $q->orderByDesc('s.downloads');
                break;
            default:
                $q->orderByDesc('s.id');
        }
        $page = $q->select('s.id', 'u.full_name', 'u.email', 'n.name as node_name', 'n.type as node_type', 'n.deleted_at as node_deleted_at')
            ->paginate(max(10, min(100, (int) ($f['per_page'] ?? 30))), ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        $models = DriveShare::query()->whereIn('id', $page->getCollection()->pluck('id')->all())->get()->keyBy('id');
        $items = [];
        foreach ($page->getCollection() as $r) {
            $m = $models[$r->id] ?? null;
            if (!$m) {
                continue;
            }
            $items[] = $m->toRowArray() + [
                'owner_id' => (int) $m->user_id, 'owner_name' => $r->full_name, 'owner_email' => $r->email,
                'node_name' => $r->node_name, 'node_type' => $r->node_type, 'node_deleted' => $r->node_name === null || $r->node_deleted_at !== null,
            ];
        }

        return ['items' => $items, 'meta' => self::meta($page)];
    }

    private function shareRow(int $id): DriveShare
    {
        $s = DriveShare::query()->where('id', $id)->first();
        if (!$s) {
            throw new DriveException('Share link not found.', 404);
        }

        return $s;
    }

    public function shareActivity(int $id): array
    {
        $s = $this->shareRow($id);

        return $this->shares->activity((int) $s->user_id, $id) + ['owner' => $this->userBrief((int) $s->user_id)];
    }

    /** @param 'pause'|'resume'|'revoke'|'delete'|'reset_claim'|'clear_activity' $action */
    public function shareAction(int $id, string $action): array
    {
        $s = $this->shareRow($id);
        $uid = (int) $s->user_id;
        switch ($action) {
            case 'pause':
                return $this->shares->pause($uid, $id, true)->toRowArray();
            case 'resume':
                return $this->shares->pause($uid, $id, false)->toRowArray();
            case 'revoke':
                $this->shares->revoke($uid, $id);
                break;
            case 'reset_claim':
                return $this->shares->resetClaim($uid, $id)->toRowArray();
            case 'clear_activity':
                $this->shares->clearActivity($uid, $id);
                break;
            case 'delete':
                $this->shares->forever($uid, $id);

                return ['id' => $id, 'deleted' => true];
        }

        return $this->shareRow($id)->toRowArray();
    }

    /** @return array{done:int,skipped:int} */
    public function shareBulk(string $action, array $ids): array
    {
        $done = 0;
        $skipped = 0;
        foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0))), 0, 500) as $id) {
            try {
                $this->shareAction($id, $action);
                $done++;
            } catch (DriveException $e) {
                $skipped++;
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /** طوارئ: يلغي كل الروابط الشغالة (أو روابط مستخدم واحد). @return int عدد الروابط */
    public function revokeAllShares(?int $uid = null): int
    {
        $q = DriveShare::query()->whereNull('revoked_at');
        if ($uid) {
            $q->where('user_id', $uid);
        }

        return (int) $q->update(['revoked_at' => now()]);
    }

    // ------------------------------------------------------------------
    // سجل النشاط + صيانة
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f action(prefix),user_id(admin),subject_id(target user),page,per_page */
    public function audit(array $f): array
    {
        $q = AuditLog::query()->where('action', 'like', 'admin.drive.%');
        if (!empty($f['action'])) {
            $q->where('action', 'like', 'admin.drive.' . str_replace(['%', '_'], ['\\%', '\\_'], preg_replace('/^admin\.drive\./', '', (string) $f['action'])) . '%');
        }
        if (!empty($f['user_id'])) {
            $q->where('user_id', (int) $f['user_id']);
        }
        if (!empty($f['subject_id'])) {
            $q->where('subject_type', 'DriveUser')->where('subject_id', (int) $f['subject_id']);
        }
        $q->orderByDesc('id');
        $page = $q->paginate(max(10, min(100, (int) ($f['per_page'] ?? 30))), ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        $ids = [];
        foreach ($page as $a) {
            $ids[] = (int) $a->user_id;
            if ($a->subject_type === 'DriveUser') {
                $ids[] = (int) $a->subject_id;
            }
        }
        $names = DB::table('users')->whereIn('id', array_values(array_unique(array_filter($ids))))->pluck('full_name', 'id')->all();

        return [
            'items' => $page->getCollection()->map(fn (AuditLog $a) => [
                'id' => (int) $a->id, 'action' => $a->action, 'admin_id' => $a->user_id, 'admin_name' => $names[$a->user_id] ?? null,
                'subject_type' => $a->subject_type, 'subject_id' => $a->subject_id,
                'target_name' => $a->subject_type === 'DriveUser' ? ($names[$a->subject_id] ?? null) : null,
                'old' => $a->old_values, 'new' => $a->new_values, 'ip' => $a->ip_address, 'created_at' => $a->created_at,
            ])->all(),
            'meta' => self::meta($page),
        ];
    }

    public function purgeExpiredTrash(): int
    {
        return $this->drive->purgeExpired();
    }

    /**
     * فحص ملفات اتسجّلت في الـ DB ومش موجودة على التخزين (بالدفعات: after_id للصفحة الجاية).
     *
     * @return array{checked:int,missing:array,next_after_id:?int}
     */
    public function missingFiles(int $afterId = 0, int $limit = 200): array
    {
        $limit = max(10, min(500, $limit));
        $rows = DriveNode::query()->where('type', 'file')->whereNotNull('storage_path')->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();
        $missing = [];
        foreach ($rows as $n) {
            try {
                $ok = $this->disk()->exists((string) $n->storage_path);
            } catch (\Throwable $e) {
                $ok = true; // مش هنقول مفقود لو التخزين نفسه مش متاح
            }
            if (!$ok) {
                $missing[] = $n->toRowArray() + ['owner_id' => (int) $n->user_id];
            }
        }

        return [
            'checked' => $rows->count(), 'missing' => $missing,
            'next_after_id' => $rows->count() === $limit ? (int) $rows->last()->id : null,
        ];
    }
}
