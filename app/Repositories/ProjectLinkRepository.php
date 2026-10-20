<?php

namespace App\Repositories;

use App\Models\ProjectLink;
use Illuminate\Support\Facades\DB;

/**
 * منقولة بالكامل من app/Repositories/ProjectLinkRepository.php القديمة —
 * وصول للبيانات لجدول `project_links`. بنفس شكل ProjectFileRepository:
 * عرض حسب المشروع، find/delete مقيّد بالمشروع عشان لينك مايتقراش/يتمسحش
 * بـ id تابع لمشروع تاني.
 */
class ProjectLinkRepository
{
    /** @return ProjectLink[] كل لينكات مشروع، الـ primary الأول ثم الأحدث. */
    public function forProject($projectId): array
    {
        return ProjectLink::where('project_id', $projectId)->orderByDesc('is_primary')->orderByDesc('created_at')->get()->all();
    }

    public function create(array $data): ProjectLink
    {
        return ProjectLink::create($data);
    }

    /** يجيب لينك بالـ id، بس لو تابع لـ $projectId — وإلا null (نفس نمط ProjectFileRepository::findForProject الآمن). */
    public function findForProject($linkId, $projectId): ?ProjectLink
    {
        $link = ProjectLink::find($linkId);
        if ($link && (string) $link->project_id === (string) $projectId) {
            return $link;
        }
        return null;
    }

    public function deleteForProject($linkId, $projectId): bool
    {
        $link = $this->findForProject($linkId, $projectId);
        if (!$link) {
            return false;
        }
        return (bool) $link->delete();
    }

    /** بيصفّر علم is_primary لأي لينك تاني في نفس المشروع — بيتستخدم لما لينك جديد/معدّل يتحط primary، عشان يفضل واحد بس true لكل مشروع. */
    public function clearPrimaryExcept($projectId, $exceptLinkId): void
    {
        DB::table('project_links')
            ->where('project_id', $projectId)
            ->where('id', '!=', $exceptLinkId)
            ->update(['is_primary' => 0]);
    }

    public function countForProject($projectId): int
    {
        return ProjectLink::where('project_id', $projectId)->count();
    }

    /**
     * اللينك اللي المفروض يتعامل معاه ككول "اللينك" من نوع معين لمشروع —
     * زي primaryByType($id, 'github') لصفحة اعتماد الجامعة أو GitHub Code
     * Review (بدل عمود projects.repository_url القديم). بيفضّل is_primary=1
     * لو من النوع ده، وإلا آخر واحد اتضاف من نفس النوع.
     */
    public function primaryByType($projectId, string $type): ?ProjectLink
    {
        return ProjectLink::where('project_id', $projectId)
            ->where('type', $type)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->first();
    }
}
