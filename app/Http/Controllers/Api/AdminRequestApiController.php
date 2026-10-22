<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\AdminRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/admin-requests — الطلبات الإدارية (شهادة قيد / بيان درجات / إفادة): الطالب ← الكلية ← الجامعة.
 */
class AdminRequestApiController extends Controller
{
    public function __construct(
        private AdminRequestService $requests,
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

    public function index(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->list($scope, $request->only(['status', 'type']), $this->locale($request))) : $this->denied();
    }

    public function store(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), [
            'type' => 'required|in:enrollment_certificate,transcript,statement',
            'purpose' => 'nullable|string|max:200',
            'message' => 'nullable|string|max:2000',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->requests->create($scope, (string) $request->input('type'), $request->input('purpose'), $request->input('message'), $this->locale($request)), 201);
    }

    public function cancel(Request $request, $id)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->requests->cancel($scope, $id, $this->locale($request))) : $this->denied();
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

    public function document(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $res = $this->requests->document($scope, $id, $this->locale($request));
        if (!$res['success']) {
            return $this->respond($res);
        }
        return response()->download($res['data']['path'], $res['data']['filename'], ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }
}
