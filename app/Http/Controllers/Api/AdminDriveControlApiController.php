<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\DriveAdminService;
use App\Services\DriveAdminSettings;
use App\Support\DriveException;
use Illuminate\Http\Request;

/**
 * Admin → My PC Control (تحكم كامل في "جهازي" من حساب الأدمن).
 * كل المسارات تحت /api/v1/admin/drive/control/* ومحمية بـ uip.auth + uip.admin.
 *
 *   GET    overview                                   أرقام التخزين/المستخدمين/الروابط + التصنيفات + الرفع آخر 30 يوم + الأعلى استهلاكًا
 *   GET    settings            PUT settings           كتالوج الإعدادات / حفظ (null = رجّع الافتراضي)
 *   DELETE settings                                   رجّع كل الإعدادات للافتراضي
 *   GET    users ?q&scope=with_files|all&role&status&sort=used|files|name|activity|trash&dir&page   المستخدمين + الاستهلاك والحصة
 *   GET    users/search?q=                            بحث سريع
 *   GET    users/{id}                                 تفاصيل: الاستهلاك، التصنيفات، الحدود، الأكبر/الأحدث، سجل إجراءات الأدمن
 *   PUT    users/{id}/limits                          {quota_mb|max_file_mb (null=وراثة، 0 حصة=بلا حد), status: active|readonly|blocked, reason, note, sharing_blocked, uploads_blocked}
 *   DELETE users/{id}/limits                          رجّع المستخدم للقيم العامة
 *   POST   users/bulk-limits                          {user_ids:[..≤500], ...نفس حقول limits}
 *   GET    users/{id}/files ?parent_id | trashed=1    تصفح (metadata)
 *   GET    users/{id}/files/{nodeId}                  تفاصيل عنصر: المسار، النسخ القديمة، الروابط، موجود على التخزين؟
 *   GET    users/{id}/files/{nodeId}/download ?inline=1   تنزيل/معاينة (بيتسجّل) — يتوقف لو admin_can_download = false
 *   GET    users/{id}/files/{nodeId}/versions/{vid}/download
 *   GET    users/{id}/archive ?ids[]=..|all=1         ZIP (بيتسجّل)
 *   POST   users/{id}/files/trash|restore|purge       {ids:[..]}
 *   POST   users/{id}/trash/empty
 *   POST   users/{id}/wipe                            {confirm:"DELETE"} مسح كل "جهازي" المستخدم نهائيًا
 *   GET    files ?q&user_id&category&min_mb&state=live|trash|any&sort=size|new|old|name   بحث/أكبر ملفات على المنصة
 *   GET    shares ?status&q&user_id&sort                كل روابط المشاركة على المنصة
 *   GET    shares/{id}/activity                       مين فتح الرابط
 *   POST   shares/{id}/pause|resume|revoke|reset-claim|clear-activity   ·   DELETE shares/{id}
 *   POST   shares/bulk                                {action: pause|resume|revoke|delete, ids:[..]}
 *   POST   emergency/revoke-all-shares                {confirm:true, user_id?}
 *   GET    audit ?action&user_id&subject_id            سجل إجراءات الأدمن (admin.drive.*)
 *   POST   maintenance/purge-trash                    امسح سلة المحذوفات المنتهية دلوقتي
 *   GET    maintenance/missing-files ?after_id&limit  ملفات في الـ DB مفقودة من التخزين
 *
 * الخصوصية: الأدمن بيقدر يفتح/ينزّل ملفات المستخدمين (admin_can_download)، وكل فتح/تنزيل/حذف/تغيير حد بيتسجّل في audit_logs
 * بـ action يبدأ بـ admin.drive. (subject = المستخدم صاحب الملف).
 */
class AdminDriveControlApiController extends Controller
{
    public function __construct(
        private DriveAdminService $admin,
        private AuditLogService $auditLog
    ) {
    }

    private function adminId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function log(Request $request, string $action, $subjectId = null, ?array $old = null, ?array $new = null, string $subjectType = 'DriveUser'): void
    {
        $this->auditLog->record($this->adminId($request), 'admin.drive.' . $action, $subjectType, $subjectId, $old, $new, $request->ip());
    }

    private function fail(DriveException $e)
    {
        $c = $e->getCode();

        return $this->apiError($e->getMessage(), null, $c >= 400 && $c < 600 ? $c : 422);
    }

    private function userOr404(int $id)
    {
        return $this->admin->userExists($id) ? null : $this->apiError('User not found.', null, 404);
    }

    private function ids(Request $request, string $key = 'ids'): array
    {
        $v = $request->input($key, $request->query($key, []));

        return is_array($v) ? array_values(array_unique(array_filter(array_map('intval', $v), fn ($i) => $i > 0))) : [];
    }

    private function canDownload()
    {
        return DriveAdminSettings::get('admin_can_download') ? null : $this->apiError('Downloading user files is turned off in My PC settings.', null, 403);
    }

    private function canDelete()
    {
        return DriveAdminSettings::get('admin_can_delete') ? null : $this->apiError('Deleting user files is turned off in My PC settings.', null, 403);
    }

    // ------------------------------------------------------------------
    // Overview / Settings
    // ------------------------------------------------------------------

    public function overview()
    {
        return $this->apiSuccess($this->admin->overview(), 'My PC overview retrieved successfully.');
    }

    public function settings()
    {
        return $this->apiSuccess(DriveAdminSettings::snapshot());
    }

    public function updateSettings(Request $request)
    {
        $values = $request->input('values');
        if (!is_array($values) || !$values) {
            return $this->apiError('values is required.', null, 422);
        }
        $result = DriveAdminSettings::setMany($values);
        if ($result['errors']) {
            return $this->apiError(reset($result['errors']), $result['errors'], 422);
        }
        if ($result['changed']) {
            $this->log($request, 'settings_updated', null, array_map(fn ($c) => $c['old'], $result['changed']), array_map(fn ($c) => $c['new'], $result['changed']), 'DriveSettings');
        }

        return $this->apiSuccess(DriveAdminSettings::snapshot(), 'Settings saved.');
    }

    public function resetSettings(Request $request)
    {
        $n = DriveAdminSettings::resetAll();
        $this->log($request, 'settings_reset', null, ['overrides_removed' => $n], null, 'DriveSettings');

        return $this->apiSuccess(DriveAdminSettings::snapshot(), 'All My PC settings were reset to their defaults.');
    }

    // ------------------------------------------------------------------
    // Users & limits
    // ------------------------------------------------------------------

    public function users(Request $request)
    {
        return $this->apiSuccess($this->admin->users($request->query()));
    }

    public function searchUsers(Request $request)
    {
        return $this->apiSuccess($this->admin->searchUsers((string) $request->query('q', '')));
    }

    public function user(int $id)
    {
        $d = $this->admin->userDetail($id);

        return $d ? $this->apiSuccess($d) : $this->apiError('User not found.', null, 404);
    }

    private function limitInput(Request $request): array
    {
        return $request->only(['quota_mb', 'max_file_mb', 'status', 'reason', 'note', 'sharing_blocked', 'uploads_blocked']);
    }

    public function setLimits(Request $request, int $id)
    {
        if ($r = $this->userOr404($id)) {
            return $r;
        }
        $in = $this->limitInput($request);
        if (!$in) {
            return $this->apiError('Nothing to update.', null, 422);
        }
        if ($id === $this->adminId($request) && isset($in['status']) && $in['status'] !== 'active') {
            return $this->apiError('You cannot restrict your own account.', null, 422);
        }
        $res = $this->admin->setLimits($id, $in, $this->adminId($request));
        if ($res['errors']) {
            return $this->apiError(reset($res['errors']), $res['errors'], 422);
        }
        if ($res['old'] !== $res['new']) {
            $this->log($request, 'limits_updated', $id, $res['old'], $res['new']);
        }

        return $this->apiSuccess($this->admin->userDetail($id), 'Limits saved.');
    }

    public function resetLimits(Request $request, int $id)
    {
        if ($r = $this->userOr404($id)) {
            return $r;
        }
        $res = $this->admin->resetLimits($id);
        $this->log($request, 'limits_reset', $id, $res['old'], $res['new']);

        return $this->apiSuccess($this->admin->userDetail($id), 'The user now follows the default limits.');
    }

    public function bulkLimits(Request $request)
    {
        $ids = array_slice($this->ids($request, 'user_ids'), 0, 500);
        $in = $this->limitInput($request);
        if (!$ids || !$in) {
            return $this->apiError('user_ids and at least one limit field are required.', null, 422);
        }
        if (in_array($this->adminId($request), $ids, true) && isset($in['status']) && $in['status'] !== 'active') {
            return $this->apiError('You cannot restrict your own account.', null, 422);
        }
        $done = 0;
        $failed = [];
        foreach ($ids as $id) {
            if (!$this->admin->userExists($id)) {
                $failed[] = ['id' => $id, 'message' => 'User not found.'];
                continue;
            }
            $res = $this->admin->setLimits($id, $in, $this->adminId($request));
            if ($res['errors']) {
                $failed[] = ['id' => $id, 'message' => reset($res['errors'])];
                continue;
            }
            $done++;
            if ($res['old'] !== $res['new']) {
                $this->log($request, 'limits_updated', $id, $res['old'], $res['new']);
            }
        }

        return $this->apiSuccess(['done' => $done, 'failed' => $failed], $done . ' user(s) updated.');
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    public function files(Request $request, int $id)
    {
        if ($r = $this->userOr404($id)) {
            return $r;
        }
        $parent = $request->query('parent_id');
        $parent = ($parent === null || $parent === '' || $parent === 'root' || (int) $parent === 0) ? null : (int) $parent;
        try {
            return $this->apiSuccess($this->admin->listFolder($id, $parent, $request->boolean('trashed')));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
    }

    public function node(int $id, int $nodeId)
    {
        try {
            return $this->apiSuccess($this->admin->nodeDetail($id, $nodeId));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
    }

    public function searchFiles(Request $request)
    {
        return $this->apiSuccess($this->admin->searchFiles($request->query()));
    }

    private function streamResponse($node, $stream, bool $inline, ?string $mime = null, ?string $name = null)
    {
        $name = $name ?? (string) $node->name;
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name));

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type'            => $mime ?: ($node->mime_type ?: 'application/octet-stream'),
            'Content-Length'          => (string) (int) $node->size_bytes,
            'Content-Disposition'     => ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            // ملف مستخدم = محتوى غير موثوق: مفيش sniffing ولا تنفيذ سكربتات.
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control'           => 'private, no-store',
        ]);
    }

    public function download(Request $request, int $id, int $nodeId)
    {
        if ($r = $this->canDownload()) {
            return $r;
        }
        try {
            [$node, $stream] = $this->admin->openFile($id, $nodeId);
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $inline = $request->boolean('inline');
        $this->log($request, $inline ? 'file_viewed' : 'file_downloaded', $id, null, ['node_id' => (int) $node->id, 'name' => (string) $node->name, 'size_bytes' => (int) $node->size_bytes]);

        return $this->streamResponse($node, $stream, $inline);
    }

    public function downloadVersion(Request $request, int $id, int $nodeId, int $vid)
    {
        if ($r = $this->canDownload()) {
            return $r;
        }
        try {
            [$v, $stream] = $this->admin->openVersion($id, $nodeId, $vid);
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $this->log($request, 'file_downloaded', $id, null, ['node_id' => $nodeId, 'version_id' => $vid, 'name' => (string) $v->name, 'size_bytes' => (int) $v->size_bytes]);

        return $this->streamResponse($v, $stream, $request->boolean('inline'), $v->mime_type ?: null, (string) $v->name);
    }

    public function archive(Request $request, int $id)
    {
        if ($r = $this->canDownload() ?? $this->userOr404($id)) {
            return $r;
        }
        $ids = $this->ids($request);
        try {
            [$tmp, $name] = $this->admin->archive($id, $ids, $request->boolean('all'));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $this->log($request, 'archive_downloaded', $id, null, ['ids' => $ids ?: 'all', 'name' => $name]);

        return response()->download($tmp, $name, [
            'Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function trash(Request $request, int $id)
    {
        if ($r = $this->canDelete() ?? $this->userOr404($id)) {
            return $r;
        }
        try {
            $n = $this->admin->trash($id, $this->ids($request));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $this->log($request, 'files_trashed', $id, null, ['ids' => $this->ids($request), 'count' => $n]);

        return $this->apiSuccess(['trashed' => $n], 'Moved to the recycle bin.');
    }

    public function restore(Request $request, int $id)
    {
        if ($r = $this->userOr404($id)) {
            return $r;
        }
        try {
            $items = $this->admin->restore($id, $this->ids($request));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $this->log($request, 'files_restored', $id, null, ['ids' => $this->ids($request)]);

        return $this->apiSuccess($items, 'Restored.');
    }

    public function purge(Request $request, int $id)
    {
        if ($r = $this->canDelete() ?? $this->userOr404($id)) {
            return $r;
        }
        try {
            $n = $this->admin->purge($id, $this->ids($request));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $this->log($request, 'files_purged', $id, null, ['ids' => $this->ids($request), 'count' => $n]);

        return $this->apiSuccess(['purged' => $n], 'Deleted permanently.');
    }

    public function emptyTrash(Request $request, int $id)
    {
        if ($r = $this->canDelete() ?? $this->userOr404($id)) {
            return $r;
        }
        $n = $this->admin->emptyTrash($id);
        $this->log($request, 'trash_emptied', $id, null, ['purged' => $n]);

        return $this->apiSuccess(['purged' => $n], 'Recycle bin emptied.');
    }

    public function wipe(Request $request, int $id)
    {
        if ($r = $this->canDelete() ?? $this->userOr404($id)) {
            return $r;
        }
        if ((string) $request->input('confirm') !== 'DELETE') {
            return $this->apiError('Type DELETE to confirm (confirm=DELETE).', ['confirm' => ['required']], 422);
        }
        $n = $this->admin->wipe($id);
        $this->log($request, 'drive_wiped', $id, null, ['roots' => $n]);

        return $this->apiSuccess(['roots' => $n], 'The whole My PC of this user was deleted.');
    }

    // ------------------------------------------------------------------
    // Shares
    // ------------------------------------------------------------------

    public function shares(Request $request)
    {
        return $this->apiSuccess($this->admin->shares($request->query()));
    }

    public function shareActivity(int $id)
    {
        try {
            return $this->apiSuccess($this->admin->shareActivity($id));
        } catch (DriveException $e) {
            return $this->fail($e);
        }
    }

    private function shareDo(Request $request, int $id, string $action, string $message)
    {
        try {
            $res = $this->admin->shareAction($id, $action);
        } catch (DriveException $e) {
            return $this->fail($e);
        }
        $this->log($request, 'share_' . $action, $id, null, null, 'DriveShare');

        return $this->apiSuccess($res, $message);
    }

    public function sharePause(Request $request, int $id)
    {
        return $this->shareDo($request, $id, 'pause', 'Link paused.');
    }

    public function shareResume(Request $request, int $id)
    {
        return $this->shareDo($request, $id, 'resume', 'Link resumed.');
    }

    public function shareRevoke(Request $request, int $id)
    {
        return $this->shareDo($request, $id, 'revoke', 'Link revoked.');
    }

    public function shareResetClaim(Request $request, int $id)
    {
        return $this->shareDo($request, $id, 'reset_claim', 'Link reset.');
    }

    public function shareClearActivity(Request $request, int $id)
    {
        return $this->shareDo($request, $id, 'clear_activity', 'Activity cleared.');
    }

    public function shareDelete(Request $request, int $id)
    {
        return $this->shareDo($request, $id, 'delete', 'Link deleted.');
    }

    public function shareBulk(Request $request)
    {
        $action = (string) $request->input('action');
        if (!in_array($action, ['pause', 'resume', 'revoke', 'delete'], true)) {
            return $this->apiError('action must be pause, resume, revoke or delete.', null, 422);
        }
        $ids = $this->ids($request);
        if (!$ids) {
            return $this->apiError('ids is required.', null, 422);
        }
        $res = $this->admin->shareBulk($action, $ids);
        $this->log($request, 'shares_bulk_' . $action, null, null, ['ids' => array_slice($ids, 0, 500)] + $res, 'DriveShare');

        return $this->apiSuccess($res, $res['done'] . ' link(s) updated.');
    }

    public function revokeAllShares(Request $request)
    {
        if (!$request->boolean('confirm')) {
            return $this->apiError('Confirmation required (confirm=true).', null, 422);
        }
        $uid = $request->filled('user_id') ? (int) $request->input('user_id') : null;
        $n = $this->admin->revokeAllShares($uid);
        $this->log($request, 'revoke_all_shares', $uid, null, ['revoked' => $n], $uid ? 'DriveUser' : 'DriveSystem');

        return $this->apiSuccess(['revoked' => $n], $n . ' link(s) revoked.');
    }

    // ------------------------------------------------------------------
    // Audit / maintenance
    // ------------------------------------------------------------------

    public function audit(Request $request)
    {
        return $this->apiSuccess($this->admin->audit($request->query()));
    }

    public function purgeTrash(Request $request)
    {
        if ($r = $this->canDelete()) {
            return $r;
        }
        $n = $this->admin->purgeExpiredTrash();
        $this->log($request, 'purge_expired_trash', null, null, ['purged' => $n], 'DriveSystem');

        return $this->apiSuccess(['purged' => $n], $n . ' expired item(s) deleted.');
    }

    public function missingFiles(Request $request)
    {
        return $this->apiSuccess($this->admin->missingFiles((int) $request->query('after_id', 0), (int) $request->query('limit', 200)));
    }
}
