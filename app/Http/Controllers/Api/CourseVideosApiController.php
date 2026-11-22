<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\ChunkedUploadService;
use App\Services\CourseVideoService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;

/**
 * مكتبة فيديوهات الشرح — /api/v1/courses/{id}/videos
 *
 *  GET    /{id}/videos                              — القائمة (الطالب: المنشور + تقدّمه، المدرّس: الكل + إحصائيات)
 *  POST   /{id}/videos/uploads                      — بدء رفع بالأجزاء  body: name, size, mime   [مديرو المادة]
 *  GET    /{id}/videos/uploads/{uid}                — الأجزاء اللي وصلت (الـ client بيكمّل من الناقص بعد انقطاع النت)
 *  POST   /{id}/videos/uploads/{uid}/chunk          — multipart: index, chunk
 *  DELETE /{id}/videos/uploads/{uid}                — إلغاء الرفع
 *  POST   /{id}/videos                              — إنشاء الفيديو بعد اكتمال الرفع  body: upload_id, title, description?, password?, status?
 *  PATCH  /{id}/videos/{vid}                        — تعديل (title, description, status, sort_order, password | remove_password)
 *  DELETE /{id}/videos/{vid}                        — حذف
 *  POST   /{id}/videos/{vid}/play                   — body: password? → رابط مشاهدة موقّع + resume_position
 *  POST   /{id}/videos/{vid}/progress               — body: ranges [[s,e],..], position, duration?, started?  [الطالب]
 *  GET    /{id}/videos/{vid}/viewers                — مين شاف الفيديو وقد إيه  [مديرو المادة]
 *  GET    /{id}/videos/report                       — تقرير كل الطلاب × كل الفيديوهات  [مديرو المادة]
 *  GET    /courses/videos/stream/{id}?u=&s=&signature=  — بث الملف (Range) — بره uip.auth، التوقيع هو الصلاحية
 */
class CourseVideosApiController extends Controller
{
    public function __construct(
        private CourseVideoService $videos,
        private ChunkedUploadService $chunks,
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

    private function respond(array $res, ?int $okStatus = null)
    {
        if (!empty($res['success'])) {
            return $this->apiSuccess($res['data'] ?? null, (string) ($res['message'] ?? 'OK'), $okStatus ?? (int) ($res['code'] ?? 200));
        }
        return $this->apiError((string) ($res['message'] ?? 'Error'), isset($res['data']) && is_array($res['data']) ? $res['data'] : null, (int) ($res['code'] ?? 422));
    }

    private function withScope(Request $request, \Closure $fn)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError($this->locale($request) === 'ar' ? 'مش مسموحلك.' : 'Not allowed.', null, 403);
        }
        return $fn($scope, $this->locale($request));
    }

    // ------------------------------------------------------------------ CRUD

    public function index(Request $request, $id)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->list($s, $id, $l)));
    }

    public function store(Request $request, $id)
    {
        return $this->withScope($request, function ($s, $l) use ($request, $id) {
            return $this->respond($this->videos->create($s, $id, $request->only(['upload_id', 'title', 'description', 'password', 'status']), $l));
        });
    }

    public function update(Request $request, $id, $vid)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->update($s, $id, $vid, $request->only(['title', 'description', 'status', 'sort_order', 'password', 'remove_password']), $l)));
    }

    public function destroy(Request $request, $id, $vid)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->delete($s, $id, $vid, $l)));
    }

    // ------------------------------------------------------------- play / track

    public function play(Request $request, $id, $vid)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->play($s, $id, $vid, $request->input('password'), $l)));
    }

    public function progress(Request $request, $id, $vid)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->progress($s, $id, $vid, $request->only(['ranges', 'position', 'duration', 'started']), $l)));
    }

    public function viewers(Request $request, $id, $vid)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->viewers($s, $id, $vid, $l)));
    }

    public function report(Request $request, $id)
    {
        return $this->withScope($request, fn ($s, $l) => $this->respond($this->videos->report($s, $id, $l)));
    }

    // -------------------------------------------------------- resumable upload

    private function uploadGuard(Request $request, $id, \Closure $action)
    {
        return $this->withScope($request, function ($s, $l) use ($id, $action, $request) {
            $deny = $this->videos->assertCanManage($s, $id, $l);
            if ($deny) {
                return $this->respond($deny);
            }
            try {
                return $action((int) $s['userId']);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                return $this->apiError($e->getMessage(), null, 422);
            }
        });
    }

    public function uploadInit(Request $request, $id)
    {
        return $this->uploadGuard($request, $id, fn (int $uid) => $this->apiSuccess(
            $this->chunks->init($uid, (string) $request->input('name', ''), (int) $request->input('size', 0), $request->input('mime'), 'course_video'),
            'Upload started.', 201
        ));
    }

    public function uploadStatus(Request $request, $id, string $uid)
    {
        return $this->uploadGuard($request, $id, fn (int $u) => $this->apiSuccess($this->chunks->status($u, $uid), 'Upload status.'));
    }

    public function uploadChunk(Request $request, $id, string $uid)
    {
        // مسار ساخن (جزء كل ~2MB): الجارد بيعمل استعلام صلاحيات واحد خفيف.
        return $this->uploadGuard($request, $id, function (int $u) use ($request, $uid) {
            $file = $request->file('chunk');
            if (!$file || !$file->isValid()) {
                throw new \RuntimeException('The chunk did not arrive. Please retry.');
            }
            return $this->apiSuccess($this->chunks->putChunk($u, $uid, (int) $request->input('index', -1), $file->getRealPath()), 'Chunk stored.');
        });
    }

    public function uploadCancel(Request $request, $id, string $uid)
    {
        return $this->uploadGuard($request, $id, function (int $u) use ($uid) {
            $this->chunks->discard($u, $uid);
            return $this->apiSuccess(null, 'Upload cancelled.');
        });
    }

    // ------------------------------------------------------------------ stream

    /** GET /courses/videos/stream/{id}?u=&s=&expires=&signature= — بعد middleware signed:relative. Range بيتعامل معاه Symfony تلقائيًا. */
    public function stream(Request $request, $id)
    {
        $sid = (int) $request->query('s');
        $v = $this->videos->streamable((int) $id, max(0, $sid));
        if (!$v) {
            return $this->apiError('Video not found.', null, 404);
        }
        $path = FileUploadService::absolutePath((string) $v->file_path);
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'][$ext] ?? 'application/octet-stream';
        return response()->file($path, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => "inline; filename*=UTF-8''" . rawurlencode('video.' . $ext),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=0, must-revalidate',
        ]);
    }
}
