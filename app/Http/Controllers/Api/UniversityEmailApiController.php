<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\UniversityEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/university-email — الإيميل الجامعي: إعدادات الدومين، توليد العناوين، تعديلها، تصدير ملف التحويل، وعنوان الطالب.
 */
class UniversityEmailApiController extends Controller
{
    public function __construct(
        private UniversityEmailService $emails,
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

    public function mine(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->emails->mine($s, $l));
    }

    public function overview(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->emails->overview($s, $request->only(['q', 'missing']), $l));
    }

    public function saveSettings(Request $request)
    {
        $v = Validator::make($request->all(), ['domain' => 'required|string|max:120', 'pattern' => 'nullable|in:number,name']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->with($request, fn ($s, $l) => $this->emails->saveSettings($s, $request->only(['domain', 'pattern']), $l));
    }

    public function generate(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->emails->generate($s, $l));
    }

    public function setLocal(Request $request, $id)
    {
        $v = Validator::make($request->all(), ['local' => 'nullable|string|max:64']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->with($request, fn ($s, $l) => $this->emails->setLocal($s, (int) $id, $request->input('local'), $l));
    }

    public function export(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $res = $this->emails->export($scope, $this->locale($request));
        if (!$res['success']) {
            return $this->respond($res);
        }
        return response()->download($res['data']['path'], $res['data']['filename'], ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend(true);
    }
}
