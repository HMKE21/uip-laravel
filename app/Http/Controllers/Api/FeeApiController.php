<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\FeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/fees — المصاريف: بنود الرسوم وإصدارها (جامعة/كلية)، سجل المستحقات، الدفعات، وإيصالات PDF، وصفحة الطالب.
 */
class FeeApiController extends Controller
{
    public function __construct(
        private FeeService $fees,
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

    private function check(Request $request, array $rules)
    {
        $v = Validator::make($request->all(), $rules);
        return $v->fails() ? $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422) : null;
    }

    private function with(Request $request, callable $fn)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($fn($scope, $this->locale($request))) : $this->denied();
    }

    public function mine(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->mine($s, $l));
    }

    public function notice(Request $request, $id)
    {
        if ($e = $this->check($request, ['amount' => 'required|numeric|min:0.01|max:10000000', 'reference' => 'required|string|max:120', 'method' => 'nullable|in:cash,bank_transfer,card,other', 'note' => 'nullable|string|max:2000'])) {
            return $e;
        }
        return $this->with($request, fn ($s, $l) => $this->fees->notice($s, $id, $request->only(['amount', 'reference', 'method', 'note']), $l));
    }

    public function cancelNotice(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->cancelNotice($s, $id, $l));
    }

    public function manage(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->manage($s, $l));
    }

    private function itemRules(): array
    {
        return ['name' => 'required|string|max:160', 'kind' => 'nullable|in:tuition,registration,service,other', 'amount' => 'required|numeric|min:0.01|max:10000000', 'due_on' => 'nullable|date'];
    }

    public function createItem(Request $request)
    {
        if ($e = $this->check($request, $this->itemRules())) {
            return $e;
        }
        return $this->with($request, fn ($s, $l) => $this->fees->saveItem($s, null, $request->all(), $l));
    }

    public function updateItem(Request $request, $id)
    {
        if ($e = $this->check($request, $this->itemRules())) {
            return $e;
        }
        return $this->with($request, fn ($s, $l) => $this->fees->saveItem($s, (int) $id, $request->all(), $l));
    }

    public function deleteItem(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->deleteItem($s, (int) $id, $l));
    }

    public function issue(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->issue($s, (int) $id, $l));
    }

    public function ledger(Request $request)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->ledger($s, $request->only(['status', 'item_id', 'q', 'overdue']), $l));
    }

    public function record(Request $request, $id)
    {
        if ($e = $this->check($request, ['amount' => 'required|numeric|min:0.01|max:10000000', 'method' => 'nullable|in:cash,bank_transfer,card,other', 'reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:2000'])) {
            return $e;
        }
        return $this->with($request, fn ($s, $l) => $this->fees->recordPayment($s, $id, $request->only(['amount', 'method', 'reference', 'note']), $l));
    }

    public function waive(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->waive($s, $id, $request->input('note'), $l));
    }

    public function confirm(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->confirm($s, $id, $l));
    }

    public function reject(Request $request, $id)
    {
        return $this->with($request, fn ($s, $l) => $this->fees->reject($s, $id, $request->input('note'), $l));
    }

    public function receipt(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $res = $this->fees->receipt($scope, $id, $this->locale($request));
        if (!$res['success']) {
            return $this->respond($res);
        }
        return response()->download($res['data']['path'], $res['data']['filename'], ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }
}
