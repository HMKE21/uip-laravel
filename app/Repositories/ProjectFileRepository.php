<?php

namespace App\Repositories;

use App\Models\ProjectFile;
use Illuminate\Support\Facades\DB;

/**
 * منقولة بالكامل من app/Repositories/ProjectFileRepository.php القديمة —
 * وصول للبيانات لجدول `project_files`. بنفس شكل ProjectRepository: عرض
 * حسب المشروع، find/delete متحقق من الملكية عبر findForProject().
 */
class ProjectFileRepository
{
    /** @return ProjectFile[] كل صف ملف لمشروع، الأحدث الأول (كل النسخ). */
    public function forProject($projectId): array
    {
        return ProjectFile::with('uploader:id,full_name')->where('project_id', $projectId)->orderByDesc('created_at')->get()->all();
    }

    /** @return ProjectFile[] بس أحدث نسخة من كل ملف — تاب الـ Files الافتراضي. */
    public function forProjectLatest($projectId): array
    {
        return ProjectFile::with('uploader:id,full_name')->where('project_id', $projectId)->where('is_latest', 1)->orderByDesc('created_at')->get()->all();
    }

    /**
     * @return ProjectFile[] عناصر الوسائط بس (image/video/video_link)،
     * مترتبة لعرض المعرض — تاب الـ Gallery بتاع الطالب + صفحة المشروع
     * العامة (معرض الوسائط المتقدم).
     */
    public function mediaForProject($projectId): array
    {
        return ProjectFile::where('project_id', $projectId)
            ->where('is_latest', 1)
            ->whereIn('file_type', ['image', 'video', 'video_link'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()->all();
    }

    public function create(array $data): ProjectFile
    {
        return ProjectFile::create($data);
    }

    /** تحديث caption / is_featured على عنصر معرض تابع للكولر (الملكية اتشيكت قبل كده). */
    public function updateMeta($fileId, $projectId, array $data): ?ProjectFile
    {
        $file = $this->findForProject($fileId, $projectId);
        if (!$file) {
            return null;
        }
        $allowed = array_intersect_key($data, array_flip(['caption', 'is_featured']));
        if (!$allowed) {
            return $file;
        }
        $file->fill($allowed);
        $file->save();
        return $file;
    }

    /** بيثبّت ترتيب عرض جديد لعناصر وسائط مشروع. $orderedIds: ids بالترتيب المطلوب (لازم تكون تابعة لـ $projectId). */
    public function reorder($projectId, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $fileId) {
            DB::table('project_files')
                ->where('id', $fileId)
                ->where('project_id', $projectId)
                ->update(['sort_order' => $position]);
        }
    }

    /** يجيب ملف بالـ id، بس لو تابع لـ $projectId — وإلا null. */
    public function findForProject($fileId, $projectId): ?ProjectFile
    {
        $file = ProjectFile::find($fileId);
        if ($file && (string) $file->project_id === (string) $projectId) {
            return $file;
        }
        return null;
    }

    public function deleteForProject($fileId, $projectId): bool
    {
        $file = $this->findForProject($fileId, $projectId);
        if (!$file) {
            return false;
        }
        return (bool) $file->delete();
    }

    /**
     * كل نسخ "عائلة" ملف واحدة (الملف الأصلي + كل الاستبدالات)، أحدث
     * نسخة الأول — تاب سجل الإصدارات في صفحة الـ Files. $anyVersionId
     * ممكن يكون id الملف الأصلي أو أي نسخة استبدلته؛ الاتنين بيحلّوا
     * لنفس العائلة.
     * @return ProjectFile[]
     */
    public function versionsOf($anyVersionId, $projectId): array
    {
        $file = $this->findForProject($anyVersionId, $projectId);
        if (!$file) {
            return [];
        }
        $rootId = $file->root_file_id ?: $file->id;

        return ProjectFile::with('uploader:id,full_name')->where('project_id', $projectId)
            ->where(function ($q) use ($rootId) {
                $q->where('id', $rootId)->orWhere('root_file_id', $rootId);
            })
            ->orderByDesc('version')
            ->get()->all();
    }

    /** كل نسخ الملفات الغير حالية (المُستبدَلة) في مشروع — بيغذي بانل "Previous Versions". */
    public function priorVersionsForProject($projectId): array
    {
        return ProjectFile::with('uploader:id,full_name')->where('project_id', $projectId)->where('is_latest', 0)->orderByDesc('version')->get()->all();
    }
}
