<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * محتوى المادة (course_materials): محاضرات / فيديوهات / صور / ملفات / منشورات / نتائج / إعلانات امتحانات.
 *
 *  - الكتابة (إضافة / تعديل / حذف / مسودة / تثبيت): اللي بيدير المادة بس (مدرّس المادة، الكلية، الجامعة) — نفس CourseService::canManage.
 *  - القراءة: مديرو المادة يشوفوا كل حاجة (حتى المسودات)، والطالب المسجّل في المادة يشوف المنشور بس.
 *  - "حفظ": الطالب بيعمل bookmark للعنصر (course_material_saves).
 *
 * `$scope` هو نفس الـ array اللي CourseApiController::scope() بيبنيه.
 * كل الدوال بترجّع array: ['success' => bool, 'message' => ..., 'code' => ..., 'data' => ...].
 */
class CourseMaterialService
{
    public const KINDS = ['lecture', 'video', 'image', 'file', 'post', 'result', 'exam'];

    /** الامتدادات المسموحة لكل نوع (بتتقاطع بعد كده مع سياسة الأدمن في FileUploadService). */
    public const ALLOWED = [
        'lecture' => ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'zip'],
        'video'   => ['mp4', 'webm', 'mov'],
        'image'   => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'file'    => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'zip'],
        'result'  => ['pdf', 'xls', 'xlsx', 'csv', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
        'post'    => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'],
        'exam'    => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
    ];

    /** امتداد => [نوع المعاينة, Content-Type]. اللي مش هنا بيتنزّل بس (attachment). */
    private const PREVIEW = [
        'pdf'  => ['pdf', 'application/pdf'],
        'jpg'  => ['image', 'image/jpeg'],
        'jpeg' => ['image', 'image/jpeg'],
        'png'  => ['image', 'image/png'],
        'gif'  => ['image', 'image/gif'],
        'webp' => ['image', 'image/webp'],
        'mp4'  => ['video', 'video/mp4'],
        'webm' => ['video', 'video/webm'],
        'mov'  => ['video', 'video/quicktime'],
        'txt'  => ['text', 'text/plain; charset=UTF-8'],
    ];

    public function __construct(
        private CourseService $courses,
        private FileUploadService $uploader,
        private NotificationService $notifications
    ) {
    }

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    // ------------------------------------------------------------------ access

    /**
     * @return array{0: Course, 1: bool}|array  [course, canManage] أو array فيها success=false
     */
    private function access(array $scope, $courseId, string $l)
    {
        if ($scope['role'] === 'student') {
            $c = Course::where('university_id', $scope['universityId'])->find((int) $courseId);
            if (!$c || $c->status !== 'active') {
                return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
            }
            $enrolled = DB::table('course_enrollments')
                ->where('course_id', $c->id)->where('student_id', $scope['studentId'])->exists();
            if (!$enrolled) {
                return $this->fail($l, 'لازم تكون مسجّل في المادة عشان تشوف محتواها.', 'You must be enrolled in this course to see its content.', 403);
            }
            return [$c, false];
        }

        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        return [$c, true];
    }

    private function findMaterial(Course $course, $mid, bool $canManage)
    {
        $q = DB::table('course_materials')->where('course_id', $course->id)->where('id', (int) $mid);
        if (!$canManage) {
            $q->where('status', 'published');
        }
        return $q->first();
    }

    // ---------------------------------------------------------------- presenting

    private static function ext(?string $name): string
    {
        return strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
    }

    /** رابط embed لـ YouTube / Vimeo (أو null لو رابط تاني — بيتفتح في تبويب جديد). */
    public static function embedUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.|m\.)?youtube\.com/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]{11})~', $url, $m)
            || preg_match('~^https?://youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)
            || preg_match('~^https?://(?:www\.)?youtube\.com/(?:shorts|embed)/([A-Za-z0-9_-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        if (preg_match('~^https?://(?:www\.)?vimeo\.com/(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return null;
    }

    private function present(object $m, bool $canManage, array $savedIds, array $extra = []): array
    {
        $ext = self::ext($m->file_name);
        $preview = $m->file_path ? (self::PREVIEW[$ext][0] ?? 'none') : 'none';
        return array_merge([
            'id'                => (int) $m->id,
            'course_id'         => (int) $m->course_id,
            'kind'              => $m->kind,
            'title'             => $m->title,
            'body'              => $m->body,
            'external_url'      => $m->external_url,
            'embed_url'         => self::embedUrl($m->external_url),
            'has_file'          => !empty($m->file_path),
            'file_name'         => $m->file_name,
            'file_ext'          => $ext ?: null,
            'file_size'         => $m->file_size !== null ? (int) $m->file_size : null,
            'preview_type'      => $preview,                       // pdf | image | video | text | none
            'exam_at'           => $m->exam_at ? date('c', strtotime($m->exam_at)) : null,
            'exam_location'     => $m->exam_location,
            'exam_duration_min' => $m->exam_duration_min !== null ? (int) $m->exam_duration_min : null,
            'is_pinned'         => (bool) $m->is_pinned,
            'status'            => $m->status,
            'author_name'       => $m->author_name ?? null,
            'author_role'       => $m->author_role,
            'created_at'        => $m->created_at ? date('c', strtotime($m->created_at)) : null,
            'updated_at'        => $m->updated_at ? date('c', strtotime($m->updated_at)) : null,
            'saved'             => isset($savedIds[(int) $m->id]),
            'can_edit'          => $canManage,
        ], $extra);
    }

    private function savedMap(array $scope, array $ids): array
    {
        if ($scope['role'] !== 'student' || !$ids) {
            return [];
        }
        return DB::table('course_material_saves')
            ->where('student_id', $scope['studentId'])->whereIn('material_id', $ids)
            ->pluck('material_id')->mapWithKeys(fn ($v) => [(int) $v => true])->all();
    }

    // ------------------------------------------------------------------- listing

    public function list(array $scope, $courseId, ?string $kind, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;

        $q = DB::table('course_materials as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by_user_id')
            ->where('m.course_id', $course->id)
            ->select('m.*', 'u.full_name as author_name');
        if (!$canManage) {
            $q->where('m.status', 'published');
        }
        if ($kind && in_array($kind, self::KINDS, true)) {
            $q->where('m.kind', $kind);
        }
        $rows = $q->orderByDesc('m.is_pinned')->orderByDesc('m.created_at')->orderByDesc('m.id')->limit(500)->get();
        $saved = $this->savedMap($scope, $rows->pluck('id')->map(fn ($v) => (int) $v)->all());

        $counts = DB::table('course_materials')->where('course_id', $course->id)
            ->when(!$canManage, fn ($qq) => $qq->where('status', 'published'))
            ->select('kind', DB::raw('COUNT(*) as n'))->groupBy('kind')->pluck('n', 'kind')->all();

        return [
            'success' => true,
            'message' => 'OK',
            'data'    => [
                'items'  => $rows->map(fn ($m) => $this->present($m, $canManage, $saved))->all(),
                'counts' => array_map('intval', $counts),
            ],
        ];
    }

    /** كل العناصر اللي الطالب عمل لها "حفظ" (من كل موادّه المسجّل فيها حاليًا). */
    public function savedList(array $scope, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return ['success' => true, 'message' => 'OK', 'data' => ['items' => []]];
        }
        $rows = DB::table('course_material_saves as s')
            ->join('course_materials as m', 'm.id', '=', 's.material_id')
            ->join('courses as c', 'c.id', '=', 'm.course_id')
            ->join('course_enrollments as e', function ($j) use ($scope) {
                $j->on('e.course_id', '=', 'c.id')->where('e.student_id', '=', $scope['studentId']);
            })
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by_user_id')
            ->where('s.student_id', $scope['studentId'])
            ->where('m.status', 'published')
            ->where('c.status', 'active')
            ->select('m.*', 'u.full_name as author_name', 'c.code as course_code', 'c.name_en as c_en', 'c.name_ar as c_ar')
            ->orderByDesc('s.created_at')->limit(500)->get();

        $items = $rows->map(function ($m) use ($l) {
            return $this->present($m, false, [(int) $m->id => true], [
                'course_code' => $m->course_code,
                'course_name' => $this->ar($l) ? ($m->c_ar ?: $m->c_en) : $m->c_en,
            ]);
        })->all();

        return ['success' => true, 'message' => 'OK', 'data' => ['items' => $items]];
    }

    // ------------------------------------------------------------- create / update

    /** قواعد المحتوى حسب النوع (إيه الإجباري). بترجّع null لو تمام أو رسالة خطأ. */
    private function contentError(string $kind, bool $hasFile, bool $hasUrl, bool $hasBody, ?string $examAt, string $l): ?array
    {
        $need = fn (string $ar, string $en) => $this->fail($l, $ar, $en);
        switch ($kind) {
            case 'lecture':
            case 'file':
                return ($hasFile || $hasUrl) ? null : $need('ارفع ملف أو حط رابط.', 'Upload a file or add a link.');
            case 'video':
                return ($hasFile || $hasUrl) ? null : $need('ارفع فيديو أو حط رابط (YouTube / Drive ...).', 'Upload a video or add a link (YouTube / Drive ...).');
            case 'image':
                return $hasFile ? null : $need('ارفع صورة.', 'Upload an image.');
            case 'post':
                return ($hasBody || $hasFile) ? null : $need('اكتب نص المنشور أو أرفق ملف.', 'Write the post text or attach a file.');
            case 'result':
                return ($hasBody || $hasFile || $hasUrl) ? null : $need('ارفع ملف النتيجة أو اكتبها.', 'Upload the results file or type them.');
            case 'exam':
                return $examAt ? null : $need('حدّد موعد الامتحان.', 'Set the exam date and time.');
        }
        return $need('نوع غير مدعوم.', 'Unsupported type.');
    }

    private function storeFile($file, Course $course, string $kind, string $l)
    {
        $maxKb = $kind === 'video'
            ? (int) config('upload.course_materials_video_max_kb', 204800)
            : ($kind === 'image' ? null : (int) config('upload.course_materials_max_kb', 51200));
        try {
            return $this->uploader->store($file, 'course_materials', 'c' . $course->id, null, self::ALLOWED[$kind] ?? [], $maxKb);
        } catch (\RuntimeException $e) {
            return $this->fail($l, 'تعذّر رفع الملف: ' . $e->getMessage(), $e->getMessage());
        }
    }

    private function deleteStored(?string $path): void
    {
        if (!$path) {
            return;
        }
        $full = public_path(ltrim($path, '/'));
        $root = realpath(public_path((string) config('upload.paths.course_materials', 'uploads/course-materials')));
        $real = is_file($full) ? realpath($full) : false;
        if ($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }

    public function create(array $scope, $courseId, array $d, $file, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تضيف محتوى للمادة دي.', 'You cannot add content to this course.', 403);
        }

        $kind = (string) $d['kind'];
        $url = trim((string) ($d['external_url'] ?? ''));
        $body = trim((string) ($d['body'] ?? ''));
        $examAt = !empty($d['exam_at']) ? date('Y-m-d H:i:s', strtotime($d['exam_at'])) : null;

        if ($err = $this->contentError($kind, (bool) $file, $url !== '', $body !== '', $examAt, $l)) {
            return $err;
        }

        $stored = null;
        if ($file) {
            $stored = $this->storeFile($file, $course, $kind, $l);
            if (isset($stored['success'])) {
                return $stored;
            }
        }

        $status = ($d['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $id = DB::table('course_materials')->insertGetId([
            'course_id'          => $course->id,
            'kind'               => $kind,
            'title'              => trim((string) $d['title']),
            'body'               => $body !== '' ? $body : null,
            'external_url'       => $url !== '' ? $url : null,
            'file_path'          => $stored['stored_path'] ?? null,
            'file_name'          => $stored['original_name'] ?? null,
            'file_mime'          => $stored['mime_type'] ?? null,
            'file_size'          => $stored['size_bytes'] ?? null,
            'exam_at'            => $kind === 'exam' ? $examAt : null,
            'exam_location'      => $kind === 'exam' ? (trim((string) ($d['exam_location'] ?? '')) ?: null) : null,
            'exam_duration_min'  => $kind === 'exam' && !empty($d['exam_duration_min']) ? (int) $d['exam_duration_min'] : null,
            'is_pinned'          => !empty($d['is_pinned']) ? 1 : 0,
            'status'             => $status,
            'created_by_user_id' => $scope['userId'],
            'author_role'        => $scope['role'],
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        if ($status === 'published') {
            $this->notifyStudents($course, $kind, trim((string) $d['title']));
        }

        return ['success' => true, 'message' => $this->ar($l) ? 'تمت الإضافة.' : 'Added.', 'data' => ['id' => $id]];
    }

    public function update(array $scope, $courseId, $mid, array $d, $file, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تعدّل المحتوى ده.', 'You cannot edit this content.', 403);
        }
        $m = $this->findMaterial($course, $mid, true);
        if (!$m) {
            return $this->fail($l, 'العنصر غير موجود.', 'Item not found.', 404);
        }

        $kind = $m->kind; // النوع ثابت بعد الإنشاء
        $title = array_key_exists('title', $d) ? trim((string) $d['title']) : $m->title;
        $body = array_key_exists('body', $d) ? trim((string) $d['body']) : (string) $m->body;
        $url = array_key_exists('external_url', $d) ? trim((string) $d['external_url']) : (string) $m->external_url;
        $examAt = array_key_exists('exam_at', $d)
            ? (!empty($d['exam_at']) ? date('Y-m-d H:i:s', strtotime($d['exam_at'])) : null)
            : $m->exam_at;
        $removeFile = !empty($d['remove_file']);

        $hasFile = $file ? true : (!$removeFile && !empty($m->file_path));
        if ($err = $this->contentError($kind, $hasFile, $url !== '', $body !== '', $examAt, $l)) {
            return $err;
        }

        $set = [
            'title'      => $title,
            'body'       => $body !== '' ? $body : null,
            'external_url' => $url !== '' ? $url : null,
            'updated_at' => now(),
        ];
        if ($kind === 'exam') {
            $set['exam_at'] = $examAt;
            if (array_key_exists('exam_location', $d)) {
                $set['exam_location'] = trim((string) $d['exam_location']) ?: null;
            }
            if (array_key_exists('exam_duration_min', $d)) {
                $set['exam_duration_min'] = !empty($d['exam_duration_min']) ? (int) $d['exam_duration_min'] : null;
            }
        }
        if (array_key_exists('is_pinned', $d)) {
            $set['is_pinned'] = !empty($d['is_pinned']) ? 1 : 0;
        }
        $newStatus = isset($d['status']) ? ($d['status'] === 'draft' ? 'draft' : 'published') : $m->status;
        $set['status'] = $newStatus;

        $oldPath = null;
        if ($file) {
            $stored = $this->storeFile($file, $course, $kind, $l);
            if (isset($stored['success'])) {
                return $stored;
            }
            $oldPath = $m->file_path;
            $set += [
                'file_path' => $stored['stored_path'], 'file_name' => $stored['original_name'],
                'file_mime' => $stored['mime_type'], 'file_size' => $stored['size_bytes'],
            ];
        } elseif ($removeFile && $m->file_path) {
            $oldPath = $m->file_path;
            $set += ['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null];
        }

        DB::table('course_materials')->where('id', $m->id)->update($set);
        $this->deleteStored($oldPath);

        if ($m->status === 'draft' && $newStatus === 'published') {
            $this->notifyStudents($course, $kind, $title);
        }

        return ['success' => true, 'message' => $this->ar($l) ? 'تم الحفظ.' : 'Saved.', 'data' => ['id' => (int) $m->id]];
    }

    public function delete(array $scope, $courseId, $mid, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تحذف المحتوى ده.', 'You cannot delete this content.', 403);
        }
        $m = $this->findMaterial($course, $mid, true);
        if (!$m) {
            return $this->fail($l, 'العنصر غير موجود.', 'Item not found.', 404);
        }
        DB::transaction(function () use ($m) {
            DB::table('course_material_saves')->where('material_id', $m->id)->delete();
            DB::table('course_materials')->where('id', $m->id)->delete();
        });
        $this->deleteStored($m->file_path);

        return ['success' => true, 'message' => $this->ar($l) ? 'تم الحذف.' : 'Deleted.', 'data' => null];
    }

    // --------------------------------------------------------------------- file

    /**
     * @return array{success:bool, path?:string, name?:string, mime?:string, inline?:bool, message?:string, code?:int}
     */
    public function fileFor(array $scope, $courseId, $mid, bool $wantInline, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        $m = $this->findMaterial($course, $mid, $canManage);
        if (!$m || !$m->file_path) {
            return $this->fail($l, 'الملف غير موجود.', 'File not found.', 404);
        }

        $full = public_path(ltrim($m->file_path, '/'));
        $root = realpath(public_path((string) config('upload.paths.course_materials', 'uploads/course-materials')));
        $real = is_file($full) ? realpath($full) : false;
        if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return $this->fail($l, 'الملف غير موجود.', 'File not found.', 404);
        }

        $ext = self::ext($m->file_name);
        $previewable = isset(self::PREVIEW[$ext]);
        return [
            'success' => true,
            'path'    => $real,
            'name'    => $m->file_name ?: ('file.' . $ext),
            'mime'    => self::PREVIEW[$ext][1] ?? 'application/octet-stream',
            'inline'  => $wantInline && $previewable,
        ];
    }

    // -------------------------------------------------------------------- saves

    public function setSaved(array $scope, $courseId, $mid, bool $saved, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'الحفظ متاح للطلاب بس.', 'Saving is for students only.', 403);
        }
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course] = $acc;
        $m = $this->findMaterial($course, $mid, false);
        if (!$m) {
            return $this->fail($l, 'العنصر غير موجود.', 'Item not found.', 404);
        }

        if ($saved) {
            $exists = DB::table('course_material_saves')->where('material_id', $m->id)->where('student_id', $scope['studentId'])->exists();
            if (!$exists) {
                DB::table('course_material_saves')->insert([
                    'material_id' => $m->id, 'student_id' => $scope['studentId'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } else {
            DB::table('course_material_saves')->where('material_id', $m->id)->where('student_id', $scope['studentId'])->delete();
        }

        return [
            'success' => true,
            'message' => $saved ? ($this->ar($l) ? 'اتحفظ في قائمتك.' : 'Saved to your list.') : ($this->ar($l) ? 'اتشال من المحفوظات.' : 'Removed from saved.'),
            'data'    => ['saved' => $saved],
        ];
    }

    // ------------------------------------------------------------ notifications

    private function notifyStudents(Course $course, string $kind, string $title): void
    {
        try {
            $label = [
                'lecture' => 'محاضرة جديدة', 'video' => 'فيديو جديد', 'image' => 'صورة جديدة', 'file' => 'ملف جديد',
                'post' => 'منشور جديد', 'result' => 'نتيجة جديدة', 'exam' => 'إعلان امتحان',
            ][$kind] ?? 'محتوى جديد';
            $name = $course->code . ' — ' . ($course->name_ar ?: $course->name_en);
            $userIds = DB::table('course_enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.course_id', $course->id)->whereNotNull('s.user_id')
                ->pluck('s.user_id');
            foreach ($userIds as $uid) {
                $this->notifications->notify((int) $uid, 'course_material', "{$label}: {$title}", $name, '/student/courses?course=' . $course->id . '&tab=content', 'normal');
            }
        } catch (\Throwable $e) {
            // الإشعارات ماينفعش تكسر الرفع نفسه
        }
    }
}
