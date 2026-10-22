<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\CourseTimetableService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * جدول المحاضرات — Study Time Table
 *
 *  GET    /courses/timetable                 — الجدول الأسبوعي حسب الدور (?course_id= &day= &kind=)
 *  GET    /courses/{id}/sessions             — حصص مادة واحدة
 *  POST   /courses/{id}/sessions             — إضافة حصة  [مديرو المادة]
 *  PATCH  /courses/{id}/sessions/{sid}       — تعديل حصة  [مديرو المادة]
 *  DELETE /courses/{id}/sessions/{sid}       — حذف حصة    [مديرو المادة]
 *
 * الصلاحيات وتعارض القاعة/المدرّس كلها في CourseTimetableService.
 */
class CourseTimetableApiController extends Controller
{
    public function __construct(
        private CourseTimetableService $timetable,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $staff,
        private StudentRepository $students
    ) {
    }

    /** نفس CourseApiController::scope() بالظبط. */
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
            return $this->apiSuccess($res['data'] ?? null, $res['message'] ?? 'OK', $okStatus);
        }
        return $this->apiError($res['message'] ?? 'Error', null, $res['code'] ?? 422);
    }

    private function denied()
    {
        return $this->apiError('Your account is not linked to a university.', null, 403);
    }

    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $time = ['regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'];
        return [
            'kind'              => ($partial ? 'sometimes|' : 'nullable|') . 'in:' . implode(',', CourseTimetableService::KINDS),
            'day_of_week'       => $req . '|integer|between:1,7',
            'start_time'        => array_merge([$req], $time),
            'end_time'          => array_merge([$req], $time),
            'room'              => 'nullable|string|max:120',
            'group_label'       => 'nullable|string|max:60',
            'academic_staff_id' => 'nullable|integer|min:1',
            'starts_on'         => 'nullable|date_format:Y-m-d',
            'ends_on'           => 'nullable|date_format:Y-m-d',
            'notes'             => 'nullable|string|max:500',
        ];
    }

    private const FIELDS = ['kind', 'day_of_week', 'start_time', 'end_time', 'room', 'group_label', 'academic_staff_id', 'starts_on', 'ends_on', 'notes'];

    public function index(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $filters = $request->only(['course_id', 'day', 'kind']);
        return $this->respond($this->timetable->timetable($scope, $filters, $this->locale($request)));
    }

    public function forCourse(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->timetable->listForCourse($scope, $id, $this->locale($request))) : $this->denied();
    }

    public function store(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        $data = $request->only(self::FIELDS) + ['kind' => 'lecture'];
        return $this->respond($this->timetable->create($scope, $id, $data, $this->locale($request)), 201);
    }

    public function update(Request $request, $id, $sid)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->timetable->update($scope, $id, $sid, $request->only(self::FIELDS), $this->locale($request)));
    }

    public function destroy(Request $request, $id, $sid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->timetable->delete($scope, $id, $sid, $this->locale($request))) : $this->denied();
    }
}
