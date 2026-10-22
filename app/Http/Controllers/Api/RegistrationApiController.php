<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\RegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/registration — التسجيل الأكاديمي.
 *  الطالب: GET /status (الفترة + الساعات + الكتالوج بالأهلية).
 *  الجامعة/الكلية: GET /manage · PUT /policy · POST|PUT|DELETE /periods · المتطلبات: PUT /courses/{id}/prerequisites (مع مدرّس المادة).
 */
class RegistrationApiController extends Controller
{
    public function __construct(
        private RegistrationService $registration,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $staff,
        private StudentRepository $students
    ) {
    }

    private function scope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');
        $base = ['role' => $role, 'userId' => $userId, 'facultyId' => null, 'staffId' => null, 'studentId' => null];

        switch ($role) {
            case 'university':
                $user = User::find($userId);
                $u = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
                return $base + ['universityId' => $u->id];
            case 'faculty':
                $f = $this->faculties->findByUserId($userId);
                return $f ? array_merge($base, ['universityId' => $f->university_id, 'facultyId' => (int) $f->id]) : null;
            case 'academic_staff':
                $a = $this->staff->findByUserId($userId);
                return $a ? array_merge($base, ['universityId' => $a->university_id, 'facultyId' => $a->faculty_id ? (int) $a->faculty_id : null, 'staffId' => (int) $a->id]) : null;
            case 'student':
                $s = $this->students->findByUserId($userId);
                return ($s && $s->university_id)
                    ? array_merge($base, ['universityId' => $s->university_id, 'facultyId' => $s->faculty_id ? (int) $s->faculty_id : null, 'studentId' => (int) $s->id])
                    : null;
        }
        return null;
    }

    private function locale(Request $r): string
    {
        return $r->input('locale') === 'en' ? 'en' : 'ar';
    }

    private function respond(array $res, int $okStatus = 200)
    {
        if ($res['success']) {
            $data = array_diff_key($res, array_flip(['success', 'message', 'code']));
            return $this->apiSuccess($data['data'] ?? ($data ?: null), $res['message'] ?? 'OK', $okStatus);
        }
        return $this->apiError($res['message'] ?? 'Error', null, $res['code'] ?? 422);
    }

    private function denied()
    {
        return $this->apiError('Your account is not linked to a university.', null, 403);
    }

    public function status(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->registration->status($scope, $this->locale($request))) : $this->denied();
    }

    public function manage(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->registration->manage($scope, $this->locale($request))) : $this->denied();
    }

    public function savePolicy(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $flag = 'nullable|boolean';
        $v = Validator::make($request->all(), [
            'enforce_period' => $flag, 'enforce_prerequisites' => $flag, 'enforce_hours' => $flag, 'enforce_conflicts' => $flag,
            'max_hours' => 'nullable|integer|min:1|max:40',
            'low_gpa_max_hours' => 'nullable|integer|min:1|max:40',
            'high_gpa_max_hours' => 'nullable|integer|min:1|max:40',
            'low_gpa_threshold' => 'nullable|numeric|min:0|max:5',
            'high_gpa_threshold' => 'nullable|numeric|min:0|max:5',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->registration->savePolicy($scope, $request->only([
            'enforce_period', 'enforce_prerequisites', 'enforce_hours', 'enforce_conflicts',
            'max_hours', 'low_gpa_threshold', 'low_gpa_max_hours', 'high_gpa_threshold', 'high_gpa_max_hours',
        ]), $this->locale($request)));
    }

    private function periodRules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'kind' => 'nullable|in:enroll,drop,both',
            'opens_at' => 'required|date',
            'closes_at' => 'required|date',
        ];
    }

    public function createPeriod(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->periodRules());
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->registration->savePeriod($scope, null, $request->only(['name', 'kind', 'opens_at', 'closes_at']), $this->locale($request)), 201);
    }

    public function updatePeriod(Request $request, $pid)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->periodRules());
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->registration->savePeriod($scope, (int) $pid, $request->only(['name', 'kind', 'opens_at', 'closes_at']), $this->locale($request)));
    }

    public function deletePeriod(Request $request, $pid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->registration->deletePeriod($scope, (int) $pid, $this->locale($request))) : $this->denied();
    }

    public function prerequisites(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->registration->prerequisiteIds($scope, (int) $id, $this->locale($request))) : $this->denied();
    }

    public function savePrerequisites(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), ['prerequisite_ids' => 'present|array|max:20', 'prerequisite_ids.*' => 'integer']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->registration->savePrerequisites($scope, (int) $id, (array) $request->input('prerequisite_ids'), $this->locale($request)));
    }
}
