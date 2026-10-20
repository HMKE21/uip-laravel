<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApprovalException;
use App\Http\Controllers\Controller;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Services\ProjectApprovalService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Supervisor/SupervisorProjectController.php
 * القديمة (web view بس، مفيهاش REST API قديمة تتنقل) — بند 11 مرحلة 2،
 * آخر جزء فيها. سطح /api/v1/supervisor/projects* JSON، بيعيد استخدام
 * ProjectApprovalService::approve()/reject()/requestChanges() **الجامعة**
 * بالظبط زي القديمة (مش الكلية ولا نسخة خاصة بالمشرف) — القديمة كانت
 * بتنادي `$this->approvals->{$action}($uuid, $supervisor->university_id,
 * ...)`، يعني قرار المشرف بيتسجل كـ university_review زي أي قرار جامعة،
 * بس بعد بوابة نطاق إضافية (projectInScope()) مفيش مثيل ليها عند الجامعة
 * نفسها. الإضافة الوحيدة هنا فوق منطق approve/reject/requestChanges
 * العادي: بوابة صلاحية 'manage_projects' + فحص نطاق صلب عبر
 * SupervisorAssignmentRepository::projectInScope()/scopedProjects() قبل
 * أي قرار — عشان مشرف عمره ما يقدر يتصرف في مشروع برا نطاقه المُسند
 * (كلية/قسم/سنة دراسية/مشروع بعينه) حتى لو خمّن الـ uuid.
 *
 * الـ rubric grading (زر "Grade project" في القديمة) عمدًا مش هنا —
 * محتاج ProjectGradingService اللي لسه ما اتنقلش لبند 11 (هيتضاف مع بند
 * 14 Graduation)، الفرونت React (SupervisorProjects.jsx) واضح في
 * docblock بتاعه إنه مؤجل لنفس السبب.
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php). role='supervisor' بتتفحص
 * جوه كل ميثود؛ صلاحية 'manage_projects' + النطاق بيتفحصوا في decide()
 * فقط (index() بترجع can_manage/has_scope للفرونت يعرض/يخفي أزرار
 * القرار، بس القراءة نفسها متاحة لأي مشرف نشط حتى من غير الصلاحية دي).
 */
class SupervisorProjectsApiController extends Controller
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private ProjectApprovalService $approvals
    ) {
    }

    private function requireSupervisor(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can access this page.', null, 403);
        }
        return null;
    }

    private const PER_PAGE_DEFAULT = 10;
    private const PER_PAGE_MAX = 50;

    /**
     * GET /api/v1/supervisor/projects?status=submitted|approved|published|rejected|draft
     *     &q=<search>&page=1&per_page=10
     *
     * الترقيم بيتم هنا (السيرفر) بدل ما الفرونت يحمّل كل المشاريع. الـ meta
     * بترجع total/page/per_page/total_pages + counts لكل حالة (محسوبة على
     * النتائج بعد البحث وقبل فلتر الحالة) عشان كروت الـ KPI والتابات
     * تفضل صح مهما كانت الصفحة الحالية.
     */
    public function index(Request $request)
    {
        if ($err = $this->requireSupervisor($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $supervisor = $this->supervisors->findActiveByUserId($userId);

        $status = $request->input('status');
        $status = in_array($status, ['submitted', 'approved', 'published', 'rejected', 'draft'], true) ? $status : null;

        $all = $supervisor
            ? $this->assignments->scopedProjects($supervisor->id, $supervisor->university_id)
            : [];

        $assignedTotal = count($all);

        $q = mb_strtolower(trim((string) $request->input('q', '')));
        if ($q !== '') {
            $all = array_values(array_filter($all, function ($r) use ($q) {
                $hay = mb_strtolower(trim(($r->title_en ?? '') . ' ' . ($r->title_ar ?? '') . ' ' . ($r->owner_name ?? '')));
                return mb_strpos($hay, $q) !== false;
            }));
        }

        $counts = ['all' => count($all), 'submitted' => 0, 'approved' => 0, 'published' => 0, 'rejected' => 0, 'draft' => 0];
        foreach ($all as $r) {
            $st = $r->status ?? 'draft';
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }

        $filtered = $status === null
            ? $all
            : array_values(array_filter($all, fn ($r) => ($r->status ?? null) === $status));

        $perPage    = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));
        $total      = count($filtered);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($totalPages, (int) $request->input('page', 1)));
        $rows       = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        // المرحلة اللي كل مشروع في الصفحة بينتظرها: قرار المشرف-بنطاق (مرحلة الجامعة) بيتفتح بس لما يجي دورها.
        $flows = $this->approvals->flowSummaries(array_map(fn ($r) => (array) $r, $rows));
        foreach ($rows as $r) {
            $r->awaiting = $flows[(int) $r->id]['awaiting'] ?? null;
            $r->stages = $flows[(int) $r->id]['stages'] ?? [];
        }

        return $this->apiSuccess($rows, 'Supervisor project queue retrieved successfully.', 200, [
            'status'         => $status ?? 'all',
            'can_manage'     => in_array('manage_projects', $this->supervisors->permissionsForUser($userId), true),
            'has_scope'      => $supervisor ? !empty($this->assignments->forSupervisor($supervisor->id)) : false,
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $perPage,
            'total_pages'    => $totalPages,
            'counts'         => $counts,
            'assigned_total' => $assignedTotal,
        ]);
    }

    /** POST /api/v1/supervisor/projects/{id}/approve — {id} هو uuid المشروع. */
    public function approve(Request $request, string $id)
    {
        return $this->decide($request, $id, 'approve', 'Approval recorded.', 'Project could not be approved.');
    }

    /** POST /api/v1/supervisor/projects/{id}/reject */
    public function reject(Request $request, string $id)
    {
        return $this->decide($request, $id, 'reject', 'Project rejected.', 'Project could not be rejected.');
    }

    /** POST /api/v1/supervisor/projects/{id}/request-changes */
    public function requestChanges(Request $request, string $id)
    {
        return $this->decide($request, $id, 'requestChanges', 'Changes requested from the student.', 'Request could not be sent.');
    }

    private function decide(Request $request, string $uuid, string $action, string $successMessage, string $errorMessage)
    {
        if ($err = $this->requireSupervisor($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $supervisor = $this->supervisors->findActiveByUserId($userId);
        $permissions = $this->supervisors->permissionsForUser($userId);

        if (!$supervisor || !in_array('manage_projects', $permissions, true)) {
            return $this->apiError('You do not have permission to review projects.', null, 403);
        }

        if (!$this->assignments->projectInScope($supervisor->id, $supervisor->university_id, $this->resolveProjectId($supervisor, $uuid))) {
            return $this->apiError($errorMessage, null, 404);
        }

        $comments = $request->input('comments');
        try {
            $ok = $this->approvals->$action($uuid, $supervisor->university_id, $userId, $comments ?: null);
        } catch (ApprovalException $e) {
            return $this->apiError($e->getMessage(), null, $e->httpStatus());
        }

        return $ok
            ? $this->apiSuccess(null, $successMessage)
            : $this->apiError($errorMessage, null, 422);
    }

    /**
     * زي findScopedProject() القديمة — بتمشي على scopedProjects() (متفلترة
     * جامعة المشرف أصلاً) لتحويل uuid لـ id داخلي، عشان projectInScope()
     * محتاجاه. بترجع 0 (مش موجود) لو الـ uuid مش لقاه، فـ projectInScope()
     * بترفض طبيعي.
     */
    private function resolveProjectId($supervisor, string $uuid): int
    {
        foreach ($this->assignments->scopedProjects($supervisor->id, $supervisor->university_id) as $row) {
            if (($row->uuid ?? null) === $uuid) {
                return (int) $row->id;
            }
        }
        return 0;
    }
}
