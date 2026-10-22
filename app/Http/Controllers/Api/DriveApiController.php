<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DriveContentSearchService;
use App\Services\DriveService;
use App\Support\DriveException;
use Illuminate\Http\Request;

/**
 * "جهازي" (My PC) — نظام ملفات شخصي لكل طالب. كل روت بيشتغل على user_id صاحب التوكن بس (DriveService بيقيّد كل استعلام)،
 * فأي id بتاع حد تاني بيرجّع 404. الدور لازم يكون ضمن config('drive.roles') (افتراضي student).
 *
 *   GET    /drive?parent_id=          محتوى مجلد (الجذر لو فاضي) + breadcrumb + الحصة
 *   GET    /drive/tree                كل المجلدات (الشجرة الجانبية)
 *   GET    /drive/search?q=
 *   GET    /drive/storage             مراقب التخزين: تصنيف + أكبر الملفات/المجلدات + السلة + النسخ القديمة + المكرر
 *   DELETE /drive/versions            مسح كل النسخ القديمة (سجل الإصدارات) لتحرير مساحة
 *   GET    /drive/resolve?ids=1,2,3   بيانات عناصر بالـ id (اختصارات سطح المكتب) + gone = اللي اتمسح نهائيًا
 *   GET    /drive/search-content?q=   بحث جوّه محتوى الملفات النصية (مطابقات + رقم السطر)
 *   GET    /drive/usage
 *   GET    /drive/templates           قوالب المشاريع
 *   POST   /drive/folders             {parent_id,name}  أو {parent_id,path:"a/b/c"} => mkdir -p
 *   POST   /drive/files               {parent_id,name,content}      ملف نصي جديد
 *   POST   /drive/projects            {parent_id,name,template}
 *   POST   /drive/upload              multipart: files[] (أو file) + parent_id + paths[] (مسار نسبي لرفع مجلد)
 *   GET    /drive/nodes/{id}/content  محتوى الملف (?download=1 للتنزيل)
 *   PUT    /drive/nodes/{id}/content  {content}  حفظ نص
 *   POST   /drive/nodes/{id}/replace  multipart file (+name) — حفظ من محررات الصور/PDF/ZIP
 *   GET    /drive/nodes/{id}/versions                       سجل إصدارات الملف (النسخ القديمة قبل كل حفظ)، الأحدث أول
 *   GET    /drive/nodes/{id}/versions/{vid}/content         محتوى نسخة قديمة (?download=1 للتنزيل)
 *   POST   /drive/nodes/{id}/versions/{vid}/restore         رجّع الملف لنسخة قديمة (الحالي بيتحفظ كنسخة)
 *   DELETE /drive/nodes/{id}/versions/{vid}                 امسح نسخة قديمة
 *   PATCH  /drive/nodes/{id}          {name}
 *   GET    /drive/nodes/{id}/archive  تنزيل ملف/مجلد كـ ZIP (?root=1 => محتوى المجلد في جذر الـ zip)
 *   POST   /drive/nodes/{id}/unzip    {parent_id?}
 *   POST   /drive/nodes/{id}/sync     multipart zip — مزامنة مجلد من محرر الـ ZIP
 *   POST   /drive/nodes/{id}/sandbox-token   توكن الـ sandbox (terminal / تشغيل مشروع) لمجلد
 *   POST   /drive/move | /drive/copy  {ids,target_id}
 *   POST   /drive/zip                 {ids,parent_id,name}
 *   POST   /drive/trash | /restore | /purge   {ids}
 *   GET    /drive/trash   |  DELETE /drive/trash (تفريغ)
 *   GET    /drive/config              إعدادات الواجهة: direct_upload + بيانات Google/Microsoft للنسخ الاحتياطي
 *   GET    /drive/manifest            كل الملفات/المجلدات (metadata) للنسخ الاحتياطي التزايدي
 *   POST   /drive/upload/init|complete          رفع مباشر لـ S3/R2 (رابط PUT موقّع ثم تأكيد)
 *   POST   /drive/nodes/{id}/replace/init|complete   حفظ مباشر فوق ملف موجود
 *   GET    /drive/nodes/{id}/download-url       رابط تنزيل موقّع مباشر
 */
class DriveApiController extends Controller
{
    public function __construct(private DriveService $drive)
    {
    }

    private function roleAllowed(Request $request): bool
    {
        return in_array((string) $request->attributes->get('uip_role'), (array) config('drive.roles'), true);
    }

    /** يشغّل $fn($uid) ويحوّل DriveException لرد JSON بنفس الـ envelope. */
    private function guard(Request $request, callable $fn)
    {
        if (!$this->roleAllowed($request)) {
            return $this->apiError('My PC is not available for your account type.', null, 403);
        }
        try {
            return $fn((int) $request->attributes->get('uip_user_id'));
        } catch (DriveException $e) {
            $c = $e->getCode();
            return $this->apiError($e->getMessage(), null, $c >= 400 && $c < 600 ? $c : 422);
        }
    }

    private function pid(Request $r, string $key = 'parent_id'): ?int
    {
        $v = $r->input($key, $r->query($key));
        return ($v === null || $v === '' || $v === 'root' || (int) $v === 0) ? null : (int) $v;
    }

    private function ids(Request $r): array
    {
        $ids = $r->input('ids', []);
        return is_array($ids) ? $ids : [];
    }

    // -- browse -----------------------------------------------------------------------------------------

    public function index(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->listFolder($uid, $this->pid($request)), 'Folder retrieved successfully.'));
    }

    public function tree(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->tree($uid), 'Folder tree retrieved successfully.'));
    }

    public function storage(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->storageReport($uid), 'OK'));
    }

    public function clearVersions(Request $request)
    {
        return $this->guard($request, function ($uid) {
            $freed = $this->drive->clearAllVersions($uid);
            return $this->apiSuccess(['freed_bytes' => $freed, 'freed_human' => \App\Models\DriveNode::humanSize($freed)], 'Old versions deleted.');
        });
    }

    public function resolve(Request $request)
    {
        $ids = array_filter(explode(',', (string) $request->query('ids', '')), 'ctype_digit');
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->resolveNodes($uid, $ids), 'OK'));
    }

    public function searchContent(Request $request, DriveContentSearchService $content)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($content->search($uid, (string) $request->query('q', '')), 'Content search results.'));
    }

    public function search(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->search($uid, (string) $request->query('q', '')), 'Search results.'));
    }

    public function usage(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->usage($uid), 'Usage retrieved successfully.'));
    }

    public function disks(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->disks($uid), 'Disks retrieved successfully.'));
    }

    public function createDisk(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->createDisk($uid, (string) $request->input('name', ''))->toRowArray(), 'Disk created.', 201));
    }

    public function setDisk(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->setDisk($uid, (int) $id, filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN))->toRowArray(), 'Updated.'));
    }

    public function templates(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(DriveService::templateKeys(), 'Templates retrieved successfully.'));
    }

    // -- create -----------------------------------------------------------------------------------------

    public function createFolder(Request $request)
    {
        if (is_string($request->input('path')) && $request->input('path') !== '') {
            return $this->guard($request, fn ($uid) => $this->apiSuccess(
                $this->drive->ensurePath($uid, $this->pid($request), (string) $request->input('path'))->toRowArray(), 'Folder created.', 201));
        }
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->createFolder($uid, $this->pid($request), (string) $request->input('name', ''))->toRowArray(), 'Folder created.', 201));
    }

    public function createFile(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->createTextFile($uid, $this->pid($request), (string) $request->input('name', ''), (string) $request->input('content', ''))->toRowArray(), 'File created.', 201));
    }

    public function createProject(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->createProject($uid, $this->pid($request), (string) $request->input('name', ''), (string) $request->input('template', 'blank'))->toRowArray(), 'Project created.', 201));
    }

    public function upload(Request $request)
    {
        return $this->guard($request, function ($uid) use ($request) {
            $files = $request->file('files', []);
            if ($request->hasFile('file')) {
                $files = array_merge(is_array($files) ? $files : [$files], [$request->file('file')]);
            }
            $files = is_array($files) ? array_values($files) : [$files];
            $paths = (array) $request->input('paths', []);
            if (!$files) {
                return $this->apiError('No file was uploaded.', null, 422);
            }
            $parent = $this->pid($request);
            $done = [];
            $failed = [];
            $firstStatus = 422;
            foreach ($files as $i => $f) {
                try {
                    $done[] = $this->drive->upload($uid, $parent, $f, isset($paths[$i]) ? (string) $paths[$i] : null)->toRowArray();
                } catch (DriveException $e) {
                    if (!$failed) {
                        $firstStatus = $e->getCode();
                    }
                    $failed[] = ['name' => $f->getClientOriginalName(), 'message' => $e->getMessage()];
                }
            }
            if (!$done) {
                return $this->apiError($failed[0]['message'], null, $firstStatus >= 400 && $firstStatus < 600 ? $firstStatus : 422);
            }
            return $this->apiSuccess(['items' => $done, 'failed' => $failed], 'Files uploaded.', 201);
        });
    }

    // -- content ----------------------------------------------------------------------------------------

    public function content(Request $request, $id)
    {
        if (!$this->roleAllowed($request)) {
            return $this->apiError('My PC is not available for your account type.', null, 403);
        }
        try {
            [$node, $stream] = $this->drive->openStream((int) $request->attributes->get('uip_user_id'), (int) $id);
        } catch (DriveException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 ? $e->getCode() : 404);
        }
        // Re-validation instead of "no-store": the browser keeps its own copy and asks "still this version?" — a 304 costs no data.
        // The bytes only ever change through replace()/writeContent(), which update sha1 / size / updated_at.
        $etag = '"' . substr(sha1($node->id . '|' . ($node->sha1 ?: '') . '|' . (int) $node->size_bytes . '|' . (string) $node->updated_at), 0, 32) . '"';
        $match = trim((string) $request->header('If-None-Match'));
        if ($match !== '' && !$request->boolean('download') && in_array($etag, array_map('trim', explode(',', str_replace('W/', '', $match))), true)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
        }
        $download = $request->boolean('download');
        $name = (string) $node->name;
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name));
        $headers = [
            'Content-Type'            => $node->mime_type ?: 'application/octet-stream',
            'Content-Length'          => (string) (int) $node->size_bytes,
            'Content-Disposition'     => ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            // ملف طالب = محتوى غير موثوق: مفيش sniffing ولا تنفيذ سكربتات لو اتفتح مباشرة في تبويب.
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control'           => 'private, no-cache',
            'ETag'                    => $etag,
        ];
        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $headers);
    }

    public function saveContent(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->writeContent($uid, (int) $id, (string) $request->input('content', ''))->toRowArray(), 'File saved.'));
    }

    public function replace(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($request, $id) {
            $file = $request->file('file');
            if (!$file) {
                return $this->apiError('No file was uploaded.', null, 422);
            }
            $name = $request->input('name');
            return $this->apiSuccess($this->drive->replaceFromUpload($uid, (int) $id, $file, is_string($name) ? $name : null)->toRowArray(), 'File saved.');
        });
    }


    // -- version history --------------------------------------------------------------------------------

    public function versions(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->versions($uid, (int) $id), 'OK'));
    }

    public function versionContent(Request $request, $id, $vid)
    {
        if (!$this->roleAllowed($request)) {
            return $this->apiError('My PC is not available for your account type.', null, 403);
        }
        try {
            [$v, $stream] = $this->drive->openVersionStream((int) $request->attributes->get('uip_user_id'), (int) $id, (int) $vid);
        } catch (DriveException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 ? $e->getCode() : 404);
        }
        $name = (string) $v->name;
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name));
        $headers = [
            'Content-Type'            => $v->mime_type ?: 'application/octet-stream',
            'Content-Length'          => (string) (int) $v->size_bytes,
            'Content-Disposition'     => ($request->boolean('download') ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control'           => 'private, max-age=3600', // a version never changes
        ];
        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $headers);
    }

    public function restoreVersion(Request $request, $id, $vid)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->restoreVersion($uid, (int) $id, (int) $vid)->toRowArray(), 'Version restored.'));
    }

    public function deleteVersion(Request $request, $id, $vid)
    {
        return $this->guard($request, function ($uid) use ($id, $vid) {
            $this->drive->deleteVersion($uid, (int) $id, (int) $vid);
            return $this->apiSuccess(null, 'Version deleted.');
        });
    }

    // -- cloud: direct transfer + backup support --------------------------------------------------------

    public function config(Request $request)
    {
        return $this->guard($request, function () {
            $g = (array) preg_split('/\s*,\s*/', trim((string) config('services.google.client_ids')), -1, PREG_SPLIT_NO_EMPTY);
            $msId = (string) config('services.microsoft.client_id');
            return $this->apiSuccess([
                'direct_upload' => $this->drive->directEnabled(),
                'max_file_mb'   => (int) config('drive.max_file_mb'),
                'cloud'         => [
                    'google'    => ['enabled' => $g !== [], 'client_id' => $g[0] ?? null, 'server_flow' => app(\App\Services\GoogleDriveOAuthService::class)->isConfigured()],
                    'microsoft' => ['enabled' => $msId !== '', 'client_id' => $msId ?: null, 'authority' => (string) config('services.microsoft.authority')],
                ],
            ], 'OK');
        });
    }

    public function manifest(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['items' => $this->drive->manifest($uid)], 'OK'));
    }

    public function uploadInit(Request $request)
    {
        return $this->guard($request, function ($uid) use ($request) {
            $v = $request->validate([
                'name' => 'required|string|max:255', 'size' => 'required|integer|min:0', 'mime' => 'nullable|string|max:150',
                'parent_id' => 'nullable|integer', 'path' => 'nullable|string|max:1000',
            ]);
            $r = $this->drive->initDirectUpload($uid, $this->pid($request), (string) $v['name'], (int) $v['size'], $v['mime'] ?? null, $v['path'] ?? null);
            return $this->apiSuccess($r, 'OK');
        });
    }

    public function uploadComplete(Request $request)
    {
        return $this->guard($request, function ($uid) use ($request) {
            $v = $request->validate(['token' => 'required|string|max:4000']);
            return $this->apiSuccess($this->drive->completeDirectUpload($uid, $v['token'])->toRowArray(), 'File uploaded.', 201);
        });
    }

    public function replaceInit(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($request, $id) {
            $v = $request->validate(['size' => 'required|integer|min:0', 'mime' => 'nullable|string|max:150']);
            return $this->apiSuccess($this->drive->initDirectReplace($uid, (int) $id, (int) $v['size'], $v['mime'] ?? null), 'OK');
        });
    }

    public function replaceComplete(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($request) {
            $v = $request->validate(['token' => 'required|string|max:4000', 'name' => 'nullable|string|max:255', 'sha1' => 'nullable|string|max:40']);
            return $this->apiSuccess($this->drive->completeDirectReplace($uid, $v['token'], $v['name'] ?? null, $v['sha1'] ?? null)->toRowArray(), 'File saved.');
        });
    }

    public function downloadUrl(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->directDownloadUrl($uid, (int) $id, $request->boolean('download')), 'OK'));
    }

    // -- organise ---------------------------------------------------------------------------------------

    public function rename(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->rename($uid, (int) $id, (string) $request->input('name', ''))->toRowArray(), 'Renamed.'));
    }

    public function move(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->move($uid, $this->ids($request), $this->pid($request, 'target_id')), 'Moved.'));
    }

    public function copy(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->copy($uid, $this->ids($request), $this->pid($request, 'target_id')), 'Copied.', 201));
    }

    // -- zip --------------------------------------------------------------------------------------------

    public function zip(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->drive->zipInto($uid, $this->ids($request), $this->pid($request), $request->input('name'))->toRowArray(), 'Archive created.', 201));
    }

    public function unzip(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->unzip($uid, (int) $id, $this->pid($request))->toRowArray(), 'Archive extracted.', 201));
    }

    public function archive(Request $request, $id)
    {
        if (!$this->roleAllowed($request)) {
            return $this->apiError('My PC is not available for your account type.', null, 403);
        }
        try {
            [$tmp, $name] = $this->drive->buildZip((int) $request->attributes->get('uip_user_id'), [(int) $id], $request->boolean('root'));
        } catch (DriveException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 ? $e->getCode() : 422);
        }
        return response()->download($tmp, $name, [
            'Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function sync(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($request, $id) {
            $file = $request->file('file');
            if (!$file) {
                return $this->apiError('No file was uploaded.', null, 422);
            }
            return $this->apiSuccess($this->drive->syncFolderFromZip($uid, (int) $id, $file), 'Folder updated.');
        });
    }

    // -- sandbox ----------------------------------------------------------------------------------------

    /**
     * نفس منطق SandboxApiController::token بس للمجلد: الصلاحية = إنه مجلد عند صاحب التوكن (find بيقيّد بالـ user_id).
     * الـ pid في التوكن "drive-{id}" — الـ runner بيستخدمه كمفتاح جلسة بس.
     */
    public function sandboxToken(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($id) {
            $url    = rtrim((string) config('services.sandbox.url'), '/');
            $secret = (string) config('services.sandbox.secret');
            if ($url === '' || strlen($secret) < 32) {
                return $this->apiError('The sandbox is not configured on this server.', null, 503);
            }
            // a folder (code editor) OR a ZIP file opened in the viewer: ownership is what matters; the id only keys the session
            $node = $this->drive->find($uid, (int) $id);
            $ttl = max(30, (int) config('services.sandbox.token_ttl', 120));
            $now = time();
            $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
            $body = $b64(json_encode([
                'iss' => 'uip-api', 'aud' => 'uip-sandbox', 'sub' => (string) $uid, 'pid' => 'drive-' . (int) $node->id,
                'iat' => $now, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(8)),
            ]));
            $sig = $b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
            return $this->apiSuccess(['url' => $url, 'token' => "{$head}.{$body}.{$sig}", 'expires_in' => $ttl], 'Sandbox token issued.');
        });
    }

    // -- recycle bin ------------------------------------------------------------------------------------

    public function trash(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['trashed' => $this->drive->trash($uid, $this->ids($request))], 'Moved to the recycle bin.'));
    }

    public function trashList(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->trashList($uid), 'Recycle bin retrieved successfully.'));
    }

    public function restore(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->drive->restore($uid, $this->ids($request)), 'Restored.'));
    }

    public function purge(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['purged' => $this->drive->purge($uid, $this->ids($request))], 'Deleted permanently.'));
    }

    public function emptyTrash(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['purged' => $this->drive->emptyTrash($uid)], 'Recycle bin emptied.'));
    }
}
