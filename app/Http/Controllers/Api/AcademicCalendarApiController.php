<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\AcademicCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/academic-calendar — التقويم الأكاديمي: عرض لكل الأدوار، وإدارة الأحداث للجامعة والكلية.
 */
class AcademicCalendarApiController extends Controller
{
    public function __construct(
        private AcademicCalendarService $calendar,
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

    private function with(Request $request, callable $fn)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($fn($scope, $this->locale($request))) : $this->denied();
    }

    private function rules(): array
    {
        return ['title' => 'required|string|max:160', 'kind' => 'nullable|in:term,exams,holiday,deadline,registration,other', 'starts_on' => 'required|date', 'ends_on' => 'nullable|date', 'note' => 'nullable|string|max:500', 'faculty_id' => 'nullable|integer'];
    }

    private function check(Request $request)
    {
        $v = Validator::make($request->all(), $this->rules());
        return $v->fails() ? $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422) : null;
    }

    public function index(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->calendar->index($s, $request->only(['kind']), $l));
    }

    public function store(Request $request)
    {
        if ($e = $this->check($request)) {
            return $e;
        }
        return $this->with($request, fn ($s, $l) => $this->calendar->save($s, null, $request->all(), $l));
    }

    public function update(Request $request, $id)
    {
        if ($e = $this->check($request)) {
            return $e;
        }
        return $this->with($request, fn ($s, $l) => $this->calendar->save($s, (int) $id, $request->all(), $l));
    }

    public function destroy(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->calendar->delete($s, (int) $id, $l));
    }
}
