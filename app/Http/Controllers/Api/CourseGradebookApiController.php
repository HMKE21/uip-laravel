<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\CourseGradebookService;
use App\Services\CourseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * GET /api/v1/courses/{id}/gradebook        — دفتر الدرجات (اللي يقدر يدير المقرر بس: جامعته/كليته/مدرّسه).
 * PUT /api/v1/courses/{id}/gradebook/items  — تحديد الامتحانات وأوزانها (نفس الصلاحية).
 * GET /api/v1/courses/{id}/my-grade         — الطالب المسجّل في المقرر بيشوف درجته هو بس (النتايج الظاهرة له فقط).
 * الصلاحية بتتقرر في CourseService::manageableCourse() (نفس منطق إدارة المقررات)، وأي مقرر برا جامعة المستخدم 404.
 */
class CourseGradebookApiController extends Controller
{
    public function __construct(
        private CourseGradebookService $gradebook,
        private CourseService $courses,
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

    /** @return array{0:?Course,1:?array,2:mixed} [course, scope, errorResponse] */
    private function managed(Request $request, $id): array
    {
        $scope = $this->scope($request);
        if (!$scope || $scope['role'] === 'student') {
            return [null, null, $this->apiError('You cannot manage course grades.', null, 403)];
        }
        $c = $this->courses->manageableCourse($scope, $id, $this->locale($request));
        if (is_array($c)) {
            return [null, null, $this->apiError($c['message'] ?? 'Error', null, $c['code'] ?? 422)];
        }
        return [$c, $scope, null];
    }

    public function show(Request $request, $id)
    {
        [$course, , $err] = $this->managed($request, $id);
        if ($err) {
            return $err;
        }
        return $this->apiSuccess($this->gradebook->gradebook($course), 'Gradebook retrieved successfully.');
    }

    public function saveItems(Request $request, $id)
    {
        [$course, $scope, $err] = $this->managed($request, $id);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'items'                  => 'present|array|max:50',
            'items.*.exam_id'        => 'required|integer',
            'items.*.weight'         => 'required|numeric|gt:0|max:100',
            'items.*.attempt_policy' => 'nullable|in:highest,latest,first',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        $res = $this->gradebook->saveItems($course, $request->input('items', []), $scope['userId']);
        if (!$res['success']) {
            return $this->apiError($res['message'], null, $res['code'] ?? 422);
        }
        return $this->apiSuccess($this->gradebook->gradebook($course->fresh()), 'Gradebook saved successfully.');
    }

    public function mine(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope || $scope['role'] !== 'student') {
            return $this->apiError('Only student accounts can view their course grade.', null, 403);
        }
        $course = Course::where('university_id', $scope['universityId'])->find((int) $id);
        $enrolled = $course && DB::table('course_enrollments')->where('course_id', $course->id)->where('student_id', $scope['studentId'])->exists();
        if (!$enrolled) {
            return $this->apiError('Course not found.', null, 404);
        }
        return $this->apiSuccess($this->gradebook->studentView($course, $scope['studentId']), 'Course grade retrieved successfully.');
    }
}
