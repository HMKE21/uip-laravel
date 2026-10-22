<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * التكليفات والتسليمات (course_assignments / assignment_submissions).
 *
 *  - إنشاء / تعديل / حذف التكليف + تصحيح التسليمات: مديرو المادة بس (مدرّس المادة، الكلية، الجامعة) — CourseService::manageableCourse.
 *  - الطالب المسجّل في المادة: يشوف التكليفات المنشورة، ويسلّم (نص و/أو ملف)، ويعيد التسليم قبل ما يتصحّح (أو لو اتّرجع له).
 *  - التأخير: لو التسليم بعد due_at بيتعلّم متأخر؛ ولو allow_late=false بيترفض.
 *  - درجة الطالب بتتحقق من 0..max_score، وبيتبعتله إشعار عند التصحيح.
 *
 * كل الدوال بترجّع array: ['success' => bool, 'message' => ..., 'code' => ..., 'data' => ...].
 */
class CourseAssignmentService
{
    /** امتدادات ملف المدرّس. */
    public const ATTACH_ALLOWED = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'jpg', 'jpeg', 'png'];
    /** امتدادات تسليم الطالب (شاملة ملفات الكود). */
    public const SUBMIT_ALLOWED = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'md', 'zip', 'jpg', 'jpeg', 'png', 'py', 'java', 'c', 'cpp', 'js', 'ipynb', 'sql'];

    private const PREVIEW = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'txt' => 'text/plain; charset=UTF-8',
    ];

    public function __construct(
        private CourseService $courses,
        private FileUploadService $uploader,
        private NotificationService $notifications
    ) {
    }

    // ------------------------------------------------------------------ helpers

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    private function ok($data = null, string $msg = 'OK'): array
    {
        return ['success' => true, 'message' => $msg, 'data' => $data];
    }

    private static function ext(?string $name): string
    {
        return strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
    }

    private function courseLabel(Course $c): string
    {
        return $c->code . ' — ' . ($c->name_ar ?: $c->name_en);
    }

    /** @return array{0: Course, 1: bool}|array [course, canManage] أو array فيها success=false */
    private function access(array $scope, $courseId, string $l)
    {
        if ($scope['role'] === 'student') {
            $c = Course::where('university_id', $scope['universityId'])->find((int) $courseId);
            if (!$c || $c->status !== 'active') {
                return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
            }
            if (!DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', $scope['studentId'])->exists()) {
                return $this->fail($l, 'لازم تكون مسجّل في المادة.', 'You must be enrolled in this course.', 403);
            }
            return [$c, false];
        }
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        return is_array($c) ? $c : [$c, true];
    }

    private function findAssignment(Course $course, $aid, bool $canManage)
    {
        $q = DB::table('course_assignments')->where('course_id', $course->id)->where('id', (int) $aid);
        if (!$canManage) {
            $q->where('status', 'published');
        }
        return $q->first();
    }

    private function deleteStored(?string $path, string $category): void
    {
        if (!$path) {
            return;
        }
        $full = public_path(ltrim($path, '/'));
        $root = realpath(public_path((string) config("upload.paths.{$category}", 'uploads/' . $category)));
        $real = is_file($full) ? realpath($full) : false;
        if ($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }

    /** @return array|array{success:false} بيانات الملف المخزّن أو خطأ */
    private function storeFile($file, Course $course, string $category, array $allowed, string $l)
    {
        try {
            return $this->uploader->store($file, $category, 'c' . $course->id, null, $allowed, (int) config('upload.course_assignments_max_kb', 20480));
        } catch (\RuntimeException $e) {
            return $this->fail($l, 'تعذّر رفع الملف: ' . $e->getMessage(), $e->getMessage());
        }
    }

    private function fileResponse(?string $path, ?string $name, string $category, bool $wantInline, string $l): array
    {
        $full = $path ? public_path(ltrim($path, '/')) : null;
        $root = realpath(public_path((string) config("upload.paths.{$category}", 'uploads/' . $category)));
        $real = $full && is_file($full) ? realpath($full) : false;
        if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return $this->fail($l, 'الملف غير موجود.', 'File not found.', 404);
        }
        $ext = self::ext($name);
        return [
            'success' => true,
            'path'    => $real,
            'name'    => $name ?: ('file.' . $ext),
            'mime'    => self::PREVIEW[$ext] ?? 'application/octet-stream',
            'inline'  => $wantInline && isset(self::PREVIEW[$ext]),
        ];
    }

    private function num($v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    // ------------------------------------------------------------------ presenting

    private function presentAssignment(object $a, bool $canManage, ?object $sub, array $extra = []): array
    {
        $due = $a->due_at ? strtotime((string) $a->due_at) : null;
        $past = $due !== null && $due < time();
        $state = null;
        if (!$canManage) {
            if ($sub) {
                $state = $sub->status === 'graded' ? 'graded' : ($sub->status === 'returned' ? 'returned' : 'submitted');
            } else {
                $state = $past ? ($a->allow_late ? 'late_open' : 'missed') : 'open';
            }
        }
        return array_merge([
            'id'          => (int) $a->id,
            'course_id'   => (int) $a->course_id,
            'title'       => $a->title,
            'body'        => $a->body,
            'due_at'      => $a->due_at,
            'is_past_due' => $past,
            'max_score'   => $this->num($a->max_score),
            'weight'      => $this->num($a->weight),
            'allow_late'  => (bool) $a->allow_late,
            'status'      => $a->status,
            'has_file'    => !empty($a->file_path),
            'file_name'   => $a->file_name,
            'file_size'   => $a->file_size !== null ? (int) $a->file_size : null,
            'created_at'  => $a->created_at,
            'can_manage'  => $canManage,
            'state'       => $state,
            'submission'  => $sub ? $this->presentSubmission($sub, true) : null,
        ], $extra);
    }

    private function presentSubmission(object $s, bool $mine = false): array
    {
        return [
            'id'           => (int) $s->id,
            'assignment_id' => (int) $s->assignment_id,
            'student_id'   => (int) $s->student_id,
            'body'         => $s->body,
            'has_file'     => !empty($s->file_path),
            'file_name'    => $s->file_name,
            'file_size'    => $s->file_size !== null ? (int) $s->file_size : null,
            'submitted_at' => $s->submitted_at,
            'is_late'      => (bool) $s->is_late,
            'attempts'     => (int) $s->attempts,
            'status'       => $s->status,
            'score'        => $this->num($s->score),
            'feedback'     => $s->feedback,
            'graded_at'    => $s->graded_at,
        ];
    }

    // ------------------------------------------------------------------ reads

    public function list(array $scope, $courseId, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;

        $q = DB::table('course_assignments')->where('course_id', $course->id)->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id');
        if (!$canManage) {
            $q->where('status', 'published');
        }
        $rows = $q->get();

        $subs = collect();
        $counts = [];
        if ($canManage) {
            $enrolled = (int) DB::table('course_enrollments')->where('course_id', $course->id)->count();
            foreach (DB::table('assignment_submissions')->where('course_id', $course->id)->select('assignment_id', 'status', DB::raw('COUNT(*) n'))->groupBy('assignment_id', 'status')->get() as $r) {
                $counts[(int) $r->assignment_id][$r->status] = (int) $r->n;
            }
        } else {
            $subs = DB::table('assignment_submissions')->where('course_id', $course->id)->where('student_id', $scope['studentId'])->get()->keyBy('assignment_id');
        }

        $data = [];
        foreach ($rows as $a) {
            $extra = [];
            if ($canManage) {
                $c = $counts[(int) $a->id] ?? [];
                $graded = $c['graded'] ?? 0;
                $submitted = array_sum($c);
                $extra = ['stats' => ['enrolled' => $enrolled, 'submitted' => $submitted, 'graded' => $graded, 'to_grade' => $submitted - $graded]];
            }
            $data[] = $this->presentAssignment($a, $canManage, $subs->get($a->id), $extra);
        }
        return $this->ok($data);
    }

    /** مهام الطالب عبر كل مواده. */
    public function mine(array $scope, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $rows = DB::table('course_assignments as a')
            ->join('course_enrollments as e', function ($j) use ($scope) {
                $j->on('e.course_id', '=', 'a.course_id')->where('e.student_id', '=', $scope['studentId']);
            })
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->where('a.status', 'published')->where('c.status', 'active')
            ->select('a.*', 'c.code as course_code', 'c.name_ar as course_name_ar', 'c.name_en as course_name_en')
            ->orderByRaw('a.due_at IS NULL')->orderBy('a.due_at')->get();
        $subs = DB::table('assignment_submissions')->where('student_id', $scope['studentId'])->get()->keyBy('assignment_id');

        $data = [];
        foreach ($rows as $a) {
            $data[] = $this->presentAssignment($a, false, $subs->get($a->id), [
                'course' => ['id' => (int) $a->course_id, 'code' => $a->course_code, 'name' => $a->course_name_ar ?: $a->course_name_en],
            ]);
        }
        return $this->ok($data);
    }

    // ------------------------------------------------------------------ manage assignments

    private function validateFields(array $d, string $l, bool $partial)
    {
        if (!$partial || array_key_exists('title', $d)) {
            if (trim((string) ($d['title'] ?? '')) === '') {
                return $this->fail($l, 'اكتب عنوان التكليف.', 'Enter a title.');
            }
        }
        if (isset($d['max_score']) && $d['max_score'] !== '' && (!is_numeric($d['max_score']) || (float) $d['max_score'] <= 0 || (float) $d['max_score'] > 9999)) {
            return $this->fail($l, 'الدرجة العظمى غير صالحة.', 'Invalid maximum score.');
        }
        if (isset($d['weight']) && $d['weight'] !== '' && (!is_numeric($d['weight']) || (float) $d['weight'] < 0 || (float) $d['weight'] > 100)) {
            return $this->fail($l, 'الوزن لازم يكون بين 0 و 100.', 'Weight must be between 0 and 100.');
        }
        return null;
    }

    public function create(array $scope, $courseId, array $d, $file, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تضيف تكليف.', 'You cannot add assignments here.', 403);
        }
        if ($err = $this->validateFields($d, $l, false)) {
            return $err;
        }

        $stored = null;
        if ($file) {
            $stored = $this->storeFile($file, $course, 'course_assignments', self::ATTACH_ALLOWED, $l);
            if (isset($stored['success'])) {
                return $stored;
            }
        }
        $status = ($d['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $due = !empty($d['due_at']) ? date('Y-m-d H:i:s', strtotime($d['due_at'])) : null;
        $id = DB::table('course_assignments')->insertGetId([
            'course_id' => $course->id,
            'title' => trim((string) $d['title']),
            'body' => ($b = trim((string) ($d['body'] ?? ''))) !== '' ? $b : null,
            'due_at' => $due,
            'max_score' => ($d['max_score'] ?? '') !== '' ? round((float) $d['max_score'], 2) : 100,
            'weight' => ($d['weight'] ?? '') !== '' && isset($d['weight']) ? round((float) $d['weight'], 2) : null,
            'allow_late' => array_key_exists('allow_late', $d) ? (filter_var($d['allow_late'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1,
            'status' => $status,
            'file_path' => $stored['stored_path'] ?? null,
            'file_name' => $stored['original_name'] ?? null,
            'file_mime' => $stored['mime_type'] ?? null,
            'file_size' => $stored['size_bytes'] ?? null,
            'created_by_user_id' => $scope['userId'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($status === 'published') {
            $this->notifyStudents($course, 'تكليف جديد: ' . trim((string) $d['title']), $due ? 'آخر موعد: ' . $due : null, '/student/assignments');
        }
        return $this->ok(['id' => $id], $this->ar($l) ? 'تم إنشاء التكليف.' : 'Assignment created.');
    }

    public function update(array $scope, $courseId, $aid, array $d, $file, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تعدّل.', 'You cannot edit this.', 403);
        }
        $a = $this->findAssignment($course, $aid, true);
        if (!$a) {
            return $this->fail($l, 'التكليف غير موجود.', 'Assignment not found.', 404);
        }
        if ($err = $this->validateFields($d, $l, true)) {
            return $err;
        }

        $set = ['updated_at' => now()];
        if (array_key_exists('title', $d)) {
            $set['title'] = trim((string) $d['title']);
        }
        if (array_key_exists('body', $d)) {
            $set['body'] = ($b = trim((string) $d['body'])) !== '' ? $b : null;
        }
        if (array_key_exists('due_at', $d)) {
            $set['due_at'] = !empty($d['due_at']) ? date('Y-m-d H:i:s', strtotime($d['due_at'])) : null;
        }
        if (array_key_exists('max_score', $d) && $d['max_score'] !== '' && $d['max_score'] !== null) {
            $set['max_score'] = round((float) $d['max_score'], 2);
        }
        if (array_key_exists('weight', $d)) {
            $set['weight'] = $d['weight'] === '' || $d['weight'] === null ? null : round((float) $d['weight'], 2);
        }
        if (array_key_exists('allow_late', $d)) {
            $set['allow_late'] = filter_var($d['allow_late'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('status', $d)) {
            $set['status'] = $d['status'] === 'draft' ? 'draft' : 'published';
        }
        $oldPath = null;
        if ($file) {
            $stored = $this->storeFile($file, $course, 'course_assignments', self::ATTACH_ALLOWED, $l);
            if (isset($stored['success'])) {
                return $stored;
            }
            $oldPath = $a->file_path;
            $set += ['file_path' => $stored['stored_path'], 'file_name' => $stored['original_name'], 'file_mime' => $stored['mime_type'], 'file_size' => $stored['size_bytes']];
        } elseif (!empty($d['remove_file']) && $a->file_path) {
            $oldPath = $a->file_path;
            $set += ['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null];
        }
        // لو التكليف نزل أصغر من أعلى درجة اتحطت لطالب، متقلّلش الدرجة العظمى تحت الدرجات الموجودة.
        if (isset($set['max_score'])) {
            $top = (float) DB::table('assignment_submissions')->where('assignment_id', $a->id)->max('score');
            if ($top > $set['max_score']) {
                return $this->fail($l, 'فيه درجات أعلى من الدرجة العظمى الجديدة.', 'Some graded scores exceed the new maximum.');
            }
        }

        DB::table('course_assignments')->where('id', $a->id)->update($set);
        $this->deleteStored($oldPath, 'course_assignments');

        $becamePublished = ($set['status'] ?? $a->status) === 'published' && $a->status === 'draft';
        $dueChanged = isset($set['due_at']) && $set['due_at'] !== $a->due_at && $a->status === 'published';
        if ($becamePublished) {
            $this->notifyStudents($course, 'تكليف جديد: ' . ($set['title'] ?? $a->title), null, '/student/assignments');
        } elseif ($dueChanged) {
            $this->notifyStudents($course, 'تعديل موعد تكليف: ' . ($set['title'] ?? $a->title), 'الموعد الجديد: ' . ($set['due_at'] ?? 'غير محدد'), '/student/assignments');
        }
        return $this->ok(['id' => (int) $a->id], $this->ar($l) ? 'تم الحفظ.' : 'Saved.');
    }

    public function delete(array $scope, $courseId, $aid, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تحذف.', 'You cannot delete this.', 403);
        }
        $a = $this->findAssignment($course, $aid, true);
        if (!$a) {
            return $this->fail($l, 'التكليف غير موجود.', 'Assignment not found.', 404);
        }
        $files = DB::table('assignment_submissions')->where('assignment_id', $a->id)->pluck('file_path')->all();
        DB::transaction(function () use ($a) {
            DB::table('assignment_submissions')->where('assignment_id', $a->id)->delete();
            DB::table('course_assignments')->where('id', $a->id)->delete();
        });
        $this->deleteStored($a->file_path, 'course_assignments');
        foreach ($files as $f) {
            $this->deleteStored($f, 'assignment_submissions');
        }
        return $this->ok(null, $this->ar($l) ? 'تم حذف التكليف.' : 'Assignment deleted.');
    }

    public function attachment(array $scope, $courseId, $aid, bool $wantInline, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        $a = $this->findAssignment($course, $aid, $canManage);
        if (!$a || !$a->file_path) {
            return $this->fail($l, 'الملف غير موجود.', 'File not found.', 404);
        }
        return $this->fileResponse($a->file_path, $a->file_name, 'course_assignments', $wantInline, $l);
    }

    // ------------------------------------------------------------------ student: submit

    public function submit(array $scope, $courseId, $aid, array $d, $file, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'التسليم للطلاب بس.', 'Only students can submit.', 403);
        }
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course] = $acc;
        $a = $this->findAssignment($course, $aid, false);
        if (!$a) {
            return $this->fail($l, 'التكليف غير موجود.', 'Assignment not found.', 404);
        }

        $past = $a->due_at && strtotime((string) $a->due_at) < time();
        if ($past && !$a->allow_late) {
            return $this->fail($l, 'انتهى موعد التسليم.', 'The deadline has passed.', 403);
        }
        $body = trim((string) ($d['body'] ?? ''));
        $existing = DB::table('assignment_submissions')->where('assignment_id', $a->id)->where('student_id', $scope['studentId'])->first();
        if ($existing && $existing->status === 'graded') {
            return $this->fail($l, 'تسليمك اتصحّح بالفعل ومينفعش يتعدّل.', 'Your submission has been graded and can no longer be changed.', 409);
        }
        $hasFile = $file || ($existing && $existing->file_path && empty($d['remove_file']));
        if ($body === '' && !$hasFile) {
            return $this->fail($l, 'اكتب إجابتك أو ارفع ملفًا.', 'Write your answer or upload a file.');
        }

        $stored = null;
        if ($file) {
            $stored = $this->storeFile($file, $course, 'assignment_submissions', self::SUBMIT_ALLOWED, $l);
            if (isset($stored['success'])) {
                return $stored;
            }
        }

        $now = now();
        $row = [
            'body' => $body !== '' ? $body : null,
            'submitted_at' => $now,
            'is_late' => $past ? 1 : 0,
            'status' => 'submitted',
            'updated_at' => $now,
        ];
        $oldPath = null;
        if ($stored) {
            $oldPath = $existing->file_path ?? null;
            $row += ['file_path' => $stored['stored_path'], 'file_name' => $stored['original_name'], 'file_mime' => $stored['mime_type'], 'file_size' => $stored['size_bytes']];
        } elseif ($existing && !empty($d['remove_file'])) {
            $oldPath = $existing->file_path;
            $row += ['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null];
        }

        if ($existing) {
            DB::table('assignment_submissions')->where('id', $existing->id)->update($row + ['attempts' => (int) $existing->attempts + 1, 'score' => null, 'feedback' => $existing->status === 'returned' ? $existing->feedback : null]);
            $this->deleteStored($oldPath, 'assignment_submissions');
        } else {
            DB::table('assignment_submissions')->insert($row + [
                'assignment_id' => $a->id, 'course_id' => $course->id, 'student_id' => $scope['studentId'], 'attempts' => 1, 'created_at' => $now,
            ]);
        }
        $sub = DB::table('assignment_submissions')->where('assignment_id', $a->id)->where('student_id', $scope['studentId'])->first();
        return $this->ok($this->presentSubmission($sub, true), $past
            ? ($this->ar($l) ? 'تم التسليم (متأخر).' : 'Submitted (late).')
            : ($this->ar($l) ? 'تم التسليم.' : 'Submitted.'));
    }

    // ------------------------------------------------------------------ managers: submissions & grading

    public function submissions(array $scope, $courseId, $aid, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $a = $this->findAssignment($course, $aid, true);
        if (!$a) {
            return $this->fail($l, 'التكليف غير موجود.', 'Assignment not found.', 404);
        }
        $subs = DB::table('assignment_submissions')->where('assignment_id', $a->id)->get()->keyBy('student_id');
        $rows = [];
        $stats = ['submitted' => 0, 'graded' => 0, 'missing' => 0, 'late' => 0];
        $students = DB::table('course_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('e.course_id', $course->id)
            ->select('s.id', 's.student_number', 'u.full_name')->orderBy('u.full_name')->get();
        foreach ($students as $s) {
            $sub = $subs->get($s->id);
            $rows[] = [
                'student_id' => (int) $s->id, 'student_number' => $s->student_number, 'full_name' => $s->full_name,
                'submission' => $sub ? $this->presentSubmission($sub) : null,
            ];
            if (!$sub) {
                $stats['missing']++;
            } else {
                $stats['submitted']++;
                $stats['late'] += $sub->is_late ? 1 : 0;
                $stats['graded'] += $sub->status === 'graded' ? 1 : 0;
            }
        }
        return $this->ok(['assignment' => $this->presentAssignment($a, true, null), 'stats' => $stats, 'students' => $rows]);
    }

    public function submissionFile(array $scope, $courseId, $aid, $studentId, bool $wantInline, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        // الطالب يفتح ملفه هو بس؛ المدير يفتح أي ملف في مادته.
        if (!$canManage && (int) $studentId !== (int) $scope['studentId']) {
            return $this->fail($l, 'مش مسموحلك.', 'Not allowed.', 403);
        }
        $a = $this->findAssignment($course, $aid, $canManage);
        if (!$a) {
            return $this->fail($l, 'التكليف غير موجود.', 'Assignment not found.', 404);
        }
        $s = DB::table('assignment_submissions')->where('assignment_id', $a->id)->where('student_id', (int) $studentId)->first();
        if (!$s || !$s->file_path) {
            return $this->fail($l, 'الملف غير موجود.', 'File not found.', 404);
        }
        return $this->fileResponse($s->file_path, $s->file_name, 'assignment_submissions', $wantInline, $l);
    }

    /** $d: score (nullable لو returned), feedback, return (bool = رجّعه للطالب يعيد التسليم). */
    public function grade(array $scope, $courseId, $aid, $studentId, array $d, string $l): array
    {
        $acc = $this->access($scope, $courseId, $l);
        if (isset($acc['success'])) {
            return $acc;
        }
        [$course, $canManage] = $acc;
        if (!$canManage) {
            return $this->fail($l, 'مش مسموحلك تصحّح.', 'You cannot grade here.', 403);
        }
        $a = $this->findAssignment($course, $aid, true);
        if (!$a) {
            return $this->fail($l, 'التكليف غير موجود.', 'Assignment not found.', 404);
        }
        $s = DB::table('assignment_submissions')->where('assignment_id', $a->id)->where('student_id', (int) $studentId)->first();
        if (!$s) {
            return $this->fail($l, 'الطالب لم يسلّم بعد.', 'The student has not submitted yet.', 404);
        }

        $feedback = isset($d['feedback']) ? trim((string) $d['feedback']) : null;
        $return = !empty($d['return']);
        $set = ['feedback' => $feedback !== '' ? $feedback : null, 'graded_by_user_id' => $scope['userId'], 'graded_at' => now(), 'updated_at' => now()];

        if ($return) {
            $set += ['status' => 'returned', 'score' => null];
        } else {
            if (!isset($d['score']) || $d['score'] === '' || !is_numeric($d['score'])) {
                return $this->fail($l, 'ادخل الدرجة.', 'Enter a score.');
            }
            $score = round((float) $d['score'], 2);
            if ($score < 0 || $score > (float) $a->max_score) {
                return $this->fail($l, 'الدرجة لازم تكون بين 0 و ' . (float) $a->max_score . '.', 'The score must be between 0 and ' . (float) $a->max_score . '.');
            }
            $set += ['status' => 'graded', 'score' => $score];
        }
        DB::table('assignment_submissions')->where('id', $s->id)->update($set);

        try {
            $uid = DB::table('students')->where('id', $s->student_id)->value('user_id');
            if ($uid) {
                $title = $return ? 'تم إرجاع تسليمك لتعديله: ' : 'تم تصحيح تكليفك: ';
                $body = $return ? ($set['feedback'] ?? 'أعد التسليم بعد التعديل.') : ('درجتك ' . $set['score'] . ' من ' . (float) $a->max_score);
                $this->notifications->notify((int) $uid, 'course_assignment', $title . $a->title, $body, '/student/assignments', 'normal');
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $fresh = DB::table('assignment_submissions')->where('id', $s->id)->first();
        return $this->ok($this->presentSubmission($fresh), $return ? ($this->ar($l) ? 'تم إرجاع التسليم للطالب.' : 'Returned to the student.') : ($this->ar($l) ? 'تم حفظ الدرجة.' : 'Grade saved.'));
    }

    // ------------------------------------------------------------------ notifications

    private function notifyStudents(Course $course, string $title, ?string $body, string $link): void
    {
        try {
            $userIds = DB::table('course_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.course_id', $course->id)->whereNotNull('s.user_id')->pluck('s.user_id');
            foreach ($userIds as $uid) {
                $this->notifications->notify((int) $uid, 'course_assignment', $title, trim($this->courseLabel($course) . ($body ? ' — ' . $body : '')), $link, 'normal');
            }
        } catch (\Throwable $e) {
            // الإشعار مش شرط لنجاح العملية.
        }
    }
}
