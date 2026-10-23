<?php

namespace App\Services;

use App\Models\DriveNode;
use App\Models\DriveShare;
use App\Repositories\SettingRepository;
use App\Support\DriveException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * مشاركة ملفات "جهازي" برابط.
 *
 * - صاحب الملف بيدير الروابط (إنشاء / تعديل / إلغاء) — كل استعلام متقيّد بـ $uid، وid بتاع حد تاني => 404.
 * - اللي معاه الرابط (من غير تسجيل دخول) بيعدّي على resolve($token): الرابط لازم يكون موجود، مش ملغي، مش منتهي، والعنصر لسه حي.
 *   ولو الرابط على مجلد، أي عنصر بيتطلب لازم يكون جوّا المجلد ده (assertInside) — مفيش خروج للمجلد الأب أو لملفات تانية.
 * - الملفات مبتتنسخش ولا بتتكشف بمسارها: التنزيل بيمشي من خلال الـ API على حساب صاحب الملف.
 * - "عرض فقط" = معاينة بدون تنزيل/zip (حماية من الاستخدام العرضي، مش DRM).
 * - بوابة اختيارية قبل الفتح (الصاحب بيحدّدها لكل رابط): باسورد و/أو (اسم + إيميل + موبايل) للزائر. الزائر اللي يعدّي
 *   البوابة بياخد session token بيبعته في هيدر X-Share-Session مع كل طلب (view/file/zip). تغيير الباسورد أو تفعيل
 *   طلب البيانات بيزوّد gate_version فكل الجلسات القديمة بتتلغي.
 * - سجل الأحداث (drive_share_events) + الزوار (drive_share_visitors): مين فتح، امتى، من أي جهاز، نزّل إيه.
 *   الـ IP بيتخزّن مخفي جزئيًا (آخر خانة) + hash للعدّ بس.
 * - إيقاف مؤقت (paused_at) بيتكمّل بعدين؛ الإلغاء (revoked_at) نهائي؛ الحذف النهائي (forever) بيمسح الرابط وسجله.
 */
class DriveShareService
{
    public const EXPIRY = ['1d' => 1, '7d' => 7, '30d' => 30, 'never' => null];
    private const MAX_PER_USER = 300;
    private const MAX_EXPIRY_MINUTES = 525600; // 365 يوم

    public function __construct(private DriveService $drive, private SettingRepository $settings)
    {
    }

    private static function expiryFor(?string $key): ?\Illuminate\Support\Carbon
    {
        $key = strtolower(trim((string) $key)) ?: '7d';
        if ($key === 'never') {
            return null;
        }
        // مدة بالدقايق/الساعات/الأيام بمزاج المستخدم: "5m" · "2h" · "3d" (الجاهزة 1d/7d/30d بتعدّي من نفس الصيغة).
        if (!preg_match('/^(\d{1,6})([mhd])$/', $key, $m)) {
            throw new DriveException('Invalid expiry.', 422);
        }
        $n = (int) $m[1];
        $minutes = $n * ['m' => 1, 'h' => 60, 'd' => 1440][$m[2]];
        if ($n < 1 || $minutes > self::MAX_EXPIRY_MINUTES) {
            throw new DriveException('Expiry must be between 1 minute and 365 days.', 422);
        }
        return now()->addMinutes($minutes);
    }

    private function mine(int $uid)
    {
        return DriveShare::query()->where('user_id', $uid);
    }

    private function findMine(int $uid, int $shareId): DriveShare
    {
        $s = $this->mine($uid)->where('id', $shareId)->whereNull('revoked_at')->first();
        if (!$s) {
            throw new DriveException('Share link not found.', 404);
        }
        return $s;
    }

    /** رابط ليا حتى لو ملغي/موقوف (للإدارة: حذف نهائي / سجل). */
    private function findAnyMine(int $uid, int $shareId): DriveShare
    {
        $s = $this->mine($uid)->where('id', $shareId)->first();
        if (!$s) {
            throw new DriveException('Share link not found.', 404);
        }
        return $s;
    }

    private static function cleanPassword($pw): string
    {
        $pw = (string) $pw;
        if (strlen($pw) < 4 || strlen($pw) > 64) {
            throw new DriveException('Password must be 4 to 64 characters.', 422);
        }
        return $pw;
    }

    // ---- owner side ----------------------------------------------------------------------------------

    /** روابط عنصر معيّن (الحيّة بس: مش ملغية). */
    public function forNode(int $uid, int $nodeId): array
    {
        $this->drive->find($uid, $nodeId);
        return $this->mine($uid)->where('node_id', $nodeId)->whereNull('revoked_at')
            ->orderByDesc('id')->get()->map(fn (DriveShare $s) => $s->toRowArray())->all();
    }

    /** كل الروابط الحيّة مع اسم العنصر (لقائمة "مشاركاتي"). العناصر المحذوفة بتتخفّى. */
    public function listMine(int $uid): array
    {
        $shares = $this->mine($uid)->whereNull('revoked_at')->orderByDesc('id')->limit(500)->get();
        if ($shares->isEmpty()) {
            return [];
        }
        $nodes = DriveNode::query()->where('user_id', $uid)->whereNull('deleted_at')
            ->whereIn('id', $shares->pluck('node_id')->unique()->all())->get()->keyBy('id');
        $out = [];
        foreach ($shares as $s) {
            $n = $nodes->get($s->node_id);
            if (!$n) {
                continue;
            }
            $out[] = $s->toRowArray() + ['node' => ['id' => (int) $n->id, 'name' => $n->name, 'type' => $n->type]];
        }
        return $out;
    }

    public function create(int $uid, int $nodeId, ?string $expiry, bool $canDownload, bool $requireInfo = false, ?string $password = null): DriveShare
    {
        $node = $this->drive->find($uid, $nodeId);
        if ($this->mine($uid)->whereNull('revoked_at')->count() >= self::MAX_PER_USER) {
            throw new DriveException('You have too many active share links. Revoke some first.', 422);
        }
        $hash = ($password !== null && $password !== '') ? Hash::make(self::cleanPassword($password)) : null;
        return DriveShare::create([
            'user_id'      => $uid,
            'node_id'      => (int) $node->id,
            'token'        => Str::random(40),
            'can_download' => $canDownload,
            'expires_at'   => self::expiryFor($expiry),
            'require_visitor_info' => $requireInfo,
            'password_hash' => $hash,
            'gate_version' => 1,
        ]);
    }

    /**
     * تعديل رابط موجود (نفس الـ token يفضل شغال): can_download / expiry / require_visitor_info / password (نص جديد) /
     * remove_password. تغيير الباسورد أو تفعيل طلب البيانات بيلغي جلسات الزوار القديمة (gate_version).
     */
    public function update(int $uid, int $shareId, array $in): DriveShare
    {
        $s = $this->findMine($uid, $shareId);
        if (array_key_exists('can_download', $in)) {
            $s->can_download = (bool) $in['can_download'];
        }
        if (array_key_exists('expiry', $in)) {
            $s->expires_at = self::expiryFor($in['expiry'] !== null ? (string) $in['expiry'] : null);
        }
        $bump = false;
        if (array_key_exists('require_visitor_info', $in)) {
            $want = (bool) $in['require_visitor_info'];
            if ($want && !$s->require_visitor_info) {
                $bump = true;
            }
            $s->require_visitor_info = $want;
        }
        if (!empty($in['remove_password'])) {
            $s->password_hash = null;
        } elseif (array_key_exists('password', $in) && $in['password'] !== null && $in['password'] !== '') {
            $s->password_hash = Hash::make(self::cleanPassword($in['password']));
            $bump = true;
        }
        if ($bump) {
            $s->gate_version = (int) $s->gate_version + 1;
        }
        $s->save();
        return $s;
    }

    /** إيقاف مؤقت / استئناف (الرابط نفسه بيفضل زي ما هو). */
    public function pause(int $uid, int $shareId, bool $pause): DriveShare
    {
        $s = $this->findMine($uid, $shareId);
        $s->paused_at = $pause ? now() : null;
        $s->save();
        return $s;
    }

    /** اسم صاحب الرابط (للشاشات العامة) — بيرجّع null لو مش متاح. */
    private function ownerName(DriveShare $s): ?string
    {
        try {
            $n = DB::table('users')->where('id', $s->user_id)->value('full_name');
        } catch (\Throwable $e) {
            $n = null;
        }
        return $n ? Str::limit(trim((string) $n), 60, '') : null;
    }

    /**
     * إجراء جماعي على روابطي: pause | resume | delete.
     * $ids = أرقام محددة، أو $all=true لكل روابطي. بيتجاهل الروابط اللي مش بتاعتي أو اللي الإجراء ما يتطبّقش عليها (مثلًا الإيقاف لرابط منتهي).
     * @return array{done:int,skipped:int}
     */
    public function bulk(int $uid, string $action, array $ids, bool $all = false): array
    {
        if (!in_array($action, ['pause', 'resume', 'delete'], true)) {
            throw new DriveException('Unknown action.', 422);
        }
        $q = $this->mine($uid);
        if (!$all) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
            if (!$ids) {
                throw new DriveException('Select at least one link.', 422);
            }
            if (count($ids) > 1000) {
                throw new DriveException('Too many links at once.', 422);
            }
            $q->whereIn('id', $ids);
        }
        $rows = $q->get();
        $done = 0;
        DB::transaction(function () use ($rows, $action, &$done) {
            foreach ($rows as $s) {
                if ($action === 'delete') {
                    DB::table('drive_share_events')->where('share_id', $s->id)->delete();
                    DB::table('drive_share_visitors')->where('share_id', $s->id)->delete();
                    $s->delete();
                    $done++;
                } elseif ($s->revoked_at === null) {
                    if ($action === 'pause' && $s->paused_at === null) {
                        $s->paused_at = now();
                        $s->save();
                        $done++;
                    } elseif ($action === 'resume' && $s->paused_at !== null) {
                        $s->paused_at = null;
                        $s->save();
                        $done++;
                    }
                }
            }
        });
        return ['done' => $done, 'skipped' => max(0, $rows->count() - $done)];
    }

    /** حذف نهائي للرابط + كل سجله (الزوار والأحداث). */
    public function forever(int $uid, int $shareId): void
    {
        $s = $this->findAnyMine($uid, $shareId);
        DB::transaction(function () use ($s) {
            DB::table('drive_share_events')->where('share_id', $s->id)->delete();
            DB::table('drive_share_visitors')->where('share_id', $s->id)->delete();
            $s->delete();
        });
    }

    /** مسح سجل الزيارات فقط (الرابط يفضل شغال، العدّادات بتتصفّر). */
    public function clearActivity(int $uid, int $shareId): void
    {
        $s = $this->findAnyMine($uid, $shareId);
        DB::transaction(function () use ($s) {
            DB::table('drive_share_events')->where('share_id', $s->id)->delete();
            DB::table('drive_share_visitors')->where('share_id', $s->id)->delete();
            $s->views = 0;
            $s->downloads = 0;
            $s->last_used_at = null;
            $s->save();
        });
    }

    /**
     * كل روابطي (شغّالة / موقوفة / منتهية / ملغية) مع اسم العنصر وعدد الزوار — لصفحة "روابطي المشاركة".
     * العنصر المحذوف بيظهر برضو (node_deleted=true) عشان صاحبه يقدر يشوف سجل الرابط ويمسحه.
     */
    public function manage(int $uid): array
    {
        $shares = $this->mine($uid)->orderByDesc('id')->limit(500)->get();
        if ($shares->isEmpty()) {
            return [];
        }
        $nodes = DriveNode::query()->where('user_id', $uid)
            ->whereIn('id', $shares->pluck('node_id')->unique()->all())->get()->keyBy('id');
        $people = [];
        $rows = DB::table('drive_share_events')->whereIn('share_id', $shares->pluck('id')->all())->where('type', 'open')
            ->select('share_id', 'visitor_id', 'ip_hash', 'device')->distinct()->limit(50000)->get();
        foreach ($rows as $r) {
            $people[$r->share_id][($r->visitor_id ?: 0) . '|' . $r->ip_hash . '|' . $r->device] = 1;
        }
        $infoCount = DB::table('drive_share_visitors')->whereIn('share_id', $shares->pluck('id')->all())
            ->whereNotNull('email')->select('share_id', DB::raw('COUNT(*) as c'))->groupBy('share_id')->pluck('c', 'share_id');
        $out = [];
        foreach ($shares as $s) {
            $n = $nodes->get($s->node_id);
            $deleted = !$n || $n->deleted_at !== null;
            $out[] = $s->toRowArray() + [
                'node' => $n ? ['id' => (int) $n->id, 'name' => $n->name, 'type' => $n->type] : ['id' => (int) $s->node_id, 'name' => '', 'type' => 'file'],
                'node_deleted' => $deleted,
                'unique_visitors' => count($people[$s->id] ?? []),
                'identified_visitors' => (int) ($infoCount[$s->id] ?? 0),
            ];
        }
        return $out;
    }

    /** تفاصيل "مين فتح الرابط": ملخص + الزوار (متجمّعين) + آخر الأحداث. */
    public function activity(int $uid, int $shareId): array
    {
        $s = $this->findAnyMine($uid, $shareId);
        $vrows = DB::table('drive_share_visitors')->where('share_id', $s->id)->orderByDesc('last_seen_at')->limit(500)->get();
        $userIds = $vrows->pluck('user_id')->filter()->unique()->all();
        $users = $userIds ? DB::table('users')->whereIn('id', $userIds)->pluck('full_name', 'id') : collect();

        // نفس الشخص (نفس الإيميل) بجلسات/أجهزة كتير => سطر واحد.
        $groups = [];
        foreach ($vrows as $v) {
            $key = $v->email ? 'e:' . mb_strtolower($v->email) : 'a:' . $v->ip_hash . '|' . $v->device;
            $g = $groups[$key] ?? [
                'key' => $key, 'name' => $v->name, 'email' => $v->email, 'phone' => $v->phone,
                'account' => null, 'opens' => 0, 'downloads' => 0, 'devices' => [], 'ips' => [],
                'first_seen_at' => $v->first_seen_at, 'last_seen_at' => $v->last_seen_at,
                'ids' => [],
            ];
            $g['opens'] += (int) $v->opens;
            $g['downloads'] += (int) $v->downloads;
            if ($v->device) {
                $g['devices'][$v->device] = 1;
            }
            if ($v->ip_masked) {
                $g['ips'][$v->ip_masked] = 1;
            }
            if ($v->first_seen_at && (!$g['first_seen_at'] || $v->first_seen_at < $g['first_seen_at'])) {
                $g['first_seen_at'] = $v->first_seen_at;
            }
            if ($v->last_seen_at && (!$g['last_seen_at'] || $v->last_seen_at > $g['last_seen_at'])) {
                $g['last_seen_at'] = $v->last_seen_at;
            }
            if ($v->user_id && !$g['account']) {
                $g['account'] = ['id' => (int) $v->user_id, 'name' => $users[$v->user_id] ?? null];
            }
            $g['ids'][] = (int) $v->id;
            $groups[$key] = $g;
        }
        $visitors = array_map(function ($g) {
            $g['devices'] = array_keys($g['devices']);
            $g['ips'] = array_keys($g['ips']);
            return $g;
        }, array_values($groups));

        $ev = DB::table('drive_share_events as e')->leftJoin('drive_share_visitors as v', 'v.id', '=', 'e.visitor_id')
            ->where('e.share_id', $s->id)->orderByDesc('e.id')->limit(150)
            ->get(['e.id', 'e.type', 'e.node_name', 'e.ip_masked', 'e.device', 'e.created_at', 'e.visitor_id', 'v.name as v_name', 'v.email as v_email'])
            ->map(fn ($e) => [
                'id' => (int) $e->id, 'type' => $e->type, 'node_name' => $e->node_name, 'ip' => $e->ip_masked, 'device' => $e->device,
                'at' => $e->created_at, 'visitor_name' => $e->v_name, 'visitor_email' => $e->v_email,
            ])->all();

        $anon = DB::table('drive_share_events')->where('share_id', $s->id)->where('type', 'open')->whereNull('visitor_id')
            ->select('ip_hash', 'device')->distinct()->limit(5000)->get()->count();
        $failed = (int) DB::table('drive_share_events')->where('share_id', $s->id)->where('type', 'password_failed')->count();
        $opens = (int) DB::table('drive_share_events')->where('share_id', $s->id)->where('type', 'open')->count();

        return [
            'share' => $s->toRowArray(),
            'summary' => [
                'opens' => $opens,
                'downloads' => (int) $s->downloads,
                'identified' => count($visitors),
                'anonymous' => $anon,
                'failed_passwords' => $failed,
                'last_used_at' => $s->last_used_at,
            ],
            'visitors' => $visitors,
            'events' => $ev,
        ];
    }

    public function revoke(int $uid, int $shareId): void
    {
        $s = $this->findMine($uid, $shareId);
        $s->revoked_at = now();
        $s->save();
    }

    // ---- public side ---------------------------------------------------------------------------------

    /** @return array{0:DriveShare,1:DriveNode} الرابط + العنصر المشارَك (من غير فحص البوابة — شوف authorize). */
    public function resolve(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64 || !preg_match('/^[A-Za-z0-9]+$/', $token)) {
            throw new DriveException('This link is not valid.', 404, ['code' => 'share_invalid']);
        }
        $s = DriveShare::query()->where('token', $token)->first();
        if (!$s) {
            throw new DriveException('This link is not valid.', 404, ['code' => 'share_invalid']);
        }
        if ($s->revoked_at !== null) {
            throw new DriveException('This link was turned off by its owner.', 410, ['code' => 'share_revoked']);
        }
        if ($s->isPaused()) {
            throw new DriveException('This link is paused by its owner right now.', 410, [
                'code'        => 'share_paused',
                'owner_name'  => $this->ownerName($s),
                'paused_at'   => $s->paused_at ? (string) $s->paused_at : null,
            ]);
        }
        if ($s->isExpired()) {
            throw new DriveException('This link has expired.', 410, ['code' => 'share_expired']);
        }
        $node = DriveNode::query()->where('user_id', $s->user_id)->where('id', $s->node_id)->whereNull('deleted_at')->first();
        if (!$node) {
            throw new DriveException('This item is no longer available.', 410, ['code' => 'share_gone']);
        }
        return [$s, $node];
    }

    // ---- gate (password / visitor details) + activity log ---------------------------------------------

    private function gateErrors(DriveShare $s): array
    {
        $owner = null;
        try {
            $owner = DB::table('users')->where('id', $s->user_id)->value('full_name');
        } catch (\Throwable $e) {
            $owner = null;
        }
        return [
            'code'           => 'share_gate',
            'needs_password' => $s->password_hash !== null && $s->password_hash !== '',
            'needs_info'     => (bool) $s->require_visitor_info,
            'owner_name'     => $owner ? Str::limit(trim((string) $owner), 60, '') : null,
        ];
    }

    private static function ipMask(?string $ip): ?string
    {
        $ip = (string) $ip;
        if ($ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.x';
        }
        $p = explode(':', $ip);
        return implode(':', array_slice($p, 0, 3)) . '::x';
    }

    private static function ipHash(?string $ip): ?string
    {
        $ip = (string) $ip;
        return $ip === '' ? null : substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 32);
    }

    /** "Chrome · Windows · Desktop" من الـ User-Agent (تقريبي، للعرض بس). */
    private static function deviceOf(?string $ua): ?string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return null;
        }
        $browser = 'Browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'SamsungBrowser' => 'Samsung Internet', 'Chrome' => 'Chrome', 'CriOS' => 'Chrome', 'Safari' => 'Safari'] as $k => $v) {
            if (stripos($ua, $k) !== false) {
                $browser = $v;
                break;
            }
        }
        $os = 'Unknown OS';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Mac OS' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'] as $k => $v) {
            if (stripos($ua, $k) !== false) {
                $os = $v;
                break;
            }
        }
        $type = (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) ? 'Tablet'
            : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'Mobile' : 'Desktop');
        return mb_substr($browser . ' · ' . $os . ' · ' . $type, 0, 120);
    }

    /**
     * لو الرابط عليه بوابة: لازم الزائر يكون عدّى منها (session token صالح ومطابق لـ gate_version الحالية).
     * @return object|null سجل الزائر (أو null لو مفيش بوابة).
     */
    private function authorize(DriveShare $s, array $ctx): ?object
    {
        if (!$s->hasGate()) {
            return null;
        }
        $session = (string) ($ctx['session'] ?? '');
        if ($session === '' || strlen($session) > 128) {
            throw new DriveException('This link needs verification first.', 423, $this->gateErrors($s));
        }
        $v = DB::table('drive_share_visitors')->where('share_id', $s->id)
            ->where('session_hash', hash('sha256', $session))->where('gate_version', (int) $s->gate_version)->first();
        if (!$v) {
            throw new DriveException('This link needs verification first.', 423, $this->gateErrors($s));
        }
        return $v;
    }

    private function logEvent(DriveShare $s, ?object $visitor, string $type, array $ctx, ?DriveNode $node = null, int $dedupeSeconds = 0): bool
    {
        try {
            $hash = self::ipHash($ctx['ip'] ?? null);
            $device = self::deviceOf($ctx['ua'] ?? null);
            if ($dedupeSeconds > 0) {
                $q = DB::table('drive_share_events')->where('share_id', $s->id)->where('type', $type)
                    ->where('created_at', '>=', now()->subSeconds($dedupeSeconds));
                $visitor ? $q->where('visitor_id', $visitor->id) : $q->whereNull('visitor_id')->where('ip_hash', $hash)->where('device', $device);
                if ($node) {
                    $q->where('node_id', $node->id);
                }
                if ($q->exists()) {
                    return false;
                }
            }
            DB::table('drive_share_events')->insert([
                'share_id'   => $s->id,
                'visitor_id' => $visitor->id ?? null,
                'type'       => $type,
                'node_id'    => $node?->id,
                'node_name'  => $node ? mb_substr((string) $node->name, 0, 255) : null,
                'ip_masked'  => self::ipMask($ctx['ip'] ?? null),
                'ip_hash'    => $hash,
                'device'     => $device,
                'created_at' => now(),
            ]);
            if ($visitor) {
                $col = in_array($type, ['download', 'zip'], true) ? 'downloads' : ($type === 'open' ? 'opens' : null);
                $upd = ['last_seen_at' => now()];
                if ($col) {
                    $upd[$col] = DB::raw($col . ' + 1');
                }
                DB::table('drive_share_visitors')->where('id', $visitor->id)->update($upd);
            }
            // سجل الأحداث مش بيكبر للأبد: آخر 3000 حدث لكل رابط.
            if (random_int(1, 40) === 1) {
                $cut = DB::table('drive_share_events')->where('share_id', $s->id)->orderByDesc('id')->skip(3000)->value('id');
                if ($cut) {
                    DB::table('drive_share_events')->where('share_id', $s->id)->where('id', '<=', $cut)->delete();
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false; // السجل مش لازم يكسر فتح الملف
        }
    }

    /**
     * البوابة: الزائر بيبعت (name/email/phone لو مطلوبين) + (password لو مطلوب). لو تمام بيرجع session token.
     * محاولات الباسورد الغلط محدودة (6 كل 15 دقيقة لكل رابط + IP).
     * @return array{session:?string, visitor_name:?string}
     */
    public function enter(string $token, array $in, array $ctx): array
    {
        [$s] = $this->resolve($token);
        if (!$s->hasGate()) {
            return ['session' => null, 'visitor_name' => null];
        }
        $ipHash = self::ipHash($ctx['ip'] ?? null) ?: 'none';
        $key = 'driveshare-enter:' . $s->id . ':' . $ipHash;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            $wait = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw new DriveException("Too many wrong attempts. Try again in {$wait} minute(s).", 429, ['code' => 'too_many_attempts', 'retry_minutes' => $wait]);
        }

        $fields = [];
        $name = $email = $phone = null;
        if ($s->require_visitor_info) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                $fields['name'] = 'Enter your full name (2–80 characters).';
            }
            $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
            if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $fields['email'] = 'Enter a valid email address.';
            }
            $rawPhone = trim((string) ($in['phone'] ?? ''));
            $phone = preg_replace('/(?!^\+)[^\d]/', '', $rawPhone);
            $digits = strlen(preg_replace('/\D/', '', (string) $phone));
            if ($digits < 7 || $digits > 15) {
                $fields['phone'] = 'Enter a valid mobile number (7–15 digits).';
            }
        }
        if (!empty($s->password_hash) && trim((string) ($in['password'] ?? '')) === '') {
            $fields['password'] = 'Enter the password.';
        }
        if ($fields) {
            throw new DriveException('Please check the highlighted fields.', 422, ['code' => 'gate_fields', 'fields' => $fields] + $this->gateErrors($s));
        }

        if (!empty($s->password_hash) && !Hash::check((string) $in['password'], $s->password_hash)) {
            RateLimiter::hit($key, 900);
            $this->logEvent($s, null, 'password_failed', $ctx, null, 60);
            throw new DriveException('Incorrect password.', 403, ['code' => 'bad_password', 'fields' => ['password' => 'Incorrect password.']] + $this->gateErrors($s));
        }
        RateLimiter::clear($key);

        $session = Str::random(48);
        $id = DB::table('drive_share_visitors')->insertGetId([
            'share_id'      => $s->id,
            'session_hash'  => hash('sha256', $session),
            'gate_version'  => (int) $s->gate_version,
            'name'          => $name !== null ? mb_substr($name, 0, 120) : null,
            'email'         => $email,
            'phone'         => $phone !== null ? substr($phone, 0, 32) : null,
            'user_id'       => !empty($ctx['user_id']) ? (int) $ctx['user_id'] : null,
            'ip_masked'     => self::ipMask($ctx['ip'] ?? null),
            'ip_hash'       => self::ipHash($ctx['ip'] ?? null),
            'device'        => self::deviceOf($ctx['ua'] ?? null),
            'opens'         => 0,
            'downloads'     => 0,
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $v = DB::table('drive_share_visitors')->where('id', $id)->first();
        $this->logEvent($s, $v, 'enter', $ctx);
        return ['session' => $session, 'visitor_name' => $name];
    }

    /** العنصر المطلوب لازم يكون هو المشارَك نفسه أو جوّاه (يمشي لفوق بالـ parent_id). */
    private function inside(DriveShare $s, DriveNode $root, ?int $nodeId): DriveNode
    {
        if ($nodeId === null || $nodeId === (int) $root->id) {
            return $root;
        }
        if (!$root->isFolder()) {
            throw new DriveException('Item not found.', 404);
        }
        $uid = (int) $s->user_id;
        $n = DriveNode::query()->where('user_id', $uid)->where('id', $nodeId)->whereNull('deleted_at')->first();
        $cur = $n;
        $guard = 0;
        while ($cur && $guard++ < 64) {
            if ((int) $cur->id === (int) $root->id) {
                return $n;
            }
            if ($cur->parent_id === null) {
                break;
            }
            $cur = DriveNode::query()->where('user_id', $uid)->where('id', $cur->parent_id)->whereNull('deleted_at')->first();
        }
        throw new DriveException('Item not found.', 404);
    }

    /** المسار من العنصر المشارَك لحد $node (من غير ما يكشف أي حاجة فوق الجذر المشارَك). */
    private function crumbs(DriveShare $s, DriveNode $root, DriveNode $node): array
    {
        $out = [];
        $cur = $node;
        $guard = 0;
        while ($cur && $guard++ < 64) {
            array_unshift($out, ['id' => (int) $cur->id, 'name' => $cur->name]);
            if ((int) $cur->id === (int) $root->id || $cur->parent_id === null) {
                break;
            }
            $cur = DriveNode::query()->where('user_id', $s->user_id)->where('id', $cur->parent_id)->first();
        }
        return $out;
    }

    private function touch(DriveShare $s, string $col): void
    {
        DriveShare::query()->where('id', $s->id)->update([
            $col => DB::raw($col . ' + 1'), 'last_used_at' => now(),
        ]);
    }

    /** بيانات صفحة المشاركة العامة: العنصر الحالي + (لو مجلد) محتواه. */
    public function view(string $token, ?int $nodeId, array $ctx = []): array
    {
        [$s, $root] = $this->resolve($token);
        $visitor = $this->authorize($s, $ctx);
        $node = $this->inside($s, $root, $nodeId);
        if ($nodeId === null && $this->logEvent($s, $visitor, 'open', $ctx, null, 300)) {
            $this->touch($s, 'views'); // فتحة جديدة (مش تحديث للصفحة في خلال 5 دقايق)
        }
        $items = [];
        if ($node->isFolder()) {
            $items = DriveNode::query()->where('user_id', $s->user_id)->where('parent_id', $node->id)->whereNull('deleted_at')
                ->orderByRaw("type = 'folder' desc")->orderBy('name')->limit(2000)->get()
                ->map(fn (DriveNode $n) => $this->publicRow($n))->all();
        }
        $owner = null;
        $ownerAvatar = null;
        try {
            $row = DB::table('users')->where('id', $s->user_id)->first(['full_name', 'avatar_path']);
            $owner = $row->full_name ?? null;
            $ownerAvatar = $row->avatar_path ?? null;
            // نفس قواعد الخصوصية بتاعة باقي الصفحات العامة: لو قفل "صورتي للعامة" يظهر الحرف الأول بس.
            if ($ownerAvatar && ($this->settings->get('profile_photo_public', 'user', (int) $s->user_id, '1') === '0'
                || $this->settings->get('messaging_show_avatar', 'user', (int) $s->user_id, '1') === '0')) {
                $ownerAvatar = null;
            }
        } catch (\Throwable $e) {
            $owner = null;
            $ownerAvatar = null;
        }
        return [
            'share' => [
                'can_download' => (bool) $s->can_download,
                'expires_at'   => $s->expires_at,
                'owner_name'   => $owner ? Str::limit(trim((string) $owner), 60, '') : null,
                'owner_avatar' => $ownerAvatar ? (string) $ownerAvatar : null,
                'visitor_name' => $visitor->name ?? null,
                'visitor_email' => $visitor->email ?? null,
                'root'         => $this->publicRow($root),
            ],
            'node'       => $this->publicRow($node),
            'breadcrumb' => $this->crumbs($s, $root, $node),
            'items'      => $items,
        ];
    }

    /** صف عام: بدون user_id / storage_path / sha1. */
    private function publicRow(DriveNode $n): array
    {
        $r = $n->toRowArray();
        return [
            'id' => $r['id'], 'type' => $r['type'], 'name' => $r['name'], 'extension' => $r['extension'],
            'mime_type' => $r['mime_type'], 'size_bytes' => $r['size_bytes'], 'size_human' => $r['size_human'], 'updated_at' => $r['updated_at'],
        ];
    }

    /**
     * ملف للمعاينة/التنزيل. $download=true محتاج can_download.
     * @return array{0:DriveNode,1:resource}
     */
    public function openFile(string $token, ?int $nodeId, bool $download, array $ctx = []): array
    {
        [$s, $root] = $this->resolve($token);
        $visitor = $this->authorize($s, $ctx);
        $node = $this->inside($s, $root, $nodeId);
        if ($node->isFolder()) {
            throw new DriveException('Item not found.', 404);
        }
        if ($download && !$s->can_download) {
            throw new DriveException('Downloading is turned off for this link.', 403);
        }
        [$n, $stream] = $this->drive->openStream((int) $s->user_id, (int) $node->id);
        if ($download) {
            $this->touch($s, 'downloads');
            $this->logEvent($s, $visitor, 'download', $ctx, $node);
        } else {
            $this->logEvent($s, $visitor, 'preview', $ctx, $node, 120);
        }
        return [$n, $stream];
    }

    /** مجلد (أو الملف المشارَك) كـ zip — بيتطلب can_download. */
    public function zipFor(string $token, ?int $nodeId, array $ctx = []): array
    {
        [$s, $root] = $this->resolve($token);
        $visitor = $this->authorize($s, $ctx);
        $node = $this->inside($s, $root, $nodeId);
        if (!$s->can_download) {
            throw new DriveException('Downloading is turned off for this link.', 403);
        }
        $res = $this->drive->buildZip((int) $s->user_id, [(int) $node->id]);
        $this->touch($s, 'downloads');
        $this->logEvent($s, $visitor, 'zip', $ctx, $node);
        return $res;
    }
}
