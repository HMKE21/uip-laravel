<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\CourseRequestService;
use App\Services\CourseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/courses — courses (المواد) for the university, faculty, doctor/TA and student portals.
 * Who sees / manages what is decided in CourseService; this controller only resolves the caller.
 */
class CourseApiController extends Controller
{
    public function __construct(
        private CourseService $courses,
        private CourseRequestService $requests,
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

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        return [
            'code'          => $req . '|string|max:40|regex:/^[A-Za-z0-9._\\- ]+$/',
            'name_en'       => $req . '|string|max:200',
            'name_ar'       => 'nullable|string|max:200',
            'description'   => 'nullable|string|max:2000',
            'credit_hours'  => 'nullable|integer|min:0|max:30',
            'academic_year' => 'nullable|integer|min:1|max:8',
            'semester'      => 'nullable|integer|in:1,2',
            'faculty_id'    => 'nullable|integer',
            'department_id' => 'nullable|integer',
        ];
    }

    public function index(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $rows = $this->courses->list($scope, [
            'q' => $request->input('q'), 'status' => $request->input('status'), 'faculty_id' => $request->input('faculty_id'),
            'mine' => $request->boolean('mine'), 'enrolled_only' => $request->boolean('enrolled'),
        ], $this->locale($request));

        return $this->apiSuccess($rows);
    }

    /** GET /courses/meta — what the course form needs: faculties (university only) + departments in scope. */
    public function meta(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $faculties = [];
        if ($scope['role'] === 'university') {
            $faculties = \Illuminate\Support\Facades\DB::table('faculties')->where('university_id', $scope['universityId'])
                ->where('status', '!=', 'archived')->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])->map(fn ($r) => (array) $r)->all();
        }
        $deps = \Illuminate\Support\Facades\DB::table('departments as d')->join('faculties as f', 'f.id', '=', 'd.faculty_id')
            ->where('f.university_id', $scope['universityId'])->whereNull('d.deleted_at')
            ->when($scope['role'] !== 'university', fn ($q) => $q->where('d.faculty_id', $scope['facultyId'] ?: 0))
            ->orderBy('d.name_en')->get(['d.id', 'd.faculty_id', 'd.name_en', 'd.name_ar'])->map(fn ($r) => (array) $r)->all();

        return $this->apiSuccess(['faculties' => $faculties, 'departments' => $deps]);
    }

    public function show(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $row = $this->courses->find($scope, $id, $this->locale($request));
        return $row ? $this->apiSuccess($row) : $this->apiError('Course not found.', null, 404);
    }

    public function store(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules());
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        $res = $this->courses->create($scope, $request->only(['code', 'name_en', 'name_ar', 'description', 'credit_hours', 'academic_year', 'semester', 'faculty_id', 'department_id']), $this->locale($request));
        return $res['success'] ? $this->apiSuccess(['id' => $res['id']], $res['message'], 201) : $this->respond($res);
    }

    public function update(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->courses->update($scope, $id, $request->only(['code', 'name_en', 'name_ar', 'description', 'credit_hours', 'academic_year', 'semester', 'faculty_id', 'department_id']), $this->locale($request)));
    }

    public function archive(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->courses->setStatus($scope, $id, 'archived', $this->locale($request))) : $this->denied();
    }

    public function restore(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->courses->setStatus($scope, $id, 'active', $this->locale($request))) : $this->denied();
    }

    public function assignStaff(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        return $this->respond($this->courses->assignStaff($scope, $id, $request->input('academic_staff_id'), $this->locale($request)));
    }

    public function removeStaff(Request $request, $id, $staffId)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->courses->removeStaff($scope, $id, $staffId, $this->locale($request))) : $this->denied();
    }

    public function roster(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->courses->roster($scope, $id, $this->locale($request))) : $this->denied();
    }

    public function candidates(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->courses->candidates($scope, $id, $request->input('q'), $this->locale($request))) : $this->denied();
    }

    public function addStudents(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $res = $this->courses->addStudents($scope, $id, (array) $request->input('student_ids', []), $this->locale($request));
        return $res['success'] ? $this->apiSuccess(['added' => $res['added']], $res['message']) : $this->respond($res);
    }

    public function removeStudent(Request $request, $id, $studentId)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->courses->removeStudent($scope, $id, $studentId, $this->locale($request))) : $this->denied();
    }

    // ---- enrol / drop REQUESTS (students no longer enrol or drop directly)

    private function sendRequest(Request $request, $id, string $type)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), [
            'message'    => 'nullable|string|max:2000',
            'attachment' => 'nullable|file|max:10240',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->requests->create($scope, $id, $type, $request->input('message'), $request->file('attachment'), $this->locale($request)), 201);
    }

    /** POST /courses/{id}/enroll — sends an enrol request (multipart: message, attachment). */
    public function enroll(Request $request, $id)
    {
        return $this->sendRequest($request, $id, 'enroll');
    }

    /** POST|DELETE /courses/{id}/unenroll|enroll — sends a drop request. */
    public function unenroll(Request $request, $id)
    {
        return $this->sendRequest($request, $id, 'drop');
    }

    /** GET /courses/requests — student: my requests; university / faculty / staff: requests addressed to me. */
    public function requestsIndex(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->apiSuccess($this->requests->list($scope, $request->only(['status', 'course_id']), $this->locale($request))) : $this->denied();
    }

    public function requestApprove(Request $request, $rid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->approve($scope, $rid, $request->input('note'), $this->locale($request))) : $this->denied();
    }

    public function requestReject(Request $request, $rid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->reject($scope, $rid, $request->input('note'), $this->locale($request))) : $this->denied();
    }

    public function requestCancel(Request $request, $rid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->cancel($scope, $rid, $this->locale($request))) : $this->denied();
    }

    public function requestAttachment(Request $request, $rid)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $a = $this->requests->attachmentFor($scope, $rid);
        $full = $a ? public_path(ltrim($a['path'], '/')) : null;
        if (!$a || !is_file($full)) {
            return $this->apiError('File not found.', null, 404);
        }
        return response()->download($full, basename($a['name']), ['Content-Type' => $a['mime'], 'X-Content-Type-Options' => 'nosniff']);
    }
}
