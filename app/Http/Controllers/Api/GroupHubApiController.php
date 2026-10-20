<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\GroupCollaborationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/group-hub/* لصفحة StudentGroupHub.jsx — أربع تابات
 * (Timeline/Announcements/Files/Tasks) لمجموعة الطالب نفسها، كل المنطق
 * جوه GroupCollaborationService المشتركة (راجع docblock الكلاس). $groupId
 * دايمًا مُشتق من groupIdForStudent(auth user) — طالب مش منضم لمجموعة
 * بياخد 404 واضح، وطالب منضم يقدر بس يتصرف في محتوى مجموعته هو (كل
 * ميثودز الخدمة بتتفحص group_id قبل أي حذف/تعديل).
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='student' بتتفحص
 * جوه كل ميثود.
 */
class GroupHubApiController extends Controller
{
    public function __construct(
        private GroupCollaborationService $collabService,
        private AuditLogService $auditLog
    ) {
    }

    private function requireStudent(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can access the group hub.', null, 403);
        }
        return null;
    }

    /** بترجع [group_id, null] لو الطالب منضم لمجموعة، أو [null, JsonResponse] غير كده. */
    private function resolveGroup(Request $request): array
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $groupId = $this->collabService->groupIdForStudent($userId);
        if (!$groupId) {
            return [null, $this->apiError('You are not assigned to a group yet.', null, 404)];
        }
        return [$groupId, null];
    }

    /** GET /api/v1/group-hub */
    public function index(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->collabService->hubData($groupId), 'Group hub retrieved successfully.');
    }

    /** POST /api/v1/group-hub/announcements */
    public function postAnnouncement(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'body'  => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $announcement = $this->collabService->postAnnouncement($groupId, $userId, trim((string) $request->input('title')), $request->input('body'));

        $this->auditLog->record($userId, 'student.group_hub.announcement_posted', 'GroupAnnouncement', $announcement->id);

        return $this->apiSuccess($announcement->toArray(), 'Announcement posted successfully.', 201);
    }

    /** DELETE /api/v1/group-hub/announcements/{id} */
    public function deleteAnnouncement(Request $request, $id)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        if (!$this->collabService->deleteAnnouncement($groupId, (int) $id)) {
            return $this->apiError('Announcement not found.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $reason = trim((string) $request->input('reason', ''));
        $this->auditLog->record($userId, 'student.group_hub.announcement_deleted', 'GroupAnnouncement', (int) $id, null, $reason !== '' ? ['reason' => $reason] : null);

        return $this->apiSuccess(null, 'Announcement deleted successfully.');
    }

    /** POST /api/v1/group-hub/files (multipart: file, description?) */
    public function uploadFile(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $file = $this->collabService->uploadFile($groupId, $userId, $request->file('file'), (string) $request->input('description', ''));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($userId, 'student.group_hub.file_uploaded', 'GroupFile', $file->id);

        return $this->apiSuccess($file->toArray(), 'File uploaded successfully.', 201);
    }

    /** DELETE /api/v1/group-hub/files/{id} */
    public function deleteFile(Request $request, $id)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        if (!$this->collabService->deleteFile($groupId, (int) $id)) {
            return $this->apiError('File not found.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $reason = trim((string) $request->input('reason', ''));
        $this->auditLog->record($userId, 'student.group_hub.file_deleted', 'GroupFile', (int) $id, null, $reason !== '' ? ['reason' => $reason] : null);

        return $this->apiSuccess(null, 'File deleted successfully.');
    }

    /** POST /api/v1/group-hub/tasks */
    public function createTask(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'title'             => 'required|string|max:200',
            'assignee_user_id'  => 'nullable',
            'due_date'          => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $assigneeUserId = (int) $request->input('assignee_user_id', 0) ?: null;

        // العضو المحدَّد لازم يكون فعلاً في نفس المجموعة — منع تكليف حد برة الفريق.
        if ($assigneeUserId) {
            $isMember = collect($this->collabService->hubData($groupId)['members'])->contains(fn ($m) => (int) $m['user_id'] === $assigneeUserId);
            if (!$isMember) {
                return $this->apiError('The selected assignee is not a member of this group.', null, 422);
            }
        }

        $task = $this->collabService->createTask($groupId, $userId, trim((string) $request->input('title')), $assigneeUserId, $request->input('due_date'));

        $this->auditLog->record($userId, 'student.group_hub.task_created', 'GroupTask', $task->id);

        return $this->apiSuccess($task->toArray(), 'Task created successfully.', 201);
    }

    /** PATCH /api/v1/group-hub/tasks/{id}/status */
    public function setTaskStatus(Request $request, $id)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        $status = (string) $request->input('status');
        $task = $this->collabService->setTaskStatus($groupId, (int) $id, $status);
        if (!$task) {
            return $this->apiError('Task not found, or the status is invalid.', null, 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $this->auditLog->record($userId, 'student.group_hub.task_status_update', 'GroupTask', $task->id, null, ['status' => $status]);

        return $this->apiSuccess($task->toArray(), 'Task status updated successfully.');
    }

    /** DELETE /api/v1/group-hub/tasks/{id} */
    public function deleteTask(Request $request, $id)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }
        [$groupId, $err] = $this->resolveGroup($request);
        if ($err) {
            return $err;
        }

        if (!$this->collabService->deleteTask($groupId, (int) $id)) {
            return $this->apiError('Task not found.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $reason = trim((string) $request->input('reason', ''));
        $this->auditLog->record($userId, 'student.group_hub.task_deleted', 'GroupTask', (int) $id, null, $reason !== '' ? ['reason' => $reason] : null);

        return $this->apiSuccess(null, 'Task deleted successfully.');
    }
}
