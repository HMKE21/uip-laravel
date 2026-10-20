<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\FacultyRepository;
use App\Repositories\UniversityRepository;
use App\Services\AccountLinkService;
use Illuminate\Http\Request;

/**
 * Link existing platform accounts to the caller's university / faculty.
 *   GET  /api/v1/people/lookup?type=student|academic_staff|supervisor&email=…
 *   POST /api/v1/people/link/student         {email, faculty_id?, department_id?, group_id?, academic_year?, current_semester?, student_number?}
 *   POST /api/v1/people/link/academic-staff  {email, faculty_id?, department_id?, academic_rank_id?, staff_number?, bio?}
 *   POST /api/v1/people/link/supervisor      {email, department?, title?, permissions?[]}   (university only)
 * University accounts act on their whole university; faculty accounts are locked to their own faculty
 * and can't link supervisors.
 */
class AccountLinkApiController extends Controller
{
    public function __construct(
        private AccountLinkService $links,
        private UniversityRepository $universities,
        private FacultyRepository $faculties
    ) {
    }

    private function scope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $user = User::find($userId);
            $u = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
            return ['role' => 'university', 'universityId' => $u->id, 'facultyId' => null, 'userId' => $userId];
        }
        if ($role === 'faculty') {
            $f = $this->faculties->findByUserId($userId);
            return $f ? ['role' => 'faculty', 'universityId' => $f->university_id, 'facultyId' => (int) $f->id, 'userId' => $userId] : null;
        }
        return null;
    }

    private function int(Request $r, string $k): ?int
    {
        $v = $r->input($k);
        return ($v !== null && $v !== '') ? (int) $v : null;
    }

    public function lookup(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can look up accounts.', null, 403);
        }
        $type = (string) $request->input('type', '');
        if ($type === 'supervisor' && $scope['role'] !== 'university') {
            return $this->apiError('Only university accounts can link supervisors.', null, 403);
        }

        $res = $this->links->lookup($type, (string) $request->input('email', ''), $scope['universityId'], $scope['facultyId'], (string) $request->input('locale', 'ar'));

        return $this->apiSuccess($res);
    }

    public function linkStudent(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can link students.', null, 403);
        }
        $res = $this->links->linkStudent(
            $scope['universityId'], $scope['userId'], (string) $request->input('email', ''),
            $this->int($request, 'faculty_id'), $this->int($request, 'department_id'),
            $this->int($request, 'group_id'), $this->int($request, 'academic_year'), $this->int($request, 'current_semester'),
            $request->input('student_number'), (string) $request->input('locale', 'ar'), $scope['facultyId']
        );

        return $res['success'] ? $this->apiSuccess(['id' => $res['id']], $res['message'], 201) : $this->apiError($res['message'], null, 422);
    }

    public function linkAcademicStaff(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can link academic staff.', null, 403);
        }
        $res = $this->links->linkAcademicStaff(
            $scope['universityId'], $scope['userId'], (string) $request->input('email', ''),
            $this->int($request, 'faculty_id'), $this->int($request, 'department_id'), $this->int($request, 'academic_rank_id'),
            $request->input('staff_number'), $request->input('bio'), (string) $request->input('locale', 'ar'), $scope['facultyId']
        );

        return $res['success'] ? $this->apiSuccess(['id' => $res['id']], $res['message'], 201) : $this->apiError($res['message'], null, 422);
    }

    public function linkSupervisor(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope || $scope['role'] !== 'university') {
            return $this->apiError('Only university accounts can link supervisors.', null, 403);
        }
        $res = $this->links->linkSupervisor(
            $scope['universityId'], $scope['userId'], (string) $request->input('email', ''),
            $request->input('department'), $request->input('title'), (array) $request->input('permissions', []),
            (string) $request->input('locale', 'ar')
        );

        return $res['success'] ? $this->apiSuccess(['id' => $res['id']], $res['message'], 201) : $this->apiError($res['message'], null, 422);
    }
}
