<?php

namespace App\Services;

use App\Models\DriveNode;
use App\Models\DriveShare;
use App\Repositories\SettingRepository;
use App\Support\DriveException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مشاركة ملفات "جهازي" برابط.
 *
 * - صاحب الملف بيدير الروابط (إنشاء / تعديل / إلغاء) — كل استعلام متقيّد بـ $uid، وid بتاع حد تاني => 404.
 * - اللي معاه الرابط (من غير تسجيل دخول) بيعدّي على resolve($token): الرابط لازم يكون موجود، مش ملغي، مش منتهي، والعنصر لسه حي.
 *   ولو الرابط على مجلد، أي عنصر بيتطلب لازم يكون جوّا المجلد ده (assertInside) — مفيش خروج للمجلد الأب أو لملفات تانية.
 * - الملفات مبتتنسخش ولا بتتكشف بمسارها: التنزيل بيمشي من خلال الـ API على حساب صاحب الملف.
 * - "عرض فقط" = معاينة بدون تنزيل/zip (حماية من الاستخدام العرضي، مش DRM).
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

    public function create(int $uid, int $nodeId, ?string $expiry, bool $canDownload): DriveShare
    {
        $node = $this->drive->find($uid, $nodeId);
        if ($this->mine($uid)->whereNull('revoked_at')->count() >= self::MAX_PER_USER) {
            throw new DriveException('You have too many active share links. Revoke some first.', 422);
        }
        return DriveShare::create([
            'user_id'      => $uid,
            'node_id'      => (int) $node->id,
            'token'        => Str::random(40),
            'can_download' => $canDownload,
            'expires_at'   => self::expiryFor($expiry),
        ]);
    }

    /** تعديل صلاحية/انتهاء رابط موجود (نفس الـ token يفضل شغال). */
    public function update(int $uid, int $shareId, array $in): DriveShare
    {
        $s = $this->findMine($uid, $shareId);
        if (array_key_exists('can_download', $in)) {
            $s->can_download = (bool) $in['can_download'];
        }
        if (array_key_exists('expiry', $in)) {
            $s->expires_at = self::expiryFor($in['expiry'] !== null ? (string) $in['expiry'] : null);
        }
        $s->save();
        return $s;
    }

    public function revoke(int $uid, int $shareId): void
    {
        $s = $this->findMine($uid, $shareId);
        $s->revoked_at = now();
        $s->save();
    }

    // ---- public side ---------------------------------------------------------------------------------

    /** @return array{0:DriveShare,1:DriveNode} الرابط + العنصر المشارَك. */
    public function resolve(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64 || !preg_match('/^[A-Za-z0-9]+$/', $token)) {
            throw new DriveException('This link is not valid.', 404);
        }
        $s = DriveShare::query()->where('token', $token)->first();
        if (!$s) {
            throw new DriveException('This link is not valid.', 404);
        }
        if ($s->revoked_at !== null) {
            throw new DriveException('This link was turned off by its owner.', 410);
        }
        if ($s->isExpired()) {
            throw new DriveException('This link has expired.', 410);
        }
        $node = DriveNode::query()->where('user_id', $s->user_id)->where('id', $s->node_id)->whereNull('deleted_at')->first();
        if (!$node) {
            throw new DriveException('This item is no longer available.', 410);
        }
        return [$s, $node];
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
    public function view(string $token, ?int $nodeId): array
    {
        [$s, $root] = $this->resolve($token);
        $node = $this->inside($s, $root, $nodeId);
        if ($nodeId === null) {
            $this->touch($s, 'views');
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
    public function openFile(string $token, ?int $nodeId, bool $download): array
    {
        [$s, $root] = $this->resolve($token);
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
        }
        return [$n, $stream];
    }

    /** مجلد (أو الملف المشارَك) كـ zip — بيتطلب can_download. */
    public function zipFor(string $token, ?int $nodeId): array
    {
        [$s, $root] = $this->resolve($token);
        $node = $this->inside($s, $root, $nodeId);
        if (!$s->can_download) {
            throw new DriveException('Downloading is turned off for this link.', 403);
        }
        $res = $this->drive->buildZip((int) $s->user_id, [(int) $node->id]);
        $this->touch($s, 'downloads');
        return $res;
    }
}
