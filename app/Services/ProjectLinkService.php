<?php

namespace App\Services;

use App\Models\ProjectLink;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة بالكامل من app/Services/ProjectLinkService.php القديمة —
 * تنسيق CRUD لينكات المشروع فوق ProjectLinkRepository، بنفس شكل
 * ProjectPublishingService ("الكنترولر عمره ما يلمس الـ repository/
 * الموديل مباشرة"). القواعد اللي هنا: type بنيوي، لينك primary واحد بس
 * لكل مشروع، وحد أقصى لعدد اللينكات.
 */
class ProjectLinkService
{
    /** بيخلي قائمة لينكات المشروع مقروءة — نفس منطق حد التاجات في ProjectPublishingService. */
    private const MAX_LINKS_PER_PROJECT = 15;

    public function __construct(
        private ProjectLinkRepository $links,
        private ProjectRepository $projects
    ) {
    }

    /** @return ProjectLink[] */
    public function listForOwner(string $projectUuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->links->forProject($project->id);
    }

    /**
     * @param array $data input خام: type, url, label (اختياري), is_primary (اختياري bool)
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    public function addForOwner(string $projectUuid, $ownerId, array $data): ProjectLink
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }

        [$type, $url, $label, $isPrimary] = $this->validated($data);

        if ($this->links->countForProject($project->id) >= self::MAX_LINKS_PER_PROJECT) {
            throw new \RuntimeException('A project can have at most ' . self::MAX_LINKS_PER_PROJECT . ' links.');
        }

        // أول لينك في مشروع بيبقى primary تلقائيًا حتى لو الكولر مطلبش
        // كده — مشروع عنده أي لينكات لازم يفضل عنده primary واحد بالظبط
        // بمجرد ما يكون عنده لينك واحد على الأقل.
        $isPrimary = $isPrimary || $this->links->countForProject($project->id) === 0;

        $link = $this->links->create([
            'project_id' => $project->id,
            'type'       => $type,
            'url'        => $url,
            'label'      => $label,
            'is_primary' => $isPrimary ? 1 : 0,
        ]);

        if ($isPrimary) {
            $this->links->clearPrimaryExcept($project->id, $link->id);
        }

        Log::info('Project link added', ['project_id' => $project->id, 'link_id' => $link->id, 'type' => $type]);

        return $link;
    }

    /**
     * @param array $data input خام: type, url, label (اختياري), is_primary (اختياري bool)
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    public function updateForOwner(string $projectUuid, $ownerId, $linkId, array $data): ProjectLink
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }

        $link = $this->links->findForProject($linkId, $project->id);
        if (!$link) {
            throw new \RuntimeException('Link not found.');
        }

        [$type, $url, $label, $isPrimary] = $this->validated($data);

        $link->fill([
            'type'       => $type,
            'url'        => $url,
            'label'      => $label,
            'is_primary' => $isPrimary ? 1 : 0,
        ]);
        $link->save();

        if ($isPrimary) {
            $this->links->clearPrimaryExcept($project->id, $link->id);
        }

        Log::info('Project link updated', ['project_id' => $project->id, 'link_id' => $link->id]);

        return $link;
    }

    public function deleteForOwner(string $projectUuid, $ownerId, $linkId): bool
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            return false;
        }
        return $this->links->deleteForProject($linkId, $project->id);
    }

    /**
     * @return array{0:string,1:string,2:?string,3:bool} [type, url, label, is_primary]
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    private function validated(array $data): array
    {
        $validator = Validator::make($data, [
            'type'  => 'required|in:' . implode(',', ProjectLink::TYPES),
            'url'   => 'required|url|max:500',
            'label' => 'nullable|max:150',
        ]);

        if ($validator->fails()) {
            throw new \RuntimeException($validator->errors()->first() ?: 'Invalid link data.');
        }

        $type = $data['type'];
        $url = trim((string) $data['url']);
        if ($type === 'github' && !ProjectLink::isValidGithubRepoUrl($url)) {
            throw new \RuntimeException('Enter a valid GitHub repository URL, e.g. https://github.com/username/project');
        }
        $label = isset($data['label']) ? trim((string) $data['label']) : null;
        $label = $label !== '' ? $label : null;
        $isPrimary = filter_var($data['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [$type, $url, $label, $isPrimary];
    }
}
