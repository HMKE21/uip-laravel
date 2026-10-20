<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\Paginates;
use App\Models\Project;
use App\Repositories\CategoryRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectRepository;
use App\Services\AIAnalysisService;
use App\Services\AIDuplicateDetectionService;
use App\Services\AISemanticSearchService;
use App\Services\AuditLogService;
use App\Services\GithubCodeReviewService;
use App\Services\NotificationService;
use App\Services\ProjectAnalyticsService;
use App\Services\ProjectLinkService;
use App\Services\ProjectPublishingService;
use App\Services\ResearchProjectService;
use App\Services\StudentTeamService;
use Illuminate\Http\Request;

/**
 * منقولة جزئيًا من app/Controllers/Api/ProjectsApiController.php القديمة
 * (988 سطر) — بند 11 **Phase 1: Core CRUD + Files/Links/Team** + Phase 2
 * (approval-status). سطح REST واحد
 * /api/v1/projects/* فوق نفس جدول `projects` اللي كل بورتال بيقراه/
 * يكتبه، بيعيد استخدام نفس تقسيم القديمة بالظبط:
 *
 *   - CRUD/submit/archive/files (عام): ResearchProjectService — رغم
 *     اسمها، غالبية ميثودزها محايدة الدور فعليًا (بتحل المشروع عبر
 *     ProjectRepository::findOwnedByUuid()/findByUuid() بس)، فآمن تتعاد
 *     استخدامها هنا لأي مالك.
 *   - create()/update(): فرع الطالب (ProjectPublishingService).
 *   - Links: ProjectLinkService (بند 11 Phase 1 كمان).
 *   - Team: StudentTeamService.
 *
 * discussion/activity/analytics (Phase 4) اتضافوا هنا:
 * ResearchProjectService::listDiscussionFor()/postDiscussionMessageAsMember()/
 * activityTimelineFor() (الأخيرة عبر AuditLogService::forSubject())،
 * ProjectAnalyticsService (project_analytics_events). approval-status (GET /{id}/approval) اتضاف في
 * Phase 2 (approvalStatus() تحت) عبر ProjectApprovalService::
 * latestDecisionForOwner().
 *
 * AI analysis (بند 21) اتقفلت تحت — consent/run/latest/similar/duplicates،
 * كل واحدة مفوّضة لـ AIAnalysisService/AISemanticSearchService/
 * AIDuplicateDetectionService. لسه مؤجل عمدًا لمرحلة لاحقة (الفرونت عنده
 * .catch() fallback جاهز، شوف StudentProjectDetail.jsx): code-review
 * (GithubCodeReviewService) —
 * بند 20 المخصص، مش جزء من بند 11.
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php). كل action بتاعة كتابة
 * بتعيد اشتقاق الملكية من uip_user_id عبر findOwnedByUuid() (عمرها ما
 * تثق بـ owner id من العميل) وبترجع 404 لأي uuid خارج نطاق الكولر.
 * قراءة مشروع واحد (show/files/media/links/team) كمان بتسمح لعضو فريق
 * ACCEPTED مش بس المالك (findAccessible()) — أفعال الكتابة على نفس
 * الموارد دي فاضلة مالك بس، مطابق للقديمة.
 */
class ProjectsApiController extends Controller
{
    use Paginates;

    public function __construct(
        private ResearchProjectService $projects,
        private ProjectPublishingService $studentProjects,
        private ProjectRepository $projectRepo,
        private CategoryRepository $categories,
        private ProjectFileRepository $projectFiles,
        private ProjectLinkService $links,
        private StudentTeamService $studentTeam,
        private \App\Services\ProjectApprovalService $approvals,
        private ProjectAnalyticsService $analytics,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private AIAnalysisService $aiAnalysis,
        private AISemanticSearchService $semanticSearch,
        private AIDuplicateDetectionService $duplicateDetection,
        private GithubCodeReviewService $codeReview
    ) {
    }

    /**
     * GET /api/v1/projects/create-meta — كل اللي فورم "New Project" في
     * React محتاجه قبل أول render: تصنيفات نشطة، انتماء الطالب (جامعة/
     * كلية/قسم متربطين تلقائيًا، هنا قراءة بس)، واقتراحات autocomplete
     * لاسم المشرف/التاجات.
     */
    public function createMeta(Request $request)
    {
        $role = (string) $request->attributes->get('uip_role');
        if ($role !== 'student') {
            return $this->apiError('Only student accounts use this form.', null, 403);
        }

        $categories = array_map(
            fn ($c) => ['id' => (int) $c->id, 'name' => ['en' => $c->name('en'), 'ar' => $c->name('ar')]],
            $this->categories->active()
        );

        $userId = (int) $request->attributes->get('uip_user_id');
        $auto = $this->studentProjects->formAutocomplete($userId);

        return $this->apiSuccess([
            'categories'             => $categories,
            'affiliation'            => $this->studentProjects->affiliationForOwner($userId),
            'supervisor_suggestions' => $auto['supervisors'],
            'tag_suggestions'        => $auto['tags'],
        ], 'Form metadata retrieved successfully.');
    }

    // -- CRUD -----------------------------------------------------------

    /** GET /api/v1/projects — مشاريع الكولر نفسه، بدعم search/status/order/pagination. */
    public function index(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $rows = $this->projects->listForUser($userId);

        $search = trim((string) $request->input('search', (string) $request->input('q', '')));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function (Project $p) use ($needle) {
                return str_contains(mb_strtolower((string) $p->title_en), $needle)
                    || str_contains(mb_strtolower((string) $p->title_ar), $needle);
            }));
        }

        $statusFilter = $request->input('status');
        if ($statusFilter !== null && $statusFilter !== '') {
            $rows = array_values(array_filter($rows, fn (Project $p) => $p->status === $statusFilter));
        }

        $order = strtolower((string) $request->input('order', 'desc')) === 'asc' ? 1 : -1;
        usort($rows, fn (Project $a, Project $b) => $order * (strcmp((string) $a->created_at, (string) $b->created_at)));

        [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);

        return $this->apiSuccess(
            array_map(fn (Project $p) => $p->toResearchCardArray(), $items),
            'Projects retrieved successfully.',
            200,
            $this->meta($page, $perPage, $total)
        );
    }

    /** GET /api/v1/projects/{id} — المالك أو عضو فريق مقبول. */
    public function show(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $data = $project->toResearchCardArray();
        $data['viewer_role'] = $this->projects->viewerRoleOnProject($project, (int) $request->attributes->get('uip_user_id'));

        return $this->apiSuccess($data, 'Project retrieved successfully.');
    }

    // -- Approval status (بانر تغذية راجعة المراجع) --------------------------

    /**
     * GET /api/v1/projects/{id}/approval — منقولة من القديمة بالظبط
     * (approvalStatus() في app/Controllers/Api/ProjectsApiController.php)
     * — بند 11 مرحلة 2. حالة المشروع الحالية + آخر قرار مراجع (اعتماد/رفض/
     * طلب تعديلات + تعليق)، مالك المشروع بس. ملحوظة من القديمة: ده مش نفس
     * تبويب "Approval Timeline" في الفرونت (محتواه placeholder ثابت،
     * تواريخ مختلقة) — ده بيرجع اللي فعلاً متسجل عبر
     * ProjectApprovalService::latestDecisionForOwner() (آخر قرار بس).
     * submitted_at هنا هو created_at المشروع بالظبط زي القديمة — مفيش عمود
     * submitted_at منفصل متسجل فعليًا.
     */
    public function approvalStatus(Request $request, $id)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $project = $this->projects->findAccessible((string) $id, $userId);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $feedback = $this->approvals->feedbackForProject($project, $userId);
        $history = $feedback['history'];

        return $this->apiSuccess([
            'status'          => $project->status,
            'submitted_at'    => $project->created_at,
            'latest_decision' => $history ? [
                'decision' => $history[0]['decision'], 'stage' => $history[0]['stage'] ?? null, 'comments' => $history[0]['comments'], 'decided_at' => $history[0]['decided_at'],
            ] : null,
            'history'         => $history,
            'flow'            => $feedback['flow'],
            'approved_at'     => $project->approved_at,
            'published_at'    => $project->published_at,
            'grade'           => $feedback['grade'],
            'my_grade'        => $feedback['my_grade'],
            'supervisor_name' => $feedback['supervisor_name'],
        ], 'Approval status retrieved successfully.');
    }

    /**
     * POST /api/v1/projects — Role طالب (ProjectPublishingService).
     */
    public function store(Request $request)
    {
        $role = (string) $request->attributes->get('uip_role');
        if ($role !== 'student') {
            return $this->apiError('Only student accounts can create projects.', null, 403);
        }

        $data = $this->validateProjectInput($request);
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }
        if ($bad = $this->invalidRepositoryUrlResponse($request)) {
            return $bad;
        }
        $data['save_as_draft'] = $request->input('status') === 'draft';

        $data['repository_url'] = $request->input('repository_url');
        $data['demo_url'] = $request->input('demo_url');
        $data['supervisor_name'] = $request->input('supervisor_name');
        $data['tags'] = $request->input('tags');

        $project = $this->studentProjects->create((int) $request->attributes->get('uip_user_id'), $data);

        return $this->apiSuccess($project->toResearchCardArray(), 'Project created successfully.', 201);
    }

    /**
     * PATCH /api/v1/projects/{id} — Role طالب، مالك المشروع فقط.
     */
    public function update(Request $request, $id)
    {
        $role = (string) $request->attributes->get('uip_role');
        if ($role !== 'student') {
            return $this->apiError('Only student accounts can update projects.', null, 403);
        }

        $data = $this->validateProjectInput($request);
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }
        if ($bad = $this->invalidRepositoryUrlResponse($request)) {
            return $bad;
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        $data['repository_url'] = $request->input('repository_url');
        $data['demo_url'] = $request->input('demo_url');
        $data['supervisor_name'] = $request->input('supervisor_name');
        $data['tags'] = $request->input('tags');

        $updated = $this->studentProjects->update((string) $id, $userId, $data);

        if (!$updated) {
            return $this->apiError('Project not found, or no longer editable.', null, 404);
        }

        $project = $this->projects->findOwned((string) $id, $userId);
        return $this->apiSuccess($project?->toResearchCardArray(), 'Project updated successfully.');
    }

    /** DELETE /api/v1/projects/{id} — المالك بس. */
    public function destroy(Request $request, $id)
    {
        $deleted = $this->projects->delete((string) $id, (int) $request->attributes->get('uip_user_id'));
        if (!$deleted) {
            return $this->apiError('Project not found.', null, 404);
        }
        return $this->apiSuccess(null, 'Project deleted successfully.');
    }

    /** GET /api/v1/projects/{id}/submission-check — بنود ناقصة قبل التقديم (blocking) وتنبيهات (warnings). */
    public function submissionCheck(Request $request, $id)
    {
        $project = $this->projects->findOwned((string) $id, (int) $request->attributes->get('uip_user_id'));
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $check = $this->projects->submissionChecklist($project);
        return $this->apiSuccess($check + ['can_submit' => in_array($project->status, ['draft', 'rejected'], true) && !$check['blocking']], 'Submission check retrieved.');
    }

    /** POST /api/v1/projects/{id}/submit — draft أو rejected -> submitted، بعد قايمة التحقق. */
    public function submit(Request $request, $id)
    {
        try {
            $ok = $this->projects->submit((string) $id, (int) $request->attributes->get('uip_user_id'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), ['missing' => explode("\n", $e->getMessage())], 422);
        }
        return $ok
            ? $this->apiSuccess(null, 'Project submitted for approval.')
            : $this->apiError('Project not found, or not in a submittable status.', null, 409);
    }

    /**
     * POST /api/v1/projects/{id}/publish — صاحب المشروع ينشر مشروعه المعتمد (approved) للعامة.
     * الاعتماد والنشر خطوتين منفصلتين: الاعتماد (دكتور ← كلية ← جامعة) مبينشرش لوحده.
     */
    public function publish(Request $request, $id)
    {
        if ((string) $request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only the project owner can publish it.', null, 403);
        }
        $ok = $this->approvals->publishAsOwner((string) $id, (int) $request->attributes->get('uip_user_id'));
        return $ok
            ? $this->apiSuccess(null, 'Project published.')
            : $this->apiError('Only an approved project that you own can be published.', null, 409);
    }

    /** POST /api/v1/projects/{id}/archive */
    public function archive(Request $request, $id)
    {
        $ok = $this->projects->archive((string) $id, (int) $request->attributes->get('uip_user_id'));
        return $ok
            ? $this->apiSuccess(null, 'Project archived successfully.')
            : $this->apiError('Project not found, or already archived.', null, 409);
    }

    /** POST /api/v1/projects/{id}/unarchive */
    public function unarchive(Request $request, $id)
    {
        $ok = $this->projects->unarchive((string) $id, (int) $request->attributes->get('uip_user_id'));
        return $ok
            ? $this->apiSuccess(null, 'Project restored to draft.')
            : $this->apiError('Project not found, or not archived.', null, 409);
    }

    // -- ملفات / وسائط ------------------------------------------------------

    /** GET /api/v1/projects/{id}/files — المالك أو عضو فريق مقبول. */
    public function files(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $rows = array_map(fn ($f) => $f->toRowArray(), $this->projects->listFilesFor($project));
        return $this->apiSuccess($rows, 'Project files retrieved successfully.');
    }

    /** GET /api/v1/projects/{id}/media — عناصر معرض الصور/الفيديو مرتبة للعرض. */
    public function media(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $rows = array_map(fn ($f) => $f->toGalleryArray(), $this->projectFiles->mediaForProject($project->id));
        return $this->apiSuccess($rows, 'Project media retrieved successfully.');
    }

    /** POST /api/v1/projects/{id}/files — رفع multipart، المالك بس. */
    public function storeFile(Request $request, $id)
    {
        try {
            $file = $this->projects->addFile((string) $id, (int) $request->attributes->get('uip_user_id'), $request->file('file'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($file->toRowArray(), 'File uploaded successfully.', 201);
    }

    /** PUT /api/v1/projects/{id}/files/{fileId} — رفع نسخة جديدة، المالك بس. */
    public function replaceFile(Request $request, $id, $fileId)
    {
        try {
            $file = $this->projects->replaceFile(
                (string) $id,
                (int) $request->attributes->get('uip_user_id'),
                $fileId,
                $request->file('file'),
                $request->boolean('force')
            );
        } catch (\RuntimeException $e) {
            // 409 = نسخة أحدث اتحفظت من حد تاني من الفريق (محرر الـ ZIP بيعرض "حفظ فوقها؟").
            return $this->apiError($e->getMessage(), null, $e->getCode() === 409 ? 409 : 422);
        }

        return $this->apiSuccess($file->toRowArray(), 'File replaced successfully.');
    }

    /** GET /api/v1/projects/{id}/files/{fileId}/versions — تاريخ الإصدارات الكامل، الأحدث الأول. */
    public function fileVersions(Request $request, $id, $fileId)
    {
        $rows = $this->projects->fileVersionHistory((string) $id, (int) $request->attributes->get('uip_user_id'), $fileId);
        return $this->apiSuccess(array_map(fn ($f) => $f->toRowArray(), $rows), 'File version history retrieved successfully.');
    }

    /** DELETE /api/v1/projects/{id}/files/{fileId} — المالك بس. */
    public function deleteFile(Request $request, $id, $fileId)
    {
        $deleted = $this->projects->deleteFile((string) $id, (int) $request->attributes->get('uip_user_id'), $fileId);
        if (!$deleted) {
            return $this->apiError('File not found, or project no longer editable.', null, 404);
        }
        return $this->apiSuccess(null, 'File deleted successfully.');
    }

    // -- لينكات ----------------------------------------------------------

    /** GET /api/v1/projects/{id}/links — المالك أو عضو فريق مقبول. */
    public function links(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $rows = array_map(fn ($l) => $l->toRowArray(), $this->links->listForOwner((string) $project->uuid, (int) $request->attributes->get('uip_user_id')));
        return $this->apiSuccess($rows, 'Project links retrieved successfully.');
    }

    /** POST /api/v1/projects/{id}/links — المالك بس. */
    public function addLink(Request $request, $id)
    {
        $data = [
            'type'       => $request->input('type'),
            'url'        => $request->input('url'),
            'label'      => $request->input('label'),
            'is_primary' => $request->input('is_primary', false),
        ];

        try {
            $link = $this->links->addForOwner((string) $id, (int) $request->attributes->get('uip_user_id'), $data);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($link->toRowArray(), 'Link added successfully.', 201);
    }

    /** PATCH /api/v1/projects/{id}/links/{linkId} — المالك بس. */
    public function updateLink(Request $request, $id, $linkId)
    {
        $data = [
            'type'       => $request->input('type'),
            'url'        => $request->input('url'),
            'label'      => $request->input('label'),
            'is_primary' => $request->input('is_primary', false),
        ];

        try {
            $link = $this->links->updateForOwner((string) $id, (int) $request->attributes->get('uip_user_id'), $linkId, $data);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($link->toRowArray(), 'Link updated successfully.');
    }

    /** DELETE /api/v1/projects/{id}/links/{linkId} — المالك بس. */
    public function deleteLink(Request $request, $id, $linkId)
    {
        $deleted = $this->links->deleteForOwner((string) $id, (int) $request->attributes->get('uip_user_id'), $linkId);
        if (!$deleted) {
            return $this->apiError('Link not found.', null, 404);
        }
        return $this->apiSuccess(null, 'Link deleted successfully.');
    }

    // -- فريق -------------------------------------------------------------

    /** GET /api/v1/projects/{id}/team — المالك أو عضو فريق مقبول. */
    public function team(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $rows = array_map(fn ($m) => $m->toRowArray(), $this->projects->listTeamFor($project));
        return $this->apiSuccess($rows, 'Project team retrieved successfully.');
    }

    /**
     * POST /api/v1/projects/{id}/team/invite — Role طالب بس في Phase 1
     * (دعوة بريد إلكتروني لحساب موجود، دايمًا role='student_member' —
     * دعوة بدور مخصص هتيجي مع توسعة بورتال الباحث الكامل).
     */
    public function inviteTeamMember(Request $request, $id)
    {
        if ((string) $request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can invite team members in this release.', null, 403);
        }

        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            return $this->apiError('Validation failed.', ['email' => 'Required.'], 422);
        }
        $locale = (string) ($request->input('locale') ?: 'ar');

        try {
            $member = $this->studentTeam->inviteMember((string) $id, (int) $request->attributes->get('uip_user_id'), $email, $locale);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($member->toRowArray(), 'Team invitation sent successfully.', 201);
    }

    /**
     * POST /api/v1/projects/{id}/team/manual — Role طالب بس. عضو بدون
     * حساب منصة: اسم + دور، اختياري سنة دراسية + رقم طالب. بتتقبل فورًا،
     * بعكس inviteTeamMember() اللي محتاجة حساب موجود وقبول المدعو.
     */
    public function addManualTeamMember(Request $request, $id)
    {
        if ((string) $request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can add members without an account.', null, 403);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->apiError('Validation failed.', ['name' => 'Required.'], 422);
        }

        $role = (string) ($request->input('role') ?: 'student_member');
        $yearInput = $request->input('academic_year');
        $year = ($yearInput !== null && $yearInput !== '') ? (int) $yearInput : null;
        $locale = (string) ($request->input('locale') ?: 'ar');

        try {
            $member = $this->studentTeam->addManualMember(
                (string) $id,
                (int) $request->attributes->get('uip_user_id'),
                $name,
                $year,
                $request->input('student_number'),
                $locale,
                $role
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($member->toRowArray(), 'Team member added successfully.', 201);
    }

    /** GET /api/v1/projects/{id}/team/search?q= — بحث بالاسم/الإيميل/الكود، المالك بس. */
    public function searchTeamCandidates(Request $request, $id)
    {
        try {
            $rows = $this->studentTeam->searchCandidates((string) $id, (int) $request->attributes->get('uip_user_id'), (string) $request->input('q', ''));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }
        return $this->apiSuccess($rows, 'Candidates retrieved successfully.');
    }

    /** POST /api/v1/projects/{id}/team/add-user {user_id, role} — إضافة حساب موجود (طالب/دكتور/معيد). */
    public function addTeamUser(Request $request, $id)
    {
        if ((string) $request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can add team members.', null, 403);
        }
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->apiError('Validation failed.', ['user_id' => 'Required.'], 422);
        }
        try {
            $member = $this->studentTeam->addUserMember((string) $id, (int) $request->attributes->get('uip_user_id'), $userId, (string) $request->input('role', ''), (string) ($request->input('locale') ?: 'ar'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        return $this->apiSuccess($member->toRowArray(), 'Team member added successfully.', 201);
    }

    /** DELETE /api/v1/projects/{id}/team/{memberId} — المالك بس. */
    public function removeTeamMember(Request $request, $id, $memberId)
    {
        $removed = $this->studentTeam->removeMember((string) $id, (int) $request->attributes->get('uip_user_id'), $memberId);
        if (!$removed) {
            return $this->apiError('Team member not found.', null, 404);
        }
        return $this->apiSuccess(null, 'Team member removed successfully.');
    }

    // -- Discussion (بند 11 مرحلة 4) -----------------------------------------

    /** GET /api/v1/projects/{id}/discussion — المالك أو عضو فريق مقبول. */
    public function discussion(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $list = $this->projects->listDiscussionFor($project);
        $staffIds = array_flip(\Illuminate\Support\Facades\DB::table('academic_staff')
            ->whereIn('user_id', array_map(fn ($r) => $r['message']->user_id, $list) ?: [0])->pluck('user_id')->all());
        $rows = array_map(fn (array $row) => [
            'id'          => $row['message']->id,
            'user_id'     => $row['message']->user_id,
            'author_name' => $row['author_name'],
            'author_kind' => isset($staffIds[$row['message']->user_id]) ? 'staff' : 'student',
            'message'     => $row['message']->message,
            'created_at'  => $row['message']->created_at,
        ], $list);

        return $this->apiSuccess($rows, 'Project discussion retrieved successfully.');
    }

    /** POST /api/v1/projects/{id}/discussion — المالك أو عضو فريق مقبول. */
    public function postDiscussion(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return $this->apiError('Validation failed.', ['message' => 'Required.'], 422);
        }

        $result = $this->projects->postDiscussionMessageAsMember($project, (int) $request->attributes->get('uip_user_id'), $message);

        return $result['success']
            ? $this->apiSuccess(null, $result['message'], 201)
            : $this->apiError($result['message'], null, 422);
    }

    // -- Activity (بند 11 مرحلة 4) --------------------------------------------

    /** GET /api/v1/projects/{id}/activity — المالك أو عضو فريق مقبول. */
    public function activity(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $limit = max(1, min(200, (int) $request->input('limit', 50)));
        return $this->apiSuccess($this->projects->activityTimelineFor($project, $limit), 'Project activity retrieved successfully.');
    }

    // -- Analytics (بند 11 مرحلة 4) -------------------------------------------

    /**
     * GET /api/v1/projects/{id}/analytics — عدادات مشاهدة/نقر، اتجاه
     * مشاهدات يومي (14 يوم)، وأكتر التفاعلات، مالك المشروع بس (نفس
     * ProjectAnalyticsService اللي صفحة تفاصيل مشروع الطالب بتقراها).
     * أصفار لمشروع لسه ما اتنشرش/ما اتزارش مقصودة، مش حالة استثنائية.
     */
    public function analytics(Request $request, $id)
    {
        $project = $this->findOwnedOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        return $this->apiSuccess([
            'summary' => $this->analytics->summary($project->id),
            'trend'   => $this->analytics->dailyViewTrend($project->id, 14),
            'top'     => $this->analytics->topInteractions($project->id, 5),
        ], 'Project analytics retrieved successfully.');
    }

    // -- بند 15 (Future > GitHub Integration) — Code Review ------------------
    // منقولة من app/Controllers/Api/ProjectsApiController.php القديمة —
    // GithubCodeReviewService::run()/latestReview()/forOwner() بالظبط،
    // نفس المحرك اللي Student\StudentGitHubController القديمة (hub صفحة
    // "GitHub Integration" — بديل الـ placeholder Future/GitHubIntegrationController
    // القديم) وشاشات Admin AI Code Review بيستخدموه.

    /** POST /{id}/code-review/run — مالك المشروع بس؛ المشروع لازم يكون متربط بـ GitHub. */
    public function runCodeReview(Request $request, $id)
    {
        try {
            $result = $this->codeReview->run((string) $id, $request->attributes->get('uip_user_id'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        // وصّل التقرير للدكتور/المعيد/الكلية/الجامعة (بدون ما يأثر على نجاح المراجعة لو الإشعار فشل).
        if (($result['status'] ?? null) === 'completed') {
            $project = $this->projectRepo->findByUuid((string) $id);
            app(\App\Services\ProjectNotifier::class)->notifyCodeReviewReady(
                $project,
                (int) ($result['scores']['overall'] ?? 0),
                (int) ($result['issues_found'] ?? 0),
                $request->attributes->get('uip_user_id')
            );
        }

        return $this->apiSuccess($result, 'Code review completed.');
    }

    /** GET /{id}/code-review — آخر مراجعة متخزّنة، مالك المشروع أو عضو فريق مقبول. */
    public function latestCodeReview(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $review = $this->codeReview->latestReview($project->id);
        return $this->apiSuccess($review, $review ? 'Latest code review retrieved successfully.' : 'No code review has been run yet.');
    }

    /**
     * GET /api/v1/projects/github — حالة GitHub عبر كل مشاريع صاحب
     * الطلب في نداء واحد (صفحة React "GitHub Integration" hub). بتعيد
     * استخدام GithubCodeReviewService::forOwner() بالظبط زي
     * Student\StudentGitHubController::index() القديمة — مفيش بيانات
     * جديدة، بس نفس الصفوف الحقيقية لكل مشروع في قايمة واحدة بدل نداء
     * منفصل لكل مشروع.
     */
    public function githubOverview(Request $request)
    {
        return $this->apiSuccess($this->codeReview->forOwner($request->attributes->get('uip_user_id')), 'GitHub integration status retrieved successfully.');
    }

    // -- بند 21 — AI Analysis ---------------------------------------------

    /**
     * POST /{id}/ai-analysis/consent — مالك المشروع بس. لازم تتنادى (وترجع
     * success) قبل ما ai-analysis/run تكمل — نفس تدفق consent-first اللي
     * فورمات Run Analysis على الويب بتفرضه بالفعل (شوف docblock كلاس
     * AIAnalysisService).
     */
    public function grantAiConsent(Request $request, $id)
    {
        $project = $this->findOwnedOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $this->aiAnalysis->recordConsent($project, $userId, $request->ip());
        return $this->apiSuccess(null, 'AI analysis consent recorded.');
    }

    /** POST /{id}/ai-analysis/run — مالك المشروع بس؛ محتاج موافقة مسبقة. */
    public function runAiAnalysis(Request $request, $id)
    {
        $project = $this->findOwnedOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        if (!$this->aiAnalysis->isAvailable()) {
            return $this->apiError('AI analysis is not configured on this platform yet.', null, 503);
        }

        try {
            $locale = (string) ($request->input('locale') ?: app()->getLocale());
            $outcomes = $this->aiAnalysis->runFullAnalysis($project, (int) $request->attributes->get('uip_user_id'), $locale);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess($outcomes, 'AI analysis run completed.');
    }

    /** GET /{id}/ai-analysis — كل حاجة متخزنة حاليًا، من غير نداء AI. المالك أو عضو فريق مقبول. */
    public function latestAiAnalysis(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        $analysis = $this->aiAnalysis->getLatest($project);

        $readiness = $analysis['readiness'];
        $classification = $analysis['classification'];
        $startupPotential = $analysis['startup_potential'];

        return $this->apiSuccess([
            'readiness' => $readiness ? $readiness->toArray() : null,
            'classification' => $classification ? array_merge($classification->toArray(), [
                'alternatives' => $classification->alternatives(),
            ]) : null,
            'startup_potential' => $startupPotential ? array_merge($startupPotential->toArray(), [
                'risks' => $startupPotential->risks(),
            ]) : null,
            'suggestions' => array_map(fn ($s) => $s->toArray(), $analysis['suggestions']->all()),
            'summary' => $analysis['summary'],
            'logs' => array_map(
                fn ($log) => array_merge($log->toArray(), ['result' => $log->result()]),
                $analysis['logs']
            ),
        ], 'Latest AI analysis retrieved successfully.');
    }

    /** GET /{id}/ai-analysis/export?format=csv|xlsx|pdf|json|docx|html|md|txt|xml&lang=ar|en — المالك أو عضو فريق مقبول. */
    public function exportAiAnalysis(Request $request, $id)
    {
        $project = $this->findAccessibleOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }
        $format = strtolower((string) $request->input('format', 'pdf'));
        try {
            $file = app(\App\Services\AIAnalysisReportService::class)
                ->render($project, $format, \App\Services\Export\ExportLocalizer::isArabic());
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        if (!$file) {
            return $this->apiError('No AI analysis has been run for this project yet.', null, 404);
        }
        [$content, $mime, $name] = $file;
        $this->auditLog->record(
            $request->attributes->get('uip_user_id'), 'ai_analysis.export_report', 'Project', $project->id, null,
            ['format' => $format], $request->ip()
        );
        return response($content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
    }

    /**
     * GET /{id}/ai-analysis/similar — مشاريع منشورة قريبة بالمعنى. مالك
     * المشروع بس، نداء حي عند الطلب — مش متخزّنة (شوف docblock
     * AISemanticSearchService).
     */
    public function similarProjects(Request $request, $id)
    {
        $project = $this->findOwnedOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        if (!$this->semanticSearch->isAvailable()) {
            return $this->apiError('AI analysis is not configured on this platform yet.', null, 503);
        }

        try {
            $limit = $request->input('limit') !== null ? max(1, min(20, (int) $request->input('limit'))) : 5;
            $matches = $this->semanticSearch->findSimilar($project, $limit);
        } catch (\Throwable $e) {
            return $this->apiError('Semantic search failed: ' . $e->getMessage(), null, 502);
        }

        return $this->apiSuccess($matches, $matches ? 'Found ' . count($matches) . ' similar project(s).' : 'No similar published projects found yet.');
    }

    /**
     * GET /{id}/ai-analysis/duplicates — مرشحين لنفس فكرة المشروع، بدرجة
     * ثقة. مالك المشروع بس، نداء حي عند الطلب — مش متخزّنة، نفس منطق
     * similarProjects().
     */
    public function duplicateCheck(Request $request, $id)
    {
        $project = $this->findOwnedOr404($request, $id);
        if (!$project instanceof Project) {
            return $project;
        }

        if (!$this->duplicateDetection->isAvailable()) {
            return $this->apiError('AI analysis is not configured on this platform yet.', null, 503);
        }

        try {
            $matches = $this->duplicateDetection->detect($project);
        } catch (\Throwable $e) {
            return $this->apiError('Duplicate check failed: ' . $e->getMessage(), null, 502);
        }

        return $this->apiSuccess($matches, $matches ? count($matches) . ' possible duplicate(s) found.' : 'No likely duplicates found.');
    }

    // -- Helpers -------------------------------------------------------------

    /** @return \Illuminate\Http\JsonResponse|array validated data, أو JsonResponse خطأ لو الفاليديشن فشل */
    private function validateProjectInput(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'title_en'    => 'required|max:255',
            'title_ar'    => 'required|max:255',
            'summary'     => 'required',
            'category_id' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->apiError('Validation failed.', $validator->errors()->toArray(), 422);
        }

        return $validator->validated();
    }

    /** لو repository_url اتبعت وهو مش رابط مستودع GitHub صالح → 422 (بدل ما يتحفظ ويفشل لاحقًا عند تشغيل المراجعة). */
    private function invalidRepositoryUrlResponse(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $url = trim((string) $request->input('repository_url', ''));
        if ($url === '' || \App\Models\ProjectLink::isValidGithubRepoUrl($url)) {
            return null;
        }
        $msg = 'Enter a valid GitHub repository URL, e.g. https://github.com/username/project';
        return $this->apiError($msg, ['repository_url' => [$msg]], 422);
    }

    /** @return Project|\Illuminate\Http\JsonResponse */
    private function findAccessibleOr404(Request $request, $uuid)
    {
        $project = $this->projects->findAccessible((string) $uuid, (int) $request->attributes->get('uip_user_id'));
        return $project ?? $this->apiError('Project not found.', null, 404);
    }

    /**
     * زي findAccessibleOr404() بس مالك المشروع بس — عضو فريق مقبول مش
     * كفاية هنا (analytics بيانات مالية/تشغيلية
     * خاصة بالمالك بس، بند 11 مرحلة 4).
     * @return Project|\Illuminate\Http\JsonResponse
     */
    private function findOwnedOr404(Request $request, $uuid)
    {
        $project = $this->projects->findOwned((string) $uuid, (int) $request->attributes->get('uip_user_id'));
        return $project ?? $this->apiError('Project not found.', null, 404);
    }
}
