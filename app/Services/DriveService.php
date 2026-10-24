<?php

namespace App\Services;

use App\Models\DriveNode;
use App\Models\DriveNodeVersion;
use App\Support\DriveArchive;
use App\Support\DriveException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * "جهازي" (My PC): نظام ملفات شخصي معزول لكل مستخدم.
 *
 * العزل: كل دالة هنا بتاخد $uid وكل استعلام بيتقيّد بيه — id بتاع حد تاني => 404 (مش 403، عشان مفيش تسريب وجود).
 * التخزين: الملفات على disk 'drive' (خاص) باسم UUID تحت u{uid}/، والشجرة والأسماء في جدول drive_nodes.
 * الحذف: soft (سلة 30 يوم)؛ الحصة بتحسب الملفات اللي في السلة كمان لحد ما تتمسح فعليًا.
 */
class DriveService
{
    /** @var array<int,int> memo لعمق المجلدات (بيتمسح مع أي نقل) */
    private array $depthMemo = [];

    public function __construct()
    {
        // Admin → My PC Control: إعدادات الأدمن (حصص/حدود/أيام السلة...) بتتطبّق على config('drive.*') قبل أي استخدام.
        DriveAdminSettings::applyToConfig();
    }

    // ---- infra ---------------------------------------------------------------------------------------

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('drive.disk'));
    }

    private function q(int $uid)
    {
        return DriveNode::query()->where('user_id', $uid);
    }

    /** حصة المستخدم (تخصيصه > حصة دوره > العامة). 0 في الإعدادات = بلا حد (PHP_INT_MAX). */
    private function quotaBytes(int $uid): int
    {
        return DriveAdminSettings::quotaBytes($uid);
    }

    private function maxFileBytes(int $uid): int
    {
        return DriveAdminSettings::maxFileBytes($uid);
    }

    private function maxFileMb(int $uid): int
    {
        return (int) DriveAdminSettings::limitsFor($uid)['max_file_mb'];
    }

    private function maxZipBytes(): int
    {
        return max(1, (int) config('drive.max_zip_mb')) * 1048576;
    }

    public function find(int $uid, int $id, bool $trashed = false): DriveNode
    {
        $q = $this->q($uid)->where('id', $id);
        $trashed ? $q->whereNotNull('deleted_at') : $q->whereNull('deleted_at');
        $n = $q->first();
        if (!$n) {
            throw new DriveException('Item not found.', 404);
        }
        return $n;
    }

    /** null = الجذر ("جهازي"). */
    private function folderOrRoot(int $uid, ?int $id): ?DriveNode
    {
        if ($id === null || $id === 0) {
            return null;
        }
        $n = $this->find($uid, $id);
        if (!$n->isFolder()) {
            throw new DriveException('The destination is not a folder.', 422);
        }
        return $n;
    }

    private function depthOf(int $uid, ?int $folderId): int
    {
        if ($folderId === null) {
            return 0;
        }
        if (isset($this->depthMemo[$folderId])) {
            return $this->depthMemo[$folderId];
        }
        $d = 0;
        $cur = $folderId;
        $guard = 0;
        while ($cur !== null && $guard++ < 200) {
            $d++;
            $cur = $this->q($uid)->where('id', $cur)->value('parent_id');
            $cur = $cur !== null ? (int) $cur : null;
        }
        return $this->depthMemo[$folderId] = $d;
    }

    public function usedBytes(int $uid): int
    {
        return (int) $this->q($uid)->where('type', 'file')->sum('size_bytes');
    }

    private function assertQuota(int $uid, int $add): void
    {
        DriveAdminSettings::assertUploadsAllowed($uid);
        if ($add > $this->maxFileBytes($uid)) {
            throw new DriveException('The file is larger than the maximum allowed size (' . $this->maxFileMb($uid) . ' MB).', 413);
        }
        if ($this->usedBytes($uid) + $add > $this->quotaBytes($uid)) {
            throw new DriveException('Not enough storage space in My PC. Empty the recycle bin or delete some files.', 413);
        }
    }

    private function assertNodeBudget(int $uid, int $add = 1): void
    {
        if ($this->q($uid)->count() + $add > (int) config('drive.max_nodes')) {
            throw new DriveException('Too many items in My PC.', 413);
        }
    }

    public function usage(int $uid): array
    {
        $used = $this->usedBytes($uid);
        $lim = DriveAdminSettings::limitsFor($uid);
        $unlimited = $lim['quota_mb'] <= 0;
        $quota = $unlimited ? 0 : $this->quotaBytes($uid);
        return [
            'used_bytes'  => $used,
            'quota_bytes' => $quota, // 0 + unlimited=true => بلا حد
            'unlimited'   => $unlimited,
            'used_human'  => DriveNode::humanSize($used),
            'quota_human' => $unlimited ? 'Unlimited' : DriveNode::humanSize($quota),
            'percent'     => $quota > 0 ? round($used * 100 / $quota, 1) : 0,
            'max_file_mb' => $this->maxFileMb($uid),
            'trash_days'  => (int) config('drive.trash_days'),
            'status'      => $lim['status'],
        ];
    }

    // ---- names ---------------------------------------------------------------------------------------

    private function nameTaken(int $uid, ?int $parentId, string $name, ?int $ignoreId = null): bool
    {
        $q = $this->q($uid)->whereNull('deleted_at')->where('name', $name);
        $parentId === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parentId);
        if ($ignoreId !== null) {
            $q->where('id', '!=', $ignoreId);
        }
        return $q->exists();
    }

    private function freeName(int $uid, ?int $parentId, string $name, ?int $ignoreId = null): string
    {
        return DriveArchive::uniqueName($name, fn ($n) => $this->nameTaken($uid, $parentId, $n, $ignoreId));
    }

    private function validName(string $name): string
    {
        $clean = DriveArchive::cleanName($name);
        if ($clean === '') {
            throw new DriveException('Invalid name.', 422);
        }
        return $clean;
    }

    // ---- browse --------------------------------------------------------------------------------------

    public function breadcrumb(int $uid, ?int $folderId): array
    {
        $out = [];
        $cur = $folderId;
        $guard = 0;
        while ($cur !== null && $guard++ < 200) {
            $n = $this->q($uid)->where('id', $cur)->first(['id', 'parent_id', 'name']);
            if (!$n) {
                break;
            }
            array_unshift($out, ['id' => (int) $n->id, 'name' => $n->name]);
            $cur = $n->parent_id !== null ? (int) $n->parent_id : null;
        }
        return $out;
    }

    public function listFolder(int $uid, ?int $parentId): array
    {
        $this->folderOrRoot($uid, $parentId);
        $q = $this->q($uid)->whereNull('deleted_at');
        $parentId === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parentId);
        $items = $q->orderByRaw("type = 'folder' desc")->orderBy('name')->get();
        return [
            'items'      => $items->map(fn (DriveNode $n) => $n->toRowArray())->all(),
            'breadcrumb' => $this->breadcrumb($uid, $parentId),
            'usage'      => $this->usage($uid),
        ];
    }

    /** كل المجلدات (للشجرة الجانبية) — خفيف: id/parent/name/kind. */
    public function tree(int $uid): array
    {
        return $this->q($uid)->whereNull('deleted_at')->where('type', 'folder')->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'kind'])
            ->map(fn ($n) => ['id' => (int) $n->id, 'parent_id' => $n->parent_id !== null ? (int) $n->parent_id : null, 'name' => $n->name, 'kind' => $n->kind])
            ->all();
    }

    public function search(int $uid, string $term, int $limit = 100): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $rows = $this->q($uid)->whereNull('deleted_at')->where('name', 'like', $like)
            ->orderByRaw("type = 'folder' desc")->orderBy('name')->limit(min(200, max(1, $limit)))->get();
        return $rows->map(function (DriveNode $n) use ($uid) {
            $r = $n->toRowArray();
            $r['path'] = implode('/', array_column($this->breadcrumb($uid, $n->parent_id !== null ? (int) $n->parent_id : null), 'name'));
            return $r;
        })->all();
    }

    // ---- create --------------------------------------------------------------------------------------

    private function insertNode(int $uid, ?int $parentId, string $type, string $name, array $extra = []): DriveNode
    {
        $this->assertNodeBudget($uid);
        return DriveNode::create(array_merge([
            'user_id' => $uid, 'parent_id' => $parentId, 'type' => $type, 'name' => $name,
        ], $extra));
    }

    public function createFolder(int $uid, ?int $parentId, string $name, ?string $kind = null): DriveNode
    {
        $parent = $this->folderOrRoot($uid, $parentId);
        if ($this->depthOf($uid, $parent?->id) + 1 > (int) config('drive.max_depth')) {
            throw new DriveException('Folders are nested too deeply.', 422);
        }
        $name = $this->freeName($uid, $parent?->id, $this->validName($name));
        return $this->insertNode($uid, $parent?->id, 'folder', $name, ['kind' => $kind]);
    }

    /** يلاقي/ينشئ سلسلة مجلدات تحت $parentId (للرفع بـ webkitRelativePath وفك الـ zip). بيدمج مع الموجود. */
    private function ensureFolderPath(int $uid, ?int $parentId, array $segments): ?int
    {
        $cur = $parentId;
        foreach ($segments as $seg) {
            $q = $this->q($uid)->whereNull('deleted_at')->where('type', 'folder')->where('name', $seg);
            $cur === null ? $q->whereNull('parent_id') : $q->where('parent_id', $cur);
            $found = $q->first();
            if ($found) {
                $cur = (int) $found->id;
                continue;
            }
            if ($this->nameTaken($uid, $cur, $seg)) { // نفس الاسم ملف
                $seg = $this->freeName($uid, $cur, $seg);
            }
            if ($this->depthOf($uid, $cur) + 1 > (int) config('drive.max_depth')) {
                throw new DriveException('Folders are nested too deeply.', 422);
            }
            $cur = (int) $this->insertNode($uid, $cur, 'folder', $seg)->id;
        }
        return $cur;
    }

    /** mkdir -p: ينشئ/يلاقي سلسلة مجلدات "a/b/c" تحت $parentId ويرجّع آخر مجلد (لرفع مجلدات فاضية بالسحب). */
    public function ensurePath(int $uid, ?int $parentId, string $path): DriveNode
    {
        $parent = $this->folderOrRoot($uid, $parentId);
        $segs = DriveArchive::segments($path);
        if ($segs === null || $segs === []) {
            throw new DriveException('Invalid folder path.', 422);
        }
        return DB::transaction(fn () => $this->find($uid, (int) $this->ensureFolderPath($uid, $parent?->id, $segs)));
    }

    private function detectMime(string $path, string $name): string
    {
        $m = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        if (!$m || $m === 'application/octet-stream' || $m === 'text/plain') {
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $map = ['md' => 'text/markdown', 'json' => 'application/json', 'js' => 'text/javascript', 'css' => 'text/css',
                'html' => 'text/html', 'svg' => 'image/svg+xml', 'csv' => 'text/csv', 'zip' => 'application/zip',
                'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            if (isset($map[$ext])) {
                return $map[$ext];
            }
        }
        return $m ?: 'application/octet-stream';
    }

    /** يدخّل ملف محلي (temp) لـ disk وينشئ الـ node. */
    private function putLocalFile(int $uid, ?int $parentId, string $name, string $localPath, ?string $mime = null): DriveNode
    {
        $size = (int) filesize($localPath);
        $this->assertQuota($uid, $size);
        $this->assertNodeBudget($uid);
        $sp = $this->writeBlob($uid, $localPath);
        try {
            return DriveNode::create([
                'user_id' => $uid, 'parent_id' => $parentId, 'type' => 'file', 'name' => $name,
                'mime_type' => $mime ?: $this->detectMime($localPath, $name), 'size_bytes' => $size,
                'storage_path' => $sp, 'sha1' => sha1_file($localPath) ?: null,
            ]);
        } catch (\Throwable $e) {
            $this->disk()->delete($sp);
            throw $e;
        }
    }

    private function writeBlob(int $uid, string $localPath): string
    {
        $sp = "u{$uid}/" . Str::uuid()->toString();
        $h = fopen($localPath, 'rb');
        if (!$h) {
            throw new DriveException('Could not read the file.', 500);
        }
        try {
            if (!$this->disk()->writeStream($sp, $h)) {
                throw new DriveException('Could not save the file.', 500);
            }
        } finally {
            if (is_resource($h)) {
                fclose($h);
            }
        }
        return $sp;
    }

    public function createTextFile(int $uid, ?int $parentId, string $name, string $content = ''): DriveNode
    {
        $parent = $this->folderOrRoot($uid, $parentId);
        if (strlen($content) > (int) config('drive.max_text_edit_kb') * 1024) {
            throw new DriveException('The text is too large.', 413);
        }
        $name = $this->validName($name);
        DriveAdminSettings::assertNameAllowed($name);
        $name = $this->freeName($uid, $parent?->id, $name);
        $tmp = tempnam(sys_get_temp_dir(), 'drv');
        try {
            file_put_contents($tmp, $content);
            return $this->putLocalFile($uid, $parent?->id, $name, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** رفع ملف (ممكن بمسار نسبي "dir/sub/file.txt" لرفع مجلد كامل). لو الاسم موجود: "name (2)". */
    public function upload(int $uid, ?int $parentId, UploadedFile $file, ?string $relativePath = null): DriveNode
    {
        if (!$file->isValid()) {
            throw new DriveException('The upload failed. Please try again.', 422);
        }
        $parent = $this->folderOrRoot($uid, $parentId);
        $name = DriveArchive::cleanName((string) $file->getClientOriginalName());
        $dirSegs = [];
        if ($relativePath !== null && $relativePath !== '') {
            $segs = DriveArchive::segments($relativePath);
            if ($segs === null || $segs === []) {
                throw new DriveException('Invalid file path.', 422);
            }
            $name = array_pop($segs);
            $dirSegs = $segs;
        }
        if ($name === '') {
            throw new DriveException('Invalid file name.', 422);
        }
        DriveAdminSettings::assertNameAllowed($name);
        return DB::transaction(function () use ($uid, $parent, $dirSegs, $name, $file) {
            $target = $dirSegs ? $this->ensureFolderPath($uid, $parent?->id, $dirSegs) : $parent?->id;
            $name = $this->freeName($uid, $target, $name);
            return $this->putLocalFile($uid, $target, $name, $file->getRealPath(), $file->getMimeType() ?: null);
        });
    }

    // ---- read / write content ------------------------------------------------------------------------

    /** @return array{0:DriveNode,1:resource} */
    public function openStream(int $uid, int $id): array
    {
        $n = $this->find($uid, $id);
        if ($n->isFolder() || !$n->storage_path) {
            throw new DriveException('Item not found.', 404);
        }
        $s = $this->disk()->readStream($n->storage_path);
        if (!$s) {
            throw new DriveException('The file is missing from storage.', 404);
        }
        return [$n, $s];
    }

    /** يستبدل محتوى ملف بمحتوى جديد (محفوظ من المحرر). $newLocal = ملف محلي مؤقت. */
    private function replaceBlob(int $uid, DriveNode $n, string $newLocal, ?string $mime = null): DriveNode
    {
        $size = (int) filesize($newLocal);
        $delta = $size - (int) $n->size_bytes;
        if ($delta > 0) {
            $this->assertQuota($uid, $delta);
        }
        if ($size > $this->maxFileBytes($uid)) {
            throw new DriveException('The file is larger than the maximum allowed size.', 413);
        }
        $old = $n->storage_path;
        $was = $this->versionAttrs($n);
        $newSha = sha1_file($newLocal) ?: null;
        $sp = $this->writeBlob($uid, $newLocal);
        $kept = false;
        try {
            DB::transaction(function () use ($uid, $n, $sp, $size, $newSha, $newLocal, $mime, $was, &$kept) {
                $n->forceFill([
                    'storage_path' => $sp, 'size_bytes' => $size, 'sha1' => $newSha,
                    'mime_type' => $mime ?: $this->detectMime($newLocal, $n->name),
                ])->save();
                $kept = $this->keepVersion($uid, (int) $n->id, $was, $newSha, 'save');
            });
        } catch (\Throwable $e) {
            $this->disk()->delete($sp);
            throw $e;
        }
        if ($old && !$kept) {
            $this->disk()->delete($old);
        }
        if ($kept) {
            $this->pruneVersions($uid, (int) $n->id);
        }
        return $n;
    }

    public function writeContent(int $uid, int $id, string $content): DriveNode
    {
        $n = $this->find($uid, $id);
        if ($n->isFolder()) {
            throw new DriveException('A folder has no content.', 422);
        }
        if (strlen($content) > (int) config('drive.max_text_edit_kb') * 1024) {
            throw new DriveException('The text is too large to edit here.', 413);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'drv');
        try {
            file_put_contents($tmp, $content);
            return $this->replaceBlob($uid, $n, $tmp, $n->mime_type);
        } finally {
            @unlink($tmp);
        }
    }

    /** حفظ من محرر الصور/PDF/ZIP: ملف جديد مكان القديم (والاسم ممكن يتغير لو الامتداد اتغير). */
    public function replaceFromUpload(int $uid, int $id, UploadedFile $file, ?string $newName = null): DriveNode
    {
        if (!$file->isValid()) {
            throw new DriveException('The upload failed. Please try again.', 422);
        }
        $n = $this->find($uid, $id);
        if ($n->isFolder()) {
            throw new DriveException('A folder has no content.', 422);
        }
        $this->replaceBlob($uid, $n, $file->getRealPath(), $file->getMimeType() ?: null);
        if ($newName !== null && $newName !== '' && DriveArchive::cleanName($newName) !== $n->name) {
            $this->rename($uid, $id, $newName);
            $n->refresh();
        }
        return $n;
    }


    // ---- direct-to-bucket transfer (S3 / Cloudflare R2) ----------------------------------------------
    // المتصفح بيرفع/بينزّل مباشرة من الـ bucket بروابط موقّعة قصيرة العمر؛ Laravel بيحفظ الـ metadata بس.
    // مفيش جدول جديد: الـ init بيرجّع token مشفّر (uid + المسار + الاسم + الحجم + الانتهاء) والـ complete بيتحقق منه.

    public function directEnabled(): bool
    {
        return (bool) config('drive.direct')
            && (string) config('filesystems.disks.' . config('drive.disk') . '.driver') === 's3'
            && (string) config('filesystems.disks.' . config('drive.disk') . '.bucket') !== '';
    }

    private function assertDirect(): void
    {
        if (!$this->directEnabled()) {
            throw new DriveException('Direct upload is not enabled.', 404);
        }
    }

    private function directTtl(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('drive.direct_ttl_min'));
    }

    private function sealPending(array $p): string
    {
        $p['exp'] = now()->addMinutes((int) config('drive.direct_ttl_min') + 30)->getTimestamp();
        return Crypt::encryptString(json_encode($p));
    }

    private function openPending(int $uid, string $token, string $kind): array
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable $e) {
            throw new DriveException('The upload session is invalid.', 422);
        }
        if (!is_array($p) || ($p['uid'] ?? null) !== $uid || ($p['kind'] ?? '') !== $kind || (int) ($p['exp'] ?? 0) < time()) {
            throw new DriveException('The upload session expired. Please try again.', 422);
        }
        return $p;
    }

    private function signedPut(string $sp, ?string $mime): array
    {
        $opts = $mime ? ['ContentType' => $mime] : [];
        $r = $this->disk()->temporaryUploadUrl($sp, $this->directTtl(), $opts);
        return ['url' => $r['url'], 'headers' => (object) ($r['headers'] ?? [])];
    }

    /** يحجز مكان لملف جديد ويرجّع رابط PUT موقّع. */
    public function initDirectUpload(int $uid, ?int $parentId, string $name, int $size, ?string $mime, ?string $relativePath = null): array
    {
        $this->assertDirect();
        $parent = $this->folderOrRoot($uid, $parentId);
        $dirSegs = [];
        $name = DriveArchive::cleanName($name);
        if ($relativePath !== null && $relativePath !== '') {
            $segs = DriveArchive::segments($relativePath);
            if ($segs === null || $segs === []) {
                throw new DriveException('Invalid file path.', 422);
            }
            $name = array_pop($segs);
            $dirSegs = $segs;
        }
        if ($name === '') {
            throw new DriveException('Invalid file name.', 422);
        }
        DriveAdminSettings::assertNameAllowed($name);
        $this->assertQuota($uid, max(0, $size));
        $this->assertNodeBudget($uid);
        $sp = "u{$uid}/" . Str::uuid()->toString();
        $mime = $mime ? substr(preg_replace('/[^\x20-\x7E]/', '', $mime), 0, 120) : null;
        return [
            'put'   => $this->signedPut($sp, $mime),
            'token' => $this->sealPending(['kind' => 'new', 'uid' => $uid, 'sp' => $sp, 'parent' => $parent?->id, 'dirs' => $dirSegs, 'name' => $name, 'size' => $size, 'mime' => $mime]),
        ];
    }

    /** بعد ما المتصفح يخلّص الرفع: يتحقق إن الملف موجود فعلًا بالحجم/الحصة ويسجّل الـ node. */
    public function completeDirectUpload(int $uid, string $token): DriveNode
    {
        $this->assertDirect();
        $p = $this->openPending($uid, $token, 'new');
        $sp = (string) $p['sp'];
        if ($again = $this->q($uid)->where('storage_path', $sp)->first()) { // retry آمن
            return $again;
        }
        $disk = $this->disk();
        if (!$disk->exists($sp)) {
            throw new DriveException('The file did not reach storage. Please try again.', 422);
        }
        $size = (int) $disk->size($sp);
        try {
            $this->assertQuota($uid, $size);
            $this->assertNodeBudget($uid);
            return DB::transaction(function () use ($uid, $p, $sp, $size) {
                $parent = $p['parent'] !== null ? $this->folderOrRoot($uid, (int) $p['parent']) : null;
                $target = $p['dirs'] ? $this->ensureFolderPath($uid, $parent?->id, (array) $p['dirs']) : $parent?->id;
                $name = $this->freeName($uid, $target, (string) $p['name']);
                return DriveNode::create([
                    'user_id' => $uid, 'parent_id' => $target, 'type' => 'file', 'name' => $name,
                    'mime_type' => $p['mime'] ?: $this->detectMime($name, $name), 'size_bytes' => $size,
                    'storage_path' => $sp, 'sha1' => null,
                ]);
            });
        } catch (\Throwable $e) {
            $disk->delete($sp);
            throw $e;
        }
    }

    /** حفظ فوق ملف موجود (محررات): نفس الفكرة. */
    public function initDirectReplace(int $uid, int $id, int $size, ?string $mime): array
    {
        $this->assertDirect();
        $n = $this->find($uid, $id);
        if ($n->isFolder()) {
            throw new DriveException('A folder has no content.', 422);
        }
        $delta = $size - (int) $n->size_bytes;
        $this->assertQuota($uid, max(0, $delta));
        if ($size > $this->maxFileBytes($uid)) {
            throw new DriveException('The file is larger than the maximum allowed size (' . $this->maxFileMb($uid) . ' MB).', 413);
        }
        $sp = "u{$uid}/" . Str::uuid()->toString();
        $mime = $mime ? substr(preg_replace('/[^\x20-\x7E]/', '', $mime), 0, 120) : ($n->mime_type ?: null);
        return [
            'put'   => $this->signedPut($sp, $mime),
            'token' => $this->sealPending(['kind' => 'replace', 'uid' => $uid, 'sp' => $sp, 'node' => (int) $n->id, 'size' => $size, 'mime' => $mime]),
        ];
    }

    public function completeDirectReplace(int $uid, string $token, ?string $newName = null, ?string $sha1 = null): DriveNode
    {
        $this->assertDirect();
        $p = $this->openPending($uid, $token, 'replace');
        $sp = (string) $p['sp'];
        $n = $this->find($uid, (int) $p['node']);
        if ($n->storage_path === $sp) { // retry: already committed
            return $n;
        }
        $disk = $this->disk();
        if (!$disk->exists($sp)) {
            throw new DriveException('The file did not reach storage. Please try again.', 422);
        }
        $size = (int) $disk->size($sp);
        $committed = false; // بعد الحفظ في الـ DB البلوب الجديد مش يتيم
        try {
            $delta = $size - (int) $n->size_bytes;
            if ($delta > 0) {
                $this->assertQuota($uid, $delta);
            }
            if ($size > $this->maxFileBytes($uid)) {
                throw new DriveException('The file is larger than the maximum allowed size.', 413);
            }
            $old = $n->storage_path;
            $was = $this->versionAttrs($n);
            $newSha = $sha1 && preg_match('/^[a-f0-9]{40}$/i', $sha1) ? strtolower($sha1) : null;
            $kept = false;
            DB::transaction(function () use ($uid, $n, $sp, $size, $newSha, $p, $was, &$kept) {
                $n->forceFill([
                    'storage_path' => $sp, 'size_bytes' => $size, 'sha1' => $newSha,
                    'mime_type' => $p['mime'] ?: $n->mime_type,
                ])->save();
                $kept = $this->keepVersion($uid, (int) $n->id, $was, $newSha, 'save');
            });
            $committed = true;
            if ($old && !$kept) {
                $disk->delete($old);
            }
            if ($kept) {
                $this->pruneVersions($uid, (int) $n->id);
            }
        } finally {
            if (!$committed) {
                $disk->delete($sp);
            }
        }
        if ($newName !== null && $newName !== '' && DriveArchive::cleanName($newName) !== $n->name) {
            $this->rename($uid, (int) $n->id, $newName);
            $n->refresh();
        }
        return $n;
    }

    /** رابط تنزيل/عرض موقّع مباشر من الـ bucket. */
    public function directDownloadUrl(int $uid, int $id, bool $download = false): array
    {
        $this->assertDirect();
        $n = $this->find($uid, $id);
        if ($n->isFolder() || !$n->storage_path) {
            throw new DriveException('Item not found.', 404);
        }
        $name = (string) $n->name;
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name));
        $dispo = ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
        $url = $this->disk()->temporaryUrl((string) $n->storage_path, $this->directTtl(), [
            'ResponseContentType'        => $download ? 'application/octet-stream' : ($n->mime_type ?: 'application/octet-stream'),
            'ResponseContentDisposition' => $dispo,
        ]);
        $etag = '"' . substr(sha1($n->id . '|' . ($n->sha1 ?: '') . '|' . (int) $n->size_bytes . '|' . (string) $n->updated_at), 0, 32) . '"';
        return ['url' => $url, 'etag' => $etag, 'size_bytes' => (int) $n->size_bytes, 'expires_in' => (int) config('drive.direct_ttl_min') * 60];
    }

    /** قائمة كل الملفات والمجلدات الحية (من غير سلة) بالـ metadata بس — محرك النسخ الاحتياطي بيقارن بيها ويرفع الجديد/المتغير. */
    public function manifest(int $uid): array
    {
        return $this->q($uid)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'parent_id', 'type', 'name', 'size_bytes', 'sha1', 'updated_at'])
            ->map(fn (DriveNode $n) => [
                'id' => (int) $n->id, 'parent_id' => $n->parent_id !== null ? (int) $n->parent_id : null, 'type' => $n->type,
                'name' => $n->name, 'size_bytes' => $n->isFolder() ? 0 : (int) $n->size_bytes, 'sha1' => $n->sha1,
                'updated_at' => optional($n->updated_at)->toIso8601String(),
            ])->all();
    }

    // ---- rename / move / copy ------------------------------------------------------------------------

    public function rename(int $uid, int $id, string $name): DriveNode
    {
        $n = $this->find($uid, $id);
        $name = $this->validName($name);
        if (!$n->isFolder() && $name !== $n->name) {
            DriveAdminSettings::assertNameAllowed($name);
        }
        if ($name !== $n->name && $this->nameTaken($uid, $n->parent_id !== null ? (int) $n->parent_id : null, $name, (int) $n->id)) {
            throw new DriveException('An item with this name already exists here.', 409);
        }
        $n->forceFill(['name' => $name])->save();
        return $n;
    }

    /** @return int[] ids بتاعة node + كل أحفاده (غير المحذوفين). */
    private function subtreeIds(int $uid, DriveNode $root): array
    {
        $ids = [(int) $root->id];
        $frontier = [(int) $root->id];
        while ($frontier) {
            $kids = $this->q($uid)->whereNull('deleted_at')->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $ids = array_merge($ids, $kids);
            $frontier = $kids;
        }
        return $ids;
    }

    public function move(int $uid, array $ids, ?int $targetId): array
    {
        $target = $this->folderOrRoot($uid, $targetId);
        $moved = [];
        DB::transaction(function () use ($uid, $ids, $target, &$moved) {
            foreach ($this->cleanIds($ids) as $id) {
                $n = $this->find($uid, $id);
                if ($n->isFolder() && $target) {
                    if (in_array((int) $target->id, $this->subtreeIds($uid, $n), true)) {
                        throw new DriveException('You cannot move a folder into itself.', 422);
                    }
                }
                if (($n->parent_id !== null ? (int) $n->parent_id : null) === $target?->id) {
                    $moved[] = $n->toRowArray();
                    continue;
                }
                $n->forceFill([
                    'parent_id' => $target?->id,
                    'name'      => $this->freeName($uid, $target?->id, $n->name, (int) $n->id),
                ]);
                if ($target && $n->kind === 'disk') {
                    $n->kind = null; // a disk lives at the top level only — inside another folder it is just a folder
                }
                $n->save();
                $moved[] = $n->toRowArray();
            }
            $this->depthMemo = [];
            if ($target) {
                $this->assertDepthUnder($uid, (int) $target->id);
            }
        });
        return $moved;
    }

    /** بعد النقل: أعمق مجلد تحت الهدف مينفعش يعدّي max_depth. */
    private function assertDepthUnder(int $uid, int $folderId): void
    {
        $max = (int) config('drive.max_depth');
        $level = $this->depthOf($uid, $folderId);
        $frontier = [$folderId];
        while ($frontier) {
            $level++;
            $frontier = $this->q($uid)->whereNull('deleted_at')->where('type', 'folder')->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($frontier && $level > $max) {
                throw new DriveException('Folders are nested too deeply.', 422);
            }
        }
    }

    public function copy(int $uid, array $ids, ?int $targetId): array
    {
        $target = $this->folderOrRoot($uid, $targetId);
        $nodes = array_map(fn ($id) => $this->find($uid, $id), $this->cleanIds($ids));

        // الحجم الكلي والعدد قبل أي نسخ فعلية
        $totalBytes = 0;
        $count = 0;
        foreach ($nodes as $n) {
            if ($n->isFolder() && $target && in_array((int) $target->id, $this->subtreeIds($uid, $n), true)) {
                throw new DriveException('You cannot copy a folder into itself.', 422);
            }
            $sub = $this->q($uid)->whereIn('id', $this->subtreeIds($uid, $n))->get(['type', 'size_bytes']);
            $count += $sub->count();
            $totalBytes += (int) $sub->where('type', 'file')->sum('size_bytes');
        }
        if ($this->usedBytes($uid) + $totalBytes > $this->quotaBytes($uid)) {
            throw new DriveException('Not enough storage space in My PC.', 413);
        }
        $this->assertNodeBudget($uid, $count);

        $written = [];
        $out = [];
        try {
            DB::transaction(function () use ($uid, $nodes, $target, &$written, &$out) {
                foreach ($nodes as $n) {
                    $out[] = $this->copyNode($uid, $n, $target?->id, $written)->toRowArray();
                }
            });
        } catch (\Throwable $e) {
            foreach ($written as $sp) {
                $this->disk()->delete($sp);
            }
            throw $e;
        }
        return $out;
    }

    private function copyNode(int $uid, DriveNode $n, ?int $parentId, array &$written): DriveNode
    {
        $name = $this->freeName($uid, $parentId, $n->name);
        if ($n->isFolder()) {
            if ($this->depthOf($uid, $parentId) + 1 > (int) config('drive.max_depth')) {
                throw new DriveException('Folders are nested too deeply.', 422);
            }
            $copy = $this->insertNode($uid, $parentId, 'folder', $name, ['kind' => $n->kind]);
            foreach ($this->q($uid)->whereNull('deleted_at')->where('parent_id', $n->id)->get() as $child) {
                $this->copyNode($uid, $child, (int) $copy->id, $written);
            }
            return $copy;
        }
        $sp = "u{$uid}/" . Str::uuid()->toString();
        if (!$n->storage_path || !$this->disk()->copy($n->storage_path, $sp)) {
            throw new DriveException('Could not copy a file.', 500);
        }
        $written[] = $sp;
        return $this->insertNode($uid, $parentId, 'file', $name, [
            'mime_type' => $n->mime_type, 'size_bytes' => $n->size_bytes, 'storage_path' => $sp, 'sha1' => $n->sha1,
        ]);
    }

    private function cleanIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            throw new DriveException('Nothing selected.', 422);
        }
        if (count($ids) > 500) {
            throw new DriveException('Too many items selected.', 422);
        }
        return $ids;
    }

    /**
     * بيانات عناصر بالـ id (لاختصارات سطح المكتب): items = الحيّة، gone = اللي اتمسحت نهائيًا (مش موجودة خالص — الاختصار بيتشال).
     * اللي في السلة مش بترجع في الاتنين: الاختصار بيستنى لحد ما تترجّع.
     * @return array{items:array,gone:int[]}
     */
    public function resolveNodes(int $uid, array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0))), 0, 100);
        if (!$ids) {
            return ['items' => [], 'gone' => []];
        }
        $rows = $this->q($uid)->whereIn('id', $ids)->get();
        $crumbs = [];
        $items = [];
        foreach ($rows as $n) {
            if ($n->deleted_at !== null) {
                continue;
            }
            $pid = $n->parent_id !== null ? (int) $n->parent_id : null;
            $crumbs[$pid ?? 0] ??= implode('/', array_column($this->breadcrumb($uid, $pid), 'name'));
            $r = $n->toRowArray();
            $r['path'] = $crumbs[$pid ?? 0];
            $items[] = $r;
        }
        return ['items' => $items, 'gone' => array_values(array_diff($ids, $rows->pluck('id')->map(fn ($i) => (int) $i)->all()))];
    }

    // ---- storage monitor -----------------------------------------------------------------------------

    private const CATEGORIES = [
        'images'    => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'avif', 'heic', 'tif', 'tiff', 'ico'],
        'video'     => ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v', 'wmv', 'flv'],
        'audio'     => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma'],
        'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'md', 'csv', 'epub'],
        'code'      => ['js', 'jsx', 'ts', 'tsx', 'py', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'php', 'rb', 'go', 'rs', 'kt', 'swift', 'html', 'htm', 'css', 'scss', 'json', 'xml', 'yml', 'yaml', 'sql', 'sh', 'vue', 'ipynb'],
        'archives'  => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'iso'],
    ];

    private function categoryOf(string $name): string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        foreach (self::CATEGORIES as $cat => $exts) {
            if (in_array($ext, $exts, true)) {
                return $cat;
            }
        }
        return 'other';
    }

    /**
     * "إيه اللي واخد المساحة": حصة + تصنيف الملفات + أكبر الملفات والمجلدات + السلة + النسخ القديمة + الملفات المكررة (نفس الـ sha1).
     * بيقرا كل عناصر المستخدم مرة واحدة (سقف DRIVE_MAX_NODES) ويحسب في الذاكرة.
     */
    public function storageReport(int $uid): array
    {
        $rows = $this->q($uid)->get(['id', 'parent_id', 'type', 'name', 'size_bytes', 'sha1', 'deleted_at', 'updated_at']);
        $byId = [];
        foreach ($rows as $n) {
            $byId[(int) $n->id] = $n;
        }
        $pathOf = function (?int $pid) use (&$byId): string {
            $parts = [];
            $g = 0;
            while ($pid !== null && isset($byId[$pid]) && $g++ < 64) {
                array_unshift($parts, $byId[$pid]->name);
                $pid = $byId[$pid]->parent_id !== null ? (int) $byId[$pid]->parent_id : null;
            }
            return implode('/', $parts);
        };

        $cats = [];
        $folderSize = [];
        $files = [];
        $bySha = [];
        $trashBytes = 0;
        $trashCount = 0;
        $liveBytes = 0;
        foreach ($rows as $n) {
            if ($n->type !== 'file') {
                continue;
            }
            $size = (int) $n->size_bytes;
            if ($n->deleted_at !== null) {
                $trashBytes += $size;
                $trashCount++;
                continue;
            }
            $liveBytes += $size;
            $cat = $this->categoryOf((string) $n->name);
            $cats[$cat] ??= ['key' => $cat, 'bytes' => 0, 'count' => 0];
            $cats[$cat]['bytes'] += $size;
            $cats[$cat]['count']++;
            $files[] = [$size, $n, $cat];
            if ($size > 0 && $n->sha1) {
                $bySha[$n->sha1][] = $n;
            }
            $pid = $n->parent_id !== null ? (int) $n->parent_id : null;
            $g = 0;
            while ($pid !== null && isset($byId[$pid]) && $g++ < 64) {
                $folderSize[$pid] = ($folderSize[$pid] ?? 0) + $size;
                $pid = $byId[$pid]->parent_id !== null ? (int) $byId[$pid]->parent_id : null;
            }
        }

        usort($files, fn ($a, $b) => $b[0] <=> $a[0]);
        $largest = [];
        foreach (array_slice($files, 0, 40) as [$size, $n, $cat]) {
            if ($size <= 0) {
                break;
            }
            $largest[] = [
                'id' => (int) $n->id, 'name' => $n->name, 'size_bytes' => $size, 'size_human' => DriveNode::humanSize($size), 'category' => $cat,
                'parent_id' => $n->parent_id !== null ? (int) $n->parent_id : null, 'path' => $pathOf($n->parent_id !== null ? (int) $n->parent_id : null),
                'updated_at' => $n->updated_at,
            ];
        }

        arsort($folderSize);
        $folders = [];
        foreach (array_slice($folderSize, 0, 10, true) as $fid => $size) {
            $f = $byId[$fid] ?? null;
            if (!$f || $f->deleted_at !== null || $size <= 0) {
                continue;
            }
            $folders[] = [
                'id' => (int) $fid, 'name' => $f->name, 'size_bytes' => (int) $size, 'size_human' => DriveNode::humanSize((int) $size),
                'path' => $pathOf($f->parent_id !== null ? (int) $f->parent_id : null),
            ];
        }

        $dups = [];
        foreach ($bySha as $sha => $list) {
            if (count($list) < 2) {
                continue;
            }
            usort($list, fn ($a, $b) => (int) $a->id <=> (int) $b->id); // الأقدم الأول = اللي بنحتفظ بيه
            $size = (int) $list[0]->size_bytes;
            $dups[] = [
                'size_bytes' => $size, 'size_human' => DriveNode::humanSize($size), 'count' => count($list), 'wasted_bytes' => $size * (count($list) - 1),
                'files' => array_map(fn ($n) => [
                    'id' => (int) $n->id, 'name' => $n->name, 'path' => $pathOf($n->parent_id !== null ? (int) $n->parent_id : null), 'updated_at' => $n->updated_at,
                ], array_slice($list, 0, 20)),
            ];
        }
        usort($dups, fn ($a, $b) => $b['wasted_bytes'] <=> $a['wasted_bytes']);
        $dups = array_slice($dups, 0, 15);
        $wasted = array_sum(array_column($dups, 'wasted_bytes'));

        $ver = DriveNodeVersion::query()->where('user_id', $uid)->selectRaw('COALESCE(SUM(size_bytes),0) as b, COUNT(*) as c')->first();
        $cats = array_values($cats);
        usort($cats, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        return [
            'usage'      => $this->usage($uid),
            'live_bytes' => $liveBytes,
            'categories' => $cats,
            'largest'    => $largest,
            'folders'    => $folders,
            'trash'      => ['bytes' => $trashBytes, 'count' => $trashCount, 'human' => DriveNode::humanSize($trashBytes)],
            'versions'   => ['bytes' => (int) ($ver->b ?? 0), 'count' => (int) ($ver->c ?? 0), 'human' => DriveNode::humanSize((int) ($ver->b ?? 0))],
            'duplicates' => ['groups' => $dups, 'wasted_bytes' => (int) $wasted, 'wasted_human' => DriveNode::humanSize((int) $wasted)],
        ];
    }

    /** يمسح كل النسخ القديمة (سجل الإصدارات) لكل ملفات المستخدم. @return int البايتات اللي اتحرّرت */
    public function clearAllVersions(int $uid): int
    {
        $freed = 0;
        DriveNodeVersion::query()->where('user_id', $uid)->orderBy('id')->chunkById(200, function ($rows) use (&$freed) {
            $freed += (int) $rows->sum('size_bytes');
            $this->dropVersionRows($rows);
        });
        return $freed;
    }

    // ---- recycle bin ---------------------------------------------------------------------------------

    // ---- disks ("أقراص") ---------------------------------------------------------------------------
    // A disk is a TOP-LEVEL folder with kind = 'disk' (no schema change). It shows up as a drive card with a storage bar.

    /** Disks with their used space. Sizes come from ONE query over the user's tree (bounded by drive.max_nodes). */
    public function disks(int $uid): array
    {
        $rows = $this->q($uid)->whereNull('deleted_at')->get(['id', 'parent_id', 'type', 'kind', 'size_bytes']);
        $kids = [];
        foreach ($rows as $r) {
            $kids[(int) ($r->parent_id ?? 0)][] = $r;
        }
        $quota = $this->quotaBytes($uid);
        $out = [];
        foreach ($rows as $r) {
            if ($r->parent_id !== null || $r->type !== 'folder' || $r->kind !== 'disk') {
                continue;
            }
            $bytes = 0;
            $files = 0;
            $stack = [(int) $r->id];
            while ($stack) {
                $cur = array_pop($stack);
                foreach ($kids[$cur] ?? [] as $k) {
                    if ($k->type === 'folder') {
                        $stack[] = (int) $k->id;
                    } else {
                        $bytes += (int) $k->size_bytes;
                        $files++;
                    }
                }
            }
            $out[(int) $r->id] = ['bytes' => $bytes, 'files' => $files];
        }
        $names = $this->q($uid)->whereIn('id', array_keys($out) ?: [0])->orderBy('name')->get();
        return $names->map(function (DriveNode $n) use ($out, $quota) {
            $u = $out[(int) $n->id];
            return $n->toRowArray() + [
                'used_bytes'  => $u['bytes'],
                'used_human'  => DriveNode::humanSize($u['bytes']),
                'files_count' => $u['files'],
                'percent'     => $quota > 0 ? round($u['bytes'] * 100 / $quota, 1) : 0, // share of the whole My PC quota
                'quota_bytes' => $quota,
                'quota_human' => DriveNode::humanSize($quota),
            ];
        })->values()->all();
    }

    public function createDisk(int $uid, string $name): DriveNode
    {
        return $this->createFolder($uid, null, $name, 'disk');
    }

    /** Turn a top-level folder into a disk (or back into a plain folder). Project folders keep their project behaviour. */
    public function setDisk(int $uid, int $id, bool $on): DriveNode
    {
        $n = $this->find($uid, $id);
        if (!$n->isFolder() || $n->parent_id !== null) {
            throw new DriveException('Only a folder at the top of My PC can be a disk.', 422);
        }
        if ($n->kind === 'project') {
            throw new DriveException('A project folder cannot be a disk.', 422);
        }
        $n->forceFill(['kind' => $on ? 'disk' : null])->save();
        return $n;
    }

    public function trash(int $uid, array $ids): int
    {
        $n = 0;
        DB::transaction(function () use ($uid, $ids, &$n) {
            foreach ($this->cleanIds($ids) as $id) {
                $root = $this->find($uid, $id);
                $all = $this->subtreeIds($uid, $root);
                $this->q($uid)->whereIn('id', $all)->update(['deleted_at' => now(), 'trash_root_id' => $root->id]);
                $this->q($uid)->where('id', $root->id)->update(['prev_parent_id' => $root->parent_id]);
                $n++;
            }
        });
        return $n;
    }

    /** عناصر السلة (اللي المستخدم حذفها فعلًا — مش أحفادها). */
    public function trashList(int $uid): array
    {
        return $this->q($uid)->whereNotNull('deleted_at')->whereColumn('id', 'trash_root_id')
            ->orderByDesc('deleted_at')->get()->map(function (DriveNode $n) use ($uid) {
                $r = $n->toRowArray();
                if ($n->isFolder()) {
                    $r['contains'] = $this->q($uid)->where('trash_root_id', $n->id)->where('id', '!=', $n->id)->count();
                }
                $r['purge_at'] = $n->deleted_at ? $n->deleted_at->copy()->addDays((int) config('drive.trash_days')) : null;
                return $r;
            })->all();
    }

    public function restore(int $uid, array $ids): array
    {
        $out = [];
        DB::transaction(function () use ($uid, $ids, &$out) {
            foreach ($this->cleanIds($ids) as $id) {
                $root = $this->find($uid, $id, true);
                if ((int) $root->trash_root_id !== (int) $root->id) {
                    throw new DriveException('Restore the containing folder instead.', 422);
                }
                $parent = $root->prev_parent_id !== null
                    ? $this->q($uid)->whereNull('deleted_at')->where('type', 'folder')->where('id', $root->prev_parent_id)->first()
                    : null;
                $pid = $parent?->id;
                $name = $this->freeName($uid, $pid, $root->name, (int) $root->id);
                $this->q($uid)->where('trash_root_id', $root->id)->update(['deleted_at' => null, 'trash_root_id' => null]);
                $this->q($uid)->where('id', $root->id)->update(['parent_id' => $pid, 'name' => $name, 'prev_parent_id' => null]);
                $out[] = $root->fresh()->toRowArray();
            }
        });
        return $out;
    }

    private function purgeRoots(int $uid, array $rootIds): int
    {
        $freed = 0;
        foreach (array_chunk($rootIds, 100) as $chunk) {
            $rows = $this->q($uid)->whereIn('trash_root_id', $chunk)->get(['id', 'type', 'storage_path']);
            foreach ($rows as $r) {
                if ($r->type === 'file' && $r->storage_path) {
                    $this->disk()->delete($r->storage_path);
                }
            }
            $fileIds = $rows->where('type', 'file')->pluck('id')->all();
            if ($fileIds) { // their old versions go with them
                $this->dropVersionRows(DriveNodeVersion::query()->where('user_id', $uid)->whereIn('node_id', $fileIds)->get());
            }
            if ($fileIds) { // والفهرس النصي بتاعهم (البحث في المحتوى)
                DB::table('drive_node_text')->whereIn('node_id', $fileIds)->delete();
            }
            $this->q($uid)->whereIn('trash_root_id', $chunk)->delete();
            $freed += count($chunk);
        }
        return $freed;
    }

    public function purge(int $uid, array $ids): int
    {
        $roots = [];
        foreach ($this->cleanIds($ids) as $id) {
            $r = $this->find($uid, $id, true);
            if ((int) $r->trash_root_id === (int) $r->id) {
                $roots[] = (int) $r->id;
            }
        }
        return $this->purgeRoots($uid, $roots);
    }

    public function emptyTrash(int $uid): int
    {
        $roots = $this->q($uid)->whereNotNull('deleted_at')->whereColumn('id', 'trash_root_id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        return $this->purgeRoots($uid, $roots);
    }

    /**
     * إعادة تهيئة "جهازي" (الخطوة الأولى): كل العناصر الجذرية بتتنقل للسلة (مش بتتمسح) عشان يبقى فيه تراجع.
     * المسح النهائي بعدها في finalizeReset(). @return int[] ids الجذور اللي اتنقلت
     */
    public function softResetAll(int $uid): array
    {
        $roots = $this->q($uid)->whereNull('deleted_at')->whereNull('parent_id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach (array_chunk($roots, 400) as $chunk) {
            $this->trash($uid, $chunk);
        }
        return $roots;
    }

    /** المسح النهائي لجذور إعادة تهيئة: الملفات + النسخ القديمة + الفهرس النصي + روابط المشاركة بتاعتها. */
    public function finalizeReset(int $uid, array $roots): int
    {
        $roots = array_values(array_filter(array_map('intval', $roots), fn ($i) => $i > 0));
        if (!$roots) {
            return 0;
        }
        $nodeIds = $this->q($uid)->whereIn('trash_root_id', $roots)->pluck('id')->all();
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            if (\Illuminate\Support\Facades\Schema::hasTable('drive_shares')) {
                DB::table('drive_shares')->where('user_id', $uid)->whereIn('node_id', $chunk)->delete();
            }
        }
        return $this->purgeRoots($uid, $roots);
    }

    /** أمر drive:purge-trash — بيمسح كل اللي عدّى trash_days، لكل المستخدمين. */
    public function purgeExpired(): int
    {
        $cut = now()->subDays((int) config('drive.trash_days'));
        $n = 0;
        DriveNode::query()->whereNotNull('deleted_at')->whereColumn('id', 'trash_root_id')->where('deleted_at', '<', $cut)
            ->orderBy('id')->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows->groupBy('user_id') as $uid => $g) {
                    $n += $this->purgeRoots((int) $uid, $g->pluck('id')->map(fn ($i) => (int) $i)->all());
                }
            });
        return $n;
    }

    // ---- version history -----------------------------------------------------------------------------
    // لما ملف بيتحفظ فوق نفسه، النسخة القديمة (الـ blob) بتفضل على الـ disk وبتتسجّل في drive_node_versions بدل ما تتمسح.
    // السقف: DRIVE_MAX_VERSIONS لكل ملف، و DRIVE_VERSIONS_MB لكل طالب (الأقدم بيتمسح)، وملفات أكبر من DRIVE_VERSION_MAX_MB مالهاش سجل.

    /** @return array{storage_path:?string,size_bytes:int,sha1:?string,mime_type:?string,name:string} */
    private function versionAttrs(DriveNode $n): array
    {
        return [
            'storage_path' => $n->storage_path, 'size_bytes' => (int) $n->size_bytes, 'sha1' => $n->sha1,
            'mime_type' => $n->mime_type, 'name' => (string) $n->name,
        ];
    }

    /**
     * يسجّل الـ blob القديم كنسخة. true = اتحفظ (الكولر ما يمسحوش من الـ disk). false = مفيش داعي (محتوى مطابق / كبير / السجل مقفول).
     * لازم يتنده جوّه الـ transaction بتاعة التحديث، وبعدها pruneVersions().
     */
    private function keepVersion(int $uid, int $nodeId, array $was, ?string $newSha1, string $reason): bool
    {
        if ((int) config('drive.max_versions') < 1 || empty($was['storage_path'])) {
            return false;
        }
        if ((int) $was['size_bytes'] > (int) config('drive.version_max_mb') * 1048576) {
            return false;
        }
        $sha = $was['sha1'] ?? null;
        if ($sha && $newSha1 && $sha === $newSha1) {
            return false; // نفس المحتوى: مفيش حاجة تترجّع
        }
        if ($sha) {
            $last = DriveNodeVersion::query()->where('user_id', $uid)->where('node_id', $nodeId)->orderByDesc('id')->first(['sha1']);
            if ($last && $last->sha1 === $sha) {
                return false; // آخر نسخة محفوظة هي نفسها
            }
        }
        DriveNodeVersion::create([
            'user_id' => $uid, 'node_id' => $nodeId, 'name' => $was['name'], 'mime_type' => $was['mime_type'],
            'size_bytes' => (int) $was['size_bytes'], 'storage_path' => $was['storage_path'], 'sha1' => $sha,
            'reason' => $reason, 'created_at' => now(),
        ]);
        return true;
    }

    private function dropVersionRows($rows): void
    {
        foreach ($rows as $v) {
            try {
                $this->disk()->delete($v->storage_path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $ids = collect($rows)->pluck('id')->all();
        if ($ids) {
            DriveNodeVersion::query()->whereIn('id', $ids)->delete();
        }
    }

    /** يقصّ الأقدم: أكتر من max_versions للملف، وأكتر من versions_mb للطالب كله (آخر نسخة للملف الحالي مبتتمسحش). */
    private function pruneVersions(int $uid, int $nodeId): void
    {
        $max = max(1, (int) config('drive.max_versions'));
        $extra = DriveNodeVersion::query()->where('user_id', $uid)->where('node_id', $nodeId)->orderByDesc('id')->get()->slice($max);
        if ($extra->isNotEmpty()) {
            $this->dropVersionRows($extra);
        }
        $cap = (int) config('drive.versions_mb') * 1048576;
        $total = (int) DriveNodeVersion::query()->where('user_id', $uid)->sum('size_bytes');
        if ($total <= $cap) {
            return;
        }
        $newest = (int) DriveNodeVersion::query()->where('user_id', $uid)->where('node_id', $nodeId)->max('id');
        $drop = [];
        foreach (DriveNodeVersion::query()->where('user_id', $uid)->where('id', '!=', $newest)->orderBy('id')->cursor() as $v) {
            if ($total <= $cap) {
                break;
            }
            $drop[] = $v;
            $total -= (int) $v->size_bytes;
        }
        $this->dropVersionRows($drop);
    }

    private function findVersion(int $uid, int $nodeId, int $versionId): DriveNodeVersion
    {
        $this->find($uid, $nodeId); // الملف لازم يكون ملكه وغير محذوف
        $v = DriveNodeVersion::query()->where('user_id', $uid)->where('node_id', $nodeId)->where('id', $versionId)->first();
        if (!$v) {
            throw new DriveException('Version not found.', 404);
        }
        return $v;
    }

    /** @return array{node:array,items:array<int,array>,limit:int} الأحدث الأول */
    public function versions(int $uid, int $nodeId): array
    {
        $n = $this->find($uid, $nodeId);
        if ($n->isFolder()) {
            throw new DriveException('A folder has no versions.', 422);
        }
        $rows = DriveNodeVersion::query()->where('user_id', $uid)->where('node_id', $nodeId)->orderByDesc('id')->get();
        return [
            'node'  => $n->toRowArray(),
            'items' => $rows->map(fn (DriveNodeVersion $v) => $v->toRowArray())->all(),
            'limit' => (int) config('drive.max_versions'),
        ];
    }

    /** @return array{0:DriveNodeVersion,1:resource} */
    public function openVersionStream(int $uid, int $nodeId, int $versionId): array
    {
        $v = $this->findVersion($uid, $nodeId, $versionId);
        $s = $this->disk()->readStream($v->storage_path);
        if (!$s) {
            throw new DriveException('This version is missing from storage.', 404);
        }
        return [$v, $s];
    }

    /** يرجّع الملف لنسخة قديمة. الحالي بيتحفظ كنسخة جديدة، فالاسترجاع نفسه ممكن يتراجع عنه. */
    public function restoreVersion(int $uid, int $nodeId, int $versionId): DriveNode
    {
        $v = $this->findVersion($uid, $nodeId, $versionId);
        $n = $this->find($uid, $nodeId);
        $delta = (int) $v->size_bytes - (int) $n->size_bytes;
        if ($delta > 0) {
            $this->assertQuota($uid, $delta);
        }
        $disk = $this->disk();
        $sp = "u{$uid}/" . Str::uuid()->toString();
        try {
            if (!$disk->copy($v->storage_path, $sp)) {
                throw new DriveException('Could not restore this version.', 500);
            }
        } catch (DriveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DriveException('This version is missing from storage.', 404);
        }
        $old = $n->storage_path;
        $was = $this->versionAttrs($n);
        $kept = false;
        try {
            DB::transaction(function () use ($uid, $n, $v, $sp, $was, &$kept) {
                $n->forceFill([
                    'storage_path' => $sp, 'size_bytes' => (int) $v->size_bytes, 'sha1' => $v->sha1,
                    'mime_type' => $v->mime_type ?: $n->mime_type,
                ])->save();
                $kept = $this->keepVersion($uid, (int) $n->id, $was, $v->sha1, 'restore');
            });
        } catch (\Throwable $e) {
            $disk->delete($sp);
            throw $e;
        }
        if ($old && !$kept) {
            $disk->delete($old);
        }
        $this->pruneVersions($uid, (int) $n->id);
        return $n->refresh();
    }

    public function deleteVersion(int $uid, int $nodeId, int $versionId): void
    {
        $v = $this->findVersion($uid, $nodeId, $versionId);
        $this->dropVersionRows([$v]);
    }

    // ---- projects ------------------------------------------------------------------------------------

    private const TEMPLATES = [
        'blank'  => ['README.md' => "# {name}\n\n"],
        'web'    => [
            'index.html' => "<!doctype html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>{name}</title>\n  <link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n  <h1>{name}</h1>\n  <script src=\"script.js\"></script>\n</body>\n</html>\n",
            'style.css'  => "body { font-family: system-ui, sans-serif; margin: 2rem; }\n",
            'script.js'  => "console.log('Hello from {name}');\n",
        ],
        'node'   => [
            'package.json' => "{\n  \"name\": \"{slug}\",\n  \"version\": \"1.0.0\",\n  \"main\": \"index.js\",\n  \"scripts\": { \"start\": \"node index.js\" }\n}\n",
            'index.js'     => "console.log('Hello from {name}');\n",
        ],
        'python' => [
            'main.py'          => "print('Hello from {name}')\n",
            'requirements.txt' => "",
        ],
    ];

    public static function templateKeys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public function createProject(int $uid, ?int $parentId, string $name, string $template = 'blank'): DriveNode
    {
        $tpl = self::TEMPLATES[$template] ?? null;
        if ($tpl === null) {
            throw new DriveException('Unknown project template.', 422);
        }
        return DB::transaction(function () use ($uid, $parentId, $name, $tpl) {
            $folder = $this->createFolder($uid, $parentId, $name, 'project');
            $slug = Str::slug($folder->name) ?: 'project';
            foreach ($tpl as $fname => $body) {
                $this->createTextFile($uid, (int) $folder->id, $fname, str_replace(['{name}', '{slug}'], [$folder->name, $slug], $body));
            }
            return $folder;
        });
    }

    // ---- zip -----------------------------------------------------------------------------------------

    /** مسار محلي لملف (لو الـ disk مش local بينسخه لـ temp وبيضيفه لـ $temps للتنضيف). */
    private function materialize(DriveNode $n, array &$temps): string
    {
        $disk = $this->disk();
        if (config('filesystems.disks.' . config('drive.disk') . '.driver') === 'local' && method_exists($disk, 'path')) {
            $p = $disk->path((string) $n->storage_path);
            if (is_file($p)) {
                return $p;
            }
            throw new DriveException('A file is missing from storage.', 404);
        }
        $s = $disk->readStream((string) $n->storage_path);
        if (!$s) {
            throw new DriveException('A file is missing from storage.', 404);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'drm');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($s, $out);
        fclose($out);
        fclose($s);
        $temps[] = $tmp;
        return $tmp;
    }

    /**
     * يبني zip مؤقت من عناصر (ملفات/مجلدات) — المجلد المفرد بيتضغط محتواه تحت اسمه.
     * @return array{0:string,1:string} [مسار الـ zip المؤقت، اسم مقترح]
     */
    public function buildZip(int $uid, array $ids, bool $folderContentsAtRoot = false): array
    {
        $nodes = array_map(fn ($id) => $this->find($uid, $id), $this->cleanIds($ids));
        $maxEntries = (int) config('drive.max_zip_entries');
        $maxBytes = $this->maxZipBytes();

        $entries = []; // [zipPath => node|null(dir)]
        $total = 0;
        $walk = function (DriveNode $n, string $prefix) use (&$walk, &$entries, &$total, $uid, $maxEntries, $maxBytes) {
            $path = $prefix . $n->name;
            if ($n->isFolder()) {
                $entries[$path . '/'] = null;
                foreach ($this->q($uid)->whereNull('deleted_at')->where('parent_id', $n->id)->orderBy('name')->get() as $c) {
                    $walk($c, $path . '/');
                }
                return;
            }
            $total += (int) $n->size_bytes;
            $entries[$path] = $n;
            if (count($entries) > $maxEntries) {
                throw new DriveException('Too many items to compress.', 422);
            }
            if ($total > $maxBytes) {
                throw new DriveException('The selection is too large to compress.', 413);
            }
        };
        if ($folderContentsAtRoot && count($nodes) === 1 && $nodes[0]->isFolder()) {
            foreach ($this->q($uid)->whereNull('deleted_at')->where('parent_id', $nodes[0]->id)->orderBy('name')->get() as $c) {
                $walk($c, '');
            }
        } else {
            foreach ($nodes as $n) {
                $walk($n, '');
            }
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'drz') . '.zip';
        $temps = [];
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DriveException('Could not create the archive.', 500);
        }
        try {
            foreach ($entries as $path => $n) {
                if ($n === null) {
                    $zip->addEmptyDir(rtrim($path, '/'));
                } else {
                    $zip->addFile($this->materialize($n, $temps), $path);
                    $zip->setCompressionName($path, ZipArchive::CM_DEFLATE);
                }
            }
            if (!$zip->close()) {
                throw new DriveException('Could not create the archive.', 500);
            }
        } catch (\Throwable $e) {
            @unlink($tmpZip);
            throw $e;
        } finally {
            // ZipArchive بيقرا الملفات وقت close() — فمسح الـ temps بعده بس.
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
        $label = count($nodes) === 1 ? $nodes[0]->name : 'Archive';
        return [$tmpZip, $label . '.zip'];
    }

    /** "ضغط" داخل جهازي: ينشئ ملف zip جديد في $parentId. */
    public function zipInto(int $uid, array $ids, ?int $parentId, ?string $name = null): DriveNode
    {
        $parent = $this->folderOrRoot($uid, $parentId);
        [$tmp, $suggest] = $this->buildZip($uid, $ids);
        try {
            $final = $name !== null && trim($name) !== '' ? $this->validName($name) : $suggest;
            if (!str_ends_with(strtolower($final), '.zip')) {
                $final .= '.zip';
            }
            $final = $this->freeName($uid, $parent?->id, $final);
            return $this->putLocalFile($uid, $parent?->id, $final, $tmp, 'application/zip');
        } finally {
            @unlink($tmp);
        }
    }

    /** فك zip داخل مجلد جديد باسم الأرشيف (زي "Extract to name/"). */
    public function unzip(int $uid, int $id, ?int $parentId = null): DriveNode
    {
        $n = $this->find($uid, $id);
        if ($n->isFolder() || strtolower((string) pathinfo($n->name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new DriveException('This is not a ZIP file.', 422);
        }
        $parentId ??= $n->parent_id !== null ? (int) $n->parent_id : null;
        $parent = $this->folderOrRoot($uid, $parentId);

        $temps = [];
        $local = $this->materialize($n, $temps);
        $zip = new ZipArchive();
        if ($zip->open($local) !== true) {
            foreach ($temps as $t) {
                @unlink($t);
            }
            throw new DriveException('The ZIP file is corrupted.', 422);
        }
        $written = [];
        try {
            $entries = DriveArchive::scan($zip, (int) config('drive.max_zip_entries'), $this->maxZipBytes());
            $folderName = preg_replace('/\.zip$/i', '', $n->name) ?: 'Extracted';
            return DB::transaction(function () use ($uid, $parent, $folderName, $zip, $entries, &$written) {
                $rootFolder = $this->createFolder($uid, $parent?->id, $folderName);
                $this->extractEntries($uid, (int) $rootFolder->id, $zip, $entries, $written);
                return $rootFolder;
            });
        } catch (\Throwable $e) {
            foreach ($written as $sp) {
                $this->disk()->delete($sp);
            }
            throw $e;
        } finally {
            $zip->close();
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    /** يكتب عناصر الـ zip تحت $baseId (ملفات جديدة بس — بيدمج المجلدات). */
    private function extractEntries(int $uid, int $baseId, ZipArchive $zip, array $entries, array &$written): void
    {
        $budget = $this->maxZipBytes();
        usort($entries, fn ($a, $b) => count($a['segments']) <=> count($b['segments']));
        foreach ($entries as $e) {
            if ($e['dir']) {
                $this->ensureFolderPath($uid, $baseId, $e['segments']);
                continue;
            }
            $segs = $e['segments'];
            $fname = array_pop($segs);
            $dir = $segs ? $this->ensureFolderPath($uid, $baseId, $segs) : $baseId;
            $tmp = tempnam(sys_get_temp_dir(), 'dre');
            try {
                $size = DriveArchive::copyEntry($zip, (int) $e['index'], $tmp, $budget);
                $budget -= $size;
                $name = $this->freeName($uid, $dir, $fname);
                $node = $this->putLocalFile($uid, $dir, $name, $tmp);
                $written[] = (string) $node->storage_path;
            } finally {
                @unlink($tmp);
            }
        }
    }

    /**
     * حفظ من محرر الـ ZIP/Workbench: الـ zip (محتوى المجلد في الجذر) بيتزامن مع المجلد —
     * ملفات اتغيّرت بتتستبدل، جديدة بتتضاف، واللي اتشال من الـ zip بيروح السلة (قابل للاسترجاع).
     * @return array{added:int,updated:int,removed:int}
     */
    public function syncFolderFromZip(int $uid, int $folderId, UploadedFile $upload): array
    {
        if (!$upload->isValid()) {
            throw new DriveException('The upload failed. Please try again.', 422);
        }
        $folder = $this->find($uid, $folderId);
        if (!$folder->isFolder()) {
            throw new DriveException('The destination is not a folder.', 422);
        }
        $zip = new ZipArchive();
        if ($zip->open($upload->getRealPath()) !== true) {
            throw new DriveException('The ZIP file is corrupted.', 422);
        }
        $written = [];
        $oldBlobs = [];
        $touched = []; // files whose previous content was kept as a version
        $stats = ['added' => 0, 'updated' => 0, 'removed' => 0];
        try {
            $entries = DriveArchive::scan($zip, (int) config('drive.max_zip_entries'), $this->maxZipBytes());

            // الموجود حاليًا: relpath => node
            $existing = [];
            $walk = function (int $parent, string $prefix) use (&$walk, &$existing, $uid) {
                foreach ($this->q($uid)->whereNull('deleted_at')->where('parent_id', $parent)->get() as $c) {
                    $existing[$prefix . $c->name] = $c;
                    if ($c->isFolder()) {
                        $walk((int) $c->id, $prefix . $c->name . '/');
                    }
                }
            };
            $walk($folderId, '');

            DB::transaction(function () use ($uid, $folderId, $zip, $entries, &$existing, &$written, &$oldBlobs, &$stats, &$touched) {
                usort($entries, fn ($a, $b) => count($a['segments']) <=> count($b['segments']));
                $keep = [];
                $budget = $this->maxZipBytes();
                foreach ($entries as $e) {
                    $rel = implode('/', $e['segments']);
                    for ($i = 1; $i < count($e['segments']); $i++) {
                        $keep[implode('/', array_slice($e['segments'], 0, $i))] = true;
                    }
                    $keep[$rel] = true;
                    if ($e['dir']) {
                        $this->ensureFolderPath($uid, $folderId, $e['segments']);
                        continue;
                    }
                    $segs = $e['segments'];
                    $fname = array_pop($segs);
                    $dir = $segs ? $this->ensureFolderPath($uid, $folderId, $segs) : $folderId;
                    $tmp = tempnam(sys_get_temp_dir(), 'dsy');
                    try {
                        $size = DriveArchive::copyEntry($zip, (int) $e['index'], $tmp, $budget);
                        $budget -= $size;
                        $cur = $existing[$rel] ?? null;
                        if ($cur && !$cur->isFolder()) {
                            if ((int) $cur->size_bytes === $size && $cur->sha1 && $cur->sha1 === sha1_file($tmp)) {
                                continue; // ما اتغيّرش
                            }
                            $old = $cur->storage_path;
                            $was = $this->versionAttrs($cur);
                            $newSha = sha1_file($tmp) ?: null;
                            $delta = $size - (int) $cur->size_bytes;
                            if ($delta > 0) {
                                $this->assertQuota($uid, $delta);
                            }
                            $sp = $this->writeBlob($uid, $tmp);
                            $written[] = $sp;
                            $cur->forceFill(['storage_path' => $sp, 'size_bytes' => $size, 'sha1' => $newSha,
                                'mime_type' => $this->detectMime($tmp, $cur->name)])->save();
                            if ($this->keepVersion($uid, (int) $cur->id, $was, $newSha, 'sync')) {
                                $touched[] = (int) $cur->id; // the old blob stays as a version
                            } elseif ($old) {
                                $oldBlobs[] = $old;
                            }
                            $stats['updated']++;
                        } else {
                            $name = $cur ? $this->freeName($uid, $dir, $fname) : $fname;
                            $node = $this->putLocalFile($uid, $dir, $name, $tmp);
                            $written[] = (string) $node->storage_path;
                            $stats['added']++;
                        }
                    } finally {
                        @unlink($tmp);
                    }
                }
                // اللي اتشال من الـ zip => السلة (الجذور بس؛ الأحفاد بتتبعها)
                $gone = [];
                foreach ($existing as $rel => $node) {
                    if (isset($keep[$rel])) {
                        continue;
                    }
                    $parentRel = str_contains($rel, '/') ? substr($rel, 0, (int) strrpos($rel, '/')) : '';
                    if ($parentRel !== '' && !isset($keep[$parentRel])) {
                        continue;
                    }
                    $gone[] = (int) $node->id;
                }
                if ($gone) {
                    $this->trash($uid, $gone);
                    $stats['removed'] = count($gone);
                }
            });
        } catch (\Throwable $e) {
            foreach ($written as $sp) {
                $this->disk()->delete($sp);
            }
            throw $e;
        } finally {
            $zip->close();
        }
        foreach ($oldBlobs as $sp) {
            $this->disk()->delete($sp);
        }
        foreach (array_unique($touched) as $nid) {
            $this->pruneVersions($uid, $nid);
        }
        $this->q($uid)->where('id', $folderId)->update(['updated_at' => now()]);
        return $stats;
    }
}
