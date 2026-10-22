<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * جدول المحاضرات (course_sessions).
 *
 *  - الكتابة (إضافة / تعديل / حذف): مديرو المادة بس (مدرّس المادة، الكلية، الجامعة) — نفس CourseService::manageableCourse.
 *  - القراءة: مديرو المادة يشوفوا جدول موادهم، والطالب يشوف جدول المواد المسجّل فيها بس.
 *  - تعارض القاعة / عضو هيئة التدريس بيتمنع وقت الحفظ؛ تعارض مواعيد الطالب بيتعلّم في العرض (clashes_with).
 *
 * `$scope` هو نفس الـ array اللي CourseApiController::scope() بيبنيه.
 */
class CourseTimetableService
{
    public const KINDS = ['lecture', 'section', 'lab', 'tutorial'];

    public function __construct(
        private CourseService $courses,
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

    // ------------------------------------------------------------------ helpers

    /** "09:00:00" → "09:00" */
    private static function hm(?string $t): string
    {
        return substr((string) $t, 0, 5);
    }

    private static function minutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));
        return $h * 60 + $m;
    }

    private static function normRoom(?string $r): string
    {
        return mb_strtolower(trim((string) $r));
    }

    /** تداخل فترتين زمنيتين (لو أي طرف null = مفتوح). */
    private static function datesOverlap(?string $aFrom, ?string $aTo, ?string $bFrom, ?string $bTo): bool
    {
        $aFrom = $aFrom ?: '0000-01-01';
        $aTo = $aTo ?: '9999-12-31';
        $bFrom = $bFrom ?: '0000-01-01';
        $bTo = $bTo ?: '9999-12-31';
        return $aFrom <= $bTo && $bFrom <= $aTo;
    }

    private function courseName(object $c, string $l): string
    {
        return $this->ar($l) ? ($c->name_ar ?: $c->name_en) : ($c->name_en ?: (string) $c->name_ar);
    }

    private function present(object $s, array $staffNames, string $l, bool $canEdit, array $clashes = []): array
    {
        return [
            'id'            => (int) $s->id,
            'course_id'     => (int) $s->course_id,
            'course'        => [
                'id'   => (int) $s->course_id,
                'code' => $s->course_code,
                'name' => $this->courseName((object) ['name_ar' => $s->course_name_ar, 'name_en' => $s->course_name_en], $l),
            ],
            'kind'          => $s->kind,
            'day_of_week'   => (int) $s->day_of_week,
            'start_time'    => self::hm($s->start_time),
            'end_time'      => self::hm($s->end_time),
            'room'          => $s->room,
            'group_label'   => $s->group_label,
            'staff_id'      => $s->academic_staff_id ? (int) $s->academic_staff_id : null,
            'staff_name'    => $s->academic_staff_id ? ($staffNames[(int) $s->academic_staff_id] ?? null) : null,
            'starts_on'     => $s->starts_on,
            'ends_on'       => $s->ends_on,
            'notes'         => $s->notes,
            'can_edit'      => $canEdit,
            'clashes_with'  => $clashes,
        ];
    }

    private function baseSessions()
    {
        return DB::table('course_sessions as s')
            ->join('courses as c', 'c.id', '=', 's.course_id')
            ->select('s.*', 'c.code as course_code', 'c.name_en as course_name_en', 'c.name_ar as course_name_ar', 'c.faculty_id as course_faculty_id', 'c.created_by_user_id as course_creator')
            ->orderBy('s.day_of_week')->orderBy('s.start_time');
    }

    private function staffNames(array $staffIds): array
    {
        $staffIds = array_values(array_unique(array_filter($staffIds)));
        if (!$staffIds) {
            return [];
        }
        return DB::table('academic_staff as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.id', $staffIds)->pluck('u.full_name', 'a.id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();
    }

    // -------------------------------------------------------------------- reads

    /** الجدول الأسبوعي حسب دور المستخدم. $filters: course_id, day, kind. */
    public function timetable(array $scope, array $filters, string $l): array
    {
        $q = $this->baseSessions()->where('c.university_id', $scope['universityId']);
        $managedStaffCourseIds = null;

        switch ($scope['role']) {
            case 'student':
                $q->where('c.status', 'active')->whereIn('s.course_id', function ($sub) use ($scope) {
                    $sub->select('course_id')->from('course_enrollments')->where('student_id', $scope['studentId']);
                });
                break;
            case 'faculty':
                $q->where('c.faculty_id', $scope['facultyId']);
                break;
            case 'academic_staff':
                $managedStaffCourseIds = DB::table('course_staff')->where('academic_staff_id', $scope['staffId'])->pluck('course_id')->all();
                $q->where(function ($w) use ($scope, $managedStaffCourseIds) {
                    $w->whereIn('s.course_id', $managedStaffCourseIds)
                      ->orWhere('c.created_by_user_id', $scope['userId'])
                      ->orWhere('s.academic_staff_id', $scope['staffId']);
                });
                break;
            case 'university':
                break;
            default:
                return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }

        if (!empty($filters['course_id'])) {
            $q->where('s.course_id', (int) $filters['course_id']);
        }
        if (!empty($filters['day'])) {
            $q->where('s.day_of_week', (int) $filters['day']);
        }
        if (!empty($filters['kind']) && in_array($filters['kind'], self::KINDS, true)) {
            $q->where('s.kind', $filters['kind']);
        }

        $rows = $q->get();
        $names = $this->staffNames($rows->pluck('academic_staff_id')->map(fn ($v) => $v ? (int) $v : null)->all());

        $clashes = $scope['role'] === 'student' ? $this->studentClashes($rows->all()) : [];

        $data = [];
        foreach ($rows as $s) {
            $canEdit = $this->canEditRow($scope, $s, $managedStaffCourseIds);
            $data[] = $this->present($s, $names, $l, $canEdit, $clashes[(int) $s->id] ?? []);
        }

        return [
            'success' => true,
            'data'    => [
                'sessions'     => $data,
                'clash_count'  => count(array_filter($data, fn ($x) => !empty($x['clashes_with']))),
            ],
        ];
    }

    public function listForCourse(array $scope, $courseId, string $l): array
    {
        $access = $this->courseAccess($scope, $courseId, $l);
        if (isset($access['success'])) {
            return $access;
        }
        [$course, $canManage] = $access;
        $rows = $this->baseSessions()->where('s.course_id', $course->id)->get();
        $names = $this->staffNames($rows->pluck('academic_staff_id')->map(fn ($v) => $v ? (int) $v : null)->all());
        return [
            'success' => true,
            'data'    => $rows->map(fn ($s) => $this->present($s, $names, $l, $canManage))->values()->all(),
        ];
    }

    // ------------------------------------------------------------------- access

    /** @return array{0: Course, 1: bool}|array */
    private function courseAccess(array $scope, $courseId, string $l): array
    {
        if ($scope['role'] === 'student') {
            $c = Course::where('university_id', $scope['universityId'])->find((int) $courseId);
            if (!$c || $c->status !== 'active') {
                return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
            }
            $enrolled = DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', $scope['studentId'])->exists();
            if (!$enrolled) {
                return $this->fail($l, 'لازم تكون مسجّل في المادة عشان تشوف جدولها.', 'You must be enrolled in this course to see its timetable.', 403);
            }
            return [$c, false];
        }
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        return [$c, true];
    }

    private function canEditRow(array $scope, object $s, ?array $staffCourseIds): bool
    {
        switch ($scope['role']) {
            case 'university':
                return true;
            case 'faculty':
                return $s->course_faculty_id !== null && (int) $s->course_faculty_id === (int) $scope['facultyId'];
            case 'academic_staff':
                if ($s->course_creator && (int) $s->course_creator === (int) $scope['userId']) {
                    return true;
                }
                return in_array((int) $s->course_id, array_map('intval', $staffCourseIds ?? []), true);
        }
        return false;
    }

    // --------------------------------------------------------------- validation

    /** @return array|null  null لو سليم، أو array فيها success=false */
    private function validate(Course $course, array $d, string $l, ?int $ignoreId): ?array
    {
        $kind = $d['kind'];
        $day = (int) $d['day_of_week'];
        $start = self::hm($d['start_time']);
        $end = self::hm($d['end_time']);

        if (!in_array($kind, self::KINDS, true)) {
            return $this->fail($l, 'نوع الحصة غير صحيح.', 'Invalid session type.');
        }
        if ($day < 1 || $day > 7) {
            return $this->fail($l, 'اليوم غير صحيح.', 'Invalid day.');
        }
        if (self::minutes($end) <= self::minutes($start)) {
            return $this->fail($l, 'وقت النهاية لازم يكون بعد وقت البداية.', 'End time must be after start time.');
        }
        if (!empty($d['starts_on']) && !empty($d['ends_on']) && $d['ends_on'] < $d['starts_on']) {
            return $this->fail($l, 'تاريخ النهاية قبل تاريخ البداية.', 'End date is before the start date.');
        }

        $staffId = !empty($d['academic_staff_id']) ? (int) $d['academic_staff_id'] : null;
        if ($staffId && !DB::table('course_staff')->where('course_id', $course->id)->where('academic_staff_id', $staffId)->exists()) {
            return $this->fail($l, 'عضو هيئة التدريس مش مُعيَّن على المادة دي.', 'This staff member is not assigned to the course.');
        }

        // تعارض القاعة / عضو هيئة التدريس مع حصص تانية في نفس الجامعة.
        $others = DB::table('course_sessions as s')->join('courses as c', 'c.id', '=', 's.course_id')
            ->where('c.university_id', $course->university_id)->where('s.day_of_week', $day)
            ->when($ignoreId, fn ($q) => $q->where('s.id', '!=', $ignoreId))
            ->select('s.*', 'c.code as course_code')->get();

        $room = self::normRoom($d['room'] ?? null);
        foreach ($others as $o) {
            if (!(self::minutes($start) < self::minutes(self::hm($o->end_time)) && self::minutes($end) > self::minutes(self::hm($o->start_time)))) {
                continue;
            }
            if (!self::datesOverlap($d['starts_on'] ?? null, $d['ends_on'] ?? null, $o->starts_on, $o->ends_on)) {
                continue;
            }
            if ($room !== '' && $room === self::normRoom($o->room)) {
                return $this->fail(
                    $l,
                    "القاعة محجوزة في نفس الوقت لمادة {$o->course_code} ({$o->room}، " . self::hm($o->start_time) . '–' . self::hm($o->end_time) . ').',
                    "The room is already booked at that time by {$o->course_code} ({$o->room}, " . self::hm($o->start_time) . '–' . self::hm($o->end_time) . ').',
                    409
                );
            }
            if ($staffId && (int) $o->academic_staff_id === $staffId) {
                return $this->fail(
                    $l,
                    "عضو هيئة التدريس عنده حصة تانية في نفس الوقت ({$o->course_code}، " . self::hm($o->start_time) . '–' . self::hm($o->end_time) . ').',
                    "This staff member already teaches {$o->course_code} at that time (" . self::hm($o->start_time) . '–' . self::hm($o->end_time) . ').',
                    409
                );
            }
        }
        return null;
    }

    // ------------------------------------------------------------------- writes

    public function create(array $scope, $courseId, array $d, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $d = $this->clean($d);
        if ($bad = $this->validate($c, $d, $l, null)) {
            return $bad;
        }

        $id = DB::table('course_sessions')->insertGetId($d + [
            'course_id'          => $c->id,
            'created_by_user_id' => $scope['userId'],
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        $this->notifyStudents($c, 'حصة جديدة في جدول المحاضرات', $d);

        return ['success' => true, 'message' => $this->ar($l) ? 'تمت إضافة الحصة للجدول.' : 'Session added to the timetable.', 'data' => ['id' => $id]];
    }

    public function update(array $scope, $courseId, $sid, array $d, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $row = DB::table('course_sessions')->where('course_id', $c->id)->where('id', (int) $sid)->first();
        if (!$row) {
            return $this->fail($l, 'الحصة غير موجودة.', 'Session not found.', 404);
        }
        $merged = $this->clean(array_merge((array) $row, $d));
        if ($bad = $this->validate($c, $merged, $l, (int) $row->id)) {
            return $bad;
        }

        DB::table('course_sessions')->where('id', $row->id)->update($merged + ['updated_at' => now()]);
        $this->notifyStudents($c, 'تعديل في جدول المحاضرات', $merged);

        return ['success' => true, 'message' => $this->ar($l) ? 'تم تعديل الحصة.' : 'Session updated.', 'data' => ['id' => (int) $row->id]];
    }

    public function delete(array $scope, $courseId, $sid, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $row = DB::table('course_sessions')->where('course_id', $c->id)->where('id', (int) $sid)->first();
        if (!$row) {
            return $this->fail($l, 'الحصة غير موجودة.', 'Session not found.', 404);
        }
        DB::table('course_sessions')->where('id', $row->id)->delete();
        $this->notifyStudents($c, 'إلغاء حصة من جدول المحاضرات', (array) $row);

        return ['success' => true, 'message' => $this->ar($l) ? 'تم حذف الحصة.' : 'Session deleted.', 'data' => null];
    }

    /** يطبّع القيم اللي هتتخزّن (بدون الأعمدة اللي مش بتتعدّل). */
    private function clean(array $d): array
    {
        $str = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        return [
            'kind'              => (string) ($d['kind'] ?? 'lecture'),
            'day_of_week'       => (int) $d['day_of_week'],
            'start_time'        => self::hm($d['start_time']) . ':00',
            'end_time'          => self::hm($d['end_time']) . ':00',
            'room'              => $str($d['room'] ?? null),
            'group_label'       => $str($d['group_label'] ?? null),
            'academic_staff_id' => !empty($d['academic_staff_id']) ? (int) $d['academic_staff_id'] : null,
            'starts_on'         => $str($d['starts_on'] ?? null),
            'ends_on'           => $str($d['ends_on'] ?? null),
            'notes'             => $str($d['notes'] ?? null),
        ];
    }

    // ------------------------------------------------------------ student clash

    /** @param object[] $rows  @return array<int,int[]> session id => ids of sessions it overlaps with */
    private function studentClashes(array $rows): array
    {
        $out = [];
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $rows[$i];
                $b = $rows[$j];
                if ((int) $a->day_of_week !== (int) $b->day_of_week || (int) $a->course_id === (int) $b->course_id) {
                    continue;
                }
                if (self::minutes(self::hm($a->start_time)) < self::minutes(self::hm($b->end_time))
                    && self::minutes(self::hm($a->end_time)) > self::minutes(self::hm($b->start_time))
                    && self::datesOverlap($a->starts_on, $a->ends_on, $b->starts_on, $b->ends_on)) {
                    $out[(int) $a->id][] = (int) $b->id;
                    $out[(int) $b->id][] = (int) $a->id;
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ notifications

    private function notifyStudents(Course $course, string $title, array $d): void
    {
        try {
            $days = [1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت', 7 => 'الأحد'];
            $name = $course->code . ' — ' . ($course->name_ar ?: $course->name_en);
            $body = $name . ' · ' . ($days[(int) $d['day_of_week']] ?? '') . ' ' . self::hm($d['start_time']) . '–' . self::hm($d['end_time'])
                . (!empty($d['room']) ? ' · ' . $d['room'] : '');
            $userIds = DB::table('course_enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.course_id', $course->id)->whereNotNull('s.user_id')->pluck('s.user_id');
            foreach ($userIds as $uid) {
                $this->notifications->notify((int) $uid, 'course_timetable', $title, $body, '/student/timetable', 'normal');
            }
        } catch (\Throwable $e) {
            // الإشعارات ماينفعش تكسر حفظ الجدول نفسه
        }
    }
}
