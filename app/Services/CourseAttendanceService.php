<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الحضور والغياب بالـ QR + الموقع الجغرافي.
 *
 *  - الفتح / الإغلاق / التعديل اليدوي / التقارير: مديرو المادة بس (مدرّس المادة، الكلية، الجامعة) — CourseService::manageableCourse.
 *  - تسجيل الحضور: الطالب المسجّل في المادة بس، بمسح QR متغيّر + موقعه لازم يكون جوه نطاق الكلية (ومكان الدكتور لو اتحدد).
 *  - الـ QR: توكن HMAC بيتغيّر كل `qr_step_seconds`، والمقبول الحالي والسابق بس → صورة QR مسرّبة بتموت بسرعة،
 *    وحتى لو اتبعتت لحد بعيد، الجيوفنس هيرفضه.
 *  - جهاز واحد = طالب واحد في كل تحضير (device_hash).
 *  - الحرمان: نسبة الغياب = الغياب ÷ (الحصص المسجّلة بدون المعذور) — الحدود في config/attendance.php.
 *
 * كل الدوال بترجّع array: ['success' => bool, 'message' => ..., 'code' => ..., 'data' => ...].
 */
class CourseAttendanceService
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    public function __construct(
        private CourseService $courses,
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

    /** المسافة بالمتر بين نقطتين (Haversine). */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function cfg(string $k)
    {
        return config('attendance.' . $k);
    }

    // ------------------------------------------------------------------ QR token

    private function step(): int
    {
        return max(10, (int) $this->cfg('qr_step_seconds'));
    }

    private function sign(object $w, int $slot): string
    {
        return substr(hash_hmac('sha256', $w->id . '|' . $slot, $w->secret . '|' . config('app.key')), 0, 20);
    }

    private function tokenFor(object $w, ?int $now = null): string
    {
        $slot = intdiv($now ?? time(), $this->step());
        return 'att.' . $w->id . '.' . $slot . '.' . $this->sign($w, $slot);
    }

    /** @return int|null window id لو التوكن سليم ومش منتهي. */
    private function windowIdFromToken(string $token, ?object &$window): ?int
    {
        $window = null;
        $p = explode('.', trim($token));
        if (count($p) !== 4 || $p[0] !== 'att' || !ctype_digit($p[1]) || !ctype_digit($p[2])) {
            return null;
        }
        $w = DB::table('attendance_windows')->where('id', (int) $p[1])->first();
        if (!$w) {
            return null;
        }
        $slot = (int) $p[2];
        $cur = intdiv(time(), $this->step());
        if ($slot > $cur || $slot < $cur - 1) {
            return null;
        }
        if (!hash_equals($this->sign($w, $slot), $p[3])) {
            return null;
        }
        $window = $w;
        return (int) $w->id;
    }

    // ------------------------------------------------------------------ stats / sweeping

    /** بيقفل أي تحضير انتهى وقته (وبيسجّل الغياب). */
    public function sweep(?int $courseId = null): void
    {
        $q = DB::table('attendance_windows')->where('status', 'open')->where('closes_at', '<=', now());
        if ($courseId) {
            $q->where('course_id', $courseId);
        }
        foreach ($q->get() as $w) {
            $this->closeNow($w);
        }
    }

    /** @return array<int, array{total:int,present:int,late:int,absent:int,excused:int}> student_id => counts */
    private function counts(int $courseId, ?array $studentIds = null): array
    {
        $q = DB::table('attendance_records')->where('course_id', $courseId)
            ->select('student_id', 'status', DB::raw('COUNT(*) as n'))->groupBy('student_id', 'status');
        if ($studentIds !== null) {
            $q->whereIn('student_id', $studentIds ?: [0]);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $o = &$out[(int) $r->student_id];
            $o ??= ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
            $o[$r->status] = (int) $r->n;
            $o['total'] += (int) $r->n;
            unset($o);
        }
        return $out;
    }

    /** @return array{percent_absent: float, state: string, counted:int} */
    private function standing(?array $c): array
    {
        $c ??= ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
        $counted = $c['total'] - $c['excused'];
        $pct = $counted > 0 ? round($c['absent'] / $counted * 100, 1) : 0.0;
        $state = 'ok';
        if ($counted > 0 && $pct >= (float) $this->cfg('max_absence_percent')) {
            $state = 'deprived';
        } elseif ($counted > 0 && $pct >= (float) $this->cfg('warn_absence_percent')) {
            $state = 'warning';
        }
        return ['percent_absent' => $pct, 'percent_attended' => $counted > 0 ? round(100 - $pct, 1) : null, 'state' => $state, 'counted' => $counted];
    }

    private function enrolledStudents(int $courseId)
    {
        return DB::table('course_enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('e.course_id', $courseId)
            ->select('s.id', 's.user_id', 's.student_number', 'u.full_name')
            ->orderBy('u.full_name')->get();
    }

    private function courseLabel(Course $c): string
    {
        return $c->code . ' — ' . ($c->name_ar ?: $c->name_en);
    }

    // ------------------------------------------------------------------ windows (managers)

    private function managedWindow(array $scope, $wid, string $l)
    {
        $w = DB::table('attendance_windows')->where('id', (int) $wid)->first();
        if (!$w) {
            return $this->fail($l, 'التحضير غير موجود.', 'Attendance session not found.', 404);
        }
        $c = $this->courses->manageableCourse($scope, $w->course_id, $l);
        if (is_array($c)) {
            return $c;
        }
        return [$w, $c];
    }

    private function campusFor(Course $c, array $scope): ?object
    {
        $fid = $c->faculty_id ?: ($scope['facultyId'] ?? null);
        if (!$fid) {
            return null;
        }
        $f = DB::table('faculties')->where('id', $fid)->first();
        if (!$f || $f->campus_lat === null || $f->campus_lng === null) {
            return null;
        }
        return (object) [
            'lat' => (float) $f->campus_lat,
            'lng' => (float) $f->campus_lng,
            'radius' => (int) ($f->campus_radius_m ?: $this->cfg('default_campus_radius_m')),
        ];
    }

    /** فتح تحضير. $d: session_id?, minutes?, lat?, lng?, accuracy?, title? */
    public function open(array $scope, $courseId, array $d, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $this->sweep((int) $c->id);

        if (DB::table('attendance_windows')->where('course_id', $c->id)->where('status', 'open')->where('closes_at', '>', now())->exists()) {
            return $this->fail($l, 'فيه تحضير مفتوح بالفعل للمادة دي. اقفله الأول.', 'An attendance session is already open for this course.', 409);
        }

        $sessionId = null;
        if (!empty($d['session_id'])) {
            $sessionId = (int) $d['session_id'];
            if (!DB::table('course_sessions')->where('id', $sessionId)->where('course_id', $c->id)->exists()) {
                return $this->fail($l, 'الحصة دي مش تابعة للمادة.', 'That timetable session does not belong to this course.');
            }
        }

        $hasLoc = isset($d['lat'], $d['lng']) && $d['lat'] !== null && $d['lng'] !== null;
        $campus = $this->campusFor($c, $scope);
        if (!$hasLoc && !$campus) {
            return $this->fail(
                $l,
                'مفيش موقع مرجعي: فعّل الموقع على جهازك عشان نربط التحضير بمكانك، أو اطلب من الكلية تحديد موقعها.',
                'No reference location: allow location access so attendance can be tied to where you are, or ask the faculty to set its campus location.'
            );
        }
        if ($hasLoc) {
            $acc = isset($d['accuracy']) ? (int) round((float) $d['accuracy']) : null;
            if ($acc !== null && $acc > (int) $this->cfg('max_accuracy_m')) {
                return $this->fail($l, 'دقة الموقع ضعيفة (' . $acc . 'م). اطلع مكان مفتوح أو فعّل GPS وحاول تاني.', 'Location accuracy is too low (' . $acc . ' m). Try again with GPS on.');
            }
            if ($campus) {
                $dist = self::distanceM((float) $d['lat'], (float) $d['lng'], $campus->lat, $campus->lng);
                if ($dist > $campus->radius + 100) {
                    return $this->fail($l, 'مكانك الحالي بعيد عن الكلية (' . (int) $dist . 'م) — مينفعش تفتح تحضير من هنا.', 'You are too far from the campus (' . (int) $dist . ' m) to open attendance.', 403);
                }
            }
        }

        $minutes = (int) ($d['minutes'] ?? $this->cfg('default_minutes'));
        $minutes = max(1, min($minutes, (int) $this->cfg('max_minutes')));
        $now = now();
        $id = DB::table('attendance_windows')->insertGetId([
            'course_id'          => $c->id,
            'course_session_id'  => $sessionId,
            'opened_by_user_id'  => $scope['userId'],
            'secret'             => Str::random(48),
            'opens_at'           => $now,
            'closes_at'          => $now->copy()->addMinutes($minutes),
            'status'             => 'open',
            'lat'                => $hasLoc ? round((float) $d['lat'], 7) : null,
            'lng'                => $hasLoc ? round((float) $d['lng'], 7) : null,
            'accuracy_m'         => $hasLoc && isset($d['accuracy']) ? (int) round((float) $d['accuracy']) : null,
            'radius_m'           => $hasLoc ? (int) $this->cfg('doctor_radius_m') : null,
            'title'              => isset($d['title']) ? mb_substr(trim((string) $d['title']), 0, 200) : null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $this->notifyOpen($c, $id);
        return $this->detail($scope, $id, $l, 'تم فتح التحضير.');
    }

    private function notifyOpen(Course $c, int $wid): void
    {
        try {
            $userIds = DB::table('course_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.course_id', $c->id)->whereNotNull('s.user_id')->pluck('s.user_id');
            foreach ($userIds as $uid) {
                $this->notifications->notify((int) $uid, 'course_attendance', 'التحضير مفتوح: ' . $this->courseLabel($c), 'امسح الـ QR من الدكتور وانت داخل الكلية.', '/student/attendance', 'high');
            }
        } catch (\Throwable $e) {
            // الإشعار مش شرط لنجاح العملية.
        }
    }

    private function presentWindow(object $w, Course $c, array $extra = []): array
    {
        $open = $w->status === 'open' && strtotime((string) $w->closes_at) > time();
        return array_merge([
            'id'          => (int) $w->id,
            'course_id'   => (int) $w->course_id,
            'course'      => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name_ar ?: $c->name_en],
            'session_id'  => $w->course_session_id ? (int) $w->course_session_id : null,
            'title'       => $w->title,
            'status'      => $open ? 'open' : 'closed',
            'opens_at'    => $w->opens_at,
            'closes_at'   => $w->closes_at,
            'closed_at'   => $w->closed_at,
            'has_location' => $w->lat !== null,
            'radius_m'    => $w->radius_m ? (int) $w->radius_m : null,
        ], $extra);
    }

    public function detail(array $scope, $wid, string $l, string $msg = 'OK'): array
    {
        $m = $this->managedWindow($scope, $wid, $l);
        if (!is_array($m) || isset($m['success'])) {
            return $m;
        }
        [$w, $c] = $m;
        $this->sweep((int) $c->id);
        $w = DB::table('attendance_windows')->where('id', $w->id)->first();

        $recs = DB::table('attendance_records')->where('window_id', $w->id)->get()->keyBy('student_id');
        $rows = [];
        foreach ($this->enrolledStudents((int) $c->id) as $s) {
            $r = $recs->get($s->id);
            $rows[] = [
                'student_id'     => (int) $s->id,
                'student_number' => $s->student_number,
                'full_name'      => $s->full_name,
                'status'         => $r->status ?? null,           // null = لسه ما حضرش (التحضير مفتوح)
                'method'         => $r->method ?? null,
                'checked_at'     => $r->checked_at ?? null,
                'distance_m'     => $r && $r->distance_campus_m !== null ? (int) $r->distance_campus_m : ($r && $r->distance_doctor_m !== null ? (int) $r->distance_doctor_m : null),
            ];
        }
        $summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0, 'total' => count($rows)];
        foreach ($rows as $r) {
            $summary[$r['status'] ?? 'pending']++;
        }
        return $this->ok($this->presentWindow($w, $c, ['summary' => $summary, 'students' => $rows]), $msg);
    }

    public function listWindows(array $scope, $courseId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $this->sweep((int) $c->id);
        $counts = DB::table('attendance_records')->where('course_id', $c->id)
            ->select('window_id', 'status', DB::raw('COUNT(*) as n'))->groupBy('window_id', 'status')->get();
        $by = [];
        foreach ($counts as $r) {
            $by[(int) $r->window_id][$r->status] = (int) $r->n;
        }
        $data = [];
        foreach (DB::table('attendance_windows')->where('course_id', $c->id)->orderByDesc('id')->limit(100)->get() as $w) {
            $data[] = $this->presentWindow($w, $c, ['summary' => $by[(int) $w->id] ?? []]);
        }
        return $this->ok($data);
    }

    /** توكن الـ QR الحالي (الدكتور بيسحبه كل كام ثانية ويعرضه). */
    public function qr(array $scope, $wid, string $l): array
    {
        $m = $this->managedWindow($scope, $wid, $l);
        if (!is_array($m) || isset($m['success'])) {
            return $m;
        }
        [$w] = $m;
        if ($w->status !== 'open' || strtotime((string) $w->closes_at) <= time()) {
            $this->sweep((int) $w->course_id);
            return $this->fail($l, 'التحضير مقفول.', 'Attendance is closed.', 410);
        }
        $step = $this->step();
        return $this->ok([
            'token'      => $this->tokenFor($w),
            'expires_in' => $step - (time() % $step),
            'step'       => $step,
            'closes_at'  => $w->closes_at,
            'checked_in' => (int) DB::table('attendance_records')->where('window_id', $w->id)->whereIn('status', ['present', 'late'])->count(),
        ]);
    }

    public function close(array $scope, $wid, string $l): array
    {
        $m = $this->managedWindow($scope, $wid, $l);
        if (!is_array($m) || isset($m['success'])) {
            return $m;
        }
        [$w] = $m;
        if ($w->status === 'open') {
            $this->closeNow($w);
        }
        return $this->detail($scope, $wid, $l, 'تم قفل التحضير وتسجيل الغياب.');
    }

    /** قفل + تسجيل الغياب لكل المسجّلين اللي ملهمش سجل + تنبيه اللي عدّوا حد التحذير. */
    private function closeNow(object $w): void
    {
        $affected = DB::table('attendance_windows')->where('id', $w->id)->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
        if (!$affected) {
            return;
        }
        $enrolled = DB::table('course_enrollments')->where('course_id', $w->course_id)->pluck('student_id')->map(fn ($v) => (int) $v)->all();
        $have = DB::table('attendance_records')->where('window_id', $w->id)->pluck('student_id')->map(fn ($v) => (int) $v)->all();
        $missing = array_values(array_diff($enrolled, $have));
        $now = now();
        foreach (array_chunk($missing, 500) as $chunk) {
            DB::table('attendance_records')->insertOrIgnore(array_map(fn ($sid) => [
                'window_id' => $w->id, 'course_id' => $w->course_id, 'student_id' => $sid,
                'status' => 'absent', 'method' => 'auto', 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        if ($missing) {
            $this->warnStudents((int) $w->course_id, $missing);
        }
    }

    private function warnStudents(int $courseId, array $studentIds): void
    {
        try {
            $course = Course::find($courseId);
            if (!$course) {
                return;
            }
            $counts = $this->counts($courseId, $studentIds);
            $users = DB::table('students')->whereIn('id', $studentIds)->pluck('user_id', 'id');
            foreach ($studentIds as $sid) {
                $st = $this->standing($counts[$sid] ?? null);
                $uid = $users[$sid] ?? null;
                if (!$uid || $st['state'] === 'ok') {
                    continue;
                }
                $deprived = $st['state'] === 'deprived';
                $this->notifications->notify(
                    (int) $uid,
                    'course_attendance',
                    ($deprived ? 'إنذار حرمان: ' : 'تنبيه غياب: ') . $this->courseLabel($course),
                    'نسبة غيابك ' . $st['percent_absent'] . '% (الحد المسموح ' . $this->cfg('max_absence_percent') . '%).',
                    '/student/attendance',
                    $deprived ? 'high' : 'normal'
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** تعديل يدوي لحالة طالب (غياب بعذر، نسي يمسح…). */
    public function mark(array $scope, $wid, $studentId, string $status, string $l): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return $this->fail($l, 'حالة غير صالحة.', 'Invalid status.');
        }
        $m = $this->managedWindow($scope, $wid, $l);
        if (!is_array($m) || isset($m['success'])) {
            return $m;
        }
        [$w, $c] = $m;
        if (!DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', (int) $studentId)->exists()) {
            return $this->fail($l, 'الطالب مش مسجّل في المادة.', 'The student is not enrolled in this course.', 404);
        }
        $now = now();
        $existing = DB::table('attendance_records')->where('window_id', $w->id)->where('student_id', (int) $studentId)->first();
        $fill = [
            'status' => $status, 'method' => 'manual', 'marked_by_user_id' => $scope['userId'], 'updated_at' => $now,
            'checked_at' => in_array($status, ['present', 'late'], true) ? ($existing->checked_at ?? $now) : null,
        ];
        if ($existing) {
            DB::table('attendance_records')->where('id', $existing->id)->update($fill);
        } else {
            DB::table('attendance_records')->insert($fill + [
                'window_id' => $w->id, 'course_id' => $c->id, 'student_id' => (int) $studentId, 'created_at' => $now,
            ]);
        }
        if ($status === 'absent') {
            $this->warnStudents((int) $c->id, [(int) $studentId]);
        }
        return $this->ok(['student_id' => (int) $studentId, 'status' => $status], 'تم التعديل.');
    }

    /** تقرير المادة: نسب كل طالب + حالة الحرمان. */
    public function report(array $scope, $courseId, string $l): array
    {
        $c = $this->courses->manageableCourse($scope, $courseId, $l);
        if (is_array($c)) {
            return $c;
        }
        $this->sweep((int) $c->id);
        $counts = $this->counts((int) $c->id);
        $rows = [];
        foreach ($this->enrolledStudents((int) $c->id) as $s) {
            $cn = $counts[(int) $s->id] ?? null;
            $rows[] = array_merge([
                'student_id'     => (int) $s->id,
                'student_number' => $s->student_number,
                'full_name'      => $s->full_name,
                'present'        => $cn['present'] ?? 0,
                'late'           => $cn['late'] ?? 0,
                'absent'         => $cn['absent'] ?? 0,
                'excused'        => $cn['excused'] ?? 0,
            ], $this->standing($cn));
        }
        usort($rows, fn ($a, $b) => $b['percent_absent'] <=> $a['percent_absent']);
        return $this->ok([
            'course'  => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name_ar ?: $c->name_en],
            'sessions_held' => (int) DB::table('attendance_windows')->where('course_id', $c->id)->where('status', 'closed')->count(),
            'limits'  => ['max_absence_percent' => (float) $this->cfg('max_absence_percent'), 'warn_absence_percent' => (float) $this->cfg('warn_absence_percent')],
            'students' => $rows,
            'deprived_count' => count(array_filter($rows, fn ($r) => $r['state'] === 'deprived')),
        ]);
    }

    // ------------------------------------------------------------------ student

    /** $d: token, lat, lng, accuracy, device_id */
    public function checkIn(array $scope, array $d, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'تسجيل الحضور للطلاب بس.', 'Only students can check in.', 403);
        }
        $this->sweep();
        $wid = $this->windowIdFromToken((string) ($d['token'] ?? ''), $w);
        if (!$wid) {
            return $this->fail($l, 'الـ QR غير صالح أو منتهي. امسح الكود الحالي من شاشة الدكتور.', 'This QR code is invalid or expired. Scan the current code.', 422);
        }
        if ($w->status !== 'open' || strtotime((string) $w->closes_at) <= time()) {
            return $this->fail($l, 'التحضير اتقفل.', 'Attendance is closed.', 410);
        }
        $c = Course::where('university_id', $scope['universityId'])->find((int) $w->course_id);
        if (!$c || $c->status !== 'active') {
            return $this->fail($l, 'المادة غير موجودة.', 'Course not found.', 404);
        }
        if (!DB::table('course_enrollments')->where('course_id', $c->id)->where('student_id', $scope['studentId'])->exists()) {
            return $this->fail($l, 'انت مش مسجّل في المادة دي.', 'You are not enrolled in this course.', 403);
        }

        // ---- الموقع (إجباري)
        if (!isset($d['lat'], $d['lng']) || !is_numeric($d['lat']) || !is_numeric($d['lng'])) {
            return $this->fail($l, 'لازم تفعّل الموقع (GPS) عشان تسجّل حضورك.', 'Location access is required to check in.', 422);
        }
        $lat = (float) $d['lat'];
        $lng = (float) $d['lng'];
        $acc = isset($d['accuracy']) && is_numeric($d['accuracy']) ? (int) round((float) $d['accuracy']) : null;
        if ($acc === null || $acc > (int) $this->cfg('max_accuracy_m')) {
            return $this->fail($l, 'دقة الموقع ضعيفة. فعّل GPS عالي الدقة وحاول تاني.', 'Location accuracy is too low. Turn on precise GPS and retry.', 422);
        }

        $campus = $this->campusFor($c, $scope);
        $distCampus = null;
        $distDoctor = null;
        if ($campus) {
            $distCampus = (int) round(self::distanceM($lat, $lng, $campus->lat, $campus->lng));
            if ($distCampus - min($acc, 50) > $campus->radius) {
                return $this->fail($l, 'انت بعيد عن الكلية (' . $distCampus . 'م). لازم تكون جوه نطاق الكلية.', 'You are too far from the campus (' . $distCampus . ' m).', 403);
            }
        }
        if ($w->lat !== null) {
            $distDoctor = (int) round(self::distanceM($lat, $lng, (float) $w->lat, (float) $w->lng));
            if ($distDoctor - min($acc, 50) > (int) ($w->radius_m ?: $this->cfg('doctor_radius_m'))) {
                return $this->fail($l, 'انت بعيد عن مكان المحاضرة (' . $distDoctor . 'م).', 'You are too far from the lecture location (' . $distDoctor . ' m).', 403);
            }
        }
        if (!$campus && $w->lat === null) {
            return $this->fail($l, 'مفيش موقع مرجعي للتحضير ده.', 'This session has no reference location.', 422);
        }

        // ---- جهاز واحد لطالب واحد
        $deviceId = trim((string) ($d['device_id'] ?? ''));
        $deviceHash = $deviceId !== '' ? hash('sha256', $deviceId . '|' . config('app.key')) : null;
        if ($deviceHash && DB::table('attendance_records')->where('window_id', $w->id)->where('device_hash', $deviceHash)->where('student_id', '!=', $scope['studentId'])->exists()) {
            return $this->fail($l, 'الجهاز ده سجّل حضور لطالب تاني في نفس المحاضرة.', 'This device has already checked in another student for this session.', 403);
        }

        $existing = DB::table('attendance_records')->where('window_id', $w->id)->where('student_id', $scope['studentId'])->first();
        if ($existing && in_array($existing->status, ['present', 'late'], true)) {
            return $this->fail($l, 'حضورك متسجّل بالفعل.', 'You are already checked in.', 409);
        }

        $late = time() > strtotime((string) $w->opens_at) + 60 * (int) $this->cfg('late_after_minutes');
        $status = $late ? 'late' : 'present';
        $now = now();
        $row = [
            'status' => $status, 'method' => 'qr', 'lat' => round($lat, 7), 'lng' => round($lng, 7), 'accuracy_m' => $acc,
            'distance_campus_m' => $distCampus, 'distance_doctor_m' => $distDoctor, 'device_hash' => $deviceHash,
            'checked_at' => $now, 'updated_at' => $now,
        ];
        if ($existing) {
            DB::table('attendance_records')->where('id', $existing->id)->update($row);
        } else {
            DB::table('attendance_records')->insert($row + ['window_id' => $w->id, 'course_id' => $c->id, 'student_id' => $scope['studentId'], 'created_at' => $now]);
        }
        return $this->ok(['status' => $status, 'course' => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name_ar ?: $c->name_en], 'checked_at' => $now->toDateTimeString()],
            $late ? 'تم تسجيل حضورك (متأخر).' : 'تم تسجيل حضورك.');
    }

    /** حضور الطالب لكل مواده + أي تحضير مفتوح دلوقتي. */
    public function mine(array $scope, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $this->sweep();
        $courses = DB::table('course_enrollments as e')->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $scope['studentId'])->where('c.status', 'active')
            ->select('c.id', 'c.code', 'c.name_ar', 'c.name_en')->orderBy('c.code')->get();
        $open = DB::table('attendance_windows')->where('status', 'open')->where('closes_at', '>', now())
            ->whereIn('course_id', $courses->pluck('id')->all() ?: [0])->pluck('id', 'course_id');
        $data = [];
        foreach ($courses as $c) {
            $cn = $this->counts((int) $c->id, [(int) $scope['studentId']])[(int) $scope['studentId']] ?? null;
            $recent = DB::table('attendance_records as r')->join('attendance_windows as w', 'w.id', '=', 'r.window_id')
                ->where('r.course_id', $c->id)->where('r.student_id', $scope['studentId'])
                ->orderByDesc('w.id')->limit(10)->get(['w.id as window_id', 'w.opens_at', 'w.title', 'r.status', 'r.checked_at']);
            $data[] = array_merge([
                'course'   => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name_ar ?: $c->name_en],
                'present'  => $cn['present'] ?? 0,
                'late'     => $cn['late'] ?? 0,
                'absent'   => $cn['absent'] ?? 0,
                'excused'  => $cn['excused'] ?? 0,
                'open_now' => isset($open[$c->id]),
                'recent'   => $recent,
            ], $this->standing($cn));
        }
        return $this->ok([
            'limits'  => ['max_absence_percent' => (float) $this->cfg('max_absence_percent'), 'warn_absence_percent' => (float) $this->cfg('warn_absence_percent')],
            'courses' => $data,
        ]);
    }

    // ------------------------------------------------------------------ campus locations

    public function locations(array $scope): array
    {
        $q = DB::table('faculties')->where('university_id', $scope['universityId']);
        if ($scope['role'] !== 'university') {
            $q->where('id', (int) ($scope['facultyId'] ?? 0));
        }
        $data = $q->orderBy('name_ar')->get()->map(fn ($f) => [
            'faculty_id' => (int) $f->id,
            'name'       => $f->name_ar ?: $f->name_en,
            'lat'        => $f->campus_lat !== null ? (float) $f->campus_lat : null,
            'lng'        => $f->campus_lng !== null ? (float) $f->campus_lng : null,
            'radius_m'   => (int) ($f->campus_radius_m ?: $this->cfg('default_campus_radius_m')),
            'is_set'     => $f->campus_lat !== null,
            'can_edit'   => in_array($scope['role'], ['university', 'faculty'], true),
        ])->all();
        return $this->ok($data);
    }

    public function setLocation(array $scope, $facultyId, array $d, string $l): array
    {
        if (!in_array($scope['role'], ['university', 'faculty'], true)) {
            return $this->fail($l, 'تحديد موقع الكلية للكلية أو الجامعة بس.', 'Only the faculty or university can set the campus location.', 403);
        }
        $f = DB::table('faculties')->where('university_id', $scope['universityId'])->where('id', (int) $facultyId)->first();
        if (!$f) {
            return $this->fail($l, 'الكلية غير موجودة.', 'Faculty not found.', 404);
        }
        if ($scope['role'] === 'faculty' && (int) $scope['facultyId'] !== (int) $f->id) {
            return $this->fail($l, 'مش كليتك.', 'Not your faculty.', 403);
        }
        DB::table('faculties')->where('id', $f->id)->update([
            'campus_lat'      => round((float) $d['lat'], 7),
            'campus_lng'      => round((float) $d['lng'], 7),
            'campus_radius_m' => (int) ($d['radius_m'] ?? $this->cfg('default_campus_radius_m')),
            'updated_at'      => now(),
        ]);
        return array_merge($this->locations($scope), ['message' => $this->ar($l) ? 'تم حفظ موقع الكلية.' : 'Campus location saved.']);
    }
}
