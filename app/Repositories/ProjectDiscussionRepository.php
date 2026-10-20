<?php

namespace App\Repositories;

use App\Models\ProjectDiscussionMessage;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ProjectDiscussionRepository.php القديمة —
 * بند 11 مرحلة 4 (Discussion). Data access لجدول
 * `project_discussion_messages` (migration 090).
 */
class ProjectDiscussionRepository
{
    /**
     * رسائل مشروع، الأقدم الأول، مدموجة مع اسم كاتبها عشان الـ view
     * ميحتاجش لوكاب مستخدم منفصل لكل صف. نفس شكل القديمة بالظبط.
     * @return array<int,array{message:ProjectDiscussionMessage, author_name:?string}>
     */
    public function forProject($projectId): array
    {
        $rows = DB::table('project_discussion_messages as pdm')
            ->leftJoin('users as u', 'u.id', '=', 'pdm.user_id')
            ->where('pdm.project_id', $projectId)
            ->select('pdm.*', 'u.full_name as author_name')
            ->orderBy('pdm.created_at')
            ->get();

        return $rows->map(function ($row) {
            $row = (array) $row;
            $authorName = $row['author_name'] ?? null;
            unset($row['author_name']);

            return [
                'message'     => ProjectDiscussionMessage::hydrate([$row])->first(),
                'author_name' => $authorName,
            ];
        })->all();
    }

    public function create(array $data): ProjectDiscussionMessage
    {
        return ProjectDiscussionMessage::create($data);
    }
}
