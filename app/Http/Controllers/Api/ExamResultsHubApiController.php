<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * سطح "النتائج / نتائج الطلبة / التقارير" في بورتال عضو هيئة التدريس.
 *
 *   GET /api/v1/exam-system/results/overview        ملخص كل امتحان (محاولات، متوسط، أعلى/أقل، نسبة النجاح)
 *   GET /api/v1/exam-system/results/students        نتيجة كل طالب عبر كل امتحانات العضو (q, exam_id)
 *   GET /api/v1/exam-system/results/students/export تصدير نفس الجدول (csv|xlsx|pdf|json)
 *
 * بيمتد من ExamResultsExportApiController عشان يعيد استخدام respondExport()
 * (نفس الـ writers ونفس الـ audit log). الملكية دايمًا من created_by_academic_staff_id
 * بتاع العضو نفسه — عمره ما بياخد staff id من العميل.
 */
class ExamResultsHubApiController extends ExamResultsExportApiController
{
    private const PENDING = ['submitted', 'auto_submitted', 'grading'];

    public function overview(Request $request)
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return $err;
        }

        $exams = DB::table('exams')->whereNull('deleted_at')->where('created_by_academic_staff_id', $staffId)->orderByDesc('id')->get();
        $attempts = $this->attemptsFor($exams->pluck('id')->all());

        $out = [];
        foreach ($exams as $e) {
            $rows = $attempts[$e->id] ?? [];
            $graded = array_values(array_filter($rows, fn ($r) => $r->status === 'graded' && $r->percentage !== null));
            $pcts = array_map(fn ($r) => (float) $r->percentage, $graded);
            $passing = $e->passing_score !== null ? (float) $e->passing_score : null;

            $out[] = [
                'id'                   => (int) $e->id,
                'title'                => $e->title,
                'subject'              => $e->subject ?? null,
                'status'               => $e->status,
                'total_marks'          => (float) $e->total_marks,
                'passing_score'        => $passing,
                'results_published_at' => $e->results_published_at,
                'attempts_total'       => count($rows),
                'graded'               => count($graded),
                'pending_grading'      => count(array_filter($rows, fn ($r) => in_array($r->status, self::PENDING, true))),
                'avg_percentage'       => $pcts ? round(array_sum($pcts) / count($pcts), 2) : null,
                'highest'              => $pcts ? round(max($pcts), 2) : null,
                'lowest'               => $pcts ? round(min($pcts), 2) : null,
                'pass_rate'            => ($pcts && $passing !== null)
                    ? round(100 * count(array_filter($pcts, fn ($p) => $p >= $passing)) / count($pcts), 2)
                    : null,
            ];
        }

        return $this->apiSuccess($out, 'Results overview retrieved successfully.');
    }

    public function students(Request $request)
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return $err;
        }
        $list = $this->buildStudentRows($staffId, trim((string) $request->input('q', '')), (int) $request->input('exam_id', 0));

        return $this->apiSuccess($list['students'], 'Student results retrieved successfully.', 200, ['total' => count($list['students'])]);
    }

    public function exportStudents(Request $request)
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return $err;
        }
        $format = $this->resolvedFormat($request);
        [$title, $meta, $sections] = $this->studentsPayload($staffId, trim((string) $request->input('q', '')), (int) $request->input('exam_id', 0));

        return $this->respondExport($request, $format, 'students_results', $title, $meta, $sections, [
            'action'       => 'academic_staff.exam_system.students_results_exported',
            'subject_type' => 'AcademicStaff',
            'subject_id'   => $staffId,
        ]);
    }

    /** @return array{0:string,1:array<string,string>,2:array} */
    protected function studentsPayload(int $staffId, string $q = '', int $examId = 0): array
    {
        $list = $this->buildStudentRows($staffId, $q, $examId);

        $header = ['Student', 'Student Number', 'Email', 'Exam', 'Attempts', 'Best Score', 'Max Marks', 'Best %', 'Status', 'Last Submission'];
        $rows = [];
        foreach ($list['students'] as $s) {
            foreach ($s['exams'] as $x) {
                $rows[] = [$s['full_name'], $s['student_number'], $s['email'], $x['exam_title'], $x['attempts'],
                    $x['best_score'] ?? '-', $x['total_marks'], $x['best_percentage'] !== null ? $x['best_percentage'] . '%' : '-',
                    $x['status'], $x['last_submitted_at'] ?? '-'];
            }
        }
        $meta = [
            'Students'     => (string) count($list['students']),
            'Exams'        => (string) $list['exam_count'],
            'Generated At' => date('Y-m-d H:i:s'),
        ];

        return ['Students Results', $meta, [['title' => 'Students Results', 'header' => $header, 'rows' => $rows]]];
    }

    // ---------------------------------------------------------------------

    /** @return array{0:?int,1:mixed} */
    protected function resolveStaffId(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return [null, $this->apiError('Only academic staff accounts can view exam results.', null, 403)];
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return [null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        return [(int) $staff->id, null];
    }

    /** @return array<int,object[]> exam_id => attempts (cancelled/in-progress مش داخلين في النتائج) */
    private function attemptsFor(array $examIds): array
    {
        if (!$examIds) {
            return [];
        }
        $rows = DB::table('exam_attempts as a')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('a.exam_id', $examIds)
            ->whereNotIn('a.status', ['in_progress', 'cancelled'])
            ->select('a.id', 'a.exam_id', 'a.student_id', 'a.status', 'a.score', 'a.percentage', 'a.submitted_at',
                's.student_number', 'u.full_name', 'u.email')
            ->get();

        $by = [];
        foreach ($rows as $r) {
            $by[$r->exam_id][] = $r;
        }
        return $by;
    }

    protected function buildStudentRows(int $staffId, string $q, int $examId): array
    {
        $examQuery = DB::table('exams')->whereNull('deleted_at')->where('created_by_academic_staff_id', $staffId);
        if ($examId > 0) {
            $examQuery->where('id', $examId);
        }
        $exams = $examQuery->get()->keyBy('id');
        $byExam = $this->attemptsFor($exams->keys()->all());

        $students = [];
        foreach ($byExam as $eid => $rows) {
            $exam = $exams[$eid];
            $perStudent = [];
            foreach ($rows as $r) {
                $perStudent[$r->student_id][] = $r;
            }
            foreach ($perStudent as $sid => $list) {
                $first = $list[0];
                if ($q !== '' && stripos($first->full_name . ' ' . $first->student_number . ' ' . $first->email, $q) === false) {
                    continue;
                }
                $gradedRows = array_values(array_filter($list, fn ($r) => $r->status === 'graded' && $r->percentage !== null));
                $best = null;
                foreach ($gradedRows as $g) {
                    if ($best === null || (float) $g->percentage > (float) $best->percentage) {
                        $best = $g;
                    }
                }
                $passing = $exam->passing_score !== null ? (float) $exam->passing_score : null;
                $status = $best ? (($passing !== null) ? ((float) $best->percentage >= $passing ? 'passed' : 'failed') : 'graded') : 'pending';
                $submitted = array_filter(array_map(fn ($r) => $r->submitted_at, $list));

                $students[$sid] ??= [
                    'student_id' => (int) $sid, 'full_name' => $first->full_name, 'student_number' => $first->student_number,
                    'email' => $first->email, 'exams' => [],
                ];
                $students[$sid]['exams'][] = [
                    'exam_id'           => (int) $eid,
                    'exam_title'        => $exam->title,
                    'total_marks'       => (float) $exam->total_marks,
                    'attempts'          => count($list),
                    'best_score'        => $best ? (float) $best->score : null,
                    'best_percentage'   => $best ? round((float) $best->percentage, 2) : null,
                    'status'            => $status,
                    'last_submitted_at' => $submitted ? max($submitted) : null,
                ];
            }
        }

        foreach ($students as &$s) {
            $pcts = array_values(array_filter(array_map(fn ($x) => $x['best_percentage'], $s['exams']), fn ($p) => $p !== null));
            $s['exams_taken'] = count($s['exams']);
            $s['avg_percentage'] = $pcts ? round(array_sum($pcts) / count($pcts), 2) : null;
        }
        unset($s);

        $list = array_values($students);
        usort($list, fn ($a, $b) => strcasecmp($a['full_name'], $b['full_name']));

        return ['students' => $list, 'exam_count' => $exams->count()];
    }
}
