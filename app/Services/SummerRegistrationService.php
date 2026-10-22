<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * التسجيل الصيفي: الأدمن (جامعة/كلية) بيفتح فصل صيفي ويعرض مواد بسعة، والطالب بيقدّم طلب، والكلية أو الجامعة بتوافق.
 * الموافقة بتسجّل الطالب في المادة (course_enrollments.source = summer) بعد إعادة فحص السعة.
 * `$scope` هو نفس مصفوفة CourseApiController::scope().
 */
class SummerRegistrationService
{
    public function __construct(
        private RegistrationService $registration,
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

    private function isManager(array $scope): bool
    {
        return ($scope['role'] ?? null) === 'university' || (($scope['role'] ?? null) === 'faculty' && !empty($scope['facultyId']));
    }

    private function name(object $c, string $l): string
    {
        return $this->ar($l) ? ($c->name_ar ?: $c->name_en) : ($c->name_en ?: $c->name_ar);
    }

    /** الفصول اللي الطرف ده بيديرها: الجامعة = كل فصول جامعتها، الكلية = فصول كليتها بس. */
    private function managedTerms(array $scope)
    {
        $q = DB::table('summer_terms')->where('university_id', $scope['universityId']);
        return ($scope['role'] === 'faculty') ? $q->where('faculty_id', (int) $scope['facultyId']) : $q;
    }

    private function approvedCount(int $termId, int $courseId): int
    {
        return (int) DB::table('summer_registrations')->where('term_id', $termId)->where('course_id', $courseId)->where('status', 'approved')->count();
    }

    // ---------------------------------------------------------------- management

    public function manage(array $scope, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $terms = $this->managedTerms($scope)->orderByDesc('opens_at')->get();
        $offerings = DB::table('summer_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')
            ->whereIn('o.term_id', $terms->pluck('id'))->get(['o.*', 'c.code', 'c.name_ar', 'c.name_en', 'c.credit_hours'])->groupBy('term_id');
        $out = $terms->map(function ($t) use ($offerings, $l) {
            $now = now()->toDateTimeString();
            return [
                'id' => (int) $t->id, 'name' => $t->name, 'faculty_id' => $t->faculty_id ? (int) $t->faculty_id : null,
                'opens_at' => $t->opens_at, 'closes_at' => $t->closes_at, 'starts_on' => $t->starts_on, 'ends_on' => $t->ends_on,
                'max_hours' => (int) $t->max_hours, 'only_failed' => (bool) $t->only_failed,
                'open' => $t->opens_at <= $now && $t->closes_at >= $now,
                'pending' => (int) DB::table('summer_registrations')->where('term_id', $t->id)->where('status', 'pending')->count(),
                'offerings' => collect($offerings->get($t->id, []))->map(fn ($o) => [
                    'course_id' => (int) $o->course_id, 'code' => $o->code, 'name' => $this->name($o, $l),
                    'credit_hours' => $o->credit_hours !== null ? (int) $o->credit_hours : null,
                    'capacity' => $o->capacity !== null ? (int) $o->capacity : null,
                    'approved' => $this->approvedCount((int) $t->id, (int) $o->course_id),
                ])->values()->all(),
            ];
        })->values()->all();
        return ['success' => true, 'message' => 'OK', 'data' => $out];
    }

    public function saveTerm(array $scope, ?int $termId, array $d, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        if (strtotime((string) $d['closes_at']) <= strtotime((string) $d['opens_at'])) {
            return $this->fail($l, 'وقت القفل لازم يكون بعد وقت الفتح.', 'The closing time must be after the opening time.');
        }
        if (!empty($d['starts_on']) && !empty($d['ends_on']) && $d['ends_on'] < $d['starts_on']) {
            return $this->fail($l, 'نهاية الفصل قبل بدايته.', 'The term ends before it starts.');
        }
        $row = [
            'name' => trim($d['name']), 'opens_at' => $d['opens_at'], 'closes_at' => $d['closes_at'],
            'starts_on' => $d['starts_on'] ?: null, 'ends_on' => $d['ends_on'] ?: null,
            'max_hours' => (int) ($d['max_hours'] ?? 9), 'only_failed' => (bool) ($d['only_failed'] ?? false), 'updated_at' => now(),
        ];
        if ($termId) {
            if (!$this->managedTerms($scope)->where('id', $termId)->exists()) {
                return $this->fail($l, 'الفصل غير موجود.', 'Term not found.', 404);
            }
            DB::table('summer_terms')->where('id', $termId)->update($row);
        } else {
            DB::table('summer_terms')->insert($row + [
                'university_id' => $scope['universityId'], 'faculty_id' => $scope['role'] === 'faculty' ? (int) $scope['facultyId'] : null,
                'created_by_user_id' => $scope['userId'], 'created_at' => now(),
            ]);
        }
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حفظ الفصل الصيفي.' : 'Summer term saved.'];
    }

    public function deleteTerm(array $scope, int $termId, string $l): array
    {
        if (!$this->isManager($scope) || !$this->managedTerms($scope)->where('id', $termId)->exists()) {
            return $this->fail($l, 'الفصل غير موجود.', 'Term not found.', 404);
        }
        if (DB::table('summer_registrations')->where('term_id', $termId)->where('status', 'approved')->exists()) {
            return $this->fail($l, 'مش ممكن تحذف فصل فيه تسجيلات معتمدة.', 'A term with approved registrations cannot be deleted.', 409);
        }
        DB::transaction(function () use ($termId) {
            DB::table('summer_registrations')->where('term_id', $termId)->delete();
            DB::table('summer_offerings')->where('term_id', $termId)->delete();
            DB::table('summer_terms')->where('id', $termId)->delete();
        });
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حذف الفصل.' : 'Term deleted.'];
    }

    /** @param array<int,array{course_id:int,capacity:?int}> $items استبدال كامل لمواد الفصل. */
    public function saveOfferings(array $scope, int $termId, array $items, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $term = $this->managedTerms($scope)->where('id', $termId)->first();
        if (!$term) {
            return $this->fail($l, 'الفصل غير موجود.', 'Term not found.', 404);
        }
        $clean = [];
        foreach ($items as $it) {
            $cid = (int) ($it['course_id'] ?? 0);
            if ($cid) {
                $clean[$cid] = isset($it['capacity']) && $it['capacity'] !== '' && $it['capacity'] !== null ? max(1, (int) $it['capacity']) : null;
            }
        }
        $courses = DB::table('courses')->whereIn('id', array_keys($clean))->where('university_id', $scope['universityId'])->get()->keyBy('id');
        if ($courses->count() !== count($clean)) {
            return $this->fail($l, 'مادة غير صالحة.', 'Invalid course.');
        }
        if ($term->faculty_id) {
            foreach ($courses as $c) {
                if ($c->faculty_id && (int) $c->faculty_id !== (int) $term->faculty_id) {
                    return $this->fail($l, 'مادة تابعة لكلية تانية.', 'A course belongs to another faculty.');
                }
            }
        }
        // مفيش تقليل سعة تحت عدد المعتمدين، ولا شيل مادة عليها تسجيلات معتمدة.
        foreach (DB::table('summer_offerings')->where('term_id', $termId)->get() as $o) {
            $ap = $this->approvedCount($termId, (int) $o->course_id);
            if (!array_key_exists((int) $o->course_id, $clean) && $ap > 0) {
                return $this->fail($l, 'مادة عليها تسجيلات معتمدة مش ممكن تتشال.', 'A course with approved registrations cannot be removed.', 409);
            }
            if (isset($clean[(int) $o->course_id]) && $clean[(int) $o->course_id] < $ap) {
                return $this->fail($l, 'السعة أقل من عدد المعتمدين.', 'Capacity is lower than the approved count.', 409);
            }
        }
        DB::transaction(function () use ($termId, $clean) {
            DB::table('summer_offerings')->where('term_id', $termId)->delete();
            foreach ($clean as $cid => $cap) {
                DB::table('summer_offerings')->insert(['term_id' => $termId, 'course_id' => $cid, 'capacity' => $cap, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حفظ المواد المعروضة.' : 'Offered courses saved.'];
    }

    // ---------------------------------------------------------------- requests inbox (manager)

    public function requests(array $scope, array $f, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $q = DB::table('summer_registrations as r')
            ->join('summer_terms as t', 't.id', '=', 'r.term_id')
            ->join('courses as c', 'c.id', '=', 'r.course_id')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('t.university_id', $scope['universityId']);
        if ($scope['role'] === 'faculty') {
            $q->where('s.faculty_id', (int) $scope['facultyId']);
        }
        if (!empty($f['status']) && in_array($f['status'], ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $q->where('r.status', $f['status']);
        }
        if (!empty($f['term_id'])) {
            $q->where('r.term_id', (int) $f['term_id']);
        }
        $rows = $q->orderByRaw("r.status = 'pending' desc")->orderByDesc('r.id')->limit(300)
            ->get(['r.*', 't.name as term_name', 'c.code', 'c.name_ar', 'c.name_en', 'c.credit_hours', 'u.full_name as student_name', 's.student_number']);
        return ['success' => true, 'message' => 'OK', 'data' => $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'status' => $r->status, 'term_id' => (int) $r->term_id, 'term' => $r->term_name,
            'course' => ['id' => (int) $r->course_id, 'code' => $r->code, 'name' => $this->name($r, $l), 'credit_hours' => $r->credit_hours !== null ? (int) $r->credit_hours : null],
            'student' => ['name' => $r->student_name, 'number' => $r->student_number],
            'message' => $r->message, 'decision_note' => $r->decision_note, 'created_at' => $r->created_at,
        ])->values()->all()];
    }

    public function approve(array $scope, $id, ?string $note, string $l): array
    {
        return $this->decide($scope, $id, 'approved', $note, $l);
    }

    public function reject(array $scope, $id, ?string $note, string $l): array
    {
        return $this->decide($scope, $id, 'rejected', $note, $l);
    }

    private function decide(array $scope, $id, string $decision, ?string $note, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($decision === 'rejected' && !$note) {
            return $this->fail($l, 'اكتب سبب الرفض.', 'Please give a reason for the rejection.');
        }
        $visible = DB::table('summer_registrations as r')->join('summer_terms as t', 't.id', '=', 'r.term_id')->join('students as s', 's.id', '=', 'r.student_id')
            ->where('r.id', (int) $id)->where('t.university_id', $scope['universityId']);
        if ($scope['role'] === 'faculty') {
            $visible->where('s.faculty_id', (int) $scope['facultyId']);
        }
        if (!$visible->exists()) {
            return $this->fail($l, 'الطلب غير موجود.', 'Request not found.', 404);
        }

        return DB::transaction(function () use ($scope, $id, $decision, $note, $l) {
            $r = DB::table('summer_registrations')->where('id', (int) $id)->lockForUpdate()->first();
            if (!$r || $r->status !== 'pending') {
                return $this->fail($l, 'تم البتّ في هذا الطلب بالفعل.', 'This request has already been handled.', 409);
            }
            if ($decision === 'approved') {
                $o = DB::table('summer_offerings')->where('term_id', $r->term_id)->where('course_id', $r->course_id)->lockForUpdate()->first();
                if (!$o) {
                    return $this->fail($l, 'المادة لم تعد معروضة.', 'The course is no longer offered.', 409);
                }
                if ($o->capacity !== null && $this->approvedCount((int) $r->term_id, (int) $r->course_id) >= (int) $o->capacity) {
                    return $this->fail($l, 'اكتملت السعة.', 'The course is full.', 409);
                }
                if (!DB::table('course_enrollments')->where('course_id', $r->course_id)->where('student_id', $r->student_id)->exists()) {
                    DB::table('course_enrollments')->insert(['course_id' => $r->course_id, 'student_id' => $r->student_id, 'source' => 'summer', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('summer_registrations')->where('id', $r->id)->update([
                'status' => $decision, 'decision_note' => $note, 'decided_by_user_id' => $scope['userId'], 'decided_by_role' => $scope['role'],
                'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->notifyStudent($r, $decision, $note);
            return ['success' => true, 'message' => $decision === 'approved'
                ? ($this->ar($l) ? 'تمت الموافقة وتسجيل الطالب في المادة.' : 'Approved — the student was enrolled.')
                : ($this->ar($l) ? 'تم رفض الطلب.' : 'Request rejected.')];
        });
    }

    // ---------------------------------------------------------------- student

    private function studentTerms(array $scope)
    {
        return DB::table('summer_terms')->where('university_id', $scope['universityId'])
            ->where(fn ($q) => $q->whereNull('faculty_id')->orWhere('faculty_id', $scope['facultyId']))
            ->where('closes_at', '>=', now()->subDays(60))
            ->orderByDesc('opens_at')->get();
    }

    /** @return array{ok:bool,reasons:array<int,string>} */
    private function eligibility(array $scope, object $term, object $offering, object $course, array $ctx, string $l): array
    {
        $reasons = [];
        $now = now()->toDateTimeString();
        if ($term->opens_at > $now) {
            $reasons[] = $this->ar($l) ? 'التسجيل لسه ما فتحش.' : 'Registration has not opened yet.';
        } elseif ($term->closes_at < $now) {
            $reasons[] = $this->ar($l) ? 'التسجيل اتقفل.' : 'Registration is closed.';
        }
        if (in_array((int) $course->id, $ctx['taken'], true)) {
            $reasons[] = $this->ar($l) ? 'مسجّل أو ناجح في المادة دي.' : 'Already enrolled in or passed this course.';
        }
        if ($term->only_failed && !in_array((int) $course->id, $ctx['failed'], true)) {
            $reasons[] = $this->ar($l) ? 'متاحة للمواد اللي رسبت فيها بس.' : 'Only for courses you failed.';
        }
        if ($ctx['missing'][(int) $course->id] ?? []) {
            $reasons[] = ($this->ar($l) ? 'متطلبات سابقة: ' : 'Prerequisites: ') . implode(', ', array_column($ctx['missing'][(int) $course->id], 'code'));
        }
        if ($course->credit_hours && $ctx['hours'][(int) $term->id] + (int) $course->credit_hours > (int) $term->max_hours) {
            $reasons[] = $this->ar($l) ? "تعدّيت الحد الأقصى للساعات ({$term->max_hours})." : "Over the credit-hour limit ({$term->max_hours}).";
        }
        if ($offering->capacity !== null && $this->approvedCount((int) $term->id, (int) $course->id) >= (int) $offering->capacity) {
            $reasons[] = $this->ar($l) ? 'اكتملت السعة.' : 'The course is full.';
        }
        return ['ok' => !$reasons, 'reasons' => $reasons];
    }

    public function status(array $scope, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $sid = (int) $scope['studentId'];
        $terms = $this->studentTerms($scope);
        $offerings = DB::table('summer_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')->whereIn('o.term_id', $terms->pluck('id'))
            ->where('c.status', 'active')->get(['o.*', 'c.id as cid', 'c.code', 'c.name_ar', 'c.name_en', 'c.credit_hours', 'c.status', 'c.university_id', 'c.faculty_id'])->groupBy('term_id');
        $mine = DB::table('summer_registrations')->where('student_id', $sid)->whereIn('term_id', $terms->pluck('id'))->get();

        $enrolled = DB::table('course_enrollments')->where('student_id', $sid)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
        $grades = DB::table('course_final_grades')->where('student_id', $sid)->where('status', 'published')->get(['course_id', 'is_fail', 'deprived']);
        $passed = $grades->filter(fn ($g) => !$g->is_fail && !$g->deprived)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
        $failed = $grades->filter(fn ($g) => $g->is_fail || $g->deprived)->pluck('course_id')->map(fn ($v) => (int) $v)->all();

        $out = $terms->map(function ($t) use ($offerings, $mine, $enrolled, $passed, $failed, $scope, $sid, $l) {
            $myT = $mine->where('term_id', $t->id);
            $active = $myT->whereIn('status', ['pending', 'approved']);
            $hours = (int) DB::table('courses')->whereIn('id', $active->pluck('course_id'))->sum('credit_hours');
            $ctx = ['taken' => array_merge(array_diff($enrolled, $failed), $passed), 'failed' => $failed, 'hours' => [(int) $t->id => $hours], 'missing' => []];
            $items = collect($offerings->get($t->id, []))->map(function ($o) use ($t, $myT, &$ctx, $scope, $sid, $l) {
                $course = (object) ['id' => $o->cid, 'credit_hours' => $o->credit_hours];
                $reg = $myT->whereIn('status', ['pending', 'approved'])->firstWhere('course_id', $o->cid);
                $ctx['missing'][(int) $o->cid] = $this->registration->missingPrerequisites((int) $o->cid, $sid, $l);
                $e = $reg ? ['ok' => false, 'reasons' => []] : $this->eligibility($scope, $t, $o, $course, $ctx, $l);
                return [
                    'course_id' => (int) $o->cid, 'code' => $o->code, 'name' => $this->name($o, $l),
                    'credit_hours' => $o->credit_hours !== null ? (int) $o->credit_hours : null,
                    'capacity' => $o->capacity !== null ? (int) $o->capacity : null,
                    'seats_left' => $o->capacity !== null ? max(0, (int) $o->capacity - $this->approvedCount((int) $t->id, (int) $o->cid)) : null,
                    'registration' => $reg ? ['id' => (int) $reg->id, 'status' => $reg->status] : null,
                    'eligible' => $e['ok'], 'reasons' => $e['reasons'],
                ];
            })->values()->all();
            $now = now()->toDateTimeString();
            return [
                'id' => (int) $t->id, 'name' => $t->name, 'opens_at' => $t->opens_at, 'closes_at' => $t->closes_at,
                'starts_on' => $t->starts_on, 'ends_on' => $t->ends_on, 'max_hours' => (int) $t->max_hours, 'only_failed' => (bool) $t->only_failed,
                'open' => $t->opens_at <= $now && $t->closes_at >= $now, 'hours_used' => $hours, 'offerings' => $items,
                'history' => $myT->where('status', 'rejected')->map(fn ($r) => ['course_id' => (int) $r->course_id, 'note' => $r->decision_note])->values()->all(),
            ];
        })->values()->all();

        return ['success' => true, 'message' => 'OK', 'data' => $out];
    }

    public function register(array $scope, $termId, $courseId, ?string $message, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $sid = (int) $scope['studentId'];
        $message = $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 2000) : null;
        $term = $this->studentTerms($scope)->firstWhere('id', (int) $termId);
        if (!$term) {
            return $this->fail($l, 'الفصل غير متاح.', 'Term not available.', 404);
        }
        $o = DB::table('summer_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')
            ->where('o.term_id', $term->id)->where('o.course_id', (int) $courseId)->where('c.status', 'active')
            ->first(['o.*', 'c.id as cid', 'c.credit_hours']);
        if (!$o) {
            return $this->fail($l, 'المادة غير معروضة.', 'The course is not offered.', 404);
        }

        return DB::transaction(function () use ($scope, $sid, $term, $o, $message, $l) {
            // قفل صف الطالب في الفصل عشان طلبين متزامنين ما يتخطوش حد الساعات.
            DB::table('students')->where('id', $sid)->lockForUpdate()->first();
            if (DB::table('summer_registrations')->where('term_id', $term->id)->where('course_id', $o->cid)->where('student_id', $sid)->whereIn('status', ['pending', 'approved'])->exists()) {
                return $this->fail($l, 'عندك طلب لهذه المادة بالفعل.', 'You already have a request for this course.', 409);
            }
            $enrolled = DB::table('course_enrollments')->where('student_id', $sid)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
            $grades = DB::table('course_final_grades')->where('student_id', $sid)->where('status', 'published')->get(['course_id', 'is_fail', 'deprived']);
            $passed = $grades->filter(fn ($g) => !$g->is_fail && !$g->deprived)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
            $failed = $grades->filter(fn ($g) => $g->is_fail || $g->deprived)->pluck('course_id')->map(fn ($v) => (int) $v)->all();
            $active = DB::table('summer_registrations')->where('term_id', $term->id)->where('student_id', $sid)->whereIn('status', ['pending', 'approved'])->pluck('course_id');
            $ctx = [
                'taken' => array_merge(array_diff($enrolled, $failed), $passed), 'failed' => $failed,
                'hours' => [(int) $term->id => (int) DB::table('courses')->whereIn('id', $active)->sum('credit_hours')],
                'missing' => [(int) $o->cid => $this->registration->missingPrerequisites((int) $o->cid, $sid, $l)],
            ];
            $e = $this->eligibility($scope, $term, $o, (object) ['id' => $o->cid, 'credit_hours' => $o->credit_hours], $ctx, $l);
            if (!$e['ok']) {
                return $this->fail($l, implode(' ', $e['reasons']), implode(' ', $e['reasons']));
            }
            $id = DB::table('summer_registrations')->insertGetId([
                'term_id' => $term->id, 'course_id' => $o->cid, 'student_id' => $sid, 'status' => 'pending', 'message' => $message,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->notifyManagers($term, (int) $scope['facultyId'], (int) $scope['userId'], $id);
            return ['success' => true, 'message' => $this->ar($l) ? 'تم إرسال طلب التسجيل الصيفي.' : 'Summer registration request sent.', 'data' => ['id' => $id, 'status' => 'pending']];
        });
    }

    public function cancel(array $scope, $id, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student') {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $n = DB::table('summer_registrations')->where('id', (int) $id)->where('student_id', $scope['studentId'])->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        return $n
            ? ['success' => true, 'message' => $this->ar($l) ? 'تم إلغاء الطلب.' : 'Request cancelled.']
            : $this->fail($l, 'الطلب غير موجود أو تم البتّ فيه.', 'Request not found or already handled.', 404);
    }

    // ---------------------------------------------------------------- notifications

    private function notifyManagers(object $term, int $studentFacultyId, int $studentUserId, int $id): void
    {
        try {
            $name = (string) DB::table('users')->where('id', $studentUserId)->value('full_name');
            $targets = [];
            if ($uid = DB::table('universities')->where('id', $term->university_id)->value('user_id')) {
                $targets[(int) $uid] = '/university/summer';
            }
            $fid = $term->faculty_id ?: $studentFacultyId;
            if ($fid && ($fu = DB::table('faculties')->where('id', $fid)->value('user_id'))) {
                $targets[(int) $fu] = '/faculty/summer';
            }
            foreach ($targets as $uid => $link) {
                $this->notifications->notify($uid, 'summer_request', 'طلب تسجيل صيفي جديد', "الطالب {$name} — {$term->name}", $link . '?request=' . $id, 'normal');
            }
        } catch (\Throwable $e) {
        }
    }

    private function notifyStudent(object $r, string $decision, ?string $note): void
    {
        try {
            $uid = (int) DB::table('students')->where('id', $r->student_id)->value('user_id');
            $course = DB::table('courses')->where('id', $r->course_id)->first();
            $title = $decision === 'approved' ? 'تمت الموافقة على تسجيلك الصيفي' : 'تم رفض طلب التسجيل الصيفي';
            $this->notifications->notify($uid, 'summer_result', $title, ($course ? $course->code . ' — ' . ($course->name_ar ?: $course->name_en) : '') . ($note ? " — {$note}" : ''), '/student/summer', 'normal');
        } catch (\Throwable $e) {
        }
    }
}
