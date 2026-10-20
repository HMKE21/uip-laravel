<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Repositories\CategoryRepository;
use App\Repositories\ProjectDiscussionRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectTeamMemberRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * منقولة جزئيًا من app/Services/ResearchProjectService.php القديمة (709
 * سطر) — بند 11 Phase 1. القديمة
 * اسمها "ResearchProjectService" بس غالبية ميثودزها عامة/محايدة الدور
 * فعليًا (بتحل المشروع عبر ProjectRepository::findOwnedByUuid()/
 * findByUuid() وتشتغل على نفس ProjectFileRepository/
 * ProjectTeamMemberRepository المشتركة)، فآمن تتعاد استخدامها هنا لأي
 * مالك — نفس تعليق docblock ProjectsApiController القديمة
 * بالظبط.
 *
 * هنا الجزء اللي ProjectsApiController محتاجاه فعليًا: submit/archive/
 * unarchive/delete/files/شير القراءة عبر عضو فريق مقبول (Phase 1).
 * inviteMember/respondToInvite/discussion هيتضافوا مع
 * توسعة لاحقة — StudentTeamService هي المستخدمة
 * لكتابات الفريق في Phase 1 (شوف ProjectsApiController).
 */
class ResearchProjectService
{
    /** الحالات اللي المالك لسه يقدر يعدّلها / يرفق ملفات ليها بحرية. */
    // التعديل (بيانات/ملفات) مقفول بعد التقديم؛ بيتفتح بس وهو draft (مسودة أو طُلب تعديل) أو rejected (قبل إعادة التقديم).
    private const EDITABLE_STATUSES = ['draft', 'rejected'];

    public function __construct(
        private ProjectRepository $projects,
        private ProjectFileRepository $files,
        private FileUploadService $uploads,
        private ProjectTeamMemberRepository $team,
        private AuditLogService $auditLog,
        private CategoryRepository $categories,
        private ProjectDiscussionRepository $discussion,
        private ProjectNotifier $notifier,
        private ProjectDeadlineService $deadlines,
        private ProjectApprovalService $approvals
    ) {
    }

    /** @return Project[] */
    public function listForOwner($ownerId): array
    {
        return $this->projects->forOwner($ownerId);
    }

    /** مشاريعي: اللي أنا مالكها + اللي اتضفت عليها كعضو فريق مقبول (طالب/معيد/دكتور). @return Project[] */
    public function listForUser($userId): array
    {
        $owned = $this->projects->forOwner($userId);
        $sharedIds = \Illuminate\Support\Facades\DB::table('project_team_members')
            ->where('user_id', $userId)->where('status', 'accepted')->pluck('project_id')->all();
        $shared = $sharedIds
            ? Project::whereIn('id', $sharedIds)->where('owner_id', '!=', $userId)->orderByDesc('created_at')->get()->all()
            : [];
        $all = array_merge($owned, $shared);
        usort($all, fn ($a, $b) => strcmp((string) $b->created_at, (string) $a->created_at));
        return $all;
    }

    public function findOwned(string $uuid, $ownerId): ?Project
    {
        return $this->projects->findOwnedByUuid($uuid, $ownerId);
    }

    public function canEdit(Project $project): bool
    {
        return in_array($project->status, self::EDITABLE_STATUSES, true);
    }

    // -- وصول مشترك / عضو فريق (قراءة بس) -----------------------------------

    /**
     * بتجيب مشروع المستخدم ده مسموحله يشوفه — إما لأنه مالكه، أو لأنه
     * عضو فريق ACCEPTED عليه (اتدعى عبر StudentTeamService::inviteMember()/
     * قبول الدعوة). بعكس findOwned()، دي قراءة بس — أي كتابة (تعديل/حذف/
     * إدارة ملفات/فريق/...) لسه بتتقفل على findOwned()/canEdit()،
     * ملكية فقط.
     */
    public function findAccessible(string $uuid, $userId): ?Project
    {
        $project = $this->projects->findByUuid($uuid);
        if (!$project) {
            return null;
        }
        if ((string) $project->owner_id === (string) $userId) {
            return $project;
        }
        return $this->team->isAcceptedMember($project->id, $userId) ? $project : null;
    }

    /** علاقة الزائر بالمشروع: 'owner'، أو دوره المقبول في الفريق، أو null لو ولا واحدة. */
    public function viewerRoleOnProject(Project $project, $userId): ?string
    {
        if ((string) $project->owner_id === (string) $userId) {
            return 'owner';
        }
        $member = $this->team->findAcceptedMember($project->id, $userId);
        return $member ? $member->role : null;
    }

    /** @return ProjectFile[] */
    public function listFilesFor(Project $project): array
    {
        return $this->files->forProjectLatest($project->id);
    }

    /** @return ProjectFile[] */
    public function listPriorFileVersionsFor(Project $project): array
    {
        return $this->files->priorVersionsForProject($project->id);
    }

    /** @return \App\Models\ProjectTeamMember[] */
    public function listTeamFor(Project $project): array
    {
        return $this->team->forProject($project->id);
    }

    // -- Discussion / Activity (بند 11 مرحلة 4) ------------------------------

    /** @return array<int,array{message:\App\Models\ProjectDiscussionMessage, author_name:?string}> */
    public function listDiscussionFor(Project $project): array
    {
        return $this->discussion->forProject($project->id);
    }

    /**
     * بوستينج لتاب Discussion كأي عضو فريق مقبول (مش المالك بس) — التعاون
     * هو أصلًا الهدف من التاب ده. الكولر لازم يكون خلّص حل $project عبر
     * findAccessible() الأول.
     * @return array{success:bool, message:string}
     */
    public function postDiscussionMessageAsMember(Project $project, $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'message' => 'Message cannot be empty.'];
        }

        $this->discussion->create([
            'project_id' => $project->id,
            'user_id'    => $userId,
            'message'    => mb_substr($message, 0, 4000),
        ]);

        $this->auditLog->record($userId, 'project.discussion_post', 'Project', $project->id, null, null);

        return ['success' => true, 'message' => 'Message posted.'];
    }

    /** سجل نشاط المشروع، الأحدث الأول — تاب Activity. الكولر لازم يكون خلّص حل $project عبر findAccessible(). */
    public function activityTimelineFor(Project $project, int $limit = 50): array
    {
        return $this->auditLog->forSubject('Project', (int) $project->id, $limit);
    }

    // -- حالة المشروع --------------------------------------------------------

    /**
     * قايمة التحقق قبل التقديم. blocking = لازم تتحل، warnings = تنبيه بس.
     * @return array{blocking: string[], warnings: string[], deadline: ?array}
     */
    public function submissionChecklist(Project $project): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $blocking = [];
        $warnings = [];

        if (trim((string) $project->title_ar) === '' || trim((string) $project->title_en) === '' || trim((string) $project->summary) === '') {
            $blocking[] = 'أكمل عنوان المشروع (عربي وإنجليزي) وملخصه.';
        }

        $hasSupervisor = trim((string) $project->supervisor_name) !== ''
            || $db::table('project_team_members')->where('project_id', $project->id)->where('status', 'accepted')
                ->whereIn('role', ['supervisor', 'professor', 'principal_investigator'])->exists();
        if (!$hasSupervisor) {
            $blocking[] = 'حدّد الدكتور المشرف على المشروع.';
        }

        $files = $db::table('project_files')->where('project_id', $project->id)->where('is_latest', 1)->get(['file_type', 'original_name']);
        $ext = fn ($f) => strtolower(pathinfo((string) $f->original_name, PATHINFO_EXTENSION));
        if (!$files->contains(fn ($f) => in_array($ext($f), ['pdf', 'doc', 'docx'], true))) {
            $blocking[] = 'ارفع تقرير المشروع (PDF أو Word).';
        }
        $hasCode = $files->contains(fn ($f) => $f->file_type === 'source_code')
            || $db::table('project_links')->where('project_id', $project->id)->whereIn('type', ['github', 'gitlab', 'repository'])->exists();
        if (!$hasCode) {
            $warnings[] = 'مفيش كود المشروع (ملف zip أو رابط GitHub).';
        }
        if (!$files->contains(fn ($f) => in_array($ext($f), ['ppt', 'pptx'], true))) {
            $warnings[] = 'مفيش عرض تقديمي للمشروع.';
        }

        $deadline = $this->deadlines->forProject($project);
        if ($deadline && !empty($deadline['submission_deadline'])) {
            $ts = strtotime((string) $deadline['submission_deadline']);
            $label = date('Y-m-d H:i', $ts);
            if ($ts < time()) {
                $blocking[] = "انتهى موعد تسليم المشروع ({$label}). تواصل مع الكلية لو محتاج مهلة.";
            } elseif ($ts - time() < 3 * 86400) {
                $warnings[] = "فاضل أقل من 3 أيام على موعد التسليم ({$label}).";
            }
        }

        return ['blocking' => $blocking, 'warnings' => $warnings, 'deadline' => $deadline];
    }

    /**
     * تقديم للمراجعة. بيقبل draft (أول مرة أو بعد طلب تعديل) و rejected (إعادة تقديم).
     * @throws \RuntimeException لو قايمة التحقق فيها بنود إجبارية ناقصة (الرسالة بنودها).
     */
    public function submit(string $uuid, $ownerId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || !in_array($project->status, ['draft', 'rejected'], true)) {
            return false;
        }
        $check = $this->submissionChecklist($project);
        if ($check['blocking']) {
            throw new \RuntimeException(implode("\n", $check['blocking']));
        }
        $previous = $project->status;
        // كل تقديم/إعادة تقديم = دورة مراجعة جديدة (review_round): موافقات الدورة القديمة مبتتحسبش،
        // والمسار كله بيتعاد بالترتيب من الدكتور المشرف.
        $ok = DB::transaction(function () use ($uuid, $ownerId, $project) {
            $updated = $this->projects->updateOwned($uuid, $ownerId, ['status' => 'submitted']);
            if ($updated) {
                DB::table('projects')->where('id', $project->id)->increment('review_round');
            }
            return $updated;
        });
        if ($ok) {
            $this->auditLog->record($ownerId, 'project.submit', 'Project', $project->id, ['status' => $previous], ['status' => 'submitted']);
            $submitted = $this->projects->findOwnedByUuid($uuid, $ownerId);
            $this->notifier->notifySubmitted($submitted, $submitted ? $this->approvals->flowFor($submitted)['awaiting'] : null);
        }
        return $ok;
    }

    /** أرشفة من أي حالة غير محذوفة — بتخفيه من تابات "My Projects" النشطة من غير ما تمسح بياناته. */
    public function archive(string $uuid, $ownerId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || $project->status === 'archived') {
            return false;
        }
        $ok = $this->projects->updateOwned($uuid, $ownerId, ['status' => 'archived']);
        if ($ok) {
            $this->auditLog->record($ownerId, 'project.archive', 'Project', $project->id, ['status' => $project->status], ['status' => 'archived']);
        }
        return $ok;
    }

    /** بترجّع مشروع مؤرشف لـ draft — عشان يدخل تاني في تدفق التعديل/التقديم العادي. */
    public function unarchive(string $uuid, $ownerId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || $project->status !== 'archived') {
            return false;
        }
        $ok = $this->projects->updateOwned($uuid, $ownerId, ['status' => 'draft']);
        if ($ok) {
            $this->auditLog->record($ownerId, 'project.unarchive', 'Project', $project->id, ['status' => 'archived'], ['status' => 'draft']);
        }
        return $ok;
    }

    public function delete(string $uuid, $ownerId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        $deleted = $this->projects->deleteOwned($uuid, $ownerId);
        if ($deleted && $project) {
            $this->auditLog->record($ownerId, 'project.delete', 'Project', $project->id, $project->toArray(), null);
        }
        return $deleted;
    }

    // -- ملفات ---------------------------------------------------------------

    /** @return ProjectFile[] بس أحدث نسخة من كل ملف (سجل الإصدارات في مكان تاني). */
    public function listFiles(string $uuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->files->forProjectLatest($project->id);
    }

    /** كل ملف اتستبدل بنسخة أحدث، عبر المشروع كله. @return ProjectFile[] */
    public function listPriorFileVersions(string $uuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->files->priorVersionsForProject($project->id);
    }

    /**
     * المالك، أو طالب عضو فريق مقبول (student_member / collaborator) — بيقدر يرفع ويستبدل ملفات.
     * الدكاترة/المعيدين وأي دور تاني مبيرفعوش.
     */
    private function findContributable(string $uuid, $userId): ?Project
    {
        $project = $this->projects->findByUuid($uuid);
        if (!$project) {
            return null;
        }
        if ((string) $project->owner_id === (string) $userId) {
            return $project;
        }
        $m = $this->team->findAcceptedMember($project->id, $userId);
        return ($m && in_array($m->role, ['student_member', 'collaborator'], true)) ? $project : null;
    }

    public function addFile(string $uuid, $ownerId, $uploadedFile): ProjectFile
    {
        $project = $this->findContributable($uuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        if (!$this->canEdit($project)) {
            throw new \RuntimeException('This project can no longer accept new files.');
        }

        $stored = $this->uploads->store($uploadedFile, 'research-projects', $project->uuid);

        $file = $this->files->create([
            'project_id'    => $project->id,
            'uploaded_by'   => $ownerId,
            'file_type'     => $this->classifyFileType($stored['extension']),
            'file_path'     => $stored['stored_path'],
            'original_name' => $stored['original_name'],
            'mime_type'     => $stored['mime_type'],
            'size_bytes'    => $stored['size_bytes'],
            'version'       => 1,
            'is_latest'     => 1,
            'root_file_id'  => null,
        ]);

        Log::info('Project file uploaded', ['project_id' => $project->id, 'file_id' => $file->id]);
        $this->auditLog->record($ownerId, 'project.file_upload', 'Project', $project->id, null, ['file' => $stored['original_name']]);

        return $file;
    }

    /**
     * رفع نسخة جديدة من ملف موجود ("Manage Versions"). النسخة السابقة
     * فاضلة (مش بتتمسح) عشان تفضل قابلة للتحميل من بانل سجل الإصدارات؛
     * الرفعة الجديدة بس اللي بتتعلّم is_latest.
     */
    public function replaceFile(string $uuid, $ownerId, $fileId, $uploadedFile, bool $force = false): ProjectFile
    {
        $project = $this->findContributable($uuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        if (!$this->canEdit($project)) {
            throw new \RuntimeException('This project can no longer accept new files.');
        }

        $previous = $this->files->findForProject($fileId, $project->id);
        if (!$previous) {
            throw new \RuntimeException('File not found.');
        }

        // محرر الـ ZIP: لو حد تاني من الفريق حفظ نسخة أحدث بعد ما الشخص ده فتح الملف، الـ base بتاعه
        // بقى قديم. من غير فحص كان هيطلع صفّين is_latest=1 لنفس الملف، وحفظ الأول يتمسح بصمت.
        // 409 = "فيه نسخة أحدث"؛ force=1 = احفظ فوق الأحدث (النسخة التانية فاضلة في سجل الإصدارات).
        if (!$previous->is_latest) {
            $latest = null;
            foreach ($this->files->versionsOf($previous->id, $project->id) as $v) {
                if ($v->is_latest) {
                    $latest = $v;
                    break;
                }
            }
            if ($latest && !$force) {
                throw new \RuntimeException('A newer version of this file was saved by someone else.', 409);
            }
            if ($latest) {
                $previous = $latest;
            }
        }

        $stored = $this->uploads->store($uploadedFile, 'research-projects', $project->uuid);
        $rootId = $previous->root_file_id ?: $previous->id;

        $file = DB::transaction(function () use ($project, $ownerId, $stored, $previous, $rootId) {
            // قفل الصف القديم: حفظتين متزامنتين من نفس الـ base مينفعش الاتنين ينجحوا.
            $locked = ProjectFile::whereKey($previous->id)->lockForUpdate()->first();
            if (!$locked || !$locked->is_latest) {
                $this->uploads->delete($stored['stored_path']);
                throw new \RuntimeException('A newer version of this file was saved by someone else.', 409);
            }

            $created = $this->files->create([
                'project_id'    => $project->id,
                'uploaded_by'   => $ownerId,
                'file_type'     => $this->classifyFileType($stored['extension']),
                'file_path'     => $stored['stored_path'],
                'original_name' => $stored['original_name'],
                'mime_type'     => $stored['mime_type'],
                'size_bytes'    => $stored['size_bytes'],
                'version'       => (int) $locked->version + 1,
                'is_latest'     => 1,
                'root_file_id'  => $rootId,
            ]);

            $locked->fill(['is_latest' => 0]);
            $locked->save();

            return $created;
        });

        Log::info('Project file replaced', ['project_id' => $project->id, 'file_id' => $file->id, 'previous_file_id' => $previous->id]);
        $this->auditLog->record($ownerId, 'project.file_replace', 'Project', $project->id, ['file' => $previous->original_name, 'version' => $previous->version], ['file' => $stored['original_name'], 'version' => $file->version]);

        return $file;
    }

    /** تاريخ الإصدارات الكامل (الأحدث الأول) لـ"عائلة" ملف واحدة. @return ProjectFile[] */
    public function fileVersionHistory(string $uuid, $ownerId, $fileId): array
    {
        $project = $this->findAccessible($uuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->files->versionsOf($fileId, $project->id);
    }

    public function deleteFile(string $uuid, $ownerId, $fileId): bool
    {
        $project = $this->findContributable($uuid, $ownerId);
        if (!$project || !$this->canEdit($project)) {
            return false;
        }

        $file = $this->files->findForProject($fileId, $project->id);
        if (!$file) {
            return false;
        }
        // العضو بيمسح الملفات اللي هو رفعها بس؛ المالك بيمسح أي ملف.
        if ((string) $project->owner_id !== (string) $ownerId && (string) $file->uploaded_by !== (string) $ownerId) {
            return false;
        }

        $this->uploads->delete($file->file_path);
        $deleted = $this->files->deleteForProject($fileId, $project->id);
        if ($deleted) {
            $this->auditLog->record($ownerId, 'project.file_delete', 'Project', $project->id, ['file' => $file->original_name], null);
        }
        return $deleted;
    }

    /** لازم القيم ترجع جوه enum عمود project_files.file_type: document|image|video|video_link|presentation|source_code|other */
    private function classifyFileType(string $extension): string
    {
        $extension = strtolower($extension);
        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            in_array($extension, ['mp4', 'mov', 'webm', 'avi'], true)        => 'video',
            in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true) => 'document',
            in_array($extension, ['ppt', 'pptx'], true)                     => 'presentation',
            in_array($extension, ['zip', 'rar', '7z'], true)                => 'source_code',
            default                                                          => 'other',
        };
    }
}
