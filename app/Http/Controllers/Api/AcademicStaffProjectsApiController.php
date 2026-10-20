<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectDiscussionRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Services\AcademicStaffProjectService;
use App\Services\ProjectNotifier;
use Illuminate\Http\Request;

/**
 * /api/v1/academic-staff/projects* — student projects linked to the logged-in doctor / TA.
 * See AcademicStaffProjectService for how a project is linked and which grade is official.
 */
class AcademicStaffProjectsApiController extends Controller
{
    public function __construct(
        private AcademicStaffProjectService $svc,
        private ProjectFileRepository $files,
        private ProjectLinkRepository $links,
        private ProjectTeamMemberRepository $team,
        private ProjectDiscussionRepository $discussion,
        private ProjectNotifier $notifier
    ) {
    }

    private function userId(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    /** @return array{0:mixed,1:mixed,2:string} [errorResponse|null, staff, fullName] */
    private function ctx(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return [$this->apiError('Only academic staff accounts can access this page.', null, 403), null, ''];
        }
        $staff = $this->svc->staffForUser($this->userId($request));
        if (!$staff) {
            return [$this->apiError('Academic staff profile not found.', null, 404), null, ''];
        }
        $user = User::find($this->userId($request));
        return [null, $staff, (string) ($user->full_name ?? '')];
    }

    /** GET /api/v1/academic-staff/projects */
    public function index(Request $request)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        return $this->apiSuccess($this->svc->projectsFor($staff, $name), 'Projects retrieved successfully.');
    }

    /** GET /api/v1/academic-staff/projects/{id} */
    public function show(Request $request, string $id)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        [$project, $link] = $this->svc->scopedProject($staff, $name, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }

        $owner = User::find($project->owner_id);
        $flow = $this->svc->flowFor($project);
        $official = $this->svc->isOfficial($link);
        return $this->apiSuccess([
            'project'  => $project->toResearchCardArray() + ['owner_name' => $owner->full_name ?? null, 'owner_email' => $owner->email ?? null],
            'files'    => array_map(fn ($f) => $f->toRowArray(), $this->files->forProjectLatest($project->id)),
            'links'    => array_map(fn ($l) => $l->toRowArray(), $this->links->forProject($project->id)),
            'team'     => array_map(fn ($m) => $m->toRowArray(), $this->team->forProject($project->id)),
            'history'  => $this->svc->approvalHistory($project),
            'grades'   => $this->svc->gradesFor($project, $this->userId($request)),
            'link_role' => $link,
            'is_official' => $official,
            'flow' => $flow,
            // القرار للدكتور المشرف (ربط رسمي) وبس لما يجي دور مرحلته. المعيد استشاري: مبيقررش.
            'can_decide' => $official && $flow['awaiting'] === \App\Support\ApprovalFlow::SUPERVISOR,
            'decide_blocked' => !$official ? 'advisory_role'
                : ($project->status === 'submitted' && $flow['awaiting'] !== \App\Support\ApprovalFlow::SUPERVISOR ? 'not_your_turn' : null),
            'requires_ai_ack' => $this->svc->requiresAck((string) $project->uuid),
        ], 'Project retrieved successfully.');
    }

    /** GET /api/v1/academic-staff/projects/{id}/discussion — ملاحظات المراجعة والنقاش مع الطالب/الفريق. */
    public function discussion(Request $request, string $id)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        [$project] = $this->svc->scopedProject($staff, $name, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $rows = array_map(fn (array $row) => [
            'id'          => $row['message']->id,
            'user_id'     => $row['message']->user_id,
            'author_name' => $row['author_name'],
            'message'     => $row['message']->message,
            'created_at'  => $row['message']->created_at,
        ], $this->discussion->forProject($project->id));
        return $this->apiSuccess($rows, 'Discussion retrieved successfully.');
    }

    /**
     * POST /api/v1/academic-staff/projects/{id}/discussion {message}
     * ملاحظة/تعديل مطلوب من الدكتور أو المعيد في أي حالة للمشروع (حتى قبل التقديم)؛
     * بتظهر في تاب Discussion عند الطالب والفريق وبيوصلهم إشعار.
     */
    public function postNote(Request $request, string $id)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        [$project] = $this->svc->scopedProject($staff, $name, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return $this->apiError('Validation failed.', ['message' => 'Required.'], 422);
        }
        $this->discussion->create([
            'project_id' => $project->id,
            'user_id'    => $this->userId($request),
            'message'    => mb_substr($message, 0, 4000),
        ]);
        $this->notifier->notifyTeam(
            $project, 'review_note', 'ملاحظة جديدة على مشروعك',
            mb_substr($message, 0, 200), '/student/projects/' . $project->uuid, $this->userId($request)
        );
        return $this->apiSuccess(null, 'Note sent.', 201);
    }

    public function approve(Request $request, string $id)
    {
        return $this->decide($request, $id, 'approve', 'Supervisor approval recorded.');
    }

    public function reject(Request $request, string $id)
    {
        return $this->decide($request, $id, 'reject', 'Project rejected.', true);
    }

    public function requestChanges(Request $request, string $id)
    {
        return $this->decide($request, $id, 'request_changes', 'Changes requested from the student.', true);
    }

    private function decide(Request $request, string $uuid, string $action, string $okMessage, bool $commentRequired = false)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        [$project, $link] = $this->svc->scopedProject($staff, $name, $uuid);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        if (!$this->svc->isOfficial($link)) {
            return $this->apiError(ApprovalException::advisoryRole()->getMessage(), null, 403);
        }

        $comments = trim((string) $request->input('comments', ''));
        if ($commentRequired && $comments === '') {
            return $this->apiError('Please write a comment so the student knows what to fix.', null, 422);
        }
        $ack = (bool) $request->input('ai_acknowledgment');
        if ($this->svc->requiresAck($uuid) && !$ack) {
            return $this->apiError("This project has AI-generated indicators — confirm you've reviewed them before deciding.", null, 422);
        }

        try {
            $ok = $this->svc->decide($uuid, $this->userId($request), $action, $comments !== '' ? $comments : null, $ack, $link);
        } catch (ApprovalException $e) {
            return $this->apiError($e->getMessage(), null, $e->httpStatus());
        }
        return $ok
            ? $this->apiSuccess(null, $okMessage)
            : $this->apiError('Only a submitted project can be decided on.', null, 422);
    }

    /** POST /api/v1/academic-staff/projects/{id}/grade/reopen {reason} — الدكتور المشرف (ربط رسمي) يعيد فتح درجة نهائية. */
    public function reopenGrade(Request $request, string $id)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        [$project, $link] = $this->svc->scopedProject($staff, $name, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        if (!$this->svc->isOfficial($link)) {
            return $this->apiError('Only the supervisor doctor can reopen the official grade.', null, 403);
        }
        $result = $this->svc->reopenGrade($project, $this->userId($request), (string) $request->input('reason', ''));
        if (!$result['success']) {
            return $this->apiError($result['message'], null, in_array($result['code'] ?? '', ['graduated_requires_university'], true) ? 403 : 422);
        }
        return $this->apiSuccess(['grades' => $this->svc->gradesFor($project, $this->userId($request))], $result['message']);
    }

    /** POST /api/v1/academic-staff/projects/{id}/grade */
    public function saveGrade(Request $request, string $id)
    {
        [$err, $staff, $name] = $this->ctx($request);
        if ($err) {
            return $err;
        }
        [$project, $link] = $this->svc->scopedProject($staff, $name, $id);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $criteria = $request->input('criteria', []);
        $result = $this->svc->saveGrade(
            $project, $this->userId($request), $this->svc->isOfficial($link),
            is_array($criteria) ? $criteria : [], $request->input('overall_comments'), (bool) $request->input('finalize', false), 'en',
            is_array($request->input('members')) ? $request->input('members') : null
        );
        if (!$result['success']) {
            return $this->apiError($result['message'], null, ($result['code'] ?? '') === 'grade_locked' ? 409 : 422);
        }
        return $this->apiSuccess(['grades' => $this->svc->gradesFor($project, $this->userId($request))], $result['message']);
    }
}
