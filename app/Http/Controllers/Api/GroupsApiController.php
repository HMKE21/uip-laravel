<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\Paginates;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\GroupCollaborationService;
use App\Services\StudentManagementService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/GroupsApiController.php القديمة — سطح
 * REST واحد لـ /api/v1/groups/* (Student Groups/Teams، migration
 * 099/122)، بنفس شكل الـ JSON ونفس قواعد الملكية بالظبط (انظر docblock كل
 * ميثود تحت). المجموعات ملك الجامعة (بتنشئها/تسميها/تحلها وتحط طلاب
 * جواها) — مفيش إنشاء ذاتي من الطالب، زي البورتال بالظبط.
 *
 * فرق شكلي فقط عن القديمة (زي StudentsApiController بالظبط):
 * Session::userId()/hasRole() -> $request->attributes->get('uip_user_id')/
 * 'uip_role'، $this->param('id') -> $id مُمرر صراحة كـ route parameter،
 * $this->validate() الداخلية -> تحقق يدوي (trim + apiError 422)، locale
 * ثابتة 'en' زي القديمة بالظبط (القديمة كانت بتمرر 'en' هنا رغم دعم
 * ar/en في StudentManagementService).
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php). كل action بتشتق سكوبها
 * من $request->attributes نفسه (مش من client input) — جامعة بتتصرف بس في
 * مجموعاتها (findOwned())، كلية بس بتشوف/تنشئ (مفيش faculty_id على
 * student_groups أصلًا — مجموعة دايمًا جامعة-وايد)، طالب بس بيشوف مجموعته
 * هو (groupIdForStudent()).
 */
class GroupsApiController extends Controller
{
    use Paginates;

    public function __construct(
        private StudentGroupRepository $groups,
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private StudentManagementService $management,
        private GroupCollaborationService $collabService
    ) {
    }

    // -- List -----------------------------------------------------------

    /**
     * GET /api/v1/groups — جامعة: كل مجموعاتها مع عدد أعضاء (search بيطابق
     * name؛ page/per_page مدعومين). كلية: كل مجموعات جامعتها (مفيش
     * faculty_id على الجدول — نفس الشكل). طالب: مجموعته هو بس (صف واحد
     * كحد أقصى، من غير pagination).
     */
    public function index(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $university = $this->universities->getOrCreate($userId);
            $rows = $this->filterBySearch($request, $this->groups->forUniversityWithCounts((int) $university->id), ['name']);
            [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);
            return $this->apiSuccess($items, 'Groups retrieved successfully.', 200, $this->meta($page, $perPage, $total));
        }

        if ($role === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            if (!$faculty) {
                return $this->apiSuccess([], 'Groups retrieved successfully.', 200, $this->meta(1, $this->paginatesDefault, 0));
            }
            $rows = $this->filterBySearch($request, $this->groups->forUniversityWithCounts((int) $faculty->university_id), ['name']);
            [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);
            return $this->apiSuccess($items, 'Groups retrieved successfully.', 200, $this->meta($page, $perPage, $total));
        }

        if ($role === 'student') {
            $groupId = $this->collabService->groupIdForStudent($userId);
            if (!$groupId) {
                return $this->apiSuccess([], 'You are not assigned to a group yet.');
            }
            $group = $this->groups->find($groupId);
            return $this->apiSuccess($group ? [$group->toArray()] : [], 'Groups retrieved successfully.');
        }

        return $this->apiError('Only university or faculty accounts can view groups.', null, 403);
    }

    // -- Show -------------------------------------------------------------

    /** GET /api/v1/groups/{id} */
    public function show(Request $request, $id)
    {
        $group = $this->authorizedGroup($request, (int) $id);
        if (!$group) {
            return $this->apiError('Group not found.', null, 404);
        }

        return $this->apiSuccess([
            'group'   => $group->toArray(),
            'members' => $this->groups->members($id),
        ], 'Group retrieved successfully.');
    }

    // -- Create -------------------------------------------------------------

    /**
     * POST /api/v1/groups — جامعة: من غير قيود. كلية: مسموح كمان (زي
     * FacultyStudentController::storeGroup() القديمة) — بتتنشئ عند
     * $faculty->university_id، فبتبقى مشتركة على مستوى الجامعة كلها زي
     * لو الجامعة نفسها عملتها.
     */
    public function store(Request $request)
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->apiError('Validation failed.', ['name' => 'Required.'], 422);
        }

        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $universityId = (int) $this->universities->getOrCreate($userId)->id;
        } elseif ($role === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            if (!$faculty) {
                return $this->apiError('This login is not linked to a faculty.', null, 422);
            }
            $universityId = (int) $faculty->university_id;
        } else {
            return $this->apiError('Only university or faculty accounts can create a group.', null, 403);
        }

        $maxMembers = $request->input('max_members') !== null && $request->input('max_members') !== ''
            ? max(0, (int) $request->input('max_members')) : null;

        $result = $this->management->createGroup(
            $universityId,
            $userId,
            $name,
            $request->input('description'),
            'en',
            $maxMembers
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 409);
        }

        $group = $this->groups->findByName($universityId, $name);
        return $this->apiSuccess($group?->toArray(), $result['message'], 201);
    }

    // -- Update -------------------------------------------------------------

    /** PATCH /api/v1/groups/{id} — جامعة بس، مجموعتها هي. */
    public function update(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a group.', null, 403);
        }

        $id = (int) $id;
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->apiError('Validation failed.', ['name' => 'Required.'], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        $maxMembers = $request->input('max_members') !== null && $request->input('max_members') !== ''
            ? max(0, (int) $request->input('max_members')) : null;

        $result = $this->management->updateGroup(
            $id,
            (int) $university->id,
            $userId,
            $name,
            $request->input('description'),
            'en',
            $maxMembers
        );

        if (!$result['success']) {
            $status = $result['message'] === 'Group not found.' ? 404 : 409;
            return $this->apiError($result['message'], null, $status);
        }

        return $this->apiSuccess($this->groups->find($id)?->toArray(), $result['message']);
    }

    // -- Dissolve (Delete) ---------------------------------------------------

    /** DELETE /api/v1/groups/{id} — جامعة بس، مجموعتها هي. الطلاب أبدًا مش بيتمسحوا، بس بيرجعوا "بدون مجموعة". */
    public function destroy(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can dissolve a group.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        $ok = $this->management->deleteGroup((int) $id, (int) $university->id, $userId);
        if (!$ok) {
            return $this->apiError('Group not found.', null, 404);
        }

        return $this->apiSuccess(null, 'Group dissolved — its students were not deleted.');
    }

    // -- Members --------------------------------------------------------

    /** GET /api/v1/groups/{id}/members */
    public function members(Request $request, $id)
    {
        $group = $this->authorizedGroup($request, (int) $id);
        if (!$group) {
            return $this->apiError('Group not found.', null, 404);
        }

        return $this->apiSuccess($this->groups->members($id), 'Members retrieved successfully.');
    }

    /** POST /api/v1/groups/{id}/members — ضم طالب/طلاب موجودين بالفعل للمجموعة. جامعة بس. */
    public function addMembers(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can manage group membership.', null, 403);
        }

        $id = (int) $id;
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        if (!$this->groups->findOwned($id, (int) $university->id)) {
            return $this->apiError('Group not found.', null, 404);
        }

        $studentIds = (array) $request->input('student_ids', []);
        if ($studentIds === []) {
            return $this->apiError('The given data was invalid.', ['student_ids' => ['At least one student id is required.']], 422);
        }

        $result = $this->management->moveStudents($studentIds, (int) $university->id, $userId, $id, 'en');
        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess($this->groups->members($id), $result['message']);
    }

    /** DELETE /api/v1/groups/{id}/members/{studentId} — شيل طالب من المجموعة (بيرجع لـ "بدون مجموعة"). جامعة بس. */
    public function removeMember(Request $request, $id, $studentId)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can manage group membership.', null, 403);
        }

        $id = (int) $id;
        $studentId = (int) $studentId;
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        if (!$this->groups->findOwned($id, (int) $university->id)) {
            return $this->apiError('Group not found.', null, 404);
        }

        $student = $this->students->find($studentId);
        if (!$student || (int) $student->university_id !== (int) $university->id || (int) $student->group_id !== $id) {
            return $this->apiError('That student is not a member of this group.', null, 404);
        }

        $result = $this->management->moveStudents([$studentId], (int) $university->id, $userId, null, 'en');
        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess(null, 'Student removed from group.');
    }

    // -- Invite -----------------------------------------------------------

    /**
     * POST /api/v1/groups/{id}/invite — دعوة حساب طالب جديد يدخل مباشرة
     * جوه المجموعة دي (نفس StudentManagementService::invite() اللي
     * "Add Student" في الجامعة بتستخدمه، مع group_id مثبت على المجموعة
     * دي). جامعة بس.
     */
    public function invite(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can invite a student to a group.', null, 403);
        }

        $id = (int) $id;
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        if (!$this->groups->findOwned($id, (int) $university->id)) {
            return $this->apiError('Group not found.', null, 404);
        }

        $fullName = trim((string) $request->input('full_name', ''));
        $email = trim((string) $request->input('email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
            && ($why = \App\Support\DisposableEmail::problem($email, (string) $request->input('locale', 'ar')))) {
            return $this->apiError('Validation failed.', ['email' => $why], 422);
        }
        if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->apiError('Validation failed.', [
                'full_name' => $fullName === '' ? 'Required.' : null,
                'email'     => ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) ? 'A valid email is required.' : null,
            ], 422);
        }

        $result = $this->management->invite(
            (int) $university->id,
            $userId,
            $fullName,
            $email,
            $request->input('student_number'),
            $request->input('faculty_id') ? (int) $request->input('faculty_id') : null,
            $request->input('department_id') ? (int) $request->input('department_id') : null,
            $request->input('academic_year') ? (int) $request->input('academic_year') : null,
            $id,
            'en',
            $request->input('current_semester') ? (int) $request->input('current_semester') : null,
            $request->input('study_start_date')
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess(null, $result['message'], 201);
    }

    // -- helpers ------------------------------------------------------------

    /** جامعة: المجموعة لازم تكون ملكها. طالب: المجموعة لازم تكون هي مجموعته. null غير كده. */
    private function authorizedGroup(Request $request, int $id): ?\App\Models\StudentGroup
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $university = $this->universities->getOrCreate($userId);
            return $this->groups->findOwned($id, (int) $university->id);
        }

        if ($role === 'student') {
            $groupId = $this->collabService->groupIdForStudent($userId);
            if ($groupId !== $id) {
                return null;
            }
            return $this->groups->find($id);
        }

        return null;
    }
}
