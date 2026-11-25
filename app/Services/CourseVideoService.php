<?php

namespace App\Services;

use App\Models\Course;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * مكتبة فيديوهات الشرح للمادة (course_videos) — مدرّس المادة يرفع، والطلاب المسجّلين يشاهدوا.
 *
 *  - الرفع: بالأجزاء (ChunkedUploadService purpose=course_video) فلو النت قطع بيكمّل من آخر جزء. بعد ما كل الأجزاء توصل: create() بيحوّلها لفيديو.
 *  - الباسورد: اختياري لكل فيديو (hash). الطالب بيبعته في play() فيرجعله رابط موقّع قصير العمر؛ مديرو المادة مابيحتاجوش باسورد.
 *  - المشاهدة: الملف خاص ومابيتخدمش غير من الرابط الموقّع (CourseVideosApiController::stream) مع دعم Range.
 *  - المتابعة: الـ client بيبعت الأجزاء اللي اتشافت فعلًا (ranges) → بندمجها، فالنسبة دقيقة حتى مع التقديم/الرجوع (التخطّي مابيتحسبش مشاهدة).
 *
 * `$scope` هو نفس array بتاع CourseApiController::scope(). كل الدوال بترجّع ['success','message','code','data'].
 */
class CourseVideoService
{
    public const MAX_RANGES_PER_CALL = 60;
    public const MAX_DURATION = 86400; // سقف أمان لمدة الفيديو (24 ساعة)

    public function __construct(
        private CourseService $courses,
        private FileUploadService $uploader,
        private ChunkedUploadService $chunks,
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

    private function ok($data = null, string $msg = 'OK', int $code = 200): array
    {
        return ['success' => true, 'message' => $msg, 'code' => $code, 'data' => $data];
    }

    // ------------------------------------------------------------------ access

    /** @return array{0: Course, 1: bool}|array  [course, canManage] أو array فيها success=false */
    private function access(array $scope, $courseId, string $l)
    {
        if ($scope['role'] === 'student') {
            $c = Course::where('university_id', $scope['universityId'])->find((int) $courseId);
            if (!$c || $c->status !== 'active') {
                return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
            }
            $enrolled = DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', $scope['studentId'])->exists();
            if (!$enrolled) {
                return $this->fail($l, 'لازم تكون مسجّل في المادة عشان تشوف الفيديوهات.', 'You must be enrolled in this course to watch its videos.', 403);
            }
            return [$c, false];
        }
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        return [$c, true];
    }

    /** للرفع بالأجزاء: يرجّع null لو المستخدم يقدر يدير المادة، وإلا array فيها الخطأ. */
    public function assertCanManage(array $scope, $courseId, string $l): ?array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        return $acc[1] ? null : $this->fail($l, 'مش مسموحلك ترفع فيديوهات للمادة دي.', 'You cannot upload videos to this course.', 403);
    }

    private function find(Course $course, $vid, bool $canManage)
    {
        $q = DB::table('course_videos')->where('course_id', $course->id)->where('id', (int) $vid);
        if (!$canManage) {
            $q->where('status', 'published');
        }
        return $q->first();
    }

    // ---------------------------------------------------------------- presenting

    private function present(object $v, bool $canManage, ?object $mine = null, ?object $agg = null, int $studentId = 0): array
    {
        $out = [
            'id'               => (int) $v->id,
            'course_id'        => (int) $v->course_id,
            'title'            => $v->title,
            'description'      => $v->description,
            'duration_seconds' => $v->duration_seconds !== null ? (int) $v->duration_seconds : null,
            'file_name'        => $v->file_name,
            'file_size'        => $v->file_size !== null ? (int) $v->file_size : null,
            'width'            => !empty($v->width) ? (int) $v->width : null,
            'height'           => !empty($v->height) ? (int) $v->height : null,
            'thumbnail_url'    => $this->thumbnailUrl($v, $canManage ? 0 : (int) $studentId),
            'has_password'     => !empty($v->password_hash),
            'sort_order'       => (int) $v->sort_order,
            'status'           => $v->status,
            'created_at'       => $v->created_at ? date('c', strtotime($v->created_at)) : null,
            'can_edit'         => $canManage,
        ];
        if ($mine !== null || !$canManage) {
            $out['my_progress'] = $mine ? $this->presentView($mine) : ['watched_seconds' => 0, 'percent' => 0.0, 'last_position' => 0, 'completed' => false, 'play_count' => 0, 'last_watched_at' => null];
        }
        if ($canManage) {
            $out['viewers_count'] = (int) ($agg->viewers ?? 0);
            $out['completed_count'] = (int) ($agg->completed ?? 0);
            $out['avg_percent'] = round((float) ($agg->avg_percent ?? 0), 1);
        }
        return $out;
    }

    private function presentView(object $r): array
    {
        return [
            'watched_seconds' => (int) $r->watched_seconds,
            'percent'         => (float) $r->percent,
            'last_position'   => (int) $r->last_position,
            'max_position'    => (int) $r->max_position,
            'completed'       => (bool) $r->completed,
            'play_count'      => (int) $r->play_count,
            'first_watched_at' => $r->first_watched_at ? date('c', strtotime($r->first_watched_at)) : null,
            'last_watched_at' => $r->last_watched_at ? date('c', strtotime($r->last_watched_at)) : null,
        ];
    }


    // ------------------------------------------------------------------- cover

    public const MAX_COVER_BYTES = 2097152; // 2MB بعد فك الـ base64

    /**
     * رابط غلاف موقّع نسبي. الانتهاء بيتقرّب لبداية الساعة فيفضل الرابط ثابت طول الساعة (كاش المتصفح بيشتغل)،
     * و v = وقت آخر تعديل فيتغيّر الرابط لو الغلاف اتبدّل.
     */
    private function thumbnailUrl(object $v, int $studentId): ?string
    {
        if (empty($v->thumbnail_path)) {
            return null;
        }
        $ttl = max(3600, (int) config('upload.course_video_url_ttl', 7200));
        $params = ['id' => (int) $v->id, 's' => $studentId, 'v' => (int) strtotime((string) ($v->updated_at ?? 'now'))] + TenantContext::signedParams();
        return URL::temporarySignedRoute('course.videos.thumb', now()->startOfHour()->addSeconds($ttl + 3600), $params, absolute: false);
    }

    /**
     * يحفظ غلاف جاي من المتصفح كـ data URL (jpeg/png/webp). بنتحقق إنه صورة فعلًا، وبنعيد ترميزه JPEG نضيف
     * (GD لو متاح) فمفيش محتوى مخفي ولا SVG. بيرجّع المسار النسبي أو null.
     */
    private function storeCover(Course $course, ?string $dataUrl): ?string
    {
        if (!is_string($dataUrl) || $dataUrl === '' || !preg_match('#^data:image/(jpeg|png|webp);base64,#i', $dataUrl, $m)) {
            return null;
        }
        $raw = base64_decode(substr($dataUrl, strlen($m[0])), true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_COVER_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
            return null;
        }

        $bytes = $raw;
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($raw);
            if ($img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $max = 960;
                if ($w > $max) {
                    $scaled = imagescale($img, $max, (int) max(1, round($h * $max / $w)));
                    if ($scaled) {
                        imagedestroy($img);
                        $img = $scaled;
                    }
                }
                ob_start();
                imagejpeg($img, null, 82);
                $out = (string) ob_get_clean();
                imagedestroy($img);
                if ($out !== '') {
                    $bytes = $out;
                    $ext = 'jpg';
                }
            }
        }

        $rel = 'uploads/course-videos/covers/c' . (int) $course->id . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $abs = FileUploadService::diskDir($rel);
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (@file_put_contents($abs, $bytes) === false) {
            return null;
        }
        \App\Support\UploadMirror::push($rel, $abs);
        return $rel;
    }

    private function dim($v): ?int
    {
        return (is_numeric($v) && $v >= 1 && $v <= 16000) ? (int) $v : null;
    }

    /** غلاف الفيديو (للمدرّس): data URL جديد + (اختياريًا) المدة والأبعاد اللي المتصفح قرأها. */
    public function setCover(array $scope, $courseId, $vid, array $d, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $v = $this->find($course, $vid, true);
        if (!$v) {
            return $this->fail($l, 'الفيديو غير موجود.', 'Video not found.', 404);
        }

        $upd = [];
        if (!empty($d['thumbnail'])) {
            $rel = $this->storeCover($course, (string) $d['thumbnail']);
            if ($rel === null) {
                return $this->fail($l, 'صورة الغلاف غير صالحة (JPEG/PNG/WebP حتى 2MB).', 'Invalid cover image (JPEG/PNG/WebP up to 2MB).');
            }
            $upd['thumbnail_path'] = $rel;
        }
        if ($v->duration_seconds === null && isset($d['duration_seconds']) && is_numeric($d['duration_seconds']) && $d['duration_seconds'] >= 1) {
            $upd['duration_seconds'] = min((int) ceil((float) $d['duration_seconds']), self::MAX_DURATION);
        }
        if (empty($v->width) && $this->dim($d['width'] ?? null) && $this->dim($d['height'] ?? null)) {
            $upd['width'] = $this->dim($d['width']);
            $upd['height'] = $this->dim($d['height']);
        }
        if ($upd) {
            $upd['updated_at'] = now();
            DB::table('course_videos')->where('id', $v->id)->update($upd);
            if (isset($upd['thumbnail_path']) && !empty($v->thumbnail_path)) {
                try {
                    $this->uploader->delete((string) $v->thumbnail_path);
                } catch (\Throwable $e) {
                }
            }
        }
        return $this->ok($this->present(DB::table('course_videos')->find($v->id), true), $this->ar($l) ? 'تم تحديث الغلاف.' : 'Cover updated.');
    }

    /** ترتيب الفيديوهات: ids بالترتيب الجديد (اللي مش مذكور بيفضل في آخر القائمة بنفس ترتيبه). */
    public function reorder(array $scope, $courseId, array $ids, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $all = DB::table('course_videos')->where('course_id', $course->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $want = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($x) => in_array($x, $all, true))));
        $order = array_merge($want, array_values(array_diff($all, $want)));
        DB::transaction(function () use ($order) {
            foreach ($order as $i => $id) {
                DB::table('course_videos')->where('id', $id)->update(['sort_order' => $i + 1]);
            }
        });
        return $this->ok(['order' => $order], $this->ar($l) ? 'تم حفظ الترتيب.' : 'Order saved.');
    }

    /** للمسار الموقّع للغلاف: نفس شروط البث (طالب مسجّل + فيديو منشور). */
    public function coverFile(int $videoId, int $studentId): ?string
    {
        $v = $this->streamable($videoId, $studentId);
        return ($v && !empty($v->thumbnail_path)) ? (string) $v->thumbnail_path : null;
    }

    // -------------------------------------------------------------------- list

    public function list(array $scope, $courseId, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;

        $q = DB::table('course_videos')->where('course_id', $course->id);
        if (!$canManage) {
            $q->where('status', 'published');
        }
        $videos = $q->orderBy('sort_order')->orderBy('id')->get();
        $ids = $videos->pluck('id')->all();

        $mine = collect();
        $agg = collect();
        if ($ids) {
            if (!$canManage) {
                $mine = DB::table('course_video_views')->where('student_id', $scope['studentId'])->whereIn('video_id', $ids)->get()->keyBy('video_id');
            } else {
                $agg = DB::table('course_video_views')->whereIn('video_id', $ids)->groupBy('video_id')
                    ->selectRaw('video_id, COUNT(*) as viewers, SUM(completed) as completed, AVG(percent) as avg_percent')->get()->keyBy('video_id');
            }
        }

        $items = [];
        foreach ($videos as $v) {
            $items[] = $this->present($v, $canManage, $mine->get($v->id), $agg->get($v->id), (int) ($scope['studentId'] ?? 0));
        }
        return $this->ok(['videos' => $items, 'can_manage' => $canManage]);
    }

    // ------------------------------------------------------------------ create

    public function create(array $scope, $courseId, array $d, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك ترفع فيديوهات للمادة دي.', 'You cannot upload videos to this course.', 403);
        }

        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            return $this->fail($l, 'عنوان الفيديو مطلوب (حتى 200 حرف).', 'A video title is required (max 200 characters).');
        }
        $uploadId = (string) ($d['upload_id'] ?? '');
        $password = isset($d['password']) ? (string) $d['password'] : '';
        if ($password !== '' && (mb_strlen($password) < 4 || mb_strlen($password) > 100)) {
            return $this->fail($l, 'الباسورد لازم يكون من 4 إلى 100 حرف.', 'The password must be 4 to 100 characters.');
        }
        $status = ($d['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

        $userId = (int) $scope['userId'];
        try {
            $file = $this->chunks->open($userId, $uploadId, 'course_video');
        } catch (\Throwable $e) {
            return $this->fail($l, 'الرفع لسه ماخلصش أو انتهت صلاحيته: ' . $e->getMessage(), $e->getMessage());
        }

        try {
            $maxMb = (int) config('upload.course_video_max_mb', 0);
            $stored = $this->uploader->store($file, 'course_videos', 'c' . $course->id, null, ['mp4', 'webm', 'mov'], $maxMb > 0 ? $maxMb * 1024 : 0);
        } catch (\RuntimeException $e) {
            return $this->fail($l, 'تعذّر حفظ الفيديو: ' . $e->getMessage(), $e->getMessage());
        }
        try {
            $this->chunks->discard($userId, $uploadId);
        } catch (\Throwable $e) {
            // مجلد الأجزاء المؤقت بيتنضف لوحده (prune)
        }

        // المدة: ffprobe لو موجود، وإلا القيمة اللي قراها المتصفح وقت الرفع، وإلا بتتسجّل بعدين من أول مشغّل.
        $duration = null;
        $cover = null;
        try {
            $probe = app(MediaProbeService::class);
            if ($probe->available()) {
                $duration = $probe->duration(FileUploadService::absolutePath($stored['stored_path']));
            }
        } catch (\Throwable $e) {
        }
        if (!$duration && isset($d['duration_seconds']) && is_numeric($d['duration_seconds']) && $d['duration_seconds'] >= 1) {
            $duration = (int) ceil((float) $d['duration_seconds']);
        }
        // الغلاف: اللي اختاره المدرّس/المتصفح أولًا، وإلا فريم من ffmpeg لو متاح.
        $cover = $this->storeCover($course, $d['thumbnail'] ?? null);
        if ($cover === null) {
            try {
                $probe ??= app(MediaProbeService::class);
                $cover = $probe->videoThumbnail(FileUploadService::absolutePath($stored['stored_path']), 'uploads/course-videos/covers/c' . (int) $course->id);
            } catch (\Throwable $e) {
            }
        }
        $w = $this->dim($d['width'] ?? null);
        $h = $this->dim($d['height'] ?? null);

        $now = now();
        $id = DB::table('course_videos')->insertGetId([
            'course_id' => $course->id, 'title' => $title, 'description' => trim((string) ($d['description'] ?? '')) ?: null,
            'file_path' => $stored['stored_path'], 'file_name' => $stored['original_name'], 'file_mime' => $stored['mime_type'],
            'file_size' => $stored['size_bytes'], 'thumbnail_path' => $cover, 'width' => ($w && $h) ? $w : null, 'height' => ($w && $h) ? $h : null,
            'duration_seconds' => ($duration && $duration > 0) ? min((int) $duration, self::MAX_DURATION) : null,
            'password_hash' => $password !== '' ? Hash::make($password) : null,
            'sort_order' => (int) (DB::table('course_videos')->where('course_id', $course->id)->max('sort_order') ?? 0) + 1,
            'status' => $status, 'created_by_user_id' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($status === 'published') {
            $this->notifyStudents($course, $title);
        }
        return $this->ok($this->present(DB::table('course_videos')->find($id), true), $this->ar($l) ? 'تم رفع الفيديو.' : 'Video uploaded.', 201);
    }

    // ------------------------------------------------------------------ update

    public function update(array $scope, $courseId, $vid, array $d, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $v = $this->find($course, $vid, true);
        if (!$v) {
            return $this->fail($l, 'الفيديو غير موجود.', 'Video not found.', 404);
        }

        $upd = [];
        if (array_key_exists('title', $d)) {
            $t = trim((string) $d['title']);
            if ($t === '' || mb_strlen($t) > 200) {
                return $this->fail($l, 'عنوان الفيديو مطلوب (حتى 200 حرف).', 'A video title is required (max 200 characters).');
            }
            $upd['title'] = $t;
        }
        if (array_key_exists('description', $d)) {
            $upd['description'] = trim((string) $d['description']) ?: null;
        }
        if (isset($d['status']) && in_array($d['status'], ['draft', 'published'], true)) {
            $upd['status'] = $d['status'];
        }
        if (isset($d['sort_order'])) {
            $upd['sort_order'] = max(0, (int) $d['sort_order']);
        }
        if (!empty($d['remove_password'])) {
            $upd['password_hash'] = null;
        } elseif (isset($d['password']) && (string) $d['password'] !== '') {
            $pw = (string) $d['password'];
            if (mb_strlen($pw) < 4 || mb_strlen($pw) > 100) {
                return $this->fail($l, 'الباسورد لازم يكون من 4 إلى 100 حرف.', 'The password must be 4 to 100 characters.');
            }
            $upd['password_hash'] = Hash::make($pw);
        }
        if ($upd) {
            $upd['updated_at'] = now();
            DB::table('course_videos')->where('id', $v->id)->update($upd);
        }
        $fresh = DB::table('course_videos')->find($v->id);
        if (($upd['status'] ?? null) === 'published' && $v->status === 'draft') {
            $this->notifyStudents($course, (string) $fresh->title);
        }
        return $this->ok($this->present($fresh, true), $this->ar($l) ? 'تم التحديث.' : 'Updated.');
    }

    public function delete(array $scope, $courseId, $vid, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $v = $this->find($course, $vid, true);
        if (!$v) {
            return $this->fail($l, 'الفيديو غير موجود.', 'Video not found.', 404);
        }
        DB::table('course_video_views')->where('video_id', $v->id)->delete();
        try { DB::table('course_video_unlocks')->where('video_id', $v->id)->delete(); } catch (\Throwable) { /* الجدول لسه مش موجود */ }
        DB::table('course_videos')->where('id', $v->id)->delete();
        foreach ([$v->file_path ?? null, $v->thumbnail_path ?? null] as $f) {
            if (!$f) {
                continue;
            }
            try {
                $this->uploader->delete((string) $f);
            } catch (\Throwable $e) {
            }
        }
        return $this->ok(null, $this->ar($l) ? 'تم حذف الفيديو.' : 'Video deleted.');
    }

    // -------------------------------------------------------------------- play

    /** هل الطالب فتح الفيديو ده بالباسورد الحالي قبل كده؟ (لو الباسورد اتغيّر يرجع false) */
    private function isUnlocked(object $v, int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }
        try {
            $sig = DB::table('course_video_unlocks')->where('video_id', $v->id)->where('student_id', $studentId)->value('pw_sig');
        } catch (\Throwable) {
            return false;   // الـ migration لسه ماتشغّلتش: نفس السلوك القديم
        }
        return $sig !== null && hash_equals((string) $sig, sha1((string) $v->password_hash));
    }

    private function rememberUnlock(object $v, int $studentId): void
    {
        if ($studentId <= 0) {
            return;
        }
        try {
            $now = now();
            DB::table('course_video_unlocks')->updateOrInsert(
                ['video_id' => $v->id, 'student_id' => $studentId],
                ['pw_sig' => sha1((string) $v->password_hash), 'updated_at' => $now, 'created_at' => $now]
            );
        } catch (\Throwable) {
            // مش مشكلة: هيتسأل عن الباسورد المرة الجاية
        }
    }

    /**
     * يتحقق من الصلاحية + الباسورد، ويرجّع رابط مشاهدة موقّع (يدعم التقديم/الاستكمال) + آخر موضع وصله الطالب.
     */
    public function play(array $scope, $courseId, $vid, ?string $password, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        $v = $this->find($course, $vid, $canManage);
        if (!$v) {
            return $this->fail($l, 'الفيديو غير موجود.', 'Video not found.', 404);
        }
        if (!$canManage && !empty($v->password_hash) && !$this->isUnlocked($v, (int) $scope['studentId'])) {
            if ($password === null || $password === '') {
                return ['success' => false, 'message' => $this->ar($l) ? 'الفيديو ده محمي بباسورد.' : 'This video is password-protected.', 'code' => 423, 'data' => ['password_required' => true]];
            }
            if (!Hash::check($password, $v->password_hash)) {
                return ['success' => false, 'message' => $this->ar($l) ? 'الباسورد غلط.' : 'Wrong password.', 'code' => 403, 'data' => ['password_required' => true]];
            }
            $this->rememberUnlock($v, (int) $scope['studentId']);   // باسورد صح مرة واحدة كفاية
        }

        $ttl = (int) config('upload.course_video_url_ttl', 7200);
        $params = ['id' => (int) $v->id, 'u' => (int) $scope['userId'], 's' => $canManage ? 0 : (int) $scope['studentId']] + TenantContext::signedParams();
        $url = URL::temporarySignedRoute('course.videos.stream', now()->addSeconds($ttl), $params, absolute: false);

        $view = $canManage ? null : DB::table('course_video_views')->where('video_id', $v->id)->where('student_id', $scope['studentId'])->first();
        return $this->ok([
            'url'              => $url,
            'expires_in'       => $ttl,
            'mime'             => $v->file_mime ?: 'video/mp4',
            'duration_seconds' => $v->duration_seconds !== null ? (int) $v->duration_seconds : null,
            'resume_position'  => $view ? (int) $view->last_position : 0,
            'tracking'         => !$canManage,   // مديرو المادة مشاهدتهم مابتتسجّلش
        ]);
    }

    /** للمسار الموقّع: ملف الفيديو لو المستخدم لسه مسموحله (تسجيل الطالب + نشر الفيديو). */
    public function streamable(int $videoId, int $studentId): ?object
    {
        $v = DB::table('course_videos')->where('id', $videoId)->first();
        if (!$v) {
            return null;
        }
        if ($studentId > 0) { // طالب: لازم الفيديو منشور والمادة نشطة وهو لسه مسجّل
            if ($v->status !== 'published') {
                return null;
            }
            $active = DB::table('courses')->where('id', $v->course_id)->where('status', 'active')->exists();
            $enrolled = DB::table('course_enrollments')->where('course_id', $v->course_id)->where('student_id', $studentId)->exists();
            if (!$active || !$enrolled) {
                return null;
            }
        }
        return $v;
    }

    // ---------------------------------------------------------------- progress

    /**
     * @param array{ranges?: array, position?: numeric, duration?: numeric, started?: bool} $d
     * ranges = [[start,end],...] بالثواني: الأجزاء اللي اتشافت فعلًا من آخر إرسال.
     */
    public function progress(array $scope, $courseId, $vid, array $d, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if ($canManage) {
            return $this->ok(['tracked' => false]); // مشاهدة المدرّس مابتتسجّلش
        }
        $v = $this->find($course, $vid, false);
        if (!$v) {
            return $this->fail($l, 'الفيديو غير موجود.', 'Video not found.', 404);
        }

        $duration = $v->duration_seconds !== null ? (int) $v->duration_seconds : null;
        if ($duration === null && isset($d['duration']) && is_numeric($d['duration']) && $d['duration'] >= 1) {
            $duration = min((int) ceil((float) $d['duration']), self::MAX_DURATION);
            DB::table('course_videos')->where('id', $v->id)->whereNull('duration_seconds')->update(['duration_seconds' => $duration]);
        }
        $cap = $duration ?? self::MAX_DURATION;

        $incoming = [];
        foreach (array_slice((array) ($d['ranges'] ?? []), 0, self::MAX_RANGES_PER_CALL) as $r) {
            if (!is_array($r) || count($r) < 2 || !is_numeric($r[0]) || !is_numeric($r[1])) {
                continue;
            }
            $s = max(0, (int) floor((float) $r[0]));
            $e = min($cap, (int) ceil((float) $r[1]));
            if ($e > $s) {
                $incoming[] = [$s, $e];
            }
        }
        $position = isset($d['position']) && is_numeric($d['position']) ? max(0, min($cap, (int) floor((float) $d['position']))) : null;
        $started = !empty($d['started']);
        $threshold = (int) config('upload.course_video_complete_pct', 90);
        $sid = (int) $scope['studentId'];

        $res = DB::transaction(function () use ($v, $course, $sid, $incoming, $position, $started, $duration, $threshold) {
            $row = DB::table('course_video_views')->where('video_id', $v->id)->where('student_id', $sid)->lockForUpdate()->first();
            $ranges = $row && $row->ranges ? (array) json_decode((string) $row->ranges, true) : [];
            $merged = self::mergeRanges(array_merge($ranges, $incoming));
            $watched = 0;
            foreach ($merged as [$s, $e]) {
                $watched += $e - $s;
            }
            $percent = $duration ? min(100.0, round($watched / $duration * 100, 2)) : 0.0;
            $now = now();
            $maxPos = $merged ? (int) end($merged)[1] : 0;
            $data = [
                'ranges' => json_encode($merged), 'watched_seconds' => $watched, 'percent' => $percent,
                'max_position' => max($maxPos, (int) ($row->max_position ?? 0)),
                'last_position' => $position ?? (int) ($row->last_position ?? 0),
                'completed' => ($row && $row->completed) || ($duration && $percent >= $threshold) ? 1 : 0,
                'last_watched_at' => $now, 'updated_at' => $now,
            ];
            if ($row) {
                if ($started) {
                    $data['play_count'] = (int) $row->play_count + 1;
                }
                DB::table('course_video_views')->where('id', $row->id)->update($data);
            } else {
                DB::table('course_video_views')->insert($data + [
                    'video_id' => $v->id, 'course_id' => $course->id, 'student_id' => $sid,
                    'play_count' => 1, 'first_watched_at' => $now, 'created_at' => $now,
                ]);
            }
            return DB::table('course_video_views')->where('video_id', $v->id)->where('student_id', $sid)->first();
        });

        return $this->ok($this->presentView($res));
    }

    /** يدمج الفترات المتداخلة/المتلاصقة. @param array<int,array{0:int,1:int}> $ranges */
    public static function mergeRanges(array $ranges): array
    {
        $clean = [];
        foreach ($ranges as $r) {
            if (is_array($r) && count($r) >= 2 && (int) $r[1] > (int) $r[0]) {
                $clean[] = [(int) $r[0], (int) $r[1]];
            }
        }
        usort($clean, static fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($clean as [$s, $e]) {
            if ($out && $s <= $out[count($out) - 1][1]) {
                if ($e > $out[count($out) - 1][1]) {
                    $out[count($out) - 1][1] = $e;
                }
            } else {
                $out[] = [$s, $e];
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------- reports

    /** مين شاف الفيديو ده وقد إيه (كل الطلاب المسجّلين، حتى اللي ماشافوش). */
    public function viewers(array $scope, $courseId, $vid, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $v = $this->find($course, $vid, true);
        if (!$v) {
            return $this->fail($l, 'الفيديو غير موجود.', 'Video not found.', 404);
        }

        $rows = DB::table('course_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('course_video_views as w', function ($j) use ($v) {
                $j->on('w.student_id', '=', 's.id')->where('w.video_id', '=', $v->id);
            })
            ->where('e.course_id', $course->id)
            ->select('s.id', 's.student_number', 'u.full_name', 'w.watched_seconds', 'w.percent', 'w.last_position', 'w.max_position', 'w.completed', 'w.play_count', 'w.first_watched_at', 'w.last_watched_at')
            ->orderBy('u.full_name')->get();

        $students = [];
        $watchedCount = 0;
        $completedCount = 0;
        foreach ($rows as $r) {
            $seen = $r->watched_seconds !== null && (int) $r->watched_seconds > 0;
            $watchedCount += $seen ? 1 : 0;
            $completedCount += $r->completed ? 1 : 0;
            $students[] = [
                'student_id' => (int) $r->id, 'student_number' => $r->student_number, 'full_name' => $r->full_name,
                'watched' => $seen,
                'watched_seconds' => (int) ($r->watched_seconds ?? 0),
                'percent' => (float) ($r->percent ?? 0),
                'completed' => (bool) $r->completed,
                'play_count' => (int) ($r->play_count ?? 0),
                'last_position' => (int) ($r->last_position ?? 0),
                'first_watched_at' => $r->first_watched_at ? date('c', strtotime($r->first_watched_at)) : null,
                'last_watched_at' => $r->last_watched_at ? date('c', strtotime($r->last_watched_at)) : null,
            ];
        }
        return $this->ok([
            'video' => $this->present($v, true),
            'stats' => ['enrolled' => count($students), 'watched' => $watchedCount, 'not_watched' => count($students) - $watchedCount, 'completed' => $completedCount],
            'students' => $students,
        ]);
    }

    /** تقرير المادة: لكل طالب نسبة مشاهدة كل فيديو + ملخص. */
    public function report(array $scope, $courseId, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $videos = DB::table('course_videos')->where('course_id', $course->id)->where('status', 'published')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title', 'duration_seconds']);
        $views = DB::table('course_video_views')->where('course_id', $course->id)->get(['video_id', 'student_id', 'percent', 'completed', 'watched_seconds', 'last_watched_at'])
            ->groupBy('student_id');
        $students = DB::table('course_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('e.course_id', $course->id)
            ->select('s.id', 's.student_number', 'u.full_name')->orderBy('u.full_name')->get();

        $total = $videos->count();
        $vids = $videos->pluck('id')->flip();
        $rows = [];
        foreach ($students as $s) {
            $per = [];
            $sum = 0.0;
            $done = 0;
            $secs = 0;
            $last = null;
            foreach ($views->get($s->id, collect()) as $w) {
                if (!isset($vids[$w->video_id])) {
                    continue; // فيديو مسودة/محذوف
                }
                $per[(int) $w->video_id] = (float) $w->percent;
                $sum += (float) $w->percent;
                $done += $w->completed ? 1 : 0;
                $secs += (int) $w->watched_seconds;
                if ($w->last_watched_at && (!$last || $w->last_watched_at > $last)) {
                    $last = $w->last_watched_at;
                }
            }
            $rows[] = [
                'student_id' => (int) $s->id, 'student_number' => $s->student_number, 'full_name' => $s->full_name,
                'videos_started' => count($per), 'videos_completed' => $done, 'videos_total' => $total,
                'avg_percent' => $total ? round($sum / $total, 1) : 0.0,
                'watched_seconds' => $secs,
                'last_watched_at' => $last ? date('c', strtotime($last)) : null,
                'per_video' => (object) $per,   // { video_id: percent }
            ];
        }
        return $this->ok([
            'videos' => $videos->map(fn ($v) => ['id' => (int) $v->id, 'title' => $v->title, 'duration_seconds' => $v->duration_seconds !== null ? (int) $v->duration_seconds : null])->all(),
            'students' => $rows,
        ]);
    }

    private function notifyStudents(Course $course, string $title): void
    {
        try {
            $name = $course->code . ' — ' . ($course->name_ar ?: $course->name_en);
            $userIds = DB::table('course_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.course_id', $course->id)->whereNotNull('s.user_id')->pluck('s.user_id');
            foreach ($userIds as $uid) {
                $this->notifications->notify((int) $uid, 'course_material', "فيديو شرح جديد: {$title}", $name, '/student/courses?course=' . $course->id . '&tab=videos', 'normal');
            }
        } catch (\Throwable $e) {
            // الإشعارات ماينفعش تكسر الرفع
        }
    }
}
