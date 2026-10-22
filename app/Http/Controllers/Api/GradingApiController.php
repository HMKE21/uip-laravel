<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\GradingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * كشف الدرجات والـ GPA
 *
 *  نظام التقديرات (الأدمن = عام، الجامعة = جامعتها وكلياتها، الكلية = كليتها):
 *    GET    /grading/scale[?faculty_id=]        — النظام الحالي + الموروث + الافتراضي
 *    PUT    /grading/scale[?faculty_id=]        — { name, bands:[{letter,min_percent,points,is_fail}], deprivation_fails, deprivation_letter, require_approval, gpa_decimals }
 *    DELETE /grading/scale[?faculty_id=]        — امسح نظامي ← يورث من الأعلى
 *  مديرو المادة (مدرّس / معيد / مشرف / كلية / جامعة):
 *    GET    /grading/courses/{id}                              — إعداد التقييم (المكوّنات + النظام)
 *    PUT    /grading/courses/{id}/components                   — { components:[{id?,kind,ref_id?,title?,weight,max_score?}] }
 *    GET    /grading/courses/{id}/components/{cid}/scores      — الدرجات اليدوية
 *    PUT    /grading/courses/{id}/components/{cid}/scores      — { scores:[{student_id,score}] }
 *    GET    /grading/courses/{id}/sheet                        — كشف المادة (النتايج + ملخص)
 *    POST   /grading/courses/{id}/calculate                    — حساب (مسودة)
 *    PUT    /grading/courses/{id}/students/{sid}               — { letter, reason } أو { clear:true }
 *    POST   /grading/courses/{id}/publish | /unpublish
 *    GET    /grading/courses/{id}/export                       — CSV
 *  الطالب:
 *    GET    /grading/transcript          — كشف الدرجات + GPA لكل فصل وتراكمي
 *    GET    /grading/transcript/pdf
 */
class GradingApiController extends Controller
{
    public function __construct(
        private GradingService $grading,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $staff,
        private StudentRepository $students
    ) {
    }

    /** نفس CourseApiController::scope() بالظبط (+ admin). */
    private function scope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');
        $base = ['role' => $role, 'userId' => $userId, 'facultyId' => null, 'staffId' => null, 'studentId' => null, 'universityId' => null];

        switch ($role) {
            case 'admin':
                return $base;
            case 'university':
                $user = User::find($userId);
                $u = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
                return array_merge($base, ['universityId' => $u->id]);
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

    private function courseScope(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope || in_array($scope['role'], ['student', 'admin'], true)) {
            return [null, $this->apiError('You cannot manage course grades.', null, 403)];
        }
        return [$scope, null];
    }

    // ------------------------------------------------------------------ scale

    /** @return array{0:?array,1:mixed} [[type,id,universityId], errorResponse] */
    private function scaleTarget(Request $request): array
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return [null, $this->apiError('Account not linked.', null, 403)];
        }
        switch ($scope['role']) {
            case 'admin':
                return [['global', 0, null], null];
            case 'university':
                $fid = (int) $request->input('faculty_id', 0);
                if ($fid > 0) {
                    $ok = DB::table('faculties')->where('id', $fid)->where('university_id', $scope['universityId'])->exists();
                    return $ok ? [['faculty', $fid, (int) $scope['universityId']], null] : [null, $this->apiError('Faculty not found.', null, 404)];
                }
                return [['university', (int) $scope['universityId'], (int) $scope['universityId']], null];
            case 'faculty':
                return [['faculty', (int) $scope['facultyId'], (int) $scope['universityId']], null];
        }
        return [null, $this->apiError('Only admin, university or faculty accounts can edit grading scales.', null, 403)];
    }

    public function scaleShow(Request $request)
    {
        [$t, $err] = $this->scaleTarget($request);
        if ($err) {
            return $err;
        }
        $data = $this->grading->scaleEditor($t[0], $t[1], $t[2]);
        if ($t[0] === 'university') {
            $data['faculties'] = DB::table('faculties')->where('university_id', $t[1])->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])->map(fn ($f) => ['id' => (int) $f->id, 'name_ar' => $f->name_ar, 'name_en' => $f->name_en, 'has_own' => DB::table('grade_scales')->where('scope_type', 'faculty')->where('scope_id', $f->id)->exists()])->all();
        }
        return $this->apiSuccess($data, 'Grading scale retrieved successfully.');
    }

    public function scaleSave(Request $request)
    {
        [$t, $err] = $this->scaleTarget($request);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'name'                 => 'nullable|string|max:120',
            'bands'                => 'required|array|min:2|max:30',
            'bands.*.letter'       => 'required|string|max:8',
            'bands.*.min_percent'  => 'required|numeric|min:0|max:100',
            'bands.*.points'       => 'required|numeric|min:0|max:10',
            'bands.*.is_fail'      => 'nullable|boolean',
            'deprivation_fails'    => 'nullable|boolean',
            'deprivation_letter'   => 'nullable|string|max:8',
            'require_approval'     => 'nullable|boolean',
            'gpa_decimals'         => 'nullable|integer|min:0|max:4',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        $res = $this->grading->saveScale($t[0], $t[1], $request->all(), $request->attributes->get('uip_user_id'), $this->locale($request));
        if (!$res['success']) {
            return $this->respond($res);
        }
        return $this->apiSuccess($this->grading->scaleEditor($t[0], $t[1], $t[2]), $res['message']);
    }

    public function scaleReset(Request $request)
    {
        [$t, $err] = $this->scaleTarget($request);
        if ($err) {
            return $err;
        }
        $res = $this->grading->resetScale($t[0], $t[1], $request->attributes->get('uip_user_id'), $this->locale($request));
        return $this->apiSuccess($this->grading->scaleEditor($t[0], $t[1], $t[2]), $res['message']);
    }

    // ------------------------------------------------------------------ course grading

    public function setup(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        return $err ?? $this->respond($this->grading->setup($scope, $id, $this->locale($request)));
    }

    public function saveComponents(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'components'              => 'present|array|max:40',
            'components.*.id'         => 'nullable|integer',
            'components.*.kind'       => 'required|in:assignment,attendance,manual',
            'components.*.ref_id'     => 'nullable|integer',
            'components.*.title'      => 'nullable|string|max:200',
            'components.*.weight'     => 'required|numeric|gt:0|max:100',
            'components.*.max_score'  => 'nullable|numeric|gt:0',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->grading->saveComponents($scope, $id, $request->input('components', []), $this->locale($request)));
    }

    public function manualScores(Request $request, $id, $cid)
    {
        [$scope, $err] = $this->courseScope($request);
        return $err ?? $this->respond($this->grading->manualScores($scope, $id, $cid, $this->locale($request)));
    }

    public function saveManualScores(Request $request, $id, $cid)
    {
        [$scope, $err] = $this->courseScope($request);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), [
            'scores'              => 'present|array|max:2000',
            'scores.*.student_id' => 'required|integer',
            'scores.*.score'      => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->grading->saveManualScores($scope, $id, $cid, $request->input('scores', []), $this->locale($request)));
    }

    public function sheet(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        return $err ?? $this->respond($this->grading->sheet($scope, $id, $this->locale($request)));
    }

    public function calculate(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        return $err ?? $this->respond($this->grading->calculate($scope, $id, $this->locale($request)));
    }

    public function override(Request $request, $id, $sid)
    {
        [$scope, $err] = $this->courseScope($request);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), ['letter' => 'nullable|string|max:8', 'reason' => 'nullable|string|max:500', 'clear' => 'nullable|boolean']);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        return $this->respond($this->grading->override($scope, $id, $sid, $request->all(), $this->locale($request)));
    }

    public function publish(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        return $err ?? $this->respond($this->grading->publish($scope, $id, $this->locale($request)));
    }

    public function unpublish(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        return $err ?? $this->respond($this->grading->unpublish($scope, $id, $this->locale($request)));
    }

    public function export(Request $request, $id)
    {
        [$scope, $err] = $this->courseScope($request);
        if ($err) {
            return $err;
        }
        $res = $this->grading->sheetCsv($scope, $id, $this->locale($request));
        if (!($res['success'] ?? false)) {
            return $this->apiError($res['message'] ?? 'Error', null, $res['code'] ?? 422);
        }
        return response($res['data']['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $res['data']['filename'] . '"',
        ]);
    }

    // ------------------------------------------------------------------ student

    public function transcript(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Account not linked.', null, 403);
        }
        return $this->respond($this->grading->transcript($scope, $this->locale($request)));
    }

    public function transcriptPdf(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Account not linked.', null, 403);
        }
        $res = $this->grading->transcriptPdf($scope, $this->locale($request));
        if (!$res['success']) {
            return $this->respond($res);
        }
        return response()->download($res['data']['path'], $res['data']['filename'], ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }
}
