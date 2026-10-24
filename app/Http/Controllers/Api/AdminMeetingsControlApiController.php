<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\MeetingAdminService;
use App\Services\MeetingAdminSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin → Meetings Control (تحكم كامل في نظام الاجتماعات من حساب الأدمن).
 * كل المسارات تحت /api/v1/admin/meetings/control/* ومحمية بـ uip.auth + uip.admin.
 *
 *   GET    overview                              أرقام + حالة المفاتيح
 *   GET    settings            PUT settings      كتالوج الإعدادات / حفظ (null = رجّع الافتراضي)
 *   DELETE settings                              رجّع كل الإعدادات للافتراضي
 *   GET    blocked-users  POST  DELETE {id}      حظر مستخدم من إنشاء اجتماعات
 *   GET    users/search?q=                       بحث مستخدمين (للحظر)
 *   GET    audit                                 سجل نشاط الاجتماعات (+ إجراءات الأدمن)
 *   POST   emergency/end-all-live                إنهاء كل الاجتماعات الشغالة
 *   GET    meetings                              قايمة + فلاتر
 *   GET    meetings/{uuid}                       تفاصيل
 *   PATCH  meetings/{uuid}                       تعديل
 *   POST   meetings/{uuid}/end|cancel|restore|lock|screen-share-lock|regenerate-link|announce|participants/action
 *   DELETE meetings/{uuid}  ·  files/{id}  ·  recordings/{id}
 *
 * الخصوصية: مفيش endpoint بيرجّع نص الشات/الملاحظات أو بيحمّل ملف/تسجيل (راجع MeetingAdminService).
 * كل إجراء بيتسجّل في audit_logs بـ action يبدأ بـ admin.meetings.
 */
class AdminMeetingsControlApiController extends Controller
{
    public function __construct(
        private MeetingAdminService $admin,
        private AuditLogService $auditLog
    ) {
    }

    private function adminId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function log(Request $request, string $action, $subjectId = null, ?array $old = null, ?array $new = null, string $subjectType = 'Meeting'): void
    {
        $this->auditLog->record($this->adminId($request), 'admin.meetings.' . $action, $subjectType, $subjectId, $old, $new, $request->ip());
    }

    private function meetingOr404(string $uuid, bool $withTrashed = false)
    {
        return $this->admin->find($uuid, $withTrashed);
    }

    // ------------------------------------------------------------------
    // Overview / Settings
    // ------------------------------------------------------------------

    public function overview()
    {
        return $this->apiSuccess($this->admin->overview(), 'Meetings overview retrieved successfully.');
    }

    public function settings()
    {
        return $this->apiSuccess(MeetingAdminSettings::snapshot());
    }

    public function updateSettings(Request $request)
    {
        $values = $request->input('values');
        if (!is_array($values) || !$values) {
            return $this->apiError('values is required.', null, 422);
        }

        $result = MeetingAdminSettings::setMany($values);
        if ($result['errors']) {
            return $this->apiError(reset($result['errors']), $result['errors'], 422);
        }

        if ($result['changed']) {
            $old = array_map(fn ($c) => $c['old'], $result['changed']);
            $new = array_map(fn ($c) => $c['new'], $result['changed']);
            $this->log($request, 'settings_updated', null, $old, $new, 'MeetingSettings');
        }

        return $this->apiSuccess(MeetingAdminSettings::snapshot(), 'Settings saved.');
    }

    public function resetSettings(Request $request)
    {
        $n = MeetingAdminSettings::resetAll();
        $this->log($request, 'settings_reset', null, ['overrides_removed' => $n], null, 'MeetingSettings');

        return $this->apiSuccess(MeetingAdminSettings::snapshot(), 'All meeting settings were reset to their defaults.');
    }

    // ------------------------------------------------------------------
    // Blocked users
    // ------------------------------------------------------------------

    public function blockedUsers()
    {
        return $this->apiSuccess($this->admin->blockedUsers());
    }

    public function searchUsers(Request $request)
    {
        return $this->apiSuccess($this->admin->searchUsers((string) $request->query('q', '')));
    }

    public function blockUser(Request $request)
    {
        $v = Validator::make($request->all(), ['user_id' => 'required|integer|min:1']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        $id = (int) $request->input('user_id');
        if (!\Illuminate\Support\Facades\DB::table('users')->where('id', $id)->exists()) {
            return $this->apiError('User not found.', null, 404);
        }
        if ($id === $this->adminId($request)) {
            return $this->apiError('You cannot block yourself.', null, 422);
        }

        $ids = (array) MeetingAdminSettings::get('blocked_user_ids');
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            $res = MeetingAdminSettings::setMany(['blocked_user_ids' => $ids]);
            if ($res['errors']) {
                return $this->apiError(reset($res['errors']), $res['errors'], 422);
            }
            $this->log($request, 'user_blocked', $id, null, ['user_id' => $id], 'User');
        }

        return $this->apiSuccess($this->admin->blockedUsers(), 'User blocked from creating meetings.');
    }

    public function unblockUser(Request $request, int $id)
    {
        $ids = array_values(array_filter((array) MeetingAdminSettings::get('blocked_user_ids'), fn ($v) => (int) $v !== $id));
        MeetingAdminSettings::setMany(['blocked_user_ids' => $ids ?: null]);
        $this->log($request, 'user_unblocked', $id, ['user_id' => $id], null, 'User');

        return $this->apiSuccess($this->admin->blockedUsers(), 'User unblocked.');
    }

    // ------------------------------------------------------------------
    // Audit / Emergency
    // ------------------------------------------------------------------

    public function audit(Request $request)
    {
        return $this->apiSuccess($this->admin->audit($request->query()));
    }

    public function endAllLive(Request $request)
    {
        if (!$request->boolean('confirm')) {
            return $this->apiError('Confirmation required (confirm=true).', null, 422);
        }
        $n = $this->admin->endAllLive();
        $this->log($request, 'end_all_live', null, null, ['ended' => $n], 'MeetingSystem');

        return $this->apiSuccess(['ended' => $n], $n . ' live meeting(s) ended.');
    }

    // ------------------------------------------------------------------
    // Meetings
    // ------------------------------------------------------------------

    public function meetings(Request $request)
    {
        return $this->apiSuccess($this->admin->list($request->query()));
    }

    public function show(string $uuid)
    {
        $m = $this->meetingOr404($uuid);
        if (!$m) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        return $this->apiSuccess($this->admin->details($m));
    }

    public function update(Request $request, string $uuid)
    {
        $m = $this->meetingOr404($uuid);
        if (!$m || $m->trashed()) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $v = Validator::make($request->all(), [
            'title'                => 'sometimes|required|string|max:200',
            'description'          => 'nullable|string|max:5000',
            'scheduled_start_at'   => 'nullable|date',
            'duration_minutes'     => 'nullable|integer|min:5|max:1440',
            'max_participants'     => 'nullable|integer|min:2|max:500',
            'waiting_room_enabled' => 'sometimes|boolean',
            'allow_guests'         => 'sometimes|boolean',
            'password'             => 'nullable|string|min:4|max:100',
            'clear_password'       => 'sometimes|boolean',
            'settings'             => 'nullable|array',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $before = array_intersect_key($m->toArray(), array_flip(['title', 'duration_minutes', 'max_participants', 'waiting_room_enabled', 'allow_guests', 'settings']));
        $m = $this->admin->update($m, $request->all());
        $this->log($request, 'meeting_updated', $m->id, $before, array_diff_key($request->except(['password']), ['password' => 1]));

        return $this->apiSuccess($this->admin->details($m), 'Meeting updated.');
    }

    private function simpleAction(Request $request, string $uuid, string $log, callable $fn, string $message, bool $withTrashed = false)
    {
        $m = $this->meetingOr404($uuid, $withTrashed);
        if (!$m) {
            return $this->apiError('Meeting not found.', null, 404);
        }
        try {
            $fn($m);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 409);
        }
        $this->log($request, $log, $m->id);

        return $this->apiSuccess($this->admin->details($m->fresh() ?? $m), $message);
    }

    public function end(Request $request, string $uuid)
    {
        return $this->simpleAction($request, $uuid, 'meeting_ended', fn ($m) => $this->admin->end($m), 'Meeting ended.');
    }

    public function cancel(Request $request, string $uuid)
    {
        return $this->simpleAction($request, $uuid, 'meeting_cancelled', fn ($m) => $this->admin->cancel($m), 'Meeting cancelled.');
    }

    public function destroy(Request $request, string $uuid)
    {
        $m = $this->meetingOr404($uuid);
        if (!$m || $m->trashed()) {
            return $this->apiError('Meeting not found.', null, 404);
        }
        $this->admin->delete($m);
        $this->log($request, 'meeting_deleted', $m->id);

        return $this->apiSuccess(null, 'Meeting deleted.');
    }

    public function restore(Request $request, string $uuid)
    {
        $m = $this->meetingOr404($uuid, true);
        if (!$m || !$m->trashed()) {
            return $this->apiError('Deleted meeting not found.', null, 404);
        }
        $this->admin->restore($m);
        $this->log($request, 'meeting_restored', $m->id);

        return $this->apiSuccess($this->admin->details($m), 'Meeting restored.');
    }

    public function lock(Request $request, string $uuid)
    {
        $locked = $request->boolean('locked', true);

        return $this->simpleAction($request, $uuid, $locked ? 'meeting_locked' : 'meeting_unlocked',
            fn ($m) => $this->admin->setLock($m, $this->adminId($request), $locked), $locked ? 'Meeting locked.' : 'Meeting unlocked.');
    }

    public function screenShareLock(Request $request, string $uuid)
    {
        $locked = $request->boolean('locked', true);

        return $this->simpleAction($request, $uuid, $locked ? 'screen_share_locked' : 'screen_share_unlocked',
            fn ($m) => $this->admin->setScreenShareLock($m, $this->adminId($request), $locked), $locked ? 'Screen sharing disabled.' : 'Screen sharing enabled.');
    }

    public function regenerateLink(Request $request, string $uuid)
    {
        return $this->simpleAction($request, $uuid, 'link_regenerated', fn ($m) => $this->admin->regenerateLink($m), 'Join link regenerated. The old link no longer works.');
    }

    public function announce(Request $request, string $uuid)
    {
        $v = Validator::make($request->all(), ['message' => 'required|string|max:500']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        return $this->simpleAction($request, $uuid, 'announcement_sent', fn ($m) => $this->admin->announce($m, (string) $request->input('message')), 'Announcement sent to the meeting chat.');
    }

    public function participantAction(Request $request, string $uuid)
    {
        $v = Validator::make($request->all(), [
            'key'    => ['required', 'string', 'max:40', 'regex:/^(user|guest):[0-9]+$/'],
            'action' => 'required|string|in:mute,disable-camera,remove,promote,demote,make-host,stop-screen-share,allow-screen-share,deny-screen-share,reset-screen-share',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $m = $this->meetingOr404($uuid);
        if (!$m || $m->trashed()) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        try {
            $this->admin->participantAction($m, $this->adminId($request), (string) $request->input('key'), (string) $request->input('action'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 409);
        }

        $this->log($request, 'participant_' . str_replace('-', '_', (string) $request->input('action')), $m->id, null, ['target' => $request->input('key')]);

        return $this->apiSuccess($this->admin->details($m->fresh() ?? $m), 'Done.');
    }

    // ------------------------------------------------------------------
    // Files / Recordings
    // ------------------------------------------------------------------

    public function deleteFile(Request $request, string $uuid, int $fileId)
    {
        $m = $this->meetingOr404($uuid, true);
        if (!$m) {
            return $this->apiError('Meeting not found.', null, 404);
        }
        try {
            $file = $this->admin->deleteFile($m, $fileId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }
        $this->log($request, 'file_deleted', $m->id, ['file_id' => $file->id, 'name' => $file->original_name]);

        return $this->apiSuccess($this->admin->details($m), 'File deleted.');
    }

    public function deleteRecording(Request $request, string $uuid, int $recordingId)
    {
        $m = $this->meetingOr404($uuid, true);
        if (!$m) {
            return $this->apiError('Meeting not found.', null, 404);
        }
        try {
            $rec = $this->admin->deleteRecording($m, $recordingId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }
        $this->log($request, 'recording_deleted', $m->id, ['recording_id' => $rec->id, 'kind' => $rec->kind]);

        return $this->apiSuccess($this->admin->details($m), 'Recording deleted.');
    }
}
