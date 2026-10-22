<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\SummerRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/summer — التسجيل الصيفي: الفصول والمواد المعروضة (جامعة/كلية)، وطلبات الطلاب وقراراتها.
 */
class SummerApiController extends Controller
{
    public function __construct(
        private SummerRegistrationService $requests,
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

    private function validate(Request $request, array $rules)
    {
        $v = Validator::make($request->all(), $rules);
        return $v->fails() ? $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422) : null;
    }

    public function status(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->status($scope, $this->locale($request))) : $this->denied();
    }

    public function register(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        if ($e = $this->validate($request, ['term_id' => 'required|integer', 'course_id' => 'required|integer', 'message' => 'nullable|string|max:2000'])) {
            return $e;
        }
        return $this->respond($this->requests->register($scope, $request->input('term_id'), $request->input('course_id'), $request->input('message'), $this->locale($request)), 201);
    }

    public function cancel(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->cancel($scope, $id, $this->locale($request))) : $this->denied();
    }

    public function manage(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->manage($scope, $this->locale($request))) : $this->denied();
    }

    private function termRules(): array
    {
        return [
            'name' => 'required|string|max:160', 'opens_at' => 'required|date', 'closes_at' => 'required|date',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date',
            'max_hours' => 'nullable|integer|min:1|max:30', 'only_failed' => 'nullable|boolean',
        ];
    }

    public function createTerm(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        if ($e = $this->validate($request, $this->termRules())) {
            return $e;
        }
        return $this->respond($this->requests->saveTerm($scope, null, $request->all(), $this->locale($request)), 201);
    }

    public function updateTerm(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        if ($e = $this->validate($request, $this->termRules())) {
            return $e;
        }
        return $this->respond($this->requests->saveTerm($scope, (int) $id, $request->all(), $this->locale($request)));
    }

    public function deleteTerm(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->deleteTerm($scope, (int) $id, $this->locale($request))) : $this->denied();
    }

    public function saveOfferings(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        if ($e = $this->validate($request, ['offerings' => 'present|array|max:200', 'offerings.*.course_id' => 'required|integer', 'offerings.*.capacity' => 'nullable|integer|min:1|max:5000'])) {
            return $e;
        }
        return $this->respond($this->requests->saveOfferings($scope, (int) $id, (array) $request->input('offerings'), $this->locale($request)));
    }

    public function requests(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->requests($scope, $request->only(['status', 'term_id']), $this->locale($request))) : $this->denied();
    }

    public function approve(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->approve($scope, $id, $request->input('note'), $this->locale($request))) : $this->denied();
    }

    public function reject(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->reject($scope, $id, $request->input('note'), $this->locale($request))) : $this->denied();
    }
}
