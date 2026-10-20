<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * دفتر درجات المقرر: درجة المقرر = مجموع (نسبة الطالب في كل امتحان × وزن الامتحان ÷ 100).
 *
 *  - الامتحانات المحسوبة هي اللي المدرس ضافها في course_grade_items، ولازم تكون مربوطة بالمقرر (exams.course_id).
 *  - الطلاب = المسجّلين في المقرر (course_enrollments).
 *  - النسبة بتتاخد من exam_attempts.percentage للمحاولات المصحّحة (graded) بس، حسب attempt_policy:
 *    highest = أعلى محاولة، latest = آخر محاولة، first = أول محاولة. (اللي فيه خصم تأخير: percentage فيه الخصم أصلًا.)
 *  - حالة كل امتحان لكل طالب:
 *      graded   = له نتيجة مصحّحة (بتتحسب)
 *      pending  = سلّم ولسه التصحيح مخلصش
 *      missing  = الامتحان اتقفل ومفيش له محاولة (بيتحسب صفر في الإجمالي)
 *      upcoming = الامتحان لسه مقفلش ومفيش له محاولة (بيتحسب صفر في الإجمالي بس مش في "حتى الآن")
 *  - total = مجموع النقاط من total_weight، so_far_pct = total ÷ وزن الامتحانات المصحّحة (حتى الآن).
 *  - الطالب (studentView) مبيشوفش غير الامتحانات اللي نتيجتها ظاهرة له (نفس قاعدة result_visibility)؛ الباقي hidden.
 */
class CourseGradebookService
{
    public const POLICIES = ['highest', 'latest', 'first'];

    public function __construct(private AuditLogService $auditLog)
    {
    }

    // ---------------------------------------------------------------- items

    /** @return array<int,object> الامتحانات المحسوبة (المربوطة فعلًا بالمقرر). */
    private function items(Course $course): array
    {
        return DB::table('course_grade_items as i')
            ->join('exams as e', function ($j) use ($course) {
                $j->on('e.id', '=', 'i.exam_id')->where('e.course_id', '=', $course->id);
            })
            ->where('i.course_id', $course->id)
            ->orderBy('i.id')
            ->select('i.exam_id', 'i.weight', 'i.attempt_policy', 'e.title', 'e.exam_type', 'e.status', 'e.start_at', 'e.end_at',
                'e.result_visibility', 'e.results_published_at', 'e.total_marks', 'e.passing_score')
            ->get()->all();
    }

    /** امتحانات المقرر اللي لسه مش في الدفتر (اللي المدرس يقدر يضيفها). */
    public function availableExams(Course $course): array
    {
        $used = DB::table('course_grade_items')->where('course_id', $course->id)->pluck('exam_id')->all();
        return DB::table('exams')
            ->where('course_id', $course->id)
            ->where(fn ($q) => $q->where('is_template', 0)->orWhereNull('is_template'))
            ->when($used, fn ($q) => $q->whereNotIn('id', $used))
            ->orderByDesc('id')
            ->get(['id', 'title', 'exam_type', 'status', 'start_at', 'total_marks'])
            ->map(fn ($e) => (array) $e)->all();
    }

    /**
     * يستبدل إعدادات الدفتر كلها. @param array<int,array{exam_id:int,weight:float|int|string,attempt_policy?:string}> $rows
     * @return array{success:bool,message?:string,code?:int}
     */
    public function saveItems(Course $course, array $rows, $userId): array
    {
        $seen = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $examId = (int) ($r['exam_id'] ?? 0);
            $w = (float) ($r['weight'] ?? 0);
            if ($examId <= 0 || isset($seen[$examId])) {
                return ['success' => false, 'message' => 'Duplicate or invalid exam in the list.', 'code' => 422];
            }
            if ($w <= 0 || $w > 100) {
                return ['success' => false, 'message' => 'Each weight must be between 0 and 100.', 'code' => 422];
            }
            if (!in_array($r['attempt_policy'] ?? 'highest', self::POLICIES, true)) {
                return ['success' => false, 'message' => 'Invalid attempt policy.', 'code' => 422];
            }
            $seen[$examId] = true;
            $total += $w;
        }
        if ($total > 100.0001) {
            return ['success' => false, 'message' => 'Total weight cannot exceed 100% (currently ' . round($total, 2) . '%).', 'code' => 422];
        }
        if ($seen) {
            $ok = DB::table('exams')->where('course_id', $course->id)->whereIn('id', array_keys($seen))
                ->where(fn ($q) => $q->where('is_template', 0)->orWhereNull('is_template'))->count();
            if ($ok !== count($seen)) {
                return ['success' => false, 'message' => 'Every exam must belong to this course.', 'code' => 422];
            }
        }

        DB::transaction(function () use ($course, $rows) {
            DB::table('course_grade_items')->where('course_id', $course->id)->delete();
            $now = now();
            foreach ($rows as $r) {
                DB::table('course_grade_items')->insert([
                    'course_id' => $course->id, 'exam_id' => (int) $r['exam_id'], 'weight' => round((float) $r['weight'], 2),
                    'attempt_policy' => $r['attempt_policy'] ?? 'highest', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        $this->auditLog->record($userId, 'course.gradebook_items_saved', 'Course', $course->id, null,
            ['items' => array_map(fn ($r) => ['exam_id' => (int) $r['exam_id'], 'weight' => (float) $r['weight']], $rows)]);

        return ['success' => true];
    }

    // ---------------------------------------------------------------- gradebook

    /** دفتر الدرجات الكامل للمدرس. */
    public function gradebook(Course $course): array
    {
        $items = $this->items($course);
        $students = DB::table('course_enrollments as ce')
            ->join('students as s', 's.id', '=', 'ce.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('ce.course_id', $course->id)
            ->orderBy('u.full_name')
            ->get(['s.id', 'u.full_name', 's.student_number'])->all();

        $picked = $this->pickAttempts($items, array_map(fn ($s) => (int) $s->id, $students));
        $rows = [];
        foreach ($students as $s) {
            $rows[] = ['student_id' => (int) $s->id, 'full_name' => $s->full_name, 'student_number' => $s->student_number]
                + $this->computeStudent($items, $picked[(int) $s->id] ?? [], false);
        }

        $totals = array_values(array_filter(array_map(fn ($r) => $r['so_far_pct'], $rows), fn ($v) => $v !== null));
        $sum = array_sum(array_map(fn ($i) => (float) $i->weight, $items));

        return [
            'course_id'    => $course->id,
            'total_weight' => round($sum, 2),
            'items'        => array_map(fn ($i) => [
                'exam_id' => (int) $i->exam_id, 'title' => $i->title, 'exam_type' => $i->exam_type, 'status' => $i->status,
                'weight' => (float) $i->weight, 'attempt_policy' => $i->attempt_policy, 'end_at' => $i->end_at,
            ], $items),
            'available_exams' => $this->availableExams($course),
            'rows'         => $rows,
            'summary'      => [
                'students'        => count($rows),
                'with_results'    => count($totals),
                'average_so_far'  => $totals ? round(array_sum($totals) / count($totals), 2) : null,
                'highest_so_far'  => $totals ? round(max($totals), 2) : null,
                'lowest_so_far'   => $totals ? round(min($totals), 2) : null,
            ],
        ];
    }

    /** درجة الطالب نفسه في المقرر (لازم يكون مسجّل — بيتأكد منه الكنترولر). */
    public function studentView(Course $course, int $studentId): array
    {
        $items = $this->items($course);
        $picked = $this->pickAttempts($items, [$studentId]);
        $calc = $this->computeStudent($items, $picked[$studentId] ?? [], true);

        return [
            'course_id'    => $course->id,
            'total_weight' => round(array_sum(array_map(fn ($i) => (float) $i->weight, $items)), 2),
            'items'        => array_map(fn ($i) => ['exam_id' => (int) $i->exam_id, 'title' => $i->title, 'weight' => (float) $i->weight], $items),
        ] + $calc;
    }

    // ---------------------------------------------------------------- internals

    /** @return array<int,array<int,object>> student_id => exam_id => attempt (حسب السياسة) + attempt status. */
    private function pickAttempts(array $items, array $studentIds): array
    {
        if (!$items || !$studentIds) {
            return [];
        }
        $policy = [];
        foreach ($items as $i) {
            $policy[(int) $i->exam_id] = $i->attempt_policy;
        }
        $rows = DB::table('exam_attempts')
            ->whereIn('exam_id', array_keys($policy))->whereIn('student_id', $studentIds)
            ->whereIn('status', ['graded', 'submitted', 'auto_submitted', 'grading'])
            ->orderBy('attempt_number')
            ->get(['exam_id', 'student_id', 'attempt_number', 'status', 'percentage']);

        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r->student_id; $eid = (int) $r->exam_id;
            $cur = $out[$sid][$eid] ?? ['graded' => [], 'pending' => false];
            if ($r->status === 'graded' && $r->percentage !== null) {
                $cur['graded'][] = (float) $r->percentage; // مرتبة بـ attempt_number
            } else {
                $cur['pending'] = true;
            }
            $out[$sid][$eid] = $cur;
        }
        $picked = [];
        foreach ($out as $sid => $byExam) {
            foreach ($byExam as $eid => $c) {
                $g = $c['graded'];
                $pct = null;
                if ($g) {
                    $pct = match ($policy[$eid]) { 'first' => $g[0], 'latest' => end($g), default => max($g) };
                }
                $picked[$sid][$eid] = ['pct' => $pct, 'pending' => $c['pending']];
            }
        }
        return $picked;
    }

    private function visible(object $item): bool
    {
        return match ($item->result_visibility) {
            'immediate'   => true,
            'after_close' => $item->end_at === null || now()->greaterThanOrEqualTo(Carbon::parse($item->end_at)),
            'manual'      => $item->results_published_at !== null && now()->greaterThanOrEqualTo(Carbon::parse($item->results_published_at)),
            default       => false,
        };
    }

    private function computeStudent(array $items, array $attempts, bool $studentView): array
    {
        $cells = [];
        $earned = 0.0; $gradedWeight = 0.0; $complete = true;
        foreach ($items as $i) {
            $a = $attempts[(int) $i->exam_id] ?? null;
            $w = (float) $i->weight;
            $closed = $i->end_at !== null && now()->greaterThanOrEqualTo(Carbon::parse($i->end_at));
            $cell = ['exam_id' => (int) $i->exam_id, 'weight' => $w, 'percentage' => null, 'points' => null];

            if ($a && $a['pct'] !== null) {
                if ($studentView && !$this->visible($i)) {
                    $cell['status'] = 'hidden';
                    $complete = false;
                } else {
                    $cell['status'] = 'graded';
                    $cell['percentage'] = round($a['pct'], 2);
                    $cell['points'] = round($a['pct'] * $w / 100, 2);
                    $earned += $a['pct'] * $w / 100;
                    $gradedWeight += $w;
                }
            } elseif ($a && $a['pending']) {
                $cell['status'] = 'pending';
                $complete = false;
            } elseif ($closed) {
                $cell['status'] = 'missing';
                $cell['points'] = 0.0;
                $gradedWeight += $w; // الغياب بعد قفل الامتحان بيتحسب صفر حتى في "حتى الآن"
            } else {
                $cell['status'] = 'upcoming';
                $complete = false;
            }
            $cells[(int) $i->exam_id] = $cell;
        }

        return [
            'cells'        => $cells,
            'total'        => round($earned, 2),
            'graded_weight'=> round($gradedWeight, 2),
            'so_far_pct'   => $gradedWeight > 0 ? round($earned / $gradedWeight * 100, 2) : null,
            'complete'     => $complete && count($items) > 0,
        ];
    }
}
