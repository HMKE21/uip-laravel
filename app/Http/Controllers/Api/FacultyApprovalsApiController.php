<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalException;
use App\Http\Controllers\Api\Concerns\ReviewerGrading;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Services\PermissionService;
use App\Services\ProjectApprovalService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/FacultyApprovalsApiController.php القديمة
 * — بند 10. توأم /api/v1/faculty/approvals/* لـ
 * UniversityApprovalsApiController (بند 5) بالظبط — نفس الـ 5 endpoints
 * (list+detail+approve/reject/request-changes)، نفس شكل الـ JSON، بس بيلف
 * ProjectApprovalService::*ForFaculty()/ProjectRepository::
 * findForFacultyByUuid() بدل نسخ الجامعة.
 *
 * فرق شكلي فقط عن القديمة (نفس نمط UniversityApprovalsApiController):
 *  - Session::userId()/hasRole()/can() -> $request->attributes->get(...)
 *    + PermissionService::currentUserCan().
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='faculty' بتتفحص
 * جوه كل ميثود. الثلاث قرارات كمان محتاجة صلاحية 'project.approve'.
 */
class FacultyApprovalsApiController extends Controller
{
    use ReviewerGrading;

    public function __construct(
        private ProjectApprovalService $approvals,
        private ProjectRepository $projects,
        private ProjectFileRepository $files,
        private ProjectLinkRepository $links,
        private FacultyRepository $faculties,
        private AIAnalysisRepository $aiAnalysis,
        private PermissionService $permissions
    ) {
    }

    /** GET /api/v1/faculty/approvals — طابور كامل. Role كلية بس. */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can view this approval queue.', null, 403);
        }

        $facultyId = $this->approvals->facultyIdForUser((int) $request->attributes->get('uip_user_id'));
        $queue = $facultyId ? $this->approvals->queueForFaculty($facultyId) : [];

        return $this->apiSuccess([
            'queue'       => $queue,
            'can_approve' => $this->can($request),
        ], 'Approval queue retrieved successfully.');
    }

    /** GET /api/v1/faculty/approvals/{id} — {id} هو uuid المشروع. Role كلية بس. */
    public function show(Request $request, string $id)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can view this project.', null, 403);
        }

        $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
        $project = $faculty ? $this->projects->findForFacultyByUuid($id, $faculty->id) : null;

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
        $isMyTurn = ($review['flow']['awaiting'] ?? null) === \App\Support\ApprovalFlow::FACULTY;

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
            'review'                     => $review,
            'grade_detail'               => $this->gradeDetail($request, $project, app(\App\Services\ProjectGradingService::class)),
        ], 'Project retrieved successfully.');
    }

    /** POST /api/v1/faculty/approvals/{id}/approve */
    public function approve(Request $request, string $id)
    {
        return $this->decide($request, $id, 'approveForFaculty', 'Approval recorded.', 'Project could not be approved.');
    }

    /** POST /api/v1/faculty/approvals/{id}/reject */
    public function reject(Request $request, string $id)
    {
        return $this->decide($request, $id, 'rejectForFaculty', 'Project rejected.', 'Project could not be rejected.');
    }

    /** POST /api/v1/faculty/approvals/{id}/request-changes */
    public function requestChanges(Request $request, string $id)
    {
        return $this->decide($request, $id, 'requestChangesForFaculty', 'Changes requested from the student.', 'Request could not be sent.');
    }

    private function decide(Request $request, string $uuid, string $action, string $successMessage, string $errorMessage)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can decide on this project.', null, 403);
        }

        if (!$this->can($request)) {
            return $this->apiError('You do not have permission to approve or reject projects.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $facultyId = $this->approvals->facultyIdForUser($userId);
        $comments = $request->input('comments');
        $aiAcknowledged = (bool) $request->input('ai_acknowledgment');

        if (!$facultyId) {
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
            $ok = $this->approvals->$action($uuid, $facultyId, $userId, $comments, $aiAcknowledged);
        } catch (ApprovalException $e) {
            return $this->apiError($e->getMessage(), null, $e->httpStatus());
        }

        return $ok
            ? $this->apiSuccess(null, $successMessage)
            : $this->apiError($errorMessage, null, 422);
    }

    protected function resolveGradeProject(Request $request, string $uuid): ?\App\Models\Project
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return null;
        }
        $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
        return $faculty ? $this->projects->findForFacultyByUuid($uuid, $faculty->id) : null;
    }

    protected function canGrade(Request $request): bool
    {
        return $this->can($request);
    }

    protected function gradeActorRole(): string
    {
        return 'faculty';
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
