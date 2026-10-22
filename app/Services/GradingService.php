<?php

namespace App\Services;

use App\Models\Course;
use App\Services\Export\PdfWriter;
use Illuminate\Support\Facades\DB;

/**
 * كشف الدرجات والـ GPA.
 *
 *  نظام التقديرات (حروف / نقاط / نسبة دنيا + سياسة الحرمان + الاعتماد):
 *    الأدمن → نظام global، الجامعة → نظام جامعتها، الكلية → نظام كليتها.
 *    الأولوية للمادة: نظام الكلية ← الجامعة ← الأدمن ← config/grading.php.
 *
 *  درجة المقرر (يحدّدها مديرو المادة: مدرّس / معيد / مشرف / كلية / جامعة):
 *    مجموع نقاط = أوزان الامتحانات (course_grade_items) + مكوّنات course_grade_components (تكليفات / حضور / درجات يدوية).
 *    النسبة = النقاط ÷ مجموع الأوزان × 100 → التقدير من النظام الفعّال.
 *    calculate → مسودة (مع إمكانية تعديل تقدير طالب بسبب) → publish → الطالب يشوفها وتدخل في الـ GPA.
 *    لو النظام بيطلب اعتماد: المدرّس/المعيد "يرفع للاعتماد" والكلية/الجامعة هي اللي تنشر.
 *    الطالب المحروم (حضور) بياخد تقدير الحرمان لو السياسة مفعّلة.
 *
 *  GPA = Σ(نقاط التقدير × الساعات المعتمدة) ÷ Σ الساعات — لكل فصل (سنة/ترم) وتراكمي. المواد بدون ساعات معتمدة بتتستبعد.
 *  نقاط التقدير بتتثبّت وقت الحساب، فتغيير النظام بعد كده مبيغيّرش نتايج منشورة إلا لو اتحسبت تاني.
 *
 * كل الدوال بترجّع array: ['success' => bool, 'message' => ..., 'code' => ..., 'data' => ...].
 */
class GradingService
{
    public const KINDS = ['assignment', 'attendance', 'manual'];
    public const LEVELS = ['global', 'university', 'faculty'];

    public function __construct(
        private CourseService $courses,
        private CourseGradebookService $gradebook,
        private CourseAttendanceService $attendance,
        private NotificationService $notifications,
        private AuditLogService $auditLog
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

    private function courseLabel(Course $c): string
    {
        return $c->code . ' — ' . ($c->name_ar ?: $c->name_en);
    }

    private function notifyUser($userId, string $title, ?string $body, string $link): void
    {
        try {
            if ($userId) {
                $this->notifications->notify((int) $userId, 'course_grades', $title, $body, $link, 'normal');
            }
        } catch (\Throwable $e) {
            // notifications are best-effort
        }
    }

    // ================================================================== grade scales

    /** @return array<string,mixed> */
    public function defaultScale(): array
    {
        return [
            'source'             => 'default',
            'scope_type'         => null,
            'scope_id'           => null,
            'name'               => null,
            'deprivation_fails'  => (bool) config('grading.deprivation_fails', true),
            'deprivation_letter' => (string) config('grading.deprivation_letter', 'DN'),
            'require_approval'   => (bool) config('grading.require_approval', false),
            'gpa_decimals'       => (int) config('grading.gpa_decimals', 2),
            'bands'              => $this->sortBands(array_map(fn ($b) => [
                'letter' => (string) $b['letter'], 'min_percent' => (float) $b['min_percent'],
                'points' => (float) $b['points'], 'is_fail' => (bool) $b['is_fail'],
            ], (array) config('grading.bands', []))),
        ];
    }

    private function sortBands(array $bands): array
    {
        usort($bands, fn ($a, $b) => $b['min_percent'] <=> $a['min_percent']);
        return array_values($bands);
    }

    private function loadScale(string $type, int $id): ?array
    {
        $s = DB::table('grade_scales')->where('scope_type', $type)->where('scope_id', $id)->first();
        if (!$s) {
            return null;
        }
        $bands = DB::table('grade_scale_bands')->where('scale_id', $s->id)->get()->map(fn ($b) => [
            'letter' => $b->letter, 'min_percent' => (float) $b->min_percent, 'points' => (float) $b->points, 'is_fail' => (bool) $b->is_fail,
        ])->all();
        if (!$bands) {
            return null;
        }
        return [
            'source'             => $type,
            'scope_type'         => $type,
            'scope_id'           => (int) $s->scope_id,
            'name'               => $s->name,
            'deprivation_fails'  => (bool) $s->deprivation_fails,
            'deprivation_letter' => (string) $s->deprivation_letter,
            'require_approval'   => (bool) $s->require_approval,
            'gpa_decimals'       => (int) $s->gpa_decimals,
            'bands'              => $this->sortBands($bands),
        ];
    }

    /** النظام الفعّال: الكلية ← الجامعة ← global ← الافتراضي. */
    public function effectiveScale(?int $facultyId, ?int $universityId): array
    {
        if ($facultyId && ($s = $this->loadScale('faculty', $facultyId))) {
            return $s;
        }
        if ($universityId && ($s = $this->loadScale('university', $universityId))) {
            return $s;
        }
        return $this->loadScale('global', 0) ?? $this->defaultScale();
    }

    public function scaleForCourse(Course $c): array
    {
        return $this->effectiveScale($c->faculty_id ? (int) $c->faculty_id : null, (int) $c->university_id);
    }

    /** النظام اللي يعدّله الدور ده + اللي هيورثه لو مسح نظامه. */
    public function scaleEditor(string $type, int $id, ?int $universityId = null): array
    {
        $own = $this->loadScale($type, $id);
        $inherited = match ($type) {
            'faculty'    => $this->effectiveScale(null, $universityId),
            'university' => $this->effectiveScale(null, null),
            default      => $this->defaultScale(),
        };
        return [
            'level'     => $type,
            'scope_id'  => $id,
            'own'       => $own,
            'effective' => $own ?? $inherited,
            'inherited' => $inherited,
            'defaults'  => $this->defaultScale(),
        ];
    }

    /** @param array{name?:?string,bands:array,deprivation_fails?:bool,deprivation_letter?:string,require_approval?:bool,gpa_decimals?:int} $d */
    public function saveScale(string $type, int $id, array $d, $userId, string $l): array
    {
        if (!in_array($type, self::LEVELS, true)) {
            return $this->fail($l, 'مستوى غير صحيح.', 'Invalid level.');
        }
        $bands = [];
        $letters = [];
        $mins = [];
        foreach ((array) ($d['bands'] ?? []) as $b) {
            $letter = trim((string) ($b['letter'] ?? ''));
            if ($letter === '' || mb_strlen($letter) > 8) {
                return $this->fail($l, 'كل تقدير لازم له رمز (حتى 8 حروف).', 'Every grade needs a letter (max 8 chars).');
            }
            if (!isset($b['min_percent'], $b['points']) || !is_numeric($b['min_percent']) || !is_numeric($b['points'])) {
                return $this->fail($l, 'ادخل النسبة الدنيا والنقاط لكل تقدير.', 'Enter min percent and points for every grade.');
            }
            $min = round((float) $b['min_percent'], 2);
            $pts = round((float) $b['points'], 2);
            if ($min < 0 || $min > 100 || $pts < 0 || $pts > 10) {
                return $this->fail($l, 'النسبة بين 0 و100 والنقاط بين 0 و10.', 'Percent must be 0–100 and points 0–10.');
            }
            $key = mb_strtolower($letter);
            if (isset($letters[$key]) || isset($mins[(string) $min])) {
                return $this->fail($l, 'رمز أو نسبة دنيا مكرّرة.', 'Duplicate letter or minimum percent.');
            }
            $letters[$key] = true;
            $mins[(string) $min] = true;
            $bands[] = ['letter' => $letter, 'min_percent' => $min, 'points' => $pts, 'is_fail' => !empty($b['is_fail'])];
        }
        if (count($bands) < 2 || count($bands) > 30) {
            return $this->fail($l, 'لازم من 2 إلى 30 تقدير.', 'Provide between 2 and 30 grades.');
        }
        if (!isset($mins['0'])) {
            return $this->fail($l, 'لازم يكون فيه تقدير نسبته الدنيا 0 (عشان كل درجة لها تقدير).', 'One grade must start at 0% so every score maps to a grade.');
        }
        $bands = $this->sortBands($bands);
        $depLetter = trim((string) ($d['deprivation_letter'] ?? 'DN'));
        if ($depLetter === '' || mb_strlen($depLetter) > 8) {
            return $this->fail($l, 'رمز الحرمان غير صالح.', 'Invalid deprivation letter.');
        }
        $dec = (int) ($d['gpa_decimals'] ?? 2);
        if ($dec < 0 || $dec > 4) {
            return $this->fail($l, 'خانات المعدل من 0 إلى 4.', 'GPA decimals must be 0–4.');
        }

        DB::transaction(function () use ($type, $id, $d, $bands, $depLetter, $dec, $userId) {
            $row = DB::table('grade_scales')->where('scope_type', $type)->where('scope_id', $id)->first();
            $vals = [
                'name' => isset($d['name']) && trim((string) $d['name']) !== '' ? mb_substr(trim((string) $d['name']), 0, 120) : null,
                'deprivation_fails' => array_key_exists('deprivation_fails', $d) ? (bool) $d['deprivation_fails'] : true,
                'deprivation_letter' => $depLetter,
                'require_approval' => !empty($d['require_approval']),
                'gpa_decimals' => $dec,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ];
            if ($row) {
                DB::table('grade_scales')->where('id', $row->id)->update($vals);
                $sid = (int) $row->id;
            } else {
                $sid = (int) DB::table('grade_scales')->insertGetId($vals + ['scope_type' => $type, 'scope_id' => $id, 'created_at' => now()]);
            }
            DB::table('grade_scale_bands')->where('scale_id', $sid)->delete();
            foreach ($bands as $i => $b) {
                DB::table('grade_scale_bands')->insert([
                    'scale_id' => $sid, 'letter' => $b['letter'], 'min_percent' => $b['min_percent'],
                    'points' => $b['points'], 'is_fail' => $b['is_fail'] ? 1 : 0, 'sort' => $i,
                ]);
            }
        });
        $this->auditLog->record($userId, 'grading.scale_saved', 'GradeScale', $id, null, ['level' => $type, 'bands' => count($bands)]);

        return $this->ok(null, $this->ar($l) ? 'تم حفظ نظام الدرجات.' : 'Grading scale saved.');
    }

    public function resetScale(string $type, int $id, $userId, string $l): array
    {
        $row = DB::table('grade_scales')->where('scope_type', $type)->where('scope_id', $id)->first();
        if ($row) {
            DB::table('grade_scale_bands')->where('scale_id', $row->id)->delete();
            DB::table('grade_scales')->where('id', $row->id)->delete();
            $this->auditLog->record($userId, 'grading.scale_reset', 'GradeScale', $id, null, ['level' => $type]);
        }
        return $this->ok(null, $this->ar($l) ? 'رجع للنظام الأعلى.' : 'Reverted to the inherited scale.');
    }

    /** @return array{letter:string,points:float,is_fail:bool} */
    public function bandFor(float $pct, array $scale): array
    {
        $pct = round($pct, 2);
        foreach ($scale['bands'] as $b) {
            if ($pct + 0.00001 >= $b['min_percent']) {
                return $b;
            }
        }
        return end($scale['bands']);
    }

    // ================================================================== course grading setup

    private function examWeight(Course $course): float
    {
        return (float) DB::table('course_grade_items as i')
            ->join('exams as e', function ($j) use ($course) {
                $j->on('e.id', '=', 'i.exam_id')->where('e.course_id', '=', $course->id);
            })
            ->where('i.course_id', $course->id)->sum('i.weight');
    }

    private function components(Course $course): array
    {
        return DB::table('course_grade_components')->where('course_id', $course->id)->orderBy('sort')->orderBy('id')->get()->all();
    }

    private function presentScale(array $s): array
    {
        return [
            'source' => $s['source'], 'name' => $s['name'], 'bands' => $s['bands'],
            'deprivation_fails' => $s['deprivation_fails'], 'deprivation_letter' => $s['deprivation_letter'],
            'require_approval' => $s['require_approval'], 'gpa_decimals' => $s['gpa_decimals'],
        ];
    }

    private function courseStatus(Course $course): string
    {
        $st = DB::table('course_final_grades')->where('course_id', $course->id)->pluck('status')->all();
        if (!$st) {
            return 'none';
        }
        if (in_array('draft', $st, true)) {
            return 'draft';
        }
        if (in_array('submitted', $st, true)) {
            return 'submitted';
        }
        return 'published';
    }

    /** إعداد التقييم للمقرر: المكوّنات + اللي ينفع يتضاف + النظام الفعّال. */
    public function setup(array $scope, $courseId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        return $this->ok($this->setupData($c, $scope));
    }

    private function setupData(Course $c, array $scope): array
    {
        $comps = $this->components($c);
        $usedAssign = array_map(fn ($x) => (int) $x->ref_id, array_filter($comps, fn ($x) => $x->kind === 'assignment'));
        $available = DB::table('course_assignments')->where('course_id', $c->id)
            ->when($usedAssign, fn ($q) => $q->whereNotIn('id', $usedAssign))
            ->orderBy('id')->get(['id', 'title', 'max_score', 'weight', 'status', 'due_at'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'title' => $a->title, 'max_score' => (float) $a->max_score, 'weight' => $a->weight !== null ? (float) $a->weight : null, 'status' => $a->status, 'due_at' => $a->due_at])->all();
        $assignTitles = DB::table('course_assignments')->where('course_id', $c->id)->pluck('title', 'id');
        $examW = $this->examWeight($c);
        $compW = array_sum(array_map(fn ($x) => (float) $x->weight, $comps));
        $scale = $this->scaleForCourse($c);

        return [
            'course' => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name_ar ?: $c->name_en, 'credit_hours' => $c->credit_hours !== null ? (int) $c->credit_hours : null],
            'components' => array_map(fn ($x) => [
                'id' => (int) $x->id, 'kind' => $x->kind, 'ref_id' => $x->ref_id ? (int) $x->ref_id : null,
                'title' => $x->title, 'weight' => (float) $x->weight, 'max_score' => $x->max_score !== null ? (float) $x->max_score : null,
                'assignment_exists' => $x->kind !== 'assignment' || isset($assignTitles[$x->ref_id]),
            ], $comps),
            'available_assignments' => $available,
            'exam_weight' => round($examW, 2),
            'component_weight' => round($compW, 2),
            'total_weight' => round($examW + $compW, 2),
            'status' => $this->courseStatus($c),
            'scale' => $this->presentScale($scale),
            'can_publish_directly' => !($scale['require_approval'] && $scope['role'] === 'academic_staff'),
        ];
    }

    /** يستبدل مكوّنات المقرر. @param array<int,array<string,mixed>> $rows */
    public function saveComponents(array $scope, $courseId, array $rows, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        if ($this->courseStatus($c) === 'published') {
            return $this->fail($l, 'النتيجة منشورة — الغي النشر الأول قبل تعديل المكوّنات.', 'Grades are published — unpublish before editing components.', 409);
        }
        $assignIds = DB::table('course_assignments')->where('course_id', $c->id)->pluck('title', 'id')->all();
        $existing = DB::table('course_grade_components')->where('course_id', $c->id)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $clean = [];
        $total = $this->examWeight($c);
        $seenAssign = [];
        $hasAttendance = false;
        foreach ($rows as $i => $r) {
            $kind = (string) ($r['kind'] ?? '');
            if (!in_array($kind, self::KINDS, true)) {
                return $this->fail($l, 'نوع مكوّن غير صحيح.', 'Invalid component type.');
            }
            $w = round((float) ($r['weight'] ?? 0), 2);
            if ($w <= 0 || $w > 100) {
                return $this->fail($l, 'الوزن لازم يكون بين 0 و100.', 'Each weight must be between 0 and 100.');
            }
            $title = trim((string) ($r['title'] ?? ''));
            $ref = null;
            $max = null;
            if ($kind === 'assignment') {
                $ref = (int) ($r['ref_id'] ?? 0);
                if (!isset($assignIds[$ref]) || isset($seenAssign[$ref])) {
                    return $this->fail($l, 'تكليف غير صالح أو مكرّر.', 'Invalid or duplicate assignment.');
                }
                $seenAssign[$ref] = true;
                $title = $title !== '' ? $title : $assignIds[$ref];
            } elseif ($kind === 'attendance') {
                if ($hasAttendance) {
                    return $this->fail($l, 'مكوّن الحضور يُضاف مرة واحدة.', 'Attendance can only be added once.');
                }
                $hasAttendance = true;
                $title = $title !== '' ? $title : ($this->ar($l) ? 'الحضور' : 'Attendance');
            } else {
                $max = round((float) ($r['max_score'] ?? 0), 2);
                if ($title === '') {
                    return $this->fail($l, 'اكتب اسم المكوّن اليدوي.', 'Enter a name for the manual component.');
                }
                if ($max <= 0) {
                    return $this->fail($l, 'الدرجة العظمى لازم أكبر من صفر.', 'Max score must be greater than zero.');
                }
            }
            $total += $w;
            $clean[] = [
                'id' => isset($r['id']) && in_array((int) $r['id'], $existing, true) ? (int) $r['id'] : null,
                'kind' => $kind, 'ref_id' => $ref, 'title' => mb_substr($title, 0, 200), 'weight' => $w, 'max_score' => $max, 'sort' => $i,
            ];
        }
        if ($total > 100.0001) {
            return $this->fail($l, 'مجموع الأوزان (امتحانات + مكوّنات) لا يزيد عن 100% — الحالي ' . round($total, 2) . '%.', 'Total weight (exams + components) cannot exceed 100% — currently ' . round($total, 2) . '%.');
        }

        DB::transaction(function () use ($c, $clean, $existing) {
            $keep = [];
            $now = now();
            foreach ($clean as $r) {
                $vals = ['kind' => $r['kind'], 'ref_id' => $r['ref_id'], 'title' => $r['title'], 'weight' => $r['weight'], 'max_score' => $r['max_score'], 'sort' => $r['sort'], 'updated_at' => $now];
                if ($r['id']) {
                    DB::table('course_grade_components')->where('id', $r['id'])->update($vals);
                    $keep[] = $r['id'];
                } else {
                    $keep[] = (int) DB::table('course_grade_components')->insertGetId($vals + ['course_id' => $c->id, 'created_at' => $now]);
                }
            }
            $drop = array_values(array_diff($existing, $keep));
            if ($drop) {
                DB::table('course_grade_component_scores')->whereIn('component_id', $drop)->delete();
                DB::table('course_grade_components')->whereIn('id', $drop)->delete();
            }
        });
        $this->auditLog->record($scope['userId'], 'course.grade_components_saved', 'Course', $c->id, null, ['components' => count($clean)]);

        return $this->ok($this->setupData($c, $scope), $this->ar($l) ? 'تم حفظ مكوّنات الدرجة.' : 'Grade components saved.');
    }

    // ------------------------------------------------------------------ manual scores

    public function manualScores(array $scope, $courseId, $componentId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $comp = DB::table('course_grade_components')->where('course_id', $c->id)->where('kind', 'manual')->find((int) $componentId);
        if (!$comp) {
            return $this->fail($l, 'المكوّن غير موجود.', 'Component not found.', 404);
        }
        $scores = DB::table('course_grade_component_scores')->where('component_id', $comp->id)->pluck('score', 'student_id');
        $rows = DB::table('course_enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('e.course_id', $c->id)->orderBy('u.full_name')
            ->get(['s.id', 's.student_number', 'u.full_name'])
            ->map(fn ($s) => [
                'student_id' => (int) $s->id, 'student_number' => $s->student_number, 'full_name' => $s->full_name,
                'score' => isset($scores[$s->id]) ? (float) $scores[$s->id] : null,
            ])->all();

        return $this->ok(['component' => ['id' => (int) $comp->id, 'title' => $comp->title, 'max_score' => (float) $comp->max_score, 'weight' => (float) $comp->weight], 'students' => $rows]);
    }

    /** @param array<int,array{student_id:int,score:mixed}> $scores */
    public function saveManualScores(array $scope, $courseId, $componentId, array $scores, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $comp = DB::table('course_grade_components')->where('course_id', $c->id)->where('kind', 'manual')->find((int) $componentId);
        if (!$comp) {
            return $this->fail($l, 'المكوّن غير موجود.', 'Component not found.', 404);
        }
        $enrolled = DB::table('course_enrollments')->where('course_id', $c->id)->pluck('student_id')->map(fn ($v) => (int) $v)->all();
        $max = (float) $comp->max_score;
        $clean = [];
        foreach ($scores as $r) {
            $sid = (int) ($r['student_id'] ?? 0);
            if (!in_array($sid, $enrolled, true)) {
                return $this->fail($l, 'طالب غير مسجّل في المادة.', 'A student is not enrolled in this course.');
            }
            $v = $r['score'] ?? null;
            if ($v === null || $v === '') {
                $clean[$sid] = null;
                continue;
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) {
                return $this->fail($l, 'الدرجة لازم بين 0 و' . $max . '.', 'Scores must be between 0 and ' . $max . '.');
            }
            $clean[$sid] = round((float) $v, 2);
        }
        DB::transaction(function () use ($comp, $clean, $scope) {
            foreach ($clean as $sid => $v) {
                if ($v === null) {
                    DB::table('course_grade_component_scores')->where('component_id', $comp->id)->where('student_id', $sid)->delete();
                    continue;
                }
                $exists = DB::table('course_grade_component_scores')->where('component_id', $comp->id)->where('student_id', $sid)->exists();
                $vals = ['score' => $v, 'entered_by_user_id' => $scope['userId'], 'updated_at' => now()];
                $exists
                    ? DB::table('course_grade_component_scores')->where('component_id', $comp->id)->where('student_id', $sid)->update($vals)
                    : DB::table('course_grade_component_scores')->insert($vals + ['component_id' => $comp->id, 'student_id' => $sid, 'created_at' => now()]);
            }
        });

        return $this->manualScores($scope, $courseId, $componentId, $l);
    }

    // ================================================================== calculation

    /** يحسب النتيجة النهائية لكل المسجّلين (مسودة). التعديلات اليدوية للتقدير بتتحافظ عليها. */
    public function calculate(array $scope, $courseId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        if ($this->courseStatus($c) === 'published') {
            return $this->fail($l, 'النتيجة منشورة — الغي النشر الأول قبل إعادة الحساب.', 'Grades are published — unpublish before recalculating.', 409);
        }
        $comps = $this->components($c);
        $gb = $this->gradebook->gradebook($c);
        $examW = (float) $gb['total_weight'];
        $compW = array_sum(array_map(fn ($x) => (float) $x->weight, $comps));
        $totalW = $examW + $compW;
        if ($totalW <= 0) {
            return $this->fail($l, 'حدّد أوزان الامتحانات أو مكوّنات الدرجة الأول.', 'Define exam weights or grade components first.');
        }

        $scale = $this->scaleForCourse($c);
        $this->attendance->sweep((int) $c->id);
        $studentIds = DB::table('course_enrollments')->where('course_id', $c->id)->pluck('student_id')->map(fn ($v) => (int) $v)->all();
        $counts = $this->attendance->counts((int) $c->id, $studentIds);

        $subs = [];
        $assignMeta = DB::table('course_assignments')->where('course_id', $c->id)->get(['id', 'max_score'])->keyBy('id');
        $refs = array_filter(array_map(fn ($x) => $x->kind === 'assignment' ? (int) $x->ref_id : null, $comps));
        if ($refs) {
            foreach (DB::table('assignment_submissions')->whereIn('assignment_id', $refs)->get(['assignment_id', 'student_id', 'status', 'score']) as $s) {
                $subs[(int) $s->assignment_id][(int) $s->student_id] = $s;
            }
        }
        $manual = [];
        $manualIds = array_map(fn ($x) => (int) $x->id, array_filter($comps, fn ($x) => $x->kind === 'manual'));
        if ($manualIds) {
            foreach (DB::table('course_grade_component_scores')->whereIn('component_id', $manualIds)->get(['component_id', 'student_id', 'score']) as $s) {
                $manual[(int) $s->component_id][(int) $s->student_id] = $s->score !== null ? (float) $s->score : null;
            }
        }

        $examRows = [];
        foreach ($gb['rows'] as $r) {
            $examRows[(int) $r['student_id']] = $r;
        }
        $existing = DB::table('course_final_grades')->where('course_id', $c->id)->get()->keyBy('student_id');
        $now = now();
        $count = 0;

        DB::transaction(function () use ($c, $studentIds, $gb, $examRows, $comps, $assignMeta, $subs, $manual, $counts, $scale, $totalW, $existing, $now, &$count) {
            foreach ($studentIds as $sid) {
                $parts = [];
                $earned = 0.0;
                $er = $examRows[$sid] ?? null;
                foreach ($gb['items'] as $it) {
                    $cell = $er['cells'][$it['exam_id']] ?? null;
                    $pts = (float) ($cell['points'] ?? 0);
                    $earned += $pts;
                    $parts[] = [
                        'type' => 'exam', 'id' => (int) $it['exam_id'], 'title' => $it['title'], 'weight' => (float) $it['weight'],
                        'points' => round($pts, 2), 'percentage' => $cell['percentage'] ?? null, 'status' => $cell['status'] ?? 'upcoming',
                    ];
                }
                foreach ($comps as $comp) {
                    $w = (float) $comp->weight;
                    $pct = null;
                    $status = 'missing';
                    if ($comp->kind === 'assignment') {
                        $meta = $assignMeta->get((int) $comp->ref_id);
                        $s = $subs[(int) $comp->ref_id][$sid] ?? null;
                        if (!$meta) {
                            $status = 'removed';
                        } elseif ($s && $s->status === 'graded' && $s->score !== null && (float) $meta->max_score > 0) {
                            $pct = (float) $s->score / (float) $meta->max_score * 100;
                            $status = 'graded';
                        } elseif ($s) {
                            $status = $s->status === 'returned' ? 'returned' : 'pending';
                        }
                    } elseif ($comp->kind === 'attendance') {
                        $cn = $counts[$sid] ?? null;
                        $counted = $cn ? $cn['total'] - $cn['excused'] : 0;
                        if ($counted > 0) {
                            $pct = ($cn['present'] + $cn['late']) / $counted * 100;
                            $status = 'graded';
                        } else {
                            $status = 'no_sessions';
                        }
                    } else {
                        $v = $manual[(int) $comp->id][$sid] ?? null;
                        if ($v !== null && (float) $comp->max_score > 0) {
                            $pct = $v / (float) $comp->max_score * 100;
                            $status = 'graded';
                        }
                    }
                    $pts = $pct !== null ? $pct * $w / 100 : 0.0;
                    $earned += $pts;
                    $parts[] = [
                        'type' => $comp->kind, 'id' => (int) $comp->id, 'title' => $comp->title, 'weight' => $w,
                        'points' => round($pts, 2), 'percentage' => $pct !== null ? round($pct, 2) : null, 'status' => $status,
                    ];
                }

                $percentage = round($earned / $totalW * 100, 2);
                $deprived = ($this->attendance->standing($counts[$sid] ?? null)['state'] ?? 'ok') === 'deprived';
                $old = $existing->get($sid);
                $keepOverride = $old && $old->overridden;
                if ($keepOverride) {
                    $letter = $old->letter; $gp = (float) $old->grade_points; $fail = (bool) $old->is_fail;
                } elseif ($deprived && $scale['deprivation_fails']) {
                    $letter = $scale['deprivation_letter']; $gp = 0.0; $fail = true;
                } else {
                    $b = $this->bandFor($percentage, $scale);
                    $letter = $b['letter']; $gp = (float) $b['points']; $fail = (bool) $b['is_fail'];
                }
                $vals = [
                    'points_earned' => round($earned, 2), 'points_total' => round($totalW, 2), 'percentage' => $percentage,
                    'letter' => $letter, 'grade_points' => $gp, 'is_fail' => $fail ? 1 : 0, 'deprived' => $deprived ? 1 : 0,
                    'overridden' => $keepOverride ? 1 : 0, 'override_reason' => $keepOverride ? $old->override_reason : null,
                    'status' => 'draft', 'breakdown' => json_encode($parts, JSON_UNESCAPED_UNICODE),
                    'calculated_at' => $now, 'updated_at' => $now, 'published_at' => null, 'published_by_user_id' => null,
                ];
                $old
                    ? DB::table('course_final_grades')->where('id', $old->id)->update($vals)
                    : DB::table('course_final_grades')->insert($vals + ['course_id' => $c->id, 'student_id' => $sid, 'created_at' => $now]);
                $count++;
            }
            // طلاب اتشالوا من المادة: امسح مسوداتهم.
            DB::table('course_final_grades')->where('course_id', $c->id)->whereNotIn('student_id', $studentIds ?: [0])->delete();
        });
        $this->auditLog->record($scope['userId'], 'course.grades_calculated', 'Course', $c->id, null, ['students' => $count]);

        return $this->sheet($scope, $courseId, $l, $this->ar($l) ? 'تم حساب النتائج (مسودة).' : 'Grades calculated (draft).');
    }

    // ------------------------------------------------------------------ sheet / override / publish

    public function sheet(array $scope, $courseId, string $l, string $msg = 'OK'): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        return $this->ok($this->sheetData($c, $scope), $msg);
    }

    private function sheetData(Course $c, array $scope): array
    {
        $scale = $this->scaleForCourse($c);
        $rows = DB::table('course_final_grades as g')
            ->join('students as s', 's.id', '=', 'g.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('g.course_id', $c->id)->orderBy('u.full_name')
            ->get(['g.*', 's.student_number', 'u.full_name'])
            ->map(fn ($g) => [
                'student_id' => (int) $g->student_id, 'student_number' => $g->student_number, 'full_name' => $g->full_name,
                'points_earned' => (float) $g->points_earned, 'points_total' => (float) $g->points_total, 'percentage' => (float) $g->percentage,
                'letter' => $g->letter, 'grade_points' => $g->grade_points !== null ? (float) $g->grade_points : null,
                'is_fail' => (bool) $g->is_fail, 'deprived' => (bool) $g->deprived, 'overridden' => (bool) $g->overridden,
                'override_reason' => $g->override_reason, 'status' => $g->status,
                'breakdown' => $g->breakdown ? json_decode($g->breakdown, true) : [],
            ])->all();

        $dist = [];
        foreach ($rows as $r) {
            $dist[$r['letter']] = ($dist[$r['letter']] ?? 0) + 1;
        }
        $n = count($rows);
        $enrolled = (int) DB::table('course_enrollments')->where('course_id', $c->id)->count();

        return [
            'course' => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name_ar ?: $c->name_en, 'credit_hours' => $c->credit_hours !== null ? (int) $c->credit_hours : null],
            'status' => $this->courseStatus($c),
            'scale' => $this->presentScale($scale),
            'can_publish_directly' => !($scale['require_approval'] && $scope['role'] === 'academic_staff'),
            'enrolled' => $enrolled,
            'rows' => $rows,
            'summary' => [
                'calculated' => $n,
                'average_pct' => $n ? round(array_sum(array_column($rows, 'percentage')) / $n, 2) : null,
                'highest_pct' => $n ? max(array_column($rows, 'percentage')) : null,
                'lowest_pct' => $n ? min(array_column($rows, 'percentage')) : null,
                'passed' => count(array_filter($rows, fn ($r) => !$r['is_fail'])),
                'failed' => count(array_filter($rows, fn ($r) => $r['is_fail'])),
                'deprived' => count(array_filter($rows, fn ($r) => $r['deprived'])),
                'distribution' => $dist,
            ],
        ];
    }

    /** تعديل تقدير طالب يدويًا (بسبب) أو إلغاء التعديل (clear). */
    public function override(array $scope, $courseId, $studentId, array $d, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $row = DB::table('course_final_grades')->where('course_id', $c->id)->where('student_id', (int) $studentId)->first();
        if (!$row) {
            return $this->fail($l, 'احسب النتائج الأول.', 'Calculate the grades first.', 404);
        }
        if ($row->status === 'published') {
            return $this->fail($l, 'النتيجة منشورة — الغي النشر الأول.', 'Grades are published — unpublish first.', 409);
        }
        $scale = $this->scaleForCourse($c);

        if (!empty($d['clear'])) {
            $deprived = (bool) $row->deprived;
            if ($deprived && $scale['deprivation_fails']) {
                $letter = $scale['deprivation_letter']; $gp = 0.0; $fail = true;
            } else {
                $b = $this->bandFor((float) $row->percentage, $scale);
                $letter = $b['letter']; $gp = (float) $b['points']; $fail = (bool) $b['is_fail'];
            }
            DB::table('course_final_grades')->where('id', $row->id)->update([
                'letter' => $letter, 'grade_points' => $gp, 'is_fail' => $fail ? 1 : 0, 'overridden' => 0, 'override_reason' => null, 'updated_at' => now(),
            ]);
            return $this->ok(null, $this->ar($l) ? 'اتلغى التعديل.' : 'Override removed.');
        }

        $letter = trim((string) ($d['letter'] ?? ''));
        $reason = trim((string) ($d['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            return $this->fail($l, 'اكتب سبب التعديل.', 'Enter a reason for the override.');
        }
        $band = null;
        foreach ($scale['bands'] as $b) {
            if (mb_strtolower($b['letter']) === mb_strtolower($letter)) {
                $band = $b;
            }
        }
        if ($band) {
            $set = ['letter' => $band['letter'], 'grade_points' => $band['points'], 'is_fail' => $band['is_fail'] ? 1 : 0];
        } elseif (mb_strtolower($letter) === mb_strtolower($scale['deprivation_letter'])) {
            $set = ['letter' => $scale['deprivation_letter'], 'grade_points' => 0, 'is_fail' => 1];
        } else {
            return $this->fail($l, 'التقدير مش موجود في نظام الدرجات.', 'That grade is not in the grading scale.');
        }
        DB::table('course_final_grades')->where('id', $row->id)->update($set + ['overridden' => 1, 'override_reason' => mb_substr($reason, 0, 500), 'updated_at' => now()]);
        $this->auditLog->record($scope['userId'], 'course.grade_overridden', 'Course', $c->id, ['letter' => $row->letter], ['student_id' => (int) $studentId, 'letter' => $set['letter'], 'reason' => $reason]);

        return $this->ok(null, $this->ar($l) ? 'تم تعديل التقدير.' : 'Grade overridden.');
    }

    /** نشر (أو رفع للاعتماد لو النظام بيطلب اعتماد والمستخدم مدرّس/معيد). */
    public function publish(array $scope, $courseId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $rows = DB::table('course_final_grades')->where('course_id', $c->id)->get();
        if ($rows->isEmpty()) {
            return $this->fail($l, 'احسب النتائج الأول.', 'Calculate the grades first.');
        }
        $enrolled = (int) DB::table('course_enrollments')->where('course_id', $c->id)->count();
        if ($rows->count() < $enrolled) {
            return $this->fail($l, 'فيه طلاب اتسجّلوا بعد آخر حساب — أعد الحساب الأول.', 'Some students enrolled after the last calculation — recalculate first.');
        }
        $scale = $this->scaleForCourse($c);

        if ($scale['require_approval'] && $scope['role'] === 'academic_staff') {
            DB::table('course_final_grades')->where('course_id', $c->id)->where('status', 'draft')->update(['status' => 'submitted', 'updated_at' => now()]);
            $this->auditLog->record($scope['userId'], 'course.grades_submitted', 'Course', $c->id, null, null);
            if ($c->faculty_id) {
                $fuid = DB::table('faculties')->where('id', $c->faculty_id)->value('user_id');
                $this->notifyUser($fuid, 'نتيجة مادة في انتظار اعتمادك', $this->courseLabel($c), '/faculty/grading');
            }
            return $this->ok($this->sheetData($c, $scope), $this->ar($l) ? 'تم رفع النتيجة للاعتماد.' : 'Submitted for approval.');
        }

        DB::table('course_final_grades')->where('course_id', $c->id)->update([
            'status' => 'published', 'published_at' => now(), 'published_by_user_id' => $scope['userId'], 'updated_at' => now(),
        ]);
        $this->auditLog->record($scope['userId'], 'course.grades_published', 'Course', $c->id, null, ['students' => $rows->count()]);
        $uids = DB::table('students')->whereIn('id', $rows->pluck('student_id')->all())->pluck('user_id');
        foreach ($uids as $uid) {
            $this->notifyUser($uid, 'نزلت نتيجتك: ' . $this->courseLabel($c), 'تقدر تشوف درجتك والمعدل التراكمي في كشف الدرجات.', '/student/grades');
        }

        return $this->ok($this->sheetData($c, $scope), $this->ar($l) ? 'تم نشر النتائج للطلاب.' : 'Grades published to students.');
    }

    public function unpublish(array $scope, $courseId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $scale = $this->scaleForCourse($c);
        $status = $this->courseStatus($c);
        if ($status === 'published' && $scale['require_approval'] && $scope['role'] === 'academic_staff') {
            return $this->fail($l, 'إلغاء نشر نتيجة معتمدة من صلاحية الكلية/الجامعة.', 'Only the faculty/university can unpublish an approved result.', 403);
        }
        DB::table('course_final_grades')->where('course_id', $c->id)->update(['status' => 'draft', 'published_at' => null, 'published_by_user_id' => null, 'updated_at' => now()]);
        $this->auditLog->record($scope['userId'], 'course.grades_unpublished', 'Course', $c->id, null, null);

        return $this->ok($this->sheetData($c, $scope), $this->ar($l) ? 'رجعت النتيجة مسودة.' : 'Reverted to draft.');
    }

    public function sheetCsv(array $scope, $courseId, string $l)
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $d = $this->sheetData($c, $scope);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Student No', 'Name', 'Points', 'Total', 'Percentage', 'Grade', 'Grade Points', 'Fail', 'Deprived', 'Overridden', 'Status']);
        foreach ($d['rows'] as $r) {
            fputcsv($out, [$r['student_number'], $r['full_name'], $r['points_earned'], $r['points_total'], $r['percentage'], $r['letter'], $r['grade_points'], $r['is_fail'] ? 'Y' : '', $r['deprived'] ? 'Y' : '', $r['overridden'] ? 'Y' : '', $r['status']]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->ok(['filename' => 'grades_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $c->code) . '.csv', 'content' => $csv]);
    }

    // ================================================================== transcript / GPA

    private function round(float $v, int $dec): float
    {
        return round($v, $dec);
    }

    /** كشف الدرجات والـ GPA للطالب (النتايج المنشورة بس). */
    public function transcript(array $scope, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        return $this->ok($this->transcriptData((int) $scope['studentId'], $l));
    }

    public function transcriptData(int $studentId, string $l): array
    {
        $st = DB::table('students as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('faculties as f', 'f.id', '=', 's.faculty_id')
            ->leftJoin('universities as un', 'un.id', '=', 's.university_id')
            ->where('s.id', $studentId)
            ->first(['s.id', 's.student_number', 's.faculty_id', 's.university_id', 'u.full_name', 'f.name_ar as fac_ar', 'f.name_en as fac_en', 'un.official_name_ar as uni_ar', 'un.official_name_en as uni_en']);
        $scale = $this->effectiveScale($st && $st->faculty_id ? (int) $st->faculty_id : null, $st && $st->university_id ? (int) $st->university_id : null);
        $dec = $scale['gpa_decimals'];

        $grades = DB::table('course_final_grades as g')
            ->join('courses as c', 'c.id', '=', 'g.course_id')
            ->where('g.student_id', $studentId)->where('g.status', 'published')
            ->get(['g.*', 'c.code', 'c.name_ar', 'c.name_en', 'c.credit_hours', 'c.academic_year', 'c.semester']);
        $publishedIds = $grades->pluck('course_id')->all();

        $terms = [];
        foreach ($grades as $g) {
            $key = ($g->academic_year ?? 0) . '-' . ($g->semester ?? 0);
            $terms[$key]['academic_year'] = $g->academic_year !== null ? (int) $g->academic_year : null;
            $terms[$key]['semester'] = $g->semester !== null ? (int) $g->semester : null;
            $hours = $g->credit_hours !== null ? (int) $g->credit_hours : 0;
            $terms[$key]['courses'][] = [
                'course_id' => (int) $g->course_id, 'code' => $g->code, 'name' => $this->ar($l) ? ($g->name_ar ?: $g->name_en) : ($g->name_en ?: $g->name_ar),
                'credit_hours' => $g->credit_hours !== null ? (int) $g->credit_hours : null,
                'percentage' => (float) $g->percentage, 'letter' => $g->letter, 'grade_points' => (float) $g->grade_points,
                'is_fail' => (bool) $g->is_fail, 'deprived' => (bool) $g->deprived, 'counted' => $hours > 0,
                'breakdown' => $g->breakdown ? json_decode($g->breakdown, true) : [],
            ];
        }
        uksort($terms, function ($a, $b) {
            [$ay, $as] = array_map('intval', explode('-', $a));
            [$by, $bs] = array_map('intval', explode('-', $b));
            $ay = $ay ?: PHP_INT_MAX; $by = $by ?: PHP_INT_MAX;
            return [$ay, $as] <=> [$by, $bs];
        });

        $cumQP = 0.0; $cumH = 0; $cumEarned = 0;
        $out = [];
        foreach ($terms as $t) {
            $qp = 0.0; $h = 0; $earned = 0;
            foreach ($t['courses'] as $c) {
                if (!$c['counted']) {
                    continue;
                }
                $qp += $c['grade_points'] * $c['credit_hours'];
                $h += $c['credit_hours'];
                if (!$c['is_fail']) {
                    $earned += $c['credit_hours'];
                }
            }
            $cumQP += $qp; $cumH += $h; $cumEarned += $earned;
            $out[] = $t + [
                'attempted_hours' => $h, 'earned_hours' => $earned,
                'gpa' => $h > 0 ? $this->round($qp / $h, $dec) : null,
                'cumulative_gpa' => $cumH > 0 ? $this->round($cumQP / $cumH, $dec) : null,
            ];
        }

        $inProgress = DB::table('course_enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $studentId)->where('c.status', 'active')
            ->when($publishedIds, fn ($q) => $q->whereNotIn('c.id', $publishedIds))
            ->get(['c.id', 'c.code', 'c.name_ar', 'c.name_en', 'c.credit_hours'])
            ->map(fn ($c) => ['course_id' => (int) $c->id, 'code' => $c->code, 'name' => $this->ar($l) ? ($c->name_ar ?: $c->name_en) : ($c->name_en ?: $c->name_ar), 'credit_hours' => $c->credit_hours !== null ? (int) $c->credit_hours : null])->all();

        return [
            'student' => $st ? [
                'full_name' => $st->full_name, 'student_number' => $st->student_number,
                'faculty' => $this->ar($l) ? ($st->fac_ar ?: $st->fac_en) : ($st->fac_en ?: $st->fac_ar),
                'university' => $this->ar($l) ? ($st->uni_ar ?: $st->uni_en) : ($st->uni_en ?: $st->uni_ar),
            ] : null,
            'terms' => $out,
            'cumulative' => [
                'gpa' => $cumH > 0 ? $this->round($cumQP / $cumH, $dec) : null,
                'attempted_hours' => $cumH, 'earned_hours' => $cumEarned,
                'courses' => array_sum(array_map(fn ($t) => count($t['courses']), $out)),
            ],
            'in_progress' => $inProgress,
            'scale' => $this->presentScale($scale),
        ];
    }

    /** يكتب كشف الدرجات PDF ويرجّع المسار. */
    public function transcriptPdf(array $scope, string $l): array
    {
        $res = $this->transcript($scope, $l);
        if (!$res['success']) {
            return $res;
        }
        $d = $res['data'];
        $ar = $this->ar($l);
        $header = $ar ? ['الفصل', 'الكود', 'المادة', 'الساعات', 'النسبة', 'التقدير', 'النقاط'] : ['Term', 'Code', 'Course', 'Hours', '%', 'Grade', 'Points'];
        $rows = [];
        foreach ($d['terms'] as $t) {
            $label = ($t['academic_year'] ? ($ar ? 'سنة ' : 'Year ') . $t['academic_year'] : '') . ($t['semester'] ? ($ar ? ' ترم ' : ' Sem ') . $t['semester'] : '');
            $label = trim($label) ?: ($ar ? 'أخرى' : 'Other');
            foreach ($t['courses'] as $c) {
                $rows[] = [$label, $c['code'], $c['name'], $c['credit_hours'] ?? '—', $c['percentage'], $c['letter'], number_format($c['grade_points'], 2)];
            }
            $rows[] = ['', '', ($ar ? 'معدل الفصل' : 'Term GPA') . ' / ' . ($ar ? 'التراكمي' : 'Cumulative'), $t['attempted_hours'], '', ($t['gpa'] ?? '—') . ' / ' . ($t['cumulative_gpa'] ?? '—'), ''];
        }
        $rows[] = ['', '', $ar ? 'المعدل التراكمي (GPA)' : 'Cumulative GPA', $d['cumulative']['attempted_hours'], '', $d['cumulative']['gpa'] ?? '—', ''];
        $rows[] = ['', '', $ar ? 'الساعات المنجزة' : 'Earned hours', $d['cumulative']['earned_hours'], '', '', ''];

        $dir = storage_path('app/tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/transcript_' . (int) $scope['studentId'] . '_' . date('Ymd_His') . '.pdf';
        $title = ($ar ? 'كشف الدرجات — ' : 'Transcript — ') . ($d['student']['full_name'] ?? '') . ' (' . ($d['student']['student_number'] ?? '') . ')';
        PdfWriter::writeTable($path, $title, $header, $rows, $ar);

        return $this->ok(['path' => $path, 'filename' => 'transcript.pdf']);
    }
}
