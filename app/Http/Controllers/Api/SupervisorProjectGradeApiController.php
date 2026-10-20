<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Services\ProjectGradingService;
use Illuminate\Http\Request;

/**
 * سطح REST جديد /api/v1/supervisor/projects/{id}/grade — بند 14
 * (Graduation). زر "Grade project" القديم كان web view بس
 * (Supervisor\SupervisorProjectController::grade()/saveGrade()، لا
 * REST مقابل ليه)، فمفيش عقد API قديم يتنقل هنا حرفيًا — لكن نفس
 * القواعد بالظبط زي SupervisorProjectsApiController::decide() (نفس
 * الكنترولر عمدًا فضّل يتقسم لكنترولر منفصل عشان ProjectGradingService
 * منطق مختلف تمامًا عن ProjectApprovalService):
 *   - role='supervisor' مطلوب.
 *   - صلاحية 'manage_projects' + SupervisorAssignmentRepository::
 *     projectInScope() قبل أي حفظ (نفس بوابة decide() بالظبط) — قراءة
 *     forProject() نفسها متاحة لأي مشرف نشط في النطاق حتى من غير
 *     الصلاحية دي، القرار (زر الحفظ) بيتحكم فيه الفرونت زي ما can_manage
 *     بيتحكم في decide() بالظبط.
 *   - {id} هو uuid المشروع، زي كل مسارات supervisor/projects التانية.
 */
class SupervisorProjectGradeApiController extends Controller
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private ProjectGradingService $grading
    ) {
    }

    /** GET /api/v1/supervisor/projects/{id}/grade */
    public function show(Request $request, string $id)
    {
        [$err, $supervisor, $project] = $this->requireScopedProject($request, $id);
        if ($err) {
            return $err;
        }

        $data = $this->grading->forProject((int) $project->id);

        return $this->apiSuccess([
            'project'    => $this->projectSummary($project),
            'grade'      => $data['grade']?->toArray(),
            'criteria'   => $data['criteria'],
            'members'    => $this->grading->memberGrades((int) $project->id),
            'member_candidates' => $this->grading->memberCandidates(\App\Models\Project::find((int) $project->id)),
            'revisions'  => $this->grading->revisions((int) $project->id),
            'can_manage' => in_array('manage_projects', $this->supervisors->permissionsForUser($this->userId($request)), true),
        ], 'Project grade retrieved successfully.');
    }

    /** POST /api/v1/supervisor/projects/{id}/grade */
    public function save(Request $request, string $id)
    {
        [$err, $supervisor, $project] = $this->requireScopedProject($request, $id);
        if ($err) {
            return $err;
        }

        $permissions = $this->supervisors->permissionsForUser($this->userId($request));
        if (!in_array('manage_projects', $permissions, true)) {
            return $this->apiError('You do not have permission to grade projects.', null, 403);
        }

        $criteria = $request->input('criteria', []);
        if (!is_array($criteria)) {
            $criteria = [];
        }

        $result = $this->grading->saveGrade(
            (int) $project->id,
            $this->userId($request),
            $criteria,
            $request->input('overall_comments'),
            (bool) $request->input('finalize', false),
            'en',
            is_array($request->input('members')) ? $request->input('members') : null,
            'supervisor'
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, ($result['code'] ?? '') === 'grade_locked' ? 409 : 422);
        }

        $data = $this->grading->forProject((int) $project->id);

        return $this->apiSuccess([
            'grade'    => $data['grade']?->toArray(),
            'criteria' => $data['criteria'],
        ], $result['message']);
    }

    /** POST /api/v1/supervisor/projects/{id}/grade/reopen {reason} */
    public function reopen(Request $request, string $id)
    {
        [$err, $supervisor, $project] = $this->requireScopedProject($request, $id);
        if ($err) {
            return $err;
        }
        if (!in_array('manage_projects', $this->supervisors->permissionsForUser($this->userId($request)), true)) {
            return $this->apiError('You do not have permission to grade projects.', null, 403);
        }
        $result = $this->grading->reopen((int) $project->id, $this->userId($request), 'supervisor', (string) $request->input('reason', ''));
        if (!$result['success']) {
            return $this->apiError($result['message'], null, ($result['code'] ?? '') === 'graduated_requires_university' ? 403 : 422);
        }
        return $this->apiSuccess(null, $result['message']);
    }

    // -- helpers --------------------------------------------------------------

    /** stdClass جاية من scopedProjects() (raw DB::select) — مش Model، فبتتحول يدويًا لمصفوفة الحقول اللي الفرونت محتاجها بس. */
    private function projectSummary($project): array
    {
        return [
            'uuid'       => $project->uuid,
            'title_ar'   => $project->title_ar,
            'title_en'   => $project->title_en,
            'owner_name' => $project->owner_name ?? null,
        ];
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** @return array{0:mixed,1:mixed,2:mixed} [errorResponse|null, supervisor|null, project|null] */
    private function requireScopedProject(Request $request, string $uuid): array
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return [$this->apiError('Only supervisor accounts can access this page.', null, 403), null, null];
        }

        $userId = $this->userId($request);
        $supervisor = $this->supervisors->findActiveByUserId($userId);
        if (!$supervisor) {
            return [$this->apiError('Project could not be graded.', null, 404), null, null];
        }

        $project = null;
        foreach ($this->assignments->scopedProjects($supervisor->id, $supervisor->university_id) as $row) {
            if (($row->uuid ?? null) === $uuid) {
                $project = $row;
                break;
            }
        }

        if (!$project || !$this->assignments->projectInScope($supervisor->id, $supervisor->university_id, (int) $project->id)) {
            return [$this->apiError('Project could not be graded.', null, 404), null, null];
        }

        return [null, $supervisor, $project];
    }
}
