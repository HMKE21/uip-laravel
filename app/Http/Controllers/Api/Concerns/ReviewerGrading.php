<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Project;
use App\Services\ProjectGradingService;
use Illuminate\Http\Request;

/**
 * 5.3: الكلية والجامعة يقدروا يقيّموا ويعيدوا فتح الدرجة النهائية (نفس خدمة الدكتور)، بنفس قواعد القفل:
 * الاعتماد بيقفل، وإعادة الفتح بسبب مكتوب فقط ومسجّلة. الكنترولر اللي بيستخدم الـ trait بيحدد
 * النطاق (resolveGradeProject) والصلاحية (canGrade) ودوره (gradeActorRole).
 */
trait ReviewerGrading
{
    abstract protected function resolveGradeProject(Request $request, string $uuid): ?Project;

    abstract protected function canGrade(Request $request): bool;

    abstract protected function gradeActorRole(): string;

    /** تفاصيل الدرجة لشاشة المراجع (rubric + الأعضاء + السجل). */
    protected function gradeDetail(Request $request, Project $project, ProjectGradingService $grading): array
    {
        $data = $grading->forProject((int) $project->id);
        return [
            'criteria'   => $data['criteria'],
            'members'    => $grading->memberGrades((int) $project->id),
            'candidates' => $grading->memberCandidates($project),
            'revisions'  => $grading->revisions((int) $project->id),
            'locked'     => ($data['grade']->status ?? null) === 'final',
            'gradable'   => in_array($project->status, ProjectGradingService::GRADABLE_STATUSES, true),
            'can_grade'  => $this->canGrade($request),
        ];
    }

    /** POST .../{id}/grade */
    public function saveGrade(Request $request, string $id)
    {
        if (!$this->canGrade($request)) {
            return $this->apiError('You do not have permission to grade projects.', null, 403);
        }
        $project = $this->resolveGradeProject($request, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $criteria = $request->input('criteria', []);
        $result = app(ProjectGradingService::class)->saveGrade(
            (int) $project->id, (int) $request->attributes->get('uip_user_id'),
            is_array($criteria) ? $criteria : [], $request->input('overall_comments'), (bool) $request->input('finalize', false), 'en',
            is_array($request->input('members')) ? $request->input('members') : null, $this->gradeActorRole()
        );
        if (!$result['success']) {
            return $this->apiError($result['message'], null, ($result['code'] ?? '') === 'grade_locked' ? 409 : 422);
        }
        return $this->apiSuccess($this->gradeDetail($request, $project, app(ProjectGradingService::class)), $result['message']);
    }

    /** POST .../{id}/grade/reopen {reason} */
    public function reopenGrade(Request $request, string $id)
    {
        if (!$this->canGrade($request)) {
            return $this->apiError('You do not have permission to reopen grades.', null, 403);
        }
        $project = $this->resolveGradeProject($request, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $grading = app(ProjectGradingService::class);
        $result = $grading->reopen((int) $project->id, (int) $request->attributes->get('uip_user_id'), $this->gradeActorRole(), (string) $request->input('reason', ''));
        if (!$result['success']) {
            return $this->apiError($result['message'], null, ($result['code'] ?? '') === 'graduated_requires_university' ? 403 : 422);
        }
        return $this->apiSuccess($this->gradeDetail($request, $project, $grading), $result['message']);
    }
}
