<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * Courses (المواد). One service for the four roles that touch them — `$scope` is built by
 * CourseApiController::scope():
 *   ['role' => university|faculty|academic_staff|student, 'userId', 'universityId',
 *    'facultyId' (faculty / staff / student), 'staffId' (staff), 'studentId' (student)]
 *
 * Who can do what
 *  - university : sees and manages every course of the university
 *  - faculty    : sees its own faculty's courses + university-wide ones; manages its own faculty's
 *  - doctor/TA  : sees the courses of their faculty; creates courses (auto-assigned to them);
 *                 manages the ones they teach or created (edit, roster, add/remove students)
 *  - student    : browses active courses of their faculty (+ university-wide) and enrols / drops
 */
class CourseService
{
    private function ar(string $locale): bool
    {
        return $locale === 'ar';
    }

    private function fail(string $locale, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $locale === 'ar' ? $ar : $en, 'code' => $code];
    }

    /** Base query: courses visible to the scope, with the denormalised bits the UI needs. */
    private function baseQuery(array $scope)
    {
        $q = DB::table('courses as c')
            ->leftJoin('faculties as f', 'f.id', '=', 'c.faculty_id')
            ->leftJoin('departments as d', 'd.id', '=', 'c.department_id')
            ->where('c.university_id', $scope['universityId'])
            ->select('c.*', 'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar', 'd.name_en as department_name_en', 'd.name_ar as department_name_ar')
            ->selectRaw('(SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) as students_count');

        switch ($scope['role']) {
            case 'faculty':
            case 'academic_staff':
                $q->where(function ($w) use ($scope) {
                    $w->whereNull('c.faculty_id')->orWhere('c.faculty_id', $scope['facultyId']);
                });
                break;
            case 'student':
                $q->where('c.status', 'active')->where(function ($w) use ($scope) {
                    $w->whereNull('c.faculty_id');
                    if ($scope['facultyId']) {
                        $w->orWhere('c.faculty_id', $scope['facultyId']);
                    }
                });
                break;
        }

        return $q;
    }

    private function staffRows(array $courseIds): array
    {
        if (!$courseIds) {
            return [];
        }
        $rows = DB::table('course_staff as cs')
            ->join('academic_staff as a', 'a.id', '=', 'cs.academic_staff_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('cs.course_id', $courseIds)
            ->select('cs.course_id', 'a.id', 'u.full_name')
            ->orderBy('u.full_name')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->course_id][] = ['id' => (int) $r->id, 'full_name' => $r->full_name];
        }
        return $out;
    }

    private function canManage(array $scope, $course, ?array $staffIds = null): bool
    {
        switch ($scope['role']) {
            case 'university':
                return true;
            case 'faculty':
                return $course->faculty_id !== null && (int) $course->faculty_id === (int) $scope['facultyId'];
            case 'academic_staff':
                if ($course->created_by_user_id && (int) $course->created_by_user_id === (int) $scope['userId']) {
                    return true;
                }
                $staffIds ??= DB::table('course_staff')->where('course_id', $course->id)->pluck('academic_staff_id')->map(fn ($v) => (int) $v)->all();
                return in_array((int) $scope['staffId'], $staffIds, true);
            default:
                return false;
        }
    }

    private function present($c, array $scope, array $staff, array $enrolled, string $locale): array
    {
        $staffIds = array_map(fn ($s) => $s['id'], $staff[$c->id] ?? []);
        return [
            'id'              => (int) $c->id,
            'code'            => $c->code,
            'name_en'         => $c->name_en,
            'name_ar'         => $c->name_ar,
            'name'            => $this->ar($locale) ? ($c->name_ar ?: $c->name_en) : $c->name_en,
            'description'     => $c->description,
            'credit_hours'    => $c->credit_hours !== null ? (int) $c->credit_hours : null,
            'academic_year'   => $c->academic_year !== null ? (int) $c->academic_year : null,
            'semester'        => $c->semester !== null ? (int) $c->semester : null,
            'status'          => $c->status,
            'faculty_id'      => $c->faculty_id ? (int) $c->faculty_id : null,
            'faculty_name'    => $this->ar($locale) ? ($c->faculty_name_ar ?: $c->faculty_name_en) : $c->faculty_name_en,
            'department_id'   => $c->department_id ? (int) $c->department_id : null,
            'department_name' => $this->ar($locale) ? ($c->department_name_ar ?: $c->department_name_en) : $c->department_name_en,
            'students_count'  => (int) $c->students_count,
            'staff'           => $staff[$c->id] ?? [],
            'can_manage'      => $this->canManage($scope, $c, $staffIds),
            'enrolled'        => isset($enrolled[$c->id]),
        ];
    }

    // ---------------------------------------------------------------- listing

    public function list(array $scope, array $f, string $locale): array
    {
        $q = $this->baseQuery($scope);

        if (($f['status'] ?? '') !== '') {
            $q->where('c.status', $f['status']);
        } elseif ($scope['role'] !== 'student') {
            $q->where('c.status', 'active');
        }
        if (!empty($f['faculty_id']) && in_array($scope['role'], ['university'], true)) {
            $q->where('c.faculty_id', (int) $f['faculty_id']);
        }
        if (!empty($f['mine']) && $scope['role'] === 'academic_staff') {
            $q->where(function ($w) use ($scope) {
                $w->where('c.created_by_user_id', $scope['userId'])
                  ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('course_staff as cs')->whereColumn('cs.course_id', 'c.id')->where('cs.academic_staff_id', $scope['staffId']));
            });
        }
        if (($needle = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('c.code', 'like', $like)->orWhere('c.name_en', 'like', $like)->orWhere('c.name_ar', 'like', $like);
            });
        }

        $rows = $q->orderBy('c.code')->limit(500)->get();
        $ids = $rows->pluck('id')->all();
        $staff = $this->staffRows($ids);
        $enrolled = [];
        if ($scope['role'] === 'student' && $ids) {
            $enrolled = array_flip(DB::table('course_enrollments')->where('student_id', $scope['studentId'])->whereIn('course_id', $ids)->pluck('course_id')->all());
        }

        $pending = $scope['role'] === 'student' && $ids ? app(CourseRequestService::class)->pendingByCourse((int) $scope['studentId'], $ids) : [];
        $out = [];
        foreach ($rows as $c) {
            $row = $this->present($c, $scope, $staff, $enrolled, $locale);
            $row['pending_request'] = $pending[(int) $c->id] ?? null;
            $out[] = $row;
        }
        if (!empty($f['enrolled_only'])) {
            $out = array_values(array_filter($out, fn ($r) => $r['enrolled']));
        }
        return $out;
    }

    public function find(array $scope, $id, string $locale): ?array
    {
        $c = $this->baseQuery($scope)->where('c.id', (int) $id)->first();
        if (!$c) {
            return null;
        }
        $staff = $this->staffRows([$c->id]);
        $enrolled = $scope['role'] === 'student'
            ? array_flip(DB::table('course_enrollments')->where('student_id', $scope['studentId'])->where('course_id', $c->id)->pluck('course_id')->all())
            : [];
        $row = $this->present($c, $scope, $staff, $enrolled, $locale);
        $row['pending_request'] = $scope['role'] === 'student' ? (app(CourseRequestService::class)->pendingByCourse((int) $scope['studentId'], [(int) $c->id])[(int) $c->id] ?? null) : null;
        return $row;
    }

    // ---------------------------------------------------------------- create / update

    /** Validates faculty/department against the scope; returns [facultyId, departmentId] or an error array. */
    private function placement(array $scope, array $d, string $locale, ?Course $existing = null)
    {
        $facultyId = array_key_exists('faculty_id', $d) ? ($d['faculty_id'] !== null && $d['faculty_id'] !== '' ? (int) $d['faculty_id'] : null) : ($existing?->faculty_id);
        if (in_array($scope['role'], ['faculty', 'academic_staff'], true)) {
            $facultyId = $scope['facultyId'] ? (int) $scope['facultyId'] : null; // locked to own faculty
            if (!$facultyId) {
                return $this->fail($locale, 'حسابك غير مرتبط بكلية.', 'Your account is not linked to a faculty.');
            }
        }
        if ($facultyId && !DB::table('faculties')->where('id', $facultyId)->where('university_id', $scope['universityId'])->exists()) {
            return $this->fail($locale, 'الكلية غير صحيحة.', 'Invalid faculty.');
        }

        $departmentId = array_key_exists('department_id', $d) ? ($d['department_id'] !== null && $d['department_id'] !== '' ? (int) $d['department_id'] : null) : ($existing?->department_id);
        if ($departmentId) {
            if (!$facultyId || !DB::table('departments')->whereNull('deleted_at')->where('id', $departmentId)->where('faculty_id', $facultyId)->exists()) {
                return $this->fail($locale, 'القسم لا يتبع الكلية المختارة.', 'The department does not belong to that faculty.');
            }
        }
        return [$facultyId, $departmentId];
    }

    public function create(array $scope, array $d, string $locale): array
    {
        if (!in_array($scope['role'], ['university', 'faculty', 'academic_staff'], true)) {
            return $this->fail($locale, 'غير مسموح.', 'Not allowed.', 403);
        }
        $p = $this->placement($scope, $d, $locale);
        if (isset($p['success'])) {
            return $p;
        }
        [$facultyId, $departmentId] = $p;

        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (DB::table('courses')->where('university_id', $scope['universityId'])->where('code', $code)->exists()) {
            return $this->fail($locale, 'كود المادة مستخدم بالفعل.', 'This course code is already in use.');
        }

        $course = DB::transaction(function () use ($scope, $d, $code, $facultyId, $departmentId) {
            $course = Course::create([
                'university_id'      => $scope['universityId'],
                'faculty_id'         => $facultyId,
                'department_id'      => $departmentId,
                'code'               => $code,
                'name_en'            => trim((string) $d['name_en']),
                'name_ar'            => isset($d['name_ar']) && trim((string) $d['name_ar']) !== '' ? trim((string) $d['name_ar']) : null,
                'description'        => $d['description'] ?? null,
                'credit_hours'       => $d['credit_hours'] ?? null,
                'academic_year'      => $d['academic_year'] ?? null,
                'semester'           => $d['semester'] ?? null,
                'status'             => 'active',
                'created_by_user_id' => $scope['userId'],
            ]);
            // A doctor who creates a course teaches it.
            if ($scope['role'] === 'academic_staff' && $scope['staffId']) {
                DB::table('course_staff')->insert(['course_id' => $course->id, 'academic_staff_id' => $scope['staffId'], 'created_at' => now(), 'updated_at' => now()]);
            }
            return $course;
        });

        return ['success' => true, 'id' => $course->id, 'message' => $this->ar($locale) ? 'تمت إضافة المادة.' : 'Course added.'];
    }

    /** نفس manageable() بس public — للـ gradebook. بترجع Course أو array فيها success=false. */
    public function manageableCourse(array $scope, $id, string $locale)
    {
        return $this->manageable($scope, $id, $locale);
    }

    private function manageable(array $scope, $id, string $locale)
    {
        $c = Course::where('university_id', $scope['universityId'])->find((int) $id);
        if (!$c) {
            return $this->fail($locale, 'المادة غير موجودة.', 'Course not found.', 404);
        }
        if (!$this->canManage($scope, $c)) {
            return $this->fail($locale, 'لا تملك صلاحية إدارة هذه المادة.', 'You cannot manage this course.', 403);
        }
        return $c;
    }

    public function update(array $scope, $id, array $d, string $locale): array
    {
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        $p = $this->placement($scope, $d, $locale, $c);
        if (isset($p['success'])) {
            return $p;
        }
        [$facultyId, $departmentId] = $p;

        $fill = ['faculty_id' => $facultyId, 'department_id' => $departmentId];
        if (array_key_exists('code', $d)) {
            $code = strtoupper(trim((string) $d['code']));
            if ($code !== $c->code && DB::table('courses')->where('university_id', $scope['universityId'])->where('code', $code)->where('id', '!=', $c->id)->exists()) {
                return $this->fail($locale, 'كود المادة مستخدم بالفعل.', 'This course code is already in use.');
            }
            $fill['code'] = $code;
        }
        foreach (['name_en', 'name_ar', 'description', 'credit_hours', 'academic_year', 'semester'] as $k) {
            if (array_key_exists($k, $d)) {
                $v = is_string($d[$k]) ? trim($d[$k]) : $d[$k];
                $fill[$k] = $v === '' ? null : $v;
            }
        }
        $c->fill($fill)->save();
        // keep exam subject labels in sync with the course name
        DB::table('exams')->where('course_id', $c->id)->update(['subject' => $c->name_en]);

        return ['success' => true, 'message' => $this->ar($locale) ? 'تم تحديث المادة.' : 'Course updated.'];
    }

    public function setStatus(array $scope, $id, string $status, string $locale): array
    {
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        $c->fill(['status' => $status])->save();
        return ['success' => true, 'message' => $status === 'archived'
            ? ($this->ar($locale) ? 'تمت أرشفة المادة. لن تظهر للطلاب.' : 'Course archived. Students can no longer see it.')
            : ($this->ar($locale) ? 'تمت إعادة تفعيل المادة.' : 'Course restored.')];
    }

    // ---------------------------------------------------------------- teaching staff

    public function assignStaff(array $scope, $id, $staffId, string $locale): array
    {
        if ($scope['role'] === 'academic_staff') {
            return $this->fail($locale, 'تعيين المدرّسين من صلاحية الكلية أو الجامعة.', 'Assigning teachers is done by the faculty or the university.', 403);
        }
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        $staff = DB::table('academic_staff')->where('id', (int) $staffId)->where('university_id', $scope['universityId'])->first();
        if (!$staff || ($c->faculty_id && $staff->faculty_id && (int) $staff->faculty_id !== (int) $c->faculty_id)) {
            return $this->fail($locale, 'عضو هيئة التدريس غير صالح لهذه المادة.', 'This staff member cannot teach this course.');
        }
        DB::table('course_staff')->updateOrInsert(
            ['course_id' => $c->id, 'academic_staff_id' => $staff->id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        return ['success' => true, 'message' => $this->ar($locale) ? 'تم تعيين المدرّس.' : 'Teacher assigned.'];
    }

    public function removeStaff(array $scope, $id, $staffId, string $locale): array
    {
        if ($scope['role'] === 'academic_staff') {
            return $this->fail($locale, 'غير مسموح.', 'Not allowed.', 403);
        }
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        DB::table('course_staff')->where('course_id', $c->id)->where('academic_staff_id', (int) $staffId)->delete();
        return ['success' => true, 'message' => $this->ar($locale) ? 'تمت إزالة المدرّس.' : 'Teacher removed.'];
    }

    // ---------------------------------------------------------------- roster

    public function roster(array $scope, $id, string $locale): array
    {
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        $rows = DB::table('course_enrollments as ce')
            ->join('students as s', 's.id', '=', 'ce.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('student_groups as g', 'g.id', '=', 's.group_id')
            ->where('ce.course_id', $c->id)
            ->select('s.id', 'u.full_name', 'u.email', 's.student_number', 's.faculty', 's.department', 's.academic_year', 'ce.source', 'ce.enrolled_at', 'g.name as group_name')
            ->orderBy('u.full_name')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return ['success' => true, 'data' => $rows];
    }

    /** Students that could be added by hand: same university (and faculty, when the course has one), not yet in. */
    public function candidates(array $scope, $id, ?string $q, string $locale): array
    {
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        $query = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.university_id', $scope['universityId'])
            ->whereNotExists(fn ($w) => $w->select(DB::raw(1))->from('course_enrollments as ce')->whereColumn('ce.student_id', 's.id')->where('ce.course_id', $c->id))
            ->select('s.id', 'u.full_name', 'u.email', 's.student_number', 's.faculty', 's.department', 's.academic_year');
        if ($c->faculty_id) {
            $query->where('s.faculty_id', $c->faculty_id);
        }
        if (($needle = trim((string) $q)) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';
            $query->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like)->orWhere('s.student_number', 'like', $like));
        }
        return ['success' => true, 'data' => $query->orderBy('u.full_name')->limit(50)->get()->map(fn ($r) => (array) $r)->all()];
    }

    public function addStudents(array $scope, $id, array $studentIds, string $locale): array
    {
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (!$ids) {
            return $this->fail($locale, 'اختر طالبًا واحدًا على الأقل.', 'Select at least one student.');
        }
        $valid = DB::table('students')->where('university_id', $scope['universityId'])->whereIn('id', $ids)
            ->when($c->faculty_id, fn ($q) => $q->where('faculty_id', $c->faculty_id))->pluck('id')->all();

        $added = 0;
        foreach ($valid as $sid) {
            $exists = DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', $sid)->exists();
            if (!$exists) {
                DB::table('course_enrollments')->insert(['course_id' => $c->id, 'student_id' => $sid, 'source' => 'staff', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $added++;
            }
        }
        return ['success' => true, 'added' => $added, 'message' => $this->ar($locale) ? "تمت إضافة {$added} طالب." : "{$added} student(s) added."];
    }

    public function removeStudent(array $scope, $id, $studentId, string $locale): array
    {
        $c = $this->manageable($scope, $id, $locale);
        if (is_array($c)) {
            return $c;
        }
        DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', (int) $studentId)->delete();
        return ['success' => true, 'message' => $this->ar($locale) ? 'تمت إزالة الطالب من المادة.' : 'Student removed from the course.'];
    }

    // ---------------------------------------------------------------- student self-service
    // Students no longer enrol / drop directly: see CourseRequestService (request -> approval by
    // the university / faculty / course staff, the first approval applies the change).
}
