<?php

namespace App\Services;

use App\Models\GroupAnnouncement;
use App\Models\GroupFile;
use App\Models\GroupTask;
use App\Models\StudentGroup;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Group Hub (StudentGroupHub.jsx — Timeline/Announcements/Files/Tasks
 * لمجموعة الطالب). groupIdForStudent() هي الجزء اللي كان منقول من الأول
 * (GroupsApiController محتاجاها في الجانب القرائي بدور student). باقي
 * الكلاس ده — hubData() + CRUD الإعلانات/الملفات/المهام — بيغطي بند
 * "Group Hub" اللي كان متأجل (راجع migration
 * 2026_08_28_000100_create_group_hub_tables.php). كل ميثودات
 * الكتابة/الحذف بتاخد $groupId وبتتحقق منه أول حاجة (findOwnedRow) —
 * عضو مجموعة مقدرش يلمس محتوى مجموعة تانية حتى لو عرف الـ id.
 */
class GroupCollaborationService
{
    public function __construct(
        private StudentRepository $students,
        private StudentGroupRepository $groups,
        private FileUploadService $uploads
    ) {
    }

    /** بترجع group_id بتاع الطالب اللي عامل الطلب، أو null لو مش منضم لمجموعة. */
    public function groupIdForStudent($userId): ?int
    {
        $student = $this->students->getOrCreate($userId);
        return $student->group_id ? (int) $student->group_id : null;
    }

    /**
     * بيانات الأربع تابات كاملة لمجموعة واحدة — members (من
     * StudentGroupRepository::members، user_id/full_name/email جاهزين
     * لقايمة الـ assignee في الفرونت)، announcements/files/tasks كل
     * واحدة متربطة باسم صاحبها، وtimeline: دمج التلاتة (+ حدث تاني لكل
     * task اتعمل done) مرتبة الأحدث الأول.
     */
    public function hubData($groupId): array
    {
        $group = StudentGroup::find($groupId);

        $members = $this->groups->members($groupId);

        $announcements = DB::table('group_announcements as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.id', 'a.group_id', 'a.user_id', 'a.title', 'a.body', 'a.created_at', 'u.full_name as author_name')
            ->where('a.group_id', $groupId)
            ->orderByDesc('a.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $files = DB::table('group_files as f')
            ->join('users as u', 'u.id', '=', 'f.user_id')
            ->select(
                'f.id', 'f.group_id', 'f.user_id', 'f.original_name', 'f.stored_path',
                'f.mime_type', 'f.size_bytes', 'f.description', 'f.created_at',
                'u.full_name as uploader_name'
            )
            ->where('f.group_id', $groupId)
            ->orderByDesc('f.created_at')
            ->get()
            ->map(function ($r) {
                $row = (array) $r;
                $row['file_path'] = $row['stored_path'];
                $row['url'] = '/' . ltrim($row['stored_path'], '/');
                return $row;
            })
            ->all();

        $tasks = DB::table('group_tasks as t')
            ->join('users as creator', 'creator.id', '=', 't.created_by_user_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 't.assignee_user_id')
            ->select(
                't.id', 't.group_id', 't.created_by_user_id', 't.assignee_user_id',
                't.title', 't.status', 't.due_date', 't.completed_at', 't.created_at',
                'creator.full_name as creator_name', 'assignee.full_name as assignee_name'
            )
            ->where('t.group_id', $groupId)
            ->orderByRaw("field(t.status, 'todo', 'in_progress', 'done')")
            ->orderByDesc('t.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $timeline = [];
        foreach ($announcements as $a) {
            $timeline[] = ['type' => 'announcement', 'actor_name' => $a['author_name'], 'title' => $a['title'], 'at' => $a['created_at']];
        }
        foreach ($files as $f) {
            $timeline[] = ['type' => 'file', 'actor_name' => $f['uploader_name'], 'title' => $f['original_name'], 'at' => $f['created_at']];
        }
        foreach ($tasks as $t) {
            $timeline[] = ['type' => 'task', 'actor_name' => $t['creator_name'], 'title' => $t['title'], 'at' => $t['created_at']];
            if ($t['status'] === 'done' && $t['completed_at']) {
                $timeline[] = ['type' => 'task_done', 'actor_name' => $t['assignee_name'] ?? $t['creator_name'], 'title' => $t['title'], 'at' => $t['completed_at']];
            }
        }
        usort($timeline, fn ($x, $y) => strcmp((string) $y['at'], (string) $x['at']));
        $timeline = array_slice($timeline, 0, 30);

        return [
            'group_id'      => (int) $groupId,
            'group_name'    => $group?->name,
            'members'       => $members,
            'announcements' => $announcements,
            'files'         => $files,
            'tasks'         => $tasks,
            'timeline'      => $timeline,
        ];
    }

    public function postAnnouncement($groupId, $userId, string $title, $body): GroupAnnouncement
    {
        return GroupAnnouncement::create([
            'group_id' => $groupId,
            'user_id'  => $userId,
            'title'    => $title,
            'body'     => $body !== null && $body !== '' ? $body : null,
        ]);
    }

    public function deleteAnnouncement($groupId, $id): bool
    {
        $announcement = GroupAnnouncement::where('id', $id)->where('group_id', $groupId)->first();
        if (!$announcement) {
            return false;
        }
        $announcement->delete();
        return true;
    }

    /** @throws \RuntimeException لو الملف مرفوش صالح (نفس رسائل FileUploadService). */
    public function uploadFile($groupId, $userId, ?UploadedFile $file, string $description): GroupFile
    {
        $stored = $this->uploads->store($file, 'group_files', (string) $groupId);

        return GroupFile::create([
            'group_id'      => $groupId,
            'user_id'       => $userId,
            'original_name' => $stored['original_name'],
            'stored_path'   => $stored['stored_path'],
            'mime_type'     => $stored['mime_type'],
            'size_bytes'    => $stored['size_bytes'],
            'description'   => $description !== '' ? $description : null,
        ]);
    }

    public function deleteFile($groupId, $id): bool
    {
        $file = GroupFile::where('id', $id)->where('group_id', $groupId)->first();
        if (!$file) {
            return false;
        }
        $this->uploads->delete($file->stored_path);
        $file->delete();
        return true;
    }

    public function createTask($groupId, $userId, string $title, ?int $assigneeUserId, $dueDate): GroupTask
    {
        return GroupTask::create([
            'group_id'           => $groupId,
            'created_by_user_id' => $userId,
            'assignee_user_id'   => $assigneeUserId,
            'title'              => $title,
            'status'             => 'todo',
            'due_date'           => $dueDate ?: null,
        ]);
    }

    /** بيرجع null لو المهمة مش لقية جوه المجموعة دي أو الـ status مش من القيم المسموحة. */
    public function setTaskStatus($groupId, $id, string $status): ?GroupTask
    {
        if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
            return null;
        }
        $task = GroupTask::where('id', $id)->where('group_id', $groupId)->first();
        if (!$task) {
            return null;
        }
        $task->status = $status;
        $task->completed_at = $status === 'done' ? now() : null;
        $task->save();
        return $task;
    }

    public function deleteTask($groupId, $id): bool
    {
        $task = GroupTask::where('id', $id)->where('group_id', $groupId)->first();
        if (!$task) {
            return false;
        }
        $task->delete();
        return true;
    }
}
