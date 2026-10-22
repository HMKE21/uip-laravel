<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\CourseMaterialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * محتوى المادة — /api/v1/courses/{id}/materials
 *
 *  GET    /courses/materials/saved                      — المحفوظات (الطالب)
 *  GET    /courses/{id}/materials?kind=                 — قائمة المحتوى + counts لكل نوع
 *  POST   /courses/{id}/materials                       — إضافة (multipart لو فيه ملف)  [مديرو المادة]
 *  POST   /courses/{id}/materials/{mid}                 — تعديل (multipart لو فيه ملف جديد)  [مديرو المادة]
 *  PATCH  /courses/{id}/materials/{mid}                 — تعديل (JSON)  [مديرو المادة]
 *  DELETE /courses/{id}/materials/{mid}                 — حذف  [مديرو المادة]
 *  GET    /courses/{id}/materials/{mid}/file            — معاينة (inline) ؛ ?download=1 للتنزيل
 *  PUT    /courses/{id}/materials/{mid}/save            — حفظ في قائمة الطالب
 *  DELETE /courses/{id}/materials/{mid}/save            — إزالة من المحفوظات
 *
 * الصلاحيات كلها في CourseMaterialService؛ الكنترولر بيحدّد مين المستخدم ويتحقق من المدخلات بس.
 */
class CourseMaterialsApiController extends Controller
{
    public function __construct(
        private CourseMaterialService $materials,
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
        $maxKb = (int) config('upload.course_materials_video_max_kb', 204800); // السقف الفعلي per-kind بيتطبّق في الـ Service
        $req = $partial ? 'sometimes|required' : 'required';
        return [
            'kind'              => ($partial ? 'nullable' : 'required') . '|in:' . implode(',', CourseMaterialService::KINDS),
            'title'             => $req . '|string|max:200',
            'body'              => 'nullable|string|max:20000',
            // http(s) بس — بيمنع javascript:/data: اللي ممكن تتحوّل لـ XSS لما الفرونت يعرضه كلينك.
            'external_url'      => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/\S+$/i'],
            'exam_at'           => 'nullable|date',
            'exam_location'     => 'nullable|string|max:200',
            'exam_duration_min' => 'nullable|integer|min:1|max:1440',
            'is_pinned'         => 'nullable|boolean',
            'status'            => 'nullable|in:published,draft',
            'remove_file'       => 'nullable|boolean',
            'file'              => 'nullable|file|max:' . $maxKb,
        ];
    }

    private const FIELDS = ['kind', 'title', 'body', 'external_url', 'exam_at', 'exam_location', 'exam_duration_min', 'is_pinned', 'status', 'remove_file'];

    public function index(Request $request, $id)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $kind = $request->input('kind');
        return $this->respond($this->materials->list($scope, $id, is_string($kind) ? $kind : null, $this->locale($request)));
    }

    public function saved(Request $request)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->materials->savedList($scope, $this->locale($request))) : $this->denied();
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
        $res = $this->materials->create($scope, $id, $request->only(self::FIELDS), $request->file('file'), $this->locale($request));
        return $this->respond($res, 201);
    }

    public function update(Request $request, $id, $mid)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->materials->update($scope, $id, $mid, $request->only(self::FIELDS), $request->file('file'), $this->locale($request)));
    }

    public function destroy(Request $request, $id, $mid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->materials->delete($scope, $id, $mid, $this->locale($request))) : $this->denied();
    }

    /** معاينة (inline) أو تنزيل. بيستخدم Bearer زي باقي الـ API، فالفرونت لازم يجيبه fetch → blob. */
    public function file(Request $request, $id, $mid)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->denied();
        }
        $res = $this->materials->fileFor($scope, $id, $mid, !$request->boolean('download'), $this->locale($request));
        if (!$res['success']) {
            return $this->apiError($res['message'] ?? 'Error', null, $res['code'] ?? 404);
        }

        $name = (string) $res['name'];
        $disposition = ($res['inline'] ? 'inline' : 'attachment')
            . '; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name);

        // BinaryFileResponse: streaming من الديسك + Range (الفيديو بيقدر يعمل seek).
        return response()->file($res['path'], [
            'Content-Type'           => $res['mime'],
            'Content-Disposition'    => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function save(Request $request, $id, $mid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->materials->setSaved($scope, $id, $mid, true, $this->locale($request))) : $this->denied();
    }

    public function unsave(Request $request, $id, $mid)
    {
        $scope = $this->scope($request);
        return $scope ? $this->respond($this->materials->setSaved($scope, $id, $mid, false, $this->locale($request))) : $this->denied();
    }
}
