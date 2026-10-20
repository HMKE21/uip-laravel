<?php

namespace App\Services;

use App\Repositories\PatentRepository;
use App\Repositories\ProjectRepository;

/**
 * منقولة من app/Services/PatentService.php القديمة — بند 14/Future
 * (Patent Portal، طالب + باحث). listForStudent() اسمها holdover من
 * القديمة بس مش مقصورة على طالب فعليًا — الكويري مفلترة بـ submitted_by
 * = المستخدم الحالي أيًا كان دوره (طالب أو باحث)، نفس ما هو موثّق في
 * PatentsApiController القديمة.
 */
class PatentService
{
    public function __construct(
        private PatentRepository $patents,
        private ProjectRepository $projects
    ) {
    }

    public function listForStudent($userId): array
    {
        return $this->patents->forSubmitterWithProject($userId);
    }

    /** المشاريع اللي المستخدم ممكن يربط ملف براءة اختراع جديد بيها. */
    public function linkableProjects($userId): array
    {
        return array_map(
            fn ($p) => ['id' => $p->id, 'title' => $p->title_en ?: $p->title_ar],
            $this->projects->forOwner($userId)
        );
    }

    /** @throws \RuntimeException لو المشروع المرتبط مش بتاع المتصل نفسه */
    public function submit($userId, array $data): void
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('A patent title is required.');
        }

        $projectId = $data['project_id'] ?? null;
        if ($projectId) {
            $owned = array_filter($this->projects->forOwner($userId), fn ($p) => (int) $p->id === (int) $projectId);
            if (!$owned) {
                throw new \RuntimeException('That project does not belong to you.');
            }
        }

        $this->patents->create([
            'project_id'         => $projectId ?: null,
            'submitted_by'       => $userId,
            'title'              => mb_substr($title, 0, 255),
            'application_number' => trim((string) ($data['application_number'] ?? '')) ?: null,
            'status'             => 'draft',
        ]);
    }
}
