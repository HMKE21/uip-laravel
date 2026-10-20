<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Repositories\CategoryRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * منقولة بالكامل من app/Services/ProjectPublishingService.php القديمة —
 * تنسيق إنشاء مشروع الطالب + الانتقال submit-for-approval فوق
 * ProjectRepository. الكنترولر بينادي هنا بدل ما يلمس الـ repository/
 * الموديل مباشرة، عشان الفاليديشن + قواعد الحالة تفضل في مكان واحد
 * مشترك بين كل نقاط الدخول (كان الفورم القديم، دلوقتي REST API).
 */
class ProjectPublishingService
{
    /** الحالات اللي الطالب لسه يقدر يعدّلها / يرفق ملفات ليها بحرية. */
    private const EDITABLE_STATUSES = ['draft', 'rejected']; // مقفول بعد التقديم

    public function __construct(
        private ProjectRepository $projects,
        private StudentRepository $students,
        private ProjectFileRepository $files,
        private FileUploadService $uploads,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private NotificationService $notifications,
        private ProjectLinkRepository $links,
        private DepartmentRepository $departments,
        private CategoryRepository $categories,
        private MediaProbeService $mediaProbe,
        private ProjectNotifier $notifier
    ) {
    }

    /**
     * بتحوّل category_id المُرسل لكل من projects.category_id (الـ FK
     * الحقيقي، migration 138) وعمود projects.category النصي القديم
     * (فاضل متزامن عشان أي قارئ لسه ماتحولش يشوف قيمة منطقية بدل فاضي).
     * لو معندوش category_id، بيسيب النص القديم زي ما هو (فورم الباحث
     * القديم لسه بيبعت نص حر).
     * @return array{category_id:?int,category:?string}
     */
    private function resolveCategory(array $data): array
    {
        $categoryId = $data['category_id'] ?? null;
        if ($categoryId === null || $categoryId === '') {
            return ['category_id' => null, 'category' => $data['category'] ?? null];
        }

        $category = $this->categories->find((int) $categoryId);
        if (!$category) {
            return ['category_id' => null, 'category' => $data['category'] ?? null];
        }

        return ['category_id' => $category->id, 'category' => $category->name_en];
    }

    /**
     * الجامعة/الكلية/القسم اللي مشروع منشأ من صاحب الحساب ده هيتربط بيهم
     * تلقائيًا — مسحوبة من بروفايل الطالب نفسه (students.university_id/
     * faculty_id/department_id)، مش متكتبة تاني في فورم المشروع.
     * @return array{university:?string,faculty:?string,department:?string,has_university:bool}
     */
    public function affiliationForOwner($ownerId): array
    {
        $student = $this->students->findByUserId($ownerId);

        $university = $student?->university_id ? $this->universities->find($student->university_id) : null;
        $faculty = $student?->faculty_id ? $this->faculties->find($student->faculty_id) : null;
        $department = $student?->department_id ? $this->departments->find($student->department_id) : null;

        return [
            'has_university' => (bool) $university,
            'university'     => $university?->official_name_en ?: $university?->official_name_ar,
            'university_ar'  => $university?->official_name_ar,
            'faculty'        => $faculty?->name('en') ?: ($faculty?->name('ar') ?: $student?->faculty),
            'department'     => $department?->name('en') ?: ($department?->name('ar') ?: $student?->department),
        ];
    }

    /**
     * @param array $data input متحقق منه: title_en, title_ar, category, summary,
     *                     repository_url (اختياري), save_as_draft (bool)
     */
    public function create($ownerId, array $data): Project
    {
        // المشروع دايمًا بيتنشأ draft: الملفات والفريق بيتضافوا بعد الإنشاء، والتقديم بيتم بـ submit بعد قايمة التحقق.
        $status = 'draft';

        $student = $this->students->findByUserId($ownerId);
        $categoryFields = $this->resolveCategory($data);

        $project = $this->projects->createForOwner($ownerId, [
            'title_ar'        => $data['title_ar'],
            'title_en'        => $data['title_en'] ?? null,
            'summary'         => $data['summary'],
            'category'        => $categoryFields['category'],
            'category_id'     => $categoryFields['category_id'],
            'supervisor_name' => $this->cleanSupervisorName($data['supervisor_name'] ?? null),
            'tags'            => $this->encodeTags($data['tags'] ?? null),
            'status'          => $status,
            'university_id'   => $student?->university_id,
        ]);

        $this->syncLinkByType($project, $data['repository_url'] ?? null, 'github');
        $this->syncLinkByType($project, $data['demo_url'] ?? null, 'live_demo');

        $this->notifier->linkSupervisor($project);
        if ($status === 'submitted') {
            $this->notifier->notifySubmitted($project);
        }

        Log::info('Project created', ['project_id' => $project->id, 'owner_id' => $ownerId, 'status' => $status]);

        return $project;
    }

    /** @return Project[] */
    public function listForOwner($ownerId): array
    {
        return $this->projects->forOwner($ownerId);
    }

    public function findOwned(string $uuid, $ownerId): ?Project
    {
        return $this->projects->findOwnedByUuid($uuid, $ownerId);
    }

    public function delete(string $uuid, $ownerId): bool
    {
        return $this->projects->deleteOwned($uuid, $ownerId);
    }

    /** بعد ما مشروع يتنشر، الطالب بيعدّل عن طريق مسار إعادة تقديم تاني (خارج نطاق هنا)، فمشروع منشور بيتعامل معاه كمقفول. */
    public function canEdit(Project $project): bool
    {
        return in_array($project->status, self::EDITABLE_STATUSES, true);
    }

    /**
     * زرار "Submit for Review" بتاع الفرونت (مش endpoint في القديمة —
     * القديمة كانت بتحدد submitted/draft وقت create() بس عبر
     * save_as_draft، الفرونت الحالي محتاج مسار submit منفصل لمشروع
     * محفوظ draft قبل كده). draft بس هو القابل للتقديم.
     */
    public function submit(string $uuid, $ownerId): bool
    {
        // التقديم الفعلي (مع قايمة التحقق) في ResearchProjectService::submit().
        return false;
    }

    /** أرشفة مشروع منشور (إخفاؤه من التصفح العام من غير ما يتمسح) — زرار الفرونت. */
    public function archive(string $uuid, $ownerId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || $project->status !== 'published') {
            return false;
        }
        return $this->projects->updateOwned($uuid, $ownerId, ['status' => 'archived']);
    }

    /** إلغاء أرشفة مشروع — بيرجعه published تاني. */
    public function unarchive(string $uuid, $ownerId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || $project->status !== 'archived') {
            return false;
        }
        return $this->projects->updateOwned($uuid, $ownerId, ['status' => 'published']);
    }

    /**
     * @param array $data input متحقق منه: title_en, title_ar, category, summary,
     *                     repository_url (اختياري)
     */
    public function update(string $uuid, $ownerId, array $data): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || !$this->canEdit($project)) {
            return false;
        }

        $categoryFields = $this->resolveCategory($data);

        $updated = $this->projects->updateOwned($uuid, $ownerId, [
            'title_ar'        => $data['title_ar'],
            'title_en'        => $data['title_en'] ?? null,
            'summary'         => $data['summary'],
            'category'        => $categoryFields['category'],
            'category_id'     => $categoryFields['category_id'],
            'supervisor_name' => $this->cleanSupervisorName($data['supervisor_name'] ?? null),
            'tags'            => $this->encodeTags($data['tags'] ?? null),
        ]);

        if ($updated) {
            $this->notifier->linkSupervisor($this->projects->findOwnedByUuid($uuid, $ownerId));
            $this->syncLinkByType($project, $data['repository_url'] ?? null, 'github');
            $this->syncLinkByType($project, $data['demo_url'] ?? null, 'live_demo');
            Log::info('Project updated', ['project_id' => $project->id, 'owner_id' => $ownerId]);
        }

        return $updated;
    }

    /**
     * بيخلّي صف project_links الوحيد من نوع 'github'/'live_demo' متزامن
     * مع حقلي فورم repository_url/demo_url القديمين: بيعمل صف project_links
     * أول مرة يتحدد فيها رابط، بيعدّله في مكانه لو اتغيّر، بيشيله لو
     * الحقل فضي. دايمًا متعلّم primary.
     */
    private function syncLinkByType(Project $project, ?string $url, string $type): void
    {
        $url = trim((string) $url);
        $existing = null;
        foreach ($this->links->forProject($project->id) as $link) {
            if ($link->type === $type) {
                $existing = $link;
                break;
            }
        }

        if ($url === '') {
            if ($existing) {
                $this->links->deleteForProject($existing->id, $project->id);
            }
            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return;
        }

        if ($existing) {
            $existing->fill(['url' => $url]);
            $existing->save();
            return;
        }

        $link = $this->links->create([
            'project_id' => $project->id,
            'type'       => $type,
            'url'        => $url,
            'is_primary' => 1,
        ]);
        $this->links->clearPrimaryExcept($project->id, $link->id);
    }

    /** @return ProjectFile[] */
    public function listFiles(string $uuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->files->forProject($project->id);
    }

    /** @return ProjectFile[] وسائط مترتبة للمعرض (image/video/video_link) — تاب "Gallery" بتاع الطالب. */
    public function listMedia(string $uuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->files->mediaForProject($project->id);
    }

    /**
     * فاليديشن + تخزين ملف مرفوع على مشروع الكولر بيملكه، وتسجيله في
     * project_files. بترمي \RuntimeException (رسالتها آمنة تتعرض
     * للمستخدم) على أي فشل فاليديشن.
     */
    public function addFile(string $uuid, $ownerId, ?UploadedFile $uploadedFile): ProjectFile
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        if (!$this->canEdit($project)) {
            throw new \RuntimeException('This project can no longer accept new files.');
        }

        $stored = $this->uploads->store($uploadedFile, 'research-projects', $project->uuid);
        $fileType = $this->classifyFileType($stored['extension']);
        $probe = $fileType === 'video' ? $this->probeVideo($stored['stored_path']) : ['thumbnail_path' => null, 'duration_seconds' => null];

        $file = $this->files->create([
            'project_id'       => $project->id,
            'uploaded_by'      => $ownerId,
            'file_type'        => $fileType,
            'file_path'        => $stored['stored_path'],
            'original_name'    => $stored['original_name'],
            'mime_type'        => $stored['mime_type'],
            'size_bytes'       => $stored['size_bytes'],
            'is_latest'        => 1,
            'thumbnail_path'   => $probe['thumbnail_path'],
            'duration_seconds' => $probe['duration_seconds'],
        ]);

        Log::info('Project file uploaded', ['project_id' => $project->id, 'file_id' => $file->id]);

        return $file;
    }

    /**
     * @return array{thumbnail_path:?string,duration_seconds:?int} بتعمل
     * ffprobe/ffmpeg على فيديو اتخزن لتوه. فاشلة-مفتوحة: لو الأدوات مش
     * متثبتة، الفيديو لسه بيترفع عادي بـ null.
     */
    private function probeVideo(string $relativeStoredPath): array
    {
        $absolutePath = public_path(ltrim($relativeStoredPath, '/'));
        $relativeDir = trim(dirname($relativeStoredPath), '/');

        return [
            'thumbnail_path'   => $this->mediaProbe->videoThumbnail($absolutePath, $relativeDir),
            'duration_seconds' => $this->mediaProbe->duration($absolutePath),
        ];
    }

    public function deleteFile(string $uuid, $ownerId, $fileId): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || !$this->canEdit($project)) {
            return false;
        }

        $file = $this->files->findForProject($fileId, $project->id);
        if (!$file) {
            return false;
        }

        if ($file->file_path) {
            $this->uploads->delete($file->file_path);
        }
        return $this->files->deleteForProject($fileId, $project->id);
    }

    /**
     * بيستبدل محتوى ملف موجود في مكانه: الرفع الجديد بياخد نفس الصف/
     * الـ id (فأي لينك ليه يفضل شغال)، الملف القديم المخزّن على القرص
     * بيتشال.
     */
    public function replaceFile(string $uuid, $ownerId, $fileId, ?UploadedFile $uploadedFile): ProjectFile
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        if (!$this->canEdit($project)) {
            throw new \RuntimeException('This project can no longer accept new files.');
        }

        $existing = $this->files->findForProject($fileId, $project->id);
        if (!$existing) {
            throw new \RuntimeException('File not found.');
        }

        $stored = $this->uploads->store($uploadedFile, 'research-projects', $project->uuid);
        $oldPath = $existing->file_path;
        $fileType = $this->classifyFileType($stored['extension']);
        $probe = $fileType === 'video' ? $this->probeVideo($stored['stored_path']) : ['thumbnail_path' => null, 'duration_seconds' => null];

        $existing->fill([
            'file_type'        => $fileType,
            'file_path'        => $stored['stored_path'],
            'original_name'    => $stored['original_name'],
            'mime_type'        => $stored['mime_type'],
            'size_bytes'       => $stored['size_bytes'],
            'thumbnail_path'   => $probe['thumbnail_path'],
            'duration_seconds' => $probe['duration_seconds'],
        ]);
        $existing->save();

        if ($oldPath && $oldPath !== $stored['stored_path']) {
            $this->uploads->delete($oldPath);
        }

        Log::info('Project file replaced', ['project_id' => $project->id, 'file_id' => $existing->id]);

        return $existing;
    }

    /**
     * بيضيف رابط YouTube/Vimeo للمعرض. على عكس addFile()/replaceFile()
     * دي عمرها ما بتلمس FileUploadService؛ الصف بيبقى file_path = null
     * و external_url بس متحدد.
     */
    public function addVideoLink(string $uuid, $ownerId, string $url, ?string $caption = null): ProjectFile
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        if (!$this->canEdit($project)) {
            throw new \RuntimeException('This project can no longer accept new media.');
        }

        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Please enter a valid link.');
        }
        if (!ProjectFile::extractYoutubeId($url) && !ProjectFile::extractVimeoId($url)) {
            throw new \RuntimeException('Only YouTube or Vimeo links are supported.');
        }

        $caption = trim((string) $caption);

        $file = $this->files->create([
            'project_id'    => $project->id,
            'uploaded_by'   => $ownerId,
            'file_type'     => 'video_link',
            'is_latest'     => 1,
            'external_url'  => $url,
            'original_name' => $caption !== '' ? $caption : $url,
            'caption'       => $caption !== '' ? $caption : null,
        ]);

        Log::info('Project video link added', ['project_id' => $project->id, 'file_id' => $file->id]);

        return $file;
    }

    /** تحديث caption / is_featured لعنصر معرض. بترجع null لو مش موجود أو مش مملوك/قابل للتعديل. */
    public function updateFileMeta(string $uuid, $ownerId, $fileId, array $data): ?ProjectFile
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || !$this->canEdit($project)) {
            return null;
        }
        return $this->files->updateMeta($fileId, $project->id, $data);
    }

    /** بيثبّت ترتيب عرض جديد للمعرض. $orderedIds: ids بالترتيب المطلوب. */
    public function reorderFiles(string $uuid, $ownerId, array $orderedIds): bool
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project || !$this->canEdit($project)) {
            return false;
        }
        $this->files->reorder($project->id, $orderedIds);
        return true;
    }

    /**
     * داتا الإكمال التلقائي لفورم إنشاء/تعديل مشروع: أسماء مشرفين
     * مستخدمة فعليًا في جامعة الكولر، زائد التاجات الأكتر استخدامًا على
     * مستوى المنصة كلها.
     * @return array{supervisors: string[], tags: string[]}
     */
    public function formAutocomplete($ownerId): array
    {
        $student = $this->students->findByUserId($ownerId);
        return [
            'supervisors' => $this->projects->distinctSupervisorNames($student?->university_id),
            'tags'        => $this->projects->distinctTags(),
        ];
    }

    private function cleanSupervisorName(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? mb_substr($value, 0, 150) : null;
    }

    /**
     * التاجات بتيجي من الفورم كسلسلة نصية مفصولة بفواصل؛ بتتخزن كـ JSON
     * array حقيقي يطابق عمود projects.tags، بعد إزالة التكرار وتحديد حد
     * أقصى.
     */
    private function encodeTags($raw): ?string
    {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = explode(',', (string) $raw);
        }

        $tags = [];
        foreach ($items as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || in_array($tag, $tags, true)) {
                continue;
            }
            $tags[] = mb_substr($tag, 0, 40);
            if (count($tags) >= 15) {
                break;
            }
        }

        return $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;
    }

    private function classifyFileType(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf', 'doc', 'docx' => 'document',
            'ppt', 'pptx' => 'presentation',
            'zip' => 'source_code',
            'jpg', 'jpeg', 'png', 'webp' => 'image',
            'mp4', 'mov' => 'video',
            default => 'other',
        };
    }
}
