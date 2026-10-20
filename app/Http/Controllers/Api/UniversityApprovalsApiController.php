<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalException;
use App\Http\Controllers\Api\Concerns\ReviewerGrading;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use App\Services\PermissionService;
use App\Services\ProjectApprovalService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/UniversityApprovalsApiController.php
 * القديمة — نفس الـ 5 endpoints بالظبط (list+detail+approve/reject/
 * request-changes)، نفس شكل الـ JSON، نفس رسائل الأخطاء. توأم JSON لـ
 * University\UniversityProjectApprovalController (project-approval.php) +
 * University\UniversityProjectViewController (project-view.php) القديمتين
 * مدموجين مع بعض.
 *
 * فرق شكلي فقط عن القديمة:
 *  - Session::userId()/can() -> $request->attributes->get('uip_user_id')
 *    + PermissionService::currentUserCan() (نفس فكرة UniversitiesApiController
 *    في بند 3 — كل جامعة بتستخرج بياناتها من التوكن، أبدًا مش من id جاي
 *    من العميل).
 *
 * RBAC: uip.auth بتغطي المجموعة كله (routes/api.php) — يعني أي حساب لسه
 * مسجّل دخول، بس universityIdForUser() بترجع null لأي حد role بتاعه مش
 * university فالطابور بيبقى فاضي واستعلامات show/decide بترجع 404/403.
 * الثلاث قرارات كمان بتتطلب صلاحية 'project.approve' زي القديمة بالظبط.
 */
class UniversityApprovalsApiController extends Controller
{
    use ReviewerGrading;

    public function __construct(
        private ProjectApprovalService $approvals,
        private ProjectRepository $projects,
        private ProjectFileRepository $files,
        private ProjectLinkRepository $links,
        private UniversityRepository $universities,
        private AIAnalysisRepository $aiAnalysis,
        private PermissionService $permissions
    ) {
    }

    /** GET /api/v1/university/approvals — طابور كامل، نفس شكل UniversityProjectApprovalController::index() القديمة. */
    public function index(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $universityId = $this->approvals->universityIdForUser($userId);
        $queue = $universityId ? $this->approvals->queueForUniversity($universityId) : [];

        return $this->apiSuccess([
            'queue'       => $queue,
            'can_approve' => $this->can($request),
        ], 'Approval queue retrieved successfully.');
    }

    /** GET /api/v1/university/approvals/{id} — {id} هو uuid المشروع. نفس شكل UniversityProjectViewController::show() القديمة. */
    public function show(Request $request, string $id)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->findByUserId($userId);
        $project = $university ? $this->projects->findForUniversityByUuid($id, $university->id) : null;

        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }

        $locale = (string) app()->getLocale();
        $files = array_map(fn ($f) => $f->toRowArray($locale), $this->files->forProjectLatest($project->id));

        $owner = User::find($project->owner_id);
        $student = Student::where('user_id', $project->owner_id)->first();

        $card = $project->toCardArray();
        $card['owner_name'] = $owner?->full_name;
        $card['owner_email'] = $owner?->email;
        $card['owner_faculty'] = $student?->faculty;
        $card['owner_department'] = $student?->department;

        $githubLink = $this->links->primaryByType($project->id, 'github');
        $card['repository_url'] = $githubLink?->url;

        $readiness = $this->aiAnalysis->readinessFor((int) $project->id);
        $classification = $this->aiAnalysis->classificationFor((int) $project->id);
        $review = $this->approvals->reviewExtras($project);
        $isMyTurn = ($review['flow']['awaiting'] ?? null) === \App\Support\ApprovalFlow::UNIVERSITY;

        return $this->apiSuccess([
            'project'          => $card,
            'files'            => $files,
            'ai_readiness'     => $readiness ? [
                'overall_score' => (float) $readiness->overall_score,
            ] : null,
            'ai_classification' => $classification ? [
                'predicted_category' => $classification->predicted_category,
                'confidence'          => $classification->confidence !== null ? (float) $classification->confidence : null,
            ] : null,
            'requires_ai_acknowledgment' => $this->approvals->requiresAiAcknowledgment($id),
            'can_approve'                => $this->can($request),
            // أزرار القرار بتظهر بس لما الصلاحية موجودة وكمان يكون دور المرحلة دي في التسلسل الإجباري.
            'can_decide'                 => $this->can($request) && $isMyTurn,
            // النشر للعامة منفصل عن الاعتماد: المشروع المعتمد (approved) بس يتنشر، والمنشور يتسحب لمعتمد.
            'can_publish'                => $this->can($request) && $project->status === 'approved',
            'can_unpublish'              => $this->can($request) && $project->status === 'published',
            'review'                     => $review,
            'grade_detail'               => $this->gradeDetail($request, $project, app(\App\Services\ProjectGradingService::class)),
        ], 'Project retrieved successfully.');
    }

    /** POST /api/v1/university/approvals/{id}/approve */
    public function approve(Request $request, string $id)
    {
        return $this->decide($request, $id, 'approve', 'Approval recorded.', 'Project could not be approved.');
    }

    /** POST /api/v1/university/approvals/{id}/reject */
    public function reject(Request $request, string $id)
    {
        return $this->decide($request, $id, 'reject', 'Project rejected.', 'Project could not be rejected.');
    }

    /** POST /api/v1/university/approvals/{id}/request-changes */
    public function requestChanges(Request $request, string $id)
    {
        return $this->decide($request, $id, 'requestChanges', 'Changes requested from the student.', 'Request could not be sent.');
    }

    /** POST /api/v1/university/approvals/{id}/publish — approved ← published (بعد الاعتماد النهائي). */
    public function publish(Request $request, string $id)
    {
        return $this->setPublication($request, $id, true);
    }

    /** POST /api/v1/university/approvals/{id}/unpublish — published ← approved. */
    public function unpublish(Request $request, string $id)
    {
        return $this->setPublication($request, $id, false);
    }

    private function setPublication(Request $request, string $uuid, bool $publish)
    {
        if (!$this->can($request)) {
            return $this->apiError('You do not have permission to publish or unpublish projects.', null, 403);
        }
        $userId = (int) $request->attributes->get('uip_user_id');
        $universityId = $this->approvals->universityIdForUser($userId);
        if (!$universityId) {
            return $this->apiError('Project not found.', null, 404);
        }
        $ok = $publish
            ? $this->approvals->publishForUniversity($uuid, $universityId, $userId)
            : $this->approvals->unpublishForUniversity($uuid, $universityId, $userId);

        return $ok
            ? $this->apiSuccess(null, $publish ? 'Project published.' : 'Project unpublished.')
            : $this->apiError($publish ? 'Only an approved project can be published.' : 'Only a published project can be unpublished.', null, 422);
    }

    private function decide(Request $request, string $uuid, string $action, string $successMessage, string $errorMessage)
    {
        if (!$this->can($request)) {
            return $this->apiError('You do not have permission to approve or reject projects.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $universityId = $this->approvals->universityIdForUser($userId);
        $comments = $request->input('comments');
        $aiAcknowledged = (bool) $request->input('ai_acknowledgment');

        if (!$universityId) {
            return $this->apiError($errorMessage, null, 404);
        }

        if ($this->approvals->requiresAiAcknowledgment($uuid) && !$aiAcknowledged) {
            return $this->apiError(
                "This project has AI-generated indicators — confirm you've reviewed them before deciding.",
                null,
                422
            );
        }

        try {
            $ok = $this->approvals->$action($uuid, $universityId, $userId, $comments, $aiAcknowledged);
        } catch (ApprovalException $e) {
            return $this->apiError($e->getMessage(), null, $e->httpStatus());
        }

        return $ok
            ? $this->apiSuccess(null, $successMessage)
            : $this->apiError($errorMessage, null, 422);
    }

    protected function resolveGradeProject(Request $request, string $uuid): ?\App\Models\Project
    {
        $university = $this->universities->findByUserId((int) $request->attributes->get('uip_user_id'));
        return $university ? $this->projects->findForUniversityByUuid($uuid, $university->id) : null;
    }

    protected function canGrade(Request $request): bool
    {
        return $this->can($request);
    }

    protected function gradeActorRole(): string
    {
        return 'university';
    }

    private function can(Request $request): bool
    {
        return $this->permissions->currentUserCan(
            (int) $request->attributes->get('uip_user_id'),
            (string) $request->attributes->get('uip_role'),
            'project.approve'
        );
    }
}
