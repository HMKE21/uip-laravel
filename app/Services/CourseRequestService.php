<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Course enrol / drop REQUESTS (طلبات تسجيل / إلغاء تسجيل المواد).
 *
 * A student cannot enrol or drop directly. They send a request (optional message + one attachment)
 * to every party that can act on the course:
 *   - the university (course.university_id)
 *   - the faculty (course.faculty_id, or the student's faculty for a university-wide course)
 *   - the course's doctors / TAs (course_staff) and the staff member who created the course
 * The FIRST party to approve applies the change immediately. A rejection is recorded per party and
 * only closes the request once EVERY party has rejected it.
 *
 * `$scope` is the same array CourseApiController::scope() builds.
 */
class CourseRequestService
{
    public function __construct(
        private NotificationService $notifications,
        private FileUploadService $uploader,
        private RegistrationService $registration
    ) {
    }

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $l === 'ar' ? $ar : $en, 'code' => $code];
    }

    // ---------------------------------------------------------------- who is a party to a request

    /** @return array<int,string> user_id => role (university|faculty|academic_staff) */
    public function recipients(object $course, ?int $studentFacultyId): array
    {
        $out = [];
        $uid = DB::table('universities')->where('id', $course->university_id)->value('user_id');
        if ($uid) {
            $out[(int) $uid] = 'university';
        }
        $facultyId = $course->faculty_id ?: $studentFacultyId;
        if ($facultyId) {
            $fu = DB::table('faculties')->where('id', $facultyId)->value('user_id');
            if ($fu) {
                $out[(int) $fu] ??= 'faculty';
            }
        }
        $staffUsers = DB::table('course_staff as cs')
            ->join('academic_staff as a', 'a.id', '=', 'cs.academic_staff_id')
            ->where('cs.course_id', $course->id)
            ->pluck('a.user_id')->all();
        if ($course->created_by_user_id && DB::table('academic_staff')->where('user_id', $course->created_by_user_id)->exists()) {
            $staffUsers[] = $course->created_by_user_id;
        }
        foreach ($staffUsers as $u) {
            $out[(int) $u] ??= 'academic_staff';
        }
        return $out;
    }

    /** Requests a non-student caller is a party to. Single source of truth for list + decide. */
    private function incomingQuery(array $scope)
    {
        $q = DB::table('course_enrollment_requests as r')
            ->join('courses as c', 'c.id', '=', 'r.course_id')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->where('c.university_id', $scope['universityId']);

        switch ($scope['role']) {
            case 'university':
                break;
            case 'faculty':
                $fid = (int) $scope['facultyId'];
                $q->where(function ($w) use ($fid) {
                    $w->where('c.faculty_id', $fid)
                      ->orWhere(fn ($x) => $x->whereNull('c.faculty_id')->where('s.faculty_id', $fid));
                });
                break;
            case 'academic_staff':
                $q->where(function ($w) use ($scope) {
                    $w->where('c.created_by_user_id', $scope['userId'])
                      ->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from('course_staff as cs')
                          ->whereColumn('cs.course_id', 'c.id')->where('cs.academic_staff_id', (int) $scope['staffId']));
                });
                break;
            default:
                $q->whereRaw('1 = 0');
        }
        return $q;
    }

    // ---------------------------------------------------------------- presenting

    private function selectCols($q)
    {
        return $q->join('users as su', 'su.id', '=', 's.user_id')
            ->leftJoin('users as du', 'du.id', '=', 'r.decided_by_user_id')
            ->select(
                'r.*',
                'c.code as course_code', 'c.name_en as course_name_en', 'c.name_ar as course_name_ar',
                's.student_number', 'su.full_name as student_name', 'du.full_name as decided_by_name'
            );
    }

    private function present(object $r, string $l, bool $withDecisions = false): array
    {
        $row = [
            'id'                => (int) $r->id,
            'course_id'         => (int) $r->course_id,
            'course_code'       => $r->course_code,
            'course_name'       => $this->ar($l) ? ($r->course_name_ar ?: $r->course_name_en) : $r->course_name_en,
            'student_id'        => (int) $r->student_id,
            'student_name'      => $r->student_name,
            'student_number'    => $r->student_number,
            'type'              => $r->type,
            'status'            => $r->status,
            'message'           => $r->message,
            'has_attachment'    => !empty($r->attachment_path),
            'attachment_name'   => $r->attachment_name,
            'decided_by_name'   => $r->decided_by_name,
            'decided_by_role'   => $r->decided_by_role,
            'decision_note'     => $r->decision_note,
            'decided_at'        => $r->decided_at,
            'created_at'        => $r->created_at,
        ];
        if ($withDecisions) {
            $row['decisions'] = DB::table('course_enrollment_request_decisions as d')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->where('d.request_id', $r->id)
                ->select('u.full_name', 'd.role', 'd.decision', 'd.note', 'd.created_at')->get()
                ->map(fn ($d) => (array) $d)->all();
        }
        return $row;
    }

    /** GET /courses/requests — a student's own requests, or the requests addressed to the caller. */
    public function list(array $scope, array $f, string $l): array
    {
        if ($scope['role'] === 'student') {
            $q = DB::table('course_enrollment_requests as r')
                ->join('courses as c', 'c.id', '=', 'r.course_id')
                ->join('students as s', 's.id', '=', 'r.student_id')
                ->where('r.student_id', $scope['studentId']);
        } else {
            $q = $this->incomingQuery($scope);
        }
        if (in_array($f['status'] ?? '', ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $q->where('r.status', $f['status']);
        }
        if (!empty($f['course_id'])) {
            $q->where('r.course_id', (int) $f['course_id']);
        }
        $rows = $this->selectCols($q)->orderByRaw("CASE r.status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('r.id')->limit(300)->get();

        $mine = $scope['role'] !== 'student'
            ? DB::table('course_enrollment_request_decisions')->whereIn('request_id', $rows->pluck('id')->all())->where('user_id', $scope['userId'])->pluck('decision', 'request_id')->all()
            : [];
        return $rows->map(function ($r) use ($l, $mine) {
            $p = $this->present($r, $l);
            $p['my_decision'] = $mine[$r->id] ?? null;
            return $p;
        })->all();
    }

    /** id => ['id','type'] of the student's pending request per course, for the courses list. */
    public function pendingByCourse(int $studentId, array $courseIds): array
    {
        if (!$courseIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('course_enrollment_requests')->where('student_id', $studentId)->where('status', 'pending')->whereIn('course_id', $courseIds)->get(['id', 'course_id', 'type']) as $r) {
            $out[(int) $r->course_id] = ['id' => (int) $r->id, 'type' => $r->type];
        }
        return $out;
    }

    // ---------------------------------------------------------------- student: create / cancel

    public function create(array $scope, $courseId, string $type, ?string $message, ?UploadedFile $file, string $l): array
    {
        if ($scope['role'] !== 'student' || !$scope['studentId']) {
            return $this->fail($l, 'للطلاب فقط.', 'Students only.', 403);
        }
        if (!in_array($type, ['enroll', 'drop'], true)) {
            return $this->fail($l, 'نوع الطلب غير صالح.', 'Invalid request type.');
        }
        $message = $message !== null ? trim($message) : null;
        if ($message !== null && mb_strlen($message) > 2000) {
            return $this->fail($l, 'الرسالة طويلة جدًا (الحد 2000 حرف).', 'The message is too long (max 2000 characters).');
        }

        $course = DB::table('courses')->where('id', (int) $courseId)->where('university_id', $scope['universityId'])->first();
        if (!$course) {
            return $this->fail($l, 'هذه المادة غير متاحة لك.', 'This course is not available to you.', 404);
        }
        $enrolled = DB::table('course_enrollments')->where('course_id', $course->id)->where('student_id', $scope['studentId'])->exists();

        if ($type === 'enroll') {
            $visible = $course->status === 'active' && ($course->faculty_id === null || (int) $course->faculty_id === (int) $scope['facultyId']);
            if (!$visible) {
                return $this->fail($l, 'هذه المادة غير متاحة لك.', 'This course is not available to you.', 404);
            }
            if ($enrolled) {
                return $this->fail($l, 'أنت مسجّل في هذه المادة بالفعل.', 'You are already enrolled in this course.', 409);
            }
        } elseif (!$enrolled) {
            return $this->fail($l, 'أنت غير مسجّل في هذه المادة.', 'You are not enrolled in this course.', 409);
        }

        // قواعد التسجيل الأكاديمي (فترة / متطلبات / ساعات / تعارض) — موافقة الجهات على الطلب استثناء مقصود.
        if ($denied = $this->registration->checkRequest($scope, $course, $type, $l)) {
            return $denied;
        }

        if (DB::table('course_enrollment_requests')->where('course_id', $course->id)->where('student_id', $scope['studentId'])->where('status', 'pending')->exists()) {
            return $this->fail($l, 'لديك طلب قيد المراجعة لهذه المادة بالفعل.', 'You already have a pending request for this course.', 409);
        }

        $stored = null;
        if ($file) {
            try {
                $stored = $this->uploader->store($file, 'course_requests', 's' . $scope['studentId']);
            } catch (\RuntimeException $e) {
                return $this->fail($l, 'تعذّر رفع الملف: ' . $e->getMessage(), $e->getMessage());
            }
        }

        $id = DB::table('course_enrollment_requests')->insertGetId([
            'course_id'       => $course->id,
            'student_id'      => $scope['studentId'],
            'type'            => $type,
            'status'          => 'pending',
            'message'         => $message !== '' ? $message : null,
            'attachment_path' => $stored['stored_path'] ?? null,
            'attachment_name' => $stored['original_name'] ?? null,
            'attachment_mime' => $stored['mime_type'] ?? null,
            'attachment_size' => $stored['size_bytes'] ?? null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->notifyParties($course, (int) $scope['facultyId'], (int) $scope['userId'], $id, $type, $l);

        return [
            'success' => true,
            'message' => $this->ar($l)
                ? 'تم إرسال طلبك للجامعة والكلية ومدرّسي المادة. سيتم التنفيذ فور موافقة أي جهة منهم.'
                : 'Your request was sent to the university, the faculty and the course staff. It is applied as soon as any of them approves.',
            'data'    => ['id' => $id, 'status' => 'pending', 'type' => $type],
        ];
    }

    public function cancel(array $scope, $requestId, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'للطلاب فقط.', 'Students only.', 403);
        }
        $n = DB::table('course_enrollment_requests')->where('id', (int) $requestId)->where('student_id', $scope['studentId'])->where('status', 'pending')
            ->update(['status' => 'cancelled', 'decided_at' => now(), 'updated_at' => now()]);
        return $n
            ? ['success' => true, 'message' => $this->ar($l) ? 'تم سحب الطلب.' : 'Request withdrawn.']
            : $this->fail($l, 'لا يوجد طلب قيد المراجعة بهذا الرقم.', 'No pending request found.', 404);
    }

    // ---------------------------------------------------------------- parties: approve / reject

    public function approve(array $scope, $requestId, ?string $note, string $l): array
    {
        return $this->decide($scope, $requestId, 'approved', $note, $l);
    }

    public function reject(array $scope, $requestId, ?string $note, string $l): array
    {
        return $this->decide($scope, $requestId, 'rejected', $note, $l);
    }

    private function decide(array $scope, $requestId, string $decision, ?string $note, string $l): array
    {
        if ($scope['role'] === 'student') {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        $row = $this->incomingQuery($scope)->where('r.id', (int) $requestId)->select('r.id')->first();
        if (!$row) {
            return $this->fail($l, 'الطلب غير موجود.', 'Request not found.', 404);
        }
        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($scope, $requestId, $decision, $note, $l) {
            $req = DB::table('course_enrollment_requests')->where('id', (int) $requestId)->lockForUpdate()->first();
            if (!$req || $req->status !== 'pending') {
                return $this->fail($l, 'تم البتّ في هذا الطلب بالفعل.', 'This request has already been handled.', 409);
            }
            $course = DB::table('courses')->where('id', $req->course_id)->first();
            $student = DB::table('students')->where('id', $req->student_id)->first();

            DB::table('course_enrollment_request_decisions')->updateOrInsert(
                ['request_id' => $req->id, 'user_id' => $scope['userId']],
                ['role' => $scope['role'], 'decision' => $decision, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]
            );

            if ($decision === 'approved') {
                if ($req->type === 'enroll') {
                    if ($course->status !== 'active') {
                        return $this->fail($l, 'المادة مؤرشفة ولا يمكن التسجيل فيها.', 'The course is archived; enrolment is closed.', 409);
                    }
                    if (!DB::table('course_enrollments')->where('course_id', $req->course_id)->where('student_id', $req->student_id)->exists()) {
                        DB::table('course_enrollments')->insert(['course_id' => $req->course_id, 'student_id' => $req->student_id, 'source' => 'request', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                } else {
                    DB::table('course_enrollments')->where('course_id', $req->course_id)->where('student_id', $req->student_id)->delete();
                }
                DB::table('course_enrollment_requests')->where('id', $req->id)->update([
                    'status' => 'approved', 'decided_by_user_id' => $scope['userId'], 'decided_by_role' => $scope['role'],
                    'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now(),
                ]);
                $this->notifyStudent($student, $course, $req, 'approved', $note, $l);
                return ['success' => true, 'message' => $this->ar($l) ? 'تمت الموافقة وتم تنفيذ الطلب فورًا.' : 'Approved — the change was applied immediately.'];
            }

            // Rejection: the request stays open for the other parties until all of them have rejected it.
            $parties = array_keys($this->recipients($course, $student->faculty_id ? (int) $student->faculty_id : null));
            $rejected = DB::table('course_enrollment_request_decisions')->where('request_id', $req->id)->where('decision', 'rejected')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $allRejected = !array_diff($parties, $rejected);
            if ($allRejected) {
                DB::table('course_enrollment_requests')->where('id', $req->id)->update([
                    'status' => 'rejected', 'decided_by_user_id' => $scope['userId'], 'decided_by_role' => $scope['role'],
                    'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now(),
                ]);
                $this->notifyStudent($student, $course, $req, 'rejected', $note, $l);
            }
            return ['success' => true, 'message' => $this->ar($l)
                ? ($allRejected ? 'تم رفض الطلب.' : 'تم تسجيل رفضك. يبقى الطلب مفتوحًا لباقي الجهات.')
                : ($allRejected ? 'Request rejected.' : 'Your rejection was recorded. The request stays open for the other parties.')];
        });
    }

    // ---------------------------------------------------------------- attachment

    /** @return array{path:string,name:string,mime:string}|null */
    public function attachmentFor(array $scope, $requestId): ?array
    {
        if ($scope['role'] === 'student') {
            $q = DB::table('course_enrollment_requests as r')->where('r.student_id', $scope['studentId']);
        } else {
            $q = $this->incomingQuery($scope);
        }
        $r = $q->where('r.id', (int) $requestId)->whereNotNull('r.attachment_path')->select('r.attachment_path', 'r.attachment_name', 'r.attachment_mime')->first();
        return $r ? ['path' => $r->attachment_path, 'name' => $r->attachment_name ?: 'attachment', 'mime' => $r->attachment_mime ?: 'application/octet-stream'] : null;
    }

    // ---------------------------------------------------------------- notifications

    private function notifyParties(object $course, int $studentFacultyId, int $studentUserId, int $requestId, string $type, string $l): void
    {
        try {
            $name = $course->code . ' — ' . ($course->name_ar ?: $course->name_en);
            $studentName = (string) DB::table('users')->where('id', $studentUserId)->value('full_name');
            $title = $type === 'enroll' ? 'طلب تسجيل في مادة' : 'طلب إلغاء تسجيل من مادة';
            $body = "الطالب {$studentName} — {$name}";
            foreach ($this->recipients($course, $studentFacultyId ?: null) as $uid => $role) {
                $link = match ($role) {
                    'university' => '/university/courses',
                    'faculty'    => '/faculty/courses',
                    default      => '/academic-staff/courses',
                };
                $this->notifications->notify($uid, 'course_request', $title, $body, $link . '?request=' . $requestId, 'normal');
            }
        } catch (\Throwable $e) {
            // notifications must never break the request itself
        }
    }

    private function notifyStudent(object $student, object $course, object $req, string $result, ?string $note, string $l): void
    {
        try {
            $name = $course->code . ' — ' . ($course->name_ar ?: $course->name_en);
            $what = $req->type === 'enroll' ? 'التسجيل في' : 'إلغاء التسجيل من';
            $title = $result === 'approved' ? "تمت الموافقة على طلب {$what} المادة" : "تم رفض طلب {$what} المادة";
            $this->notifications->notify((int) $student->user_id, 'course_request_result', $title, $name . ($note ? " — {$note}" : ''), '/student/courses', 'normal');
        } catch (\Throwable $e) {
        }
    }
}
