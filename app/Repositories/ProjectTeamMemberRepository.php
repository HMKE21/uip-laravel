<?php

namespace App\Repositories;

use App\Models\ProjectTeamMember;
use Illuminate\Support\Facades\DB;

/**
 * منقولة بالكامل من app/Repositories/ProjectTeamMemberRepository.php
 * القديمة — بند 11. وصول للبيانات لجدول `project_team_members`. بنفس
 * شكل ProjectFileRepository (عرض حسب المشروع، find/delete مقيّد
 * بالمشروع)، زائد pendingForUser()/findByIdForUser() لصندوق "My
 * Invitations" المشترك عبر الأدوار (Common\ProjectTeamInviteController
 * القديم).
 */
class ProjectTeamMemberRepository
{
    /** @return ProjectTeamMember[] */
    public function forProject($projectId): array
    {
        return ProjectTeamMember::where('project_id', $projectId)->orderBy('created_at')->get()->all();
    }

    public function countAcceptedForProject($projectId): int
    {
        return ProjectTeamMember::where('project_id', $projectId)->where('status', 'accepted')->count();
    }

    public function findByProjectAndEmail($projectId, string $email): ?ProjectTeamMember
    {
        return ProjectTeamMember::where('project_id', $projectId)->where('invited_email', $email)->first();
    }

    /** يجيب عضو فريق بالـ id، بس لو تابع لـ $projectId — وإلا null. */
    public function findForProject($id, $projectId): ?ProjectTeamMember
    {
        $member = ProjectTeamMember::find($id);
        if ($member && (string) $member->project_id === (string) $projectId) {
            return $member;
        }
        return null;
    }

    /** دعوات pending موجّهة لحساب مستخدم متحل (user_id)، عبر كل المشاريع — "My Invitations". */
    public function pendingForUser($userId): array
    {
        return ProjectTeamMember::where('user_id', $userId)->where('status', 'pending')->orderByDesc('invited_at')->get()->all();
    }

    public function findByIdForUser($id, $userId): ?ProjectTeamMember
    {
        $member = ProjectTeamMember::find($id);
        if ($member && (string) $member->user_id === (string) $userId) {
            return $member;
        }
        return null;
    }

    /**
     * هل $userId عنده صف عضو فريق ACCEPTED على $projectId — البوابة
     * للوصول المشترك (قراءة بس) لمشروع بمجرد ما دعوة تتقبل (شوف
     * ResearchProjectService::findAccessible()).
     */
    public function isAcceptedMember($projectId, $userId): bool
    {
        return $this->findAcceptedMember($projectId, $userId) !== null;
    }

    /** صف عضو الفريق المقبول لـ $userId على $projectId، أو null. */
    public function findAcceptedMember($projectId, $userId): ?ProjectTeamMember
    {
        return ProjectTeamMember::where('project_id', $projectId)
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->first();
    }

    public function create(array $data): ProjectTeamMember
    {
        return ProjectTeamMember::create($data);
    }

    public function deleteForProject($id, $projectId): bool
    {
        $member = $this->findForProject($id, $projectId);
        if (!$member) {
            return false;
        }
        return (bool) $member->delete();
    }
}
