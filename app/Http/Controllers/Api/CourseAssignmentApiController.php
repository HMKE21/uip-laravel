<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\CourseAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * التكليفات والتسليمات
 *
 *  الطالب:
 *    GET    /assignments/my                                       كل تكليفاتي (عبر المواد) + حالتها
 *    POST   /assignments/courses/{id}/{aid}/submit                تسليم / إعادة تسليم (multipart: body?, file?, remove_file?)
 *    GET    /assignments/courses/{id}/{aid}/submissions/{sid}/file  ملف تسليمي (ملفي أنا بس)
 *  الكل (حسب الصلاحية):
 *    GET    /assignments/courses/{id}                             تكليفات المادة
 *    GET    /assignments/courses/{id}/{aid}/file                  مرفق التكليف
 *  مديرو المادة (مدرّس / كلية / جامعة):
 *    POST   /assignments/courses/{id}                             إنشاء (multipart)
 *    POST   /assignments/courses/{id}/{aid}                       تعديل (multipart) — PATCH للـ JSON
 *    DELETE /assignments/courses/{id}/{aid}
 *    GET    /assignments/courses/{id}/{aid}/submissions           تسليمات الطلاب
 *    PUT    /assignments/courses/{id}/{aid}/submissions/{sid}/grade   { score, feedback } أو { return: true, feedback }
 */
class CourseAssignmentApiController extends Controller
{
    private const FIELDS = ['title', 'body', 'due_at', 'max_score', 'weight', 'allow_late', 'status', 'remove_file'];

    public function __construct(
        private CourseAssignmentService $assignments,
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

    private function fileOut(array $res, Request $request)
    {
        if (!$res['success']) {
            return $this->apiError($res['message'] ?? 'Error', null, $res['code'] ?? 404);
        }
        $name = (string) $res['name'];
        $disposition = ($res['inline'] ? 'inline' : 'attachment')
            . '; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name);
        return response()->file($res['path'], [
            'Content-Type'           => $res['mime'],
            'Content-Disposition'    => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=0, must-revalidate',
        ]);
    }

    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        return [
            'title'      => $req . '|string|max:200',
            'body'       => 'nullable|string|max:20000',
            'due_at'     => 'nullable|date',
            'max_score'  => 'nullable|numeric|min:0.01|max:9999',
            'weight'     => 'nullable|numeric|min:0|max:100',
            'allow_late' => 'nullable',
            'status'     => 'nullable|in:draft,published',
            'file'       => 'nullable|file',
        ];
    }

    public function mine(Request $request)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->assignments->mine($s, $this->locale($request))) : $this->denied();
    }

    public function index(Request $request, $id)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->assignments->list($s, $id, $this->locale($request))) : $this->denied();
    }

    public function store(Request $request, $id)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->assignments->create($s, $id, $request->only(self::FIELDS), $request->file('file'), $this->locale($request)), 201);
    }

    public function update(Request $request, $id, $aid)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->assignments->update($s, $id, $aid, $request->only(self::FIELDS), $request->file('file'), $this->locale($request)));
    }

    public function destroy(Request $request, $id, $aid)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->assignments->delete($s, $id, $aid, $this->locale($request))) : $this->denied();
    }

    public function attachment(Request $request, $id, $aid)
    {
        $s = $this->scope($request);
        return $s ? $this->fileOut($this->assignments->attachment($s, $id, $aid, !$request->boolean('download'), $this->locale($request)), $request) : $this->denied();
    }

    public function submit(Request $request, $id, $aid)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), ['body' => 'nullable|string|max:20000', 'file' => 'nullable|file', 'remove_file' => 'nullable']);
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->assignments->submit($s, $id, $aid, $request->only(['body', 'remove_file']), $request->file('file'), $this->locale($request)));
    }

    public function submissions(Request $request, $id, $aid)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->assignments->submissions($s, $id, $aid, $this->locale($request))) : $this->denied();
    }

    public function submissionFile(Request $request, $id, $aid, $sid)
    {
        $s = $this->scope($request);
        return $s ? $this->fileOut($this->assignments->submissionFile($s, $id, $aid, $sid, !$request->boolean('download'), $this->locale($request)), $request) : $this->denied();
    }

    public function grade(Request $request, $id, $aid, $sid)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), ['score' => 'nullable|numeric', 'feedback' => 'nullable|string|max:5000', 'return' => 'nullable|boolean']);
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }
        return $this->respond($this->assignments->grade($s, $id, $aid, $sid, $request->only(['score', 'feedback', 'return']), $this->locale($request)));
    }
}
