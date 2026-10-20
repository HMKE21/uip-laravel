<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\Paginates;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;
use App\Services\SupervisorManagementService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SupervisorsApiController.php القديمة —
 * بند 9 (Supervisors)، جزء 1. سطح REST واحد /api/v1/supervisors/* لروستر
 * `supervisors` (migration 095، حسابات /supervisor/* حقيقية login-capable)،
 * بيلف SupervisorManagementService بالظبط زي ما
 * University\UniversitySupervisorController القديمة كانت بتعمل:
 *
 *   1. روستر مملوك للجامعة + CRUD — index()/show()/store()/update()/
 *      activate()/deactivate()/resend()/destroy() بتلف
 *      SupervisorRepository::forUniversity()/findOwned() ونداءات
 *      SupervisorManagementService (invite/updateRole/activate/
 *      deactivate/resendInvite/delete) نفسها. Role جامعة بس؛
 *      university_id دايمًا محلول من uip_user_id، أبدًا قيمة من العميل.
 *   2. نطاقات الإسناد — assignments()/assign()/unassign() تغليف
 *      SupervisorAssignmentRepository::forSupervisorWithLabels() و
 *      SupervisorManagementService::assignScope()/unassignScope() بالظبط
 *      (منح نطاق faculty/department/academic_year/group/project).
 *   3. صف المشرف نفسه — me() صف الروستر بتاع المستدعي + نطاقاته
 *      المُسندة، محلول عبر SupervisorRepository::findActiveByUserId()،
 *      أبدًا id من العميل. المشرفين مفيش عندهم تعديل بروفايل ذاتي (شغلانة
 *      الجامعة، زي أعضاء الفرق)، فـ me() للقراءة بس.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/hasRole() ->
 * $request->attributes->get('uip_user_id')/'uip_role' (نفس نمط بند 6/8)،
 * وuse الـ Paginates trait بنسختها الملارافيلة.
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php)؛ الأدوار الجامعة-فقط
 * بتتفحص كمان جوه كل ميثود عبر uip_role.
 */
class SupervisorsApiController extends Controller
{
    use Paginates;

    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private UniversityRepository $universities,
        private SupervisorManagementService $management
    ) {
    }

    // -- روستر مملوك للجامعة + CRUD --------------------------------------

    /**
     * GET /api/v1/supervisors — روستر مشرفي جامعة الكولر نفسه، بنطاقاتهم.
     * Role جامعة بس. بتدعم `search` (على full_name/email) و`page`/`per_page`.
     */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can list their supervisors.', null, 403);
        }

        $university = $this->universities->getOrCreate((int) $request->attributes->get('uip_user_id'));

        $rows = array_map(function ($s) {
            $row = $s->toArray();
            $row['effective_invitation_status'] = SupervisorManagementService::effectiveInvitationStatus($row);
            $row['permissions_list'] = $row['permissions'] !== '' && $row['permissions'] !== null ? explode(',', $row['permissions']) : [];
            $row['assignments'] = $this->assignments->forSupervisorWithLabels($s->id);
            return $row;
        }, $this->supervisors->forUniversity($university->id));

        $rows = $this->filterBySearch($request, $rows, ['full_name', 'name_ar', 'name_en', 'email']);
        [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);

        return $this->apiSuccess($items, 'Supervisors retrieved successfully.', 200, $this->meta($page, $perPage, $total));
    }

    /** GET /api/v1/supervisors/{id} — محكوم بالملكية، مع نطاقاته. Role جامعة بس. */
    public function show(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can view a supervisor this way.', null, 403);
        }

        $university = $this->universities->getOrCreate((int) $request->attributes->get('uip_user_id'));
        $supervisor = $this->supervisors->findOwned($id, $university->id);
        if (!$supervisor) {
            return $this->apiError('Supervisor not found.', null, 404);
        }

        $row = $supervisor->toArray();
        $row['effective_invitation_status'] = SupervisorManagementService::effectiveInvitationStatus($row);
        $row['permissions_list'] = $row['permissions'] !== '' && $row['permissions'] !== null ? explode(',', $row['permissions']) : [];
        $row['assignments'] = $this->assignments->forSupervisorWithLabels($supervisor->id);

        return $this->apiSuccess($row, 'Supervisor retrieved successfully.');
    }

    /** POST /api/v1/supervisors — دعوة مشرف جديد. Role جامعة بس. */
    public function store(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can invite supervisors.', null, 403);
        }

        $names = \App\Support\BilingualName::fromRequest($request);
        $email = trim((string) $request->input('email', ''));
        $errors = $names['errors'];
        if ($email === '') {
            $errors['email'] = 'Required.';
        }
        if ($errors) {
            return $this->apiError('Validation failed.', $errors, 422);
        }
        $fullName = $names['full_name'];

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $permissions = (array) $request->input('permissions', []);

        $result = $this->management->invite(
            $university->id,
            $userId,
            $fullName,
            $email,
            $request->input('department'),
            $request->input('title'),
            $permissions,
            (string) $request->input('locale', 'ar'),
            $request->input('password') !== null ? (string) $request->input('password') : null,
            $names['name_ar'],
            $names['name_en']
        );

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password'] ?? null, 'email_sent' => $result['email_sent'] ?? false], $result['message'], 201)
            : $this->apiError($result['message'], null, 422);
    }

    /** PATCH /api/v1/supervisors/{id}/password — set or regenerate the supervisor's password. Role جامعة بس. */
    public function setPassword(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can change a supervisor password.', null, 403);
        }
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $result = $this->management->setPassword(
            $id, $university->id, $userId,
            $request->input('password') !== null ? (string) $request->input('password') : null,
            (string) $request->input('locale', 'ar')
        );

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password'] ?? null], $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /** PATCH /api/v1/supervisors/{id} — department/title/permissions. Role جامعة بس. */
    public function update(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a supervisor.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $permissions = (array) $request->input('permissions', []);

        $ok = $this->management->updateRole(
            $id,
            $university->id,
            $userId,
            $request->input('department'),
            $request->input('title'),
            $permissions
        );

        return $ok
            ? $this->apiSuccess(null, 'Supervisor permissions updated successfully.')
            : $this->apiError('Supervisor not found.', null, 404);
    }

    /** POST /api/v1/supervisors/{id}/activate — Role جامعة بس. */
    public function activate(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can activate a supervisor.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $ok = $this->management->activate($id, $university->id, $userId);

        return $ok
            ? $this->apiSuccess(null, 'Supervisor account activated successfully.')
            : $this->apiError('Supervisor not found.', null, 404);
    }

    /** POST /api/v1/supervisors/{id}/deactivate — Role جامعة بس. */
    public function deactivate(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can deactivate a supervisor.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $ok = $this->management->deactivate($id, $university->id, $userId);

        return $ok
            ? $this->apiSuccess(null, 'Supervisor account deactivated — they can no longer sign in.')
            : $this->apiError('Supervisor not found.', null, 404);
    }

    /** POST /api/v1/supervisors/{id}/resend — بتعيد إرسال إيميل الدعوة. Role جامعة بس. */
    public function resend(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can resend a supervisor invite.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $result = $this->management->resendInvite($id, $university->id, $userId);

        return $result['success']
            ? $this->apiSuccess(null, $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /** DELETE /api/v1/supervisors/{id} — Role جامعة بس. */
    public function destroy(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can remove a supervisor.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $ok = $this->management->delete($id, $university->id, $userId);

        return $ok
            ? $this->apiSuccess(null, 'Supervisor removed successfully.')
            : $this->apiError('Supervisor not found.', null, 404);
    }

    // -- نطاقات الإسناد ----------------------------------------------------

    /** GET /api/v1/supervisors/{id}/assignments — نطاقات المشرف ده. Role جامعة بس. */
    public function assignments(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can view supervisor assignments.', null, 403);
        }

        $university = $this->universities->getOrCreate((int) $request->attributes->get('uip_user_id'));
        $supervisor = $this->supervisors->findOwned($id, $university->id);
        if (!$supervisor) {
            return $this->apiError('Supervisor not found.', null, 404);
        }

        return $this->apiSuccess($this->assignments->forSupervisorWithLabels($supervisor->id), 'Assignments retrieved successfully.');
    }

    /**
     * POST /api/v1/supervisors/{id}/assignments — منح نطاق
     * faculty/department/academic_year/group/project. Role جامعة بس.
     */
    public function assign(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can assign a supervisor scope.', null, 403);
        }

        $scopeType = (string) $request->input('scope_type', '');
        if ($scopeType === '') {
            return $this->apiError('Validation failed.', ['scope_type' => 'Required.'], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $scopeValue = $request->input('scope_value');
        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;

        $result = $this->management->assignScope(
            $id,
            $university->id,
            $userId,
            $scopeType,
            $scopeValue,
            $projectId
        );

        return $result['success']
            ? $this->apiSuccess(null, $result['message'], 201)
            : $this->apiError($result['message'], null, 422);
    }

    /** DELETE /api/v1/supervisors/{id}/assignments/{assignmentId} — سحب نطاق واحد. Role جامعة بس. */
    public function unassign(Request $request, $id, $assignmentId)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can remove a supervisor scope.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $ok = $this->management->unassignScope($assignmentId, $id, $university->id, $userId);

        return $ok
            ? $this->apiSuccess(null, 'Scope removed from supervisor successfully.')
            : $this->apiError('Assignment not found.', null, 404);
    }

    // -- صف المشرف نفسه ------------------------------------------------------

    /** GET /api/v1/supervisors/me — صف روستر الكولر نفسه + نطاقاته المُسندة. Role مشرف بس، للقراءة بس (شوف docblock الكلاس). */
    public function me(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts have a supervisor profile.', null, 403);
        }

        $supervisor = $this->supervisors->findActiveByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$supervisor) {
            return $this->apiError('Supervisor profile not found or inactive.', null, 404);
        }

        $row = $supervisor->toArray();
        $row['permissions_list'] = $row['permissions'] !== '' && $row['permissions'] !== null ? explode(',', $row['permissions']) : [];
        $row['assignments'] = $this->assignments->forSupervisorWithLabels($supervisor->id);

        return $this->apiSuccess($row, 'Supervisor profile retrieved successfully.');
    }
}
