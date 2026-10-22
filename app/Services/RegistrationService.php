<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * التسجيل الأكاديمي: فترات التسجيل، حد الساعات (حسب المعدل)، المتطلبات السابقة، وتعارض المواعيد.
 * السياسة الفعّالة: الكلية ← الجامعة ← config/registration.php (كل حقل لوحده، null = يورّث).
 * `$scope` هو نفس مصفوفة CourseApiController::scope().
 */
class RegistrationService
{
    private const FLAGS = ['enforce_period', 'enforce_prerequisites', 'enforce_hours', 'enforce_conflicts'];
    private const NUMS = ['max_hours', 'low_gpa_threshold', 'low_gpa_max_hours', 'high_gpa_threshold', 'high_gpa_max_hours'];

    public function __construct(private GradingService $grading)
    {
    }

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    // ---------------------------------------------------------------- policy

    public function effectivePolicy(?int $facultyId, ?int $universityId): array
    {
        $out = [];
        foreach (array_merge(self::FLAGS, self::NUMS) as $k) {
            $out[$k] = config('registration.' . $k);
        }
        $rows = [];
        if ($universityId) {
            $rows[] = DB::table('registration_policies')->where('scope_type', 'university')->where('scope_id', $universityId)->first();
        }
        if ($facultyId) {
            $rows[] = DB::table('registration_policies')->where('scope_type', 'faculty')->where('scope_id', $facultyId)->first();
        }
        foreach ($rows as $r) {
            if (!$r) {
                continue;
            }
            foreach (array_merge(self::FLAGS, self::NUMS) as $k) {
                if ($r->{$k} !== null) {
                    $out[$k] = in_array($k, self::FLAGS, true) ? (bool) $r->{$k} : $r->{$k} + 0;
                }
            }
        }
        foreach (self::FLAGS as $k) {
            $out[$k] = (bool) $out[$k];
        }
        return $out;
    }

    /** أقصى ساعات مسموحة حسب المعدل التراكمي (null = طالب جديد بدون معدل → الحد العادي). */
    public function maxHoursFor(array $policy, ?float $gpa): int
    {
        $max = (int) $policy['max_hours'];
        if ($gpa === null) {
            return $max;
        }
        if ($gpa < (float) $policy['low_gpa_threshold']) {
            return (int) $policy['low_gpa_max_hours'];
        }
        if ($gpa >= (float) $policy['high_gpa_threshold']) {
            return (int) $policy['high_gpa_max_hours'];
        }
        return $max;
    }

    // ---------------------------------------------------------------- periods

    /** @return array<int,object> الفترات المفتوحة الآن لطالب (جامعته وكليته) لنوع الطلب. */
    public function openPeriods(?int $facultyId, ?int $universityId, string $type): array
    {
        $now = now();
        return DB::table('registration_periods')
            ->where('opens_at', '<=', $now)->where('closes_at', '>=', $now)
            ->whereIn('kind', [$type, 'both'])
            ->where(function ($q) use ($facultyId, $universityId) {
                $q->where(fn ($w) => $w->where('scope_type', 'university')->where('scope_id', (int) $universityId));
                if ($facultyId) {
                    $q->orWhere(fn ($w) => $w->where('scope_type', 'faculty')->where('scope_id', $facultyId));
                }
            })->get()->all();
    }

    // ---------------------------------------------------------------- student state

    private function cumulativeGpa(int $studentId, string $l): ?float
    {
        $t = $this->grading->transcriptData($studentId, $l);
        return $t['cumulative']['gpa'] ?? null;
    }

    /** ساعات الطالب الحالية: المسجّل في مواد نشطة + طلبات التسجيل المعلّقة. */
    private function currentHours(int $studentId): array
    {
        $enrolled = (int) DB::table('course_enrollments as e')->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $studentId)->where('c.status', 'active')->sum('c.credit_hours');
        $pending = (int) DB::table('course_enrollment_requests as r')->join('courses as c', 'c.id', '=', 'r.course_id')
            ->where('r.student_id', $studentId)->where('r.status', 'pending')->where('r.type', 'enroll')->sum('c.credit_hours');
        return ['enrolled' => $enrolled, 'pending' => $pending];
    }

    /** @return array<int,int> مواد نجح فيها الطالب (نتيجة منشورة وغير راسب). */
    private function passedCourseIds(int $studentId): array
    {
        return DB::table('course_final_grades')->where('student_id', $studentId)->where('status', 'published')
            ->where('is_fail', false)->where('deprived', false)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<int,array{id:int,code:string,name:string}> متطلبات لسه ماتخلصتش. */
    public function missingPrerequisites(int $courseId, int $studentId, string $l): array
    {
        $passed = $this->passedCourseIds($studentId);
        return DB::table('course_prerequisites as p')->join('courses as c', 'c.id', '=', 'p.prerequisite_course_id')
            ->where('p.course_id', $courseId)
            ->get(['c.id', 'c.code', 'c.name_ar', 'c.name_en'])
            ->reject(fn ($c) => in_array((int) $c->id, $passed, true))
            ->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $this->ar($l) ? ($c->name_ar ?: $c->name_en) : ($c->name_en ?: $c->name_ar)])
            ->values()->all();
    }

    /** تعارض مواعيد: حصص المادة مقابل حصص مواد الطالب الحالية (نفس اليوم + تداخل وقت + تداخل تواريخ). */
    public function conflicts(int $courseId, int $studentId, string $l): array
    {
        $mine = DB::table('course_sessions as s')->join('courses as c', 'c.id', '=', 's.course_id')
            ->join('course_enrollments as e', 'e.course_id', '=', 's.course_id')
            ->where('e.student_id', $studentId)->where('c.status', 'active')->where('s.course_id', '!=', $courseId)
            ->get(['s.*', 'c.code', 'c.name_ar', 'c.name_en']);
        $target = DB::table('course_sessions')->where('course_id', $courseId)->get();
        $out = [];
        foreach ($target as $t) {
            foreach ($mine as $m) {
                if ((int) $t->day_of_week !== (int) $m->day_of_week) {
                    continue;
                }
                if ($t->start_time >= $m->end_time || $m->start_time >= $t->end_time) {
                    continue;
                }
                if ($t->starts_on && $m->ends_on && $t->starts_on > $m->ends_on) {
                    continue;
                }
                if ($m->starts_on && $t->ends_on && $m->starts_on > $t->ends_on) {
                    continue;
                }
                $out[(int) $m->course_id] = [
                    'course_id' => (int) $m->course_id, 'code' => $m->code,
                    'name' => $this->ar($l) ? ($m->name_ar ?: $m->name_en) : ($m->name_en ?: $m->name_ar),
                    'day_of_week' => (int) $t->day_of_week, 'start' => substr($t->start_time, 0, 5), 'end' => substr($t->end_time, 0, 5),
                ];
            }
        }
        return array_values($out);
    }

    // ---------------------------------------------------------------- eligibility

    /**
     * @return array{ok:bool,reasons:array<int,array{code:string,text:string}>}
     */
    public function eligibility(array $scope, object $course, string $type, string $l, ?array $ctx = null): array
    {
        $studentId = (int) $scope['studentId'];
        $policy = $ctx['policy'] ?? $this->effectivePolicy($scope['facultyId'] ?? null, $scope['universityId'] ?? null);
        $reasons = [];
        $add = fn (string $code, string $ar, string $en) => $reasons[] = ['code' => $code, 'text' => $this->ar($l) ? $ar : $en];

        if ($policy['enforce_period'] && !$this->openPeriods($scope['facultyId'] ?? null, $scope['universityId'] ?? null, $type)) {
            $add('period_closed', 'فترة التسجيل مقفولة حاليًا.', 'The registration period is currently closed.');
        }

        if ($type === 'enroll') {
            if ($policy['enforce_prerequisites']) {
                $missing = $ctx['missing'] ?? $this->missingPrerequisites((int) $course->id, $studentId, $l);
                if ($missing) {
                    $names = implode('، ', array_map(fn ($m) => $m['code'], $missing));
                    $add('prerequisites', 'لازم تنجح الأول في: ' . $names . '.', 'You must first pass: ' . implode(', ', array_map(fn ($m) => $m['code'], $missing)) . '.');
                }
            }
            if ($policy['enforce_hours'] && $course->credit_hours) {
                $gpa = $ctx['gpa'] ?? $this->cumulativeGpa($studentId, $l);
                $max = $this->maxHoursFor($policy, $gpa);
                $h = $ctx['hours'] ?? $this->currentHours($studentId);
                $total = $h['enrolled'] + $h['pending'] + (int) $course->credit_hours;
                if ($total > $max) {
                    $add('hours', "تعدّيت الحد الأقصى للساعات ($max) — ساعاتك مع المادة دي $total.", "Over the credit-hour limit ($max) — you would have $total hours.");
                }
            }
            if ($policy['enforce_conflicts'] && ($c = $this->conflicts((int) $course->id, $studentId, $l))) {
                $add('conflict', 'تعارض مواعيد مع: ' . implode('، ', array_column($c, 'code')) . '.', 'Schedule conflict with: ' . implode(', ', array_column($c, 'code')) . '.');
            }
        }
        return ['ok' => !$reasons, 'reasons' => $reasons];
    }

    /** يُستدعى من CourseRequestService::create — null لو مسموح، وإلا مصفوفة فشل. */
    public function checkRequest(array $scope, object $course, string $type, string $l): ?array
    {
        $e = $this->eligibility($scope, $course, $type, $l);
        if ($e['ok']) {
            return null;
        }
        return ['success' => false, 'message' => implode(' ', array_column($e['reasons'], 'text')), 'code' => 422];
    }

    // ---------------------------------------------------------------- student status + catalog

    public function status(array $scope, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $sid = (int) $scope['studentId'];
        $policy = $this->effectivePolicy($scope['facultyId'] ?? null, $scope['universityId'] ?? null);
        $gpa = $this->cumulativeGpa($sid, $l);
        $hours = $this->currentHours($sid);
        $periods = $this->openPeriods($scope['facultyId'] ?? null, $scope['universityId'] ?? null, 'enroll');
        $passed = $this->passedCourseIds($sid);

        $enrolledIds = DB::table('course_enrollments')->where('student_id', $sid)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
        $pendingIds = DB::table('course_enrollment_requests')->where('student_id', $sid)->where('status', 'pending')->pluck('course_id')->map(fn ($v) => (int) $v)->all();

        $courses = DB::table('courses')->where('university_id', $scope['universityId'])->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('faculty_id')->orWhere('faculty_id', $scope['facultyId']))
            ->orderBy('code')->get();
        $prereqs = DB::table('course_prerequisites as p')->join('courses as c', 'c.id', '=', 'p.prerequisite_course_id')
            ->whereIn('p.course_id', $courses->pluck('id'))->get(['p.course_id', 'c.id', 'c.code', 'c.name_ar', 'c.name_en'])->groupBy('course_id');

        $ctx = ['policy' => $policy, 'gpa' => $gpa, 'hours' => $hours];
        $catalog = $courses->map(function ($c) use ($scope, $l, $ctx, $prereqs, $passed, $enrolledIds, $pendingIds, $sid) {
            $enrolled = in_array((int) $c->id, $enrolledIds, true);
            $pending = in_array((int) $c->id, $pendingIds, true);
            $pre = collect($prereqs->get($c->id, []))->map(fn ($p) => [
                'id' => (int) $p->id, 'code' => $p->code,
                'name' => $this->ar($l) ? ($p->name_ar ?: $p->name_en) : ($p->name_en ?: $p->name_ar),
                'passed' => in_array((int) $p->id, $passed, true),
            ])->values()->all();
            $reasons = [];
            $eligible = false;
            if (!$enrolled && !$pending) {
                $ctx['missing'] = array_values(array_filter($pre, fn ($p) => !$p['passed']));
                $e = $this->eligibility($scope, $c, 'enroll', $l, $ctx);
                $eligible = $e['ok'];
                $reasons = $e['reasons'];
            }
            return [
                'id' => (int) $c->id, 'code' => $c->code,
                'name' => $this->ar($l) ? ($c->name_ar ?: $c->name_en) : ($c->name_en ?: $c->name_ar),
                'credit_hours' => $c->credit_hours !== null ? (int) $c->credit_hours : null,
                'academic_year' => $c->academic_year !== null ? (int) $c->academic_year : null,
                'semester' => $c->semester !== null ? (int) $c->semester : null,
                'enrolled' => $enrolled, 'pending' => $pending, 'eligible' => $eligible,
                'prerequisites' => $pre, 'reasons' => $reasons,
            ];
        })->values()->all();

        return ['success' => true, 'message' => 'OK', 'data' => [
            'period' => [
                'enforced' => $policy['enforce_period'], 'open' => (bool) $periods || !$policy['enforce_period'],
                'current' => array_map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'opens_at' => $p->opens_at, 'closes_at' => $p->closes_at], $periods),
            ],
            'hours' => $hours + ['max' => $this->maxHoursFor($policy, $gpa), 'gpa' => $gpa],
            'policy' => $policy,
            'catalog' => $catalog,
        ]];
    }

    // ---------------------------------------------------------------- management (university / faculty)

    /** مستوى الإدارة: university أو faculty فقط. @return array{0:string,1:int}|null */
    private function level(array $scope): ?array
    {
        if (($scope['role'] ?? null) === 'university') {
            return ['university', (int) $scope['universityId']];
        }
        if (($scope['role'] ?? null) === 'faculty' && !empty($scope['facultyId'])) {
            return ['faculty', (int) $scope['facultyId']];
        }
        return null;
    }

    public function manage(array $scope, string $l): array
    {
        $lv = $this->level($scope);
        if (!$lv) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        [$type, $id] = $lv;
        $row = DB::table('registration_policies')->where('scope_type', $type)->where('scope_id', $id)->first();
        $own = [];
        foreach (array_merge(self::FLAGS, self::NUMS) as $k) {
            $own[$k] = $row && $row->{$k} !== null ? (in_array($k, self::FLAGS, true) ? (bool) $row->{$k} : $row->{$k} + 0) : null;
        }
        $periods = DB::table('registration_periods')->where('scope_type', $type)->where('scope_id', $id)->orderByDesc('opens_at')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'kind' => $p->kind, 'opens_at' => $p->opens_at, 'closes_at' => $p->closes_at])->all();
        return ['success' => true, 'message' => 'OK', 'data' => [
            'level' => $type, 'own_policy' => $own,
            'effective_policy' => $this->effectivePolicy($type === 'faculty' ? $id : null, $type === 'university' ? $id : (int) $scope['universityId']),
            'periods' => $periods,
        ]];
    }

    public function savePolicy(array $scope, array $d, string $l): array
    {
        $lv = $this->level($scope);
        if (!$lv) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        [$type, $id] = $lv;
        $vals = [];
        foreach (self::FLAGS as $k) {
            if (array_key_exists($k, $d)) {
                $vals[$k] = $d[$k] === null ? null : (bool) $d[$k];
            }
        }
        foreach (self::NUMS as $k) {
            if (array_key_exists($k, $d)) {
                $vals[$k] = $d[$k] === null || $d[$k] === '' ? null : $d[$k];
            }
        }
        $now = now();
        $exists = DB::table('registration_policies')->where('scope_type', $type)->where('scope_id', $id)->exists();
        if ($exists) {
            DB::table('registration_policies')->where('scope_type', $type)->where('scope_id', $id)
                ->update($vals + ['updated_by_user_id' => $scope['userId'], 'updated_at' => $now]);
        } else {
            DB::table('registration_policies')->insert($vals + ['scope_type' => $type, 'scope_id' => $id, 'updated_by_user_id' => $scope['userId'], 'created_at' => $now, 'updated_at' => $now]);
        }
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حفظ سياسة التسجيل.' : 'Registration policy saved.'];
    }

    public function savePeriod(array $scope, ?int $periodId, array $d, string $l): array
    {
        $lv = $this->level($scope);
        if (!$lv) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        [$type, $id] = $lv;
        if (strtotime((string) $d['closes_at']) <= strtotime((string) $d['opens_at'])) {
            return $this->fail($l, 'وقت القفل لازم يكون بعد وقت الفتح.', 'The closing time must be after the opening time.');
        }
        $row = ['name' => trim($d['name']), 'kind' => $d['kind'] ?? 'both', 'opens_at' => $d['opens_at'], 'closes_at' => $d['closes_at'], 'updated_at' => now()];
        if ($periodId) {
            $n = DB::table('registration_periods')->where('id', $periodId)->where('scope_type', $type)->where('scope_id', $id)->update($row);
            if (!$n && !DB::table('registration_periods')->where('id', $periodId)->where('scope_type', $type)->where('scope_id', $id)->exists()) {
                return $this->fail($l, 'الفترة غير موجودة.', 'Period not found.', 404);
            }
        } else {
            DB::table('registration_periods')->insert($row + ['scope_type' => $type, 'scope_id' => $id, 'created_by_user_id' => $scope['userId'], 'created_at' => now()]);
        }
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حفظ فترة التسجيل.' : 'Registration period saved.'];
    }

    public function deletePeriod(array $scope, int $periodId, string $l): array
    {
        $lv = $this->level($scope);
        if (!$lv) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        [$type, $id] = $lv;
        $n = DB::table('registration_periods')->where('id', $periodId)->where('scope_type', $type)->where('scope_id', $id)->delete();
        if (!$n) {
            return $this->fail($l, 'الفترة غير موجودة.', 'Period not found.', 404);
        }
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حذف الفترة.' : 'Period deleted.'];
    }

    public function prerequisiteIds(array $scope, int $courseId, string $l): array
    {
        if (!$this->level($scope) && ($scope['role'] ?? null) !== 'academic_staff') {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        if (!DB::table('courses')->where('id', $courseId)->where('university_id', $scope['universityId'])->exists()) {
            return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
        }
        return ['success' => true, 'message' => 'OK', 'data' => ['course_id' => $courseId,
            'prerequisite_ids' => DB::table('course_prerequisites')->where('course_id', $courseId)->pluck('prerequisite_course_id')->map(fn ($v) => (int) $v)->all()]];
    }

    /** المتطلبات السابقة لمادة (للمديرين): استبدال كامل بقائمة ids من نفس الجامعة، بدون دوران ولا المادة نفسها. */
    public function savePrerequisites(array $scope, int $courseId, array $ids, string $l): array
    {
        if (!$this->level($scope) && ($scope['role'] ?? null) !== 'academic_staff') {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        $course = DB::table('courses')->where('id', $courseId)->where('university_id', $scope['universityId'])->first();
        if (!$course) {
            return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
        }
        if (($scope['role'] ?? null) === 'faculty' && $course->faculty_id && (int) $course->faculty_id !== (int) $scope['facultyId']) {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        if (($scope['role'] ?? null) === 'academic_staff'
            && !DB::table('course_staff')->where('course_id', $courseId)->where('academic_staff_id', $scope['staffId'])->exists()) {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (in_array($courseId, $ids, true)) {
            return $this->fail($l, 'المادة مش ممكن تكون متطلب لنفسها.', 'A course cannot be its own prerequisite.');
        }
        $valid = DB::table('courses')->whereIn('id', $ids)->where('university_id', $scope['universityId'])->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (count($valid) !== count($ids)) {
            return $this->fail($l, 'متطلب غير صالح.', 'Invalid prerequisite.');
        }
        // منع الدوران: أي متطلب لا يعتمد (مباشرة أو غير مباشرة) على هذه المادة.
        foreach ($ids as $pid) {
            if (in_array($courseId, $this->ancestors($pid), true)) {
                return $this->fail($l, 'ده هيعمل دورة في المتطلبات.', 'That would create a prerequisite cycle.');
            }
        }
        DB::transaction(function () use ($courseId, $ids) {
            DB::table('course_prerequisites')->where('course_id', $courseId)->delete();
            foreach ($ids as $pid) {
                DB::table('course_prerequisites')->insert(['course_id' => $courseId, 'prerequisite_course_id' => $pid, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return ['success' => true, 'message' => $this->ar($l) ? 'تم حفظ المتطلبات السابقة.' : 'Prerequisites saved.', 'data' => ['course_id' => $courseId, 'prerequisite_ids' => $ids]];
    }

    /** كل متطلبات المادة (مباشرة وغير مباشرة). */
    private function ancestors(int $courseId): array
    {
        $seen = [];
        $stack = [$courseId];
        while ($stack) {
            $cur = array_pop($stack);
            foreach (DB::table('course_prerequisites')->where('course_id', $cur)->pluck('prerequisite_course_id') as $p) {
                $p = (int) $p;
                if (!isset($seen[$p])) {
                    $seen[$p] = true;
                    $stack[] = $p;
                }
            }
        }
        return array_keys($seen);
    }
}
