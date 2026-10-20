<?php

namespace App\Repositories;

use App\Models\ExamTarget;
use Illuminate\Support\Facades\DB;

/**
 * Exam & Assessment System — Round 2 (Targeting). كل استعلامات
 * "مين مؤهل يشوف الامتحان ده" بتعدي من هنا — سواء الحساب بيتم على
 * صفوف محفوظة (forExam) أو صفوف مُقترحة لسه ما اتحفظتش (preview قبل
 * الحفظ، راجع Phase 8 في السبك: "يحسب العدد قبل النشر").
 *
 * eligibilityWhereGroups() هي القلب: بتبني (group1 OR group2 OR ...)
 * SQL fragment من مصفوفة صفوف استهداف — كل صف بيتحول لـ AND من
 * الأعمدة المحددة فيه بس (الأعمدة الفاضية/null بتتجاهل تمامًا، مش حتى
 * "col IS NULL OR" زي announcements، لأن هنا القرار بيتاخد وقت بناء
 * المصفوفة نفسها مش وقت الاستعلام). صف من غير أي عمود = "1=1" (الجامعة
 * كلها). student_id يقفل باقي أعمدة الصف (لو موجودة بيتجاهلوا).
 */
class ExamTargetRepository
{
    /** @return ExamTarget[] */
    public function forExam($examId): array
    {
        return ExamTarget::where('exam_id', $examId)->get()->all();
    }

    public function countForExam($examId): int
    {
        return ExamTarget::where('exam_id', $examId)->count();
    }

    /** بيمسح كل صفوف الاستهداف القديمة للامتحان ده ويحط بدلها الجديدة، جوه transaction. */
    public function replaceForExam($examId, array $rows): void
    {
        DB::transaction(function () use ($examId, $rows) {
            ExamTarget::where('exam_id', $examId)->delete();
            foreach ($rows as $row) {
                ExamTarget::create(array_merge(['exam_id' => $examId], $this->normalizeRow($row)));
            }
        });
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function countMatching($universityId, array $rows): int
    {
        [$sql, $params] = $this->eligibilityWhereGroups($rows);
        if ($sql === null) {
            return 0;
        }

        return (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM students s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.university_id = ? AND u.status = 'active' AND $sql",
            array_merge([$universityId], $params)
        )->c ?? 0);
    }

    /** @return array<int,array<string,mixed>> عينة (بحد أقصى $limit) من الطلاب المؤهلين — لمراجعة المدرس لقائمة الاستهداف. */
    public function listMatching($universityId, array $rows, int $limit = 200): array
    {
        [$sql, $params] = $this->eligibilityWhereGroups($rows);
        if ($sql === null) {
            return [];
        }

        return DB::select(
            "SELECT s.id, s.student_number, s.faculty_id, s.department_id,
                    s.academic_year, s.group_id, u.full_name, u.email
             FROM students s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.university_id = ? AND u.status = 'active' AND $sql
             ORDER BY u.full_name
             LIMIT $limit",
            array_merge([$universityId], $params)
        );
    }

    /**
     * الطلاب المؤهلين لامتحان محفوظ، مع بحث نصي اختياري (اسم / إيميل / رقم جامعي). بيشغّل شاشة
     * "اختيار طالب ماجاش للامتحان" عند المدرس. $onlyWithoutAttempts=true بيستبعد أي طالب ليه محاولة على الامتحان
     * (يعني الغايب بس).
     * @return array<int,object>
     */
    public function searchForExamSaved($examId, $universityId, ?string $query = null, bool $onlyWithoutAttempts = false, int $limit = 50): array
    {
        [$sql, $params] = $this->eligibilityWhereGroups($this->rowsAsArrays($this->forExam($examId)));
        if ($sql === null) {
            return [];
        }

        $where = '';
        $bindings = array_merge([$universityId], $params);
        $query = $query !== null ? trim($query) : '';
        if ($query !== '') {
            // '!' كحرف escape عشان نفس الجملة تشتغل على MySQL وSQLite بنفس المعنى (الباك-سلاش بيختلف بينهم).
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $where .= " AND (u.full_name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR s.student_number LIKE ? ESCAPE '!')";
            array_push($bindings, $like, $like, $like);
        }
        if ($onlyWithoutAttempts) {
            $where .= ' AND s.id NOT IN (SELECT a.student_id FROM exam_attempts a WHERE a.exam_id = ?)';
            $bindings[] = $examId;
        }
        $limit = max(1, min(200, $limit));

        return DB::select(
            "SELECT s.id, s.student_number, u.full_name, u.email
             FROM students s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.university_id = ? AND u.status = 'active' AND $sql $where
             ORDER BY u.full_name
             LIMIT $limit",
            $bindings
        );
    }

    public function countForExamSaved($examId, $universityId): int
    {
        return $this->countMatching($universityId, $this->rowsAsArrays($this->forExam($examId)));
    }

    public function listForExamSaved($examId, $universityId, int $limit = 200): array
    {
        return $this->listMatching($universityId, $this->rowsAsArrays($this->forExam($examId)), $limit);
    }

    /**
     * Round 8 (Phase 29 — Notifications). كل user_id للطلاب المؤهلين
     * لامتحان محفوظ، من غير حد أقصى (عكس listForExamSaved اللي LIMIT 200
     * بتاعها لعرض "مين هيشوف الامتحان ده" في الفورم — هنا محتاجين نوصل
     * للكل فعلًا وقت الإشعار، مش عينة).
     * @return int[]
     */
    public function userIdsForExamSaved($examId, $universityId): array
    {
        [$sql, $params] = $this->eligibilityWhereGroups($this->rowsAsArrays($this->forExam($examId)));
        if ($sql === null) {
            return [];
        }

        $rows = DB::select(
            "SELECT u.id
             FROM students s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.university_id = ? AND u.status = 'active' AND $sql",
            array_merge([$universityId], $params)
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * زي userIdsForExamSaved() بس بترجّع كمان student_id (التذكيرات بتحتاجه عشان تستبعد اللي خلّص محاولاته).
     * @return array<int,array{user_id:int,student_id:int}>
     */
    public function recipientsForExamSaved($examId, $universityId): array
    {
        [$sql, $params] = $this->eligibilityWhereGroups($this->rowsAsArrays($this->forExam($examId)));
        if ($sql === null) {
            return [];
        }

        $rows = DB::select(
            "SELECT u.id AS user_id, s.id AS student_id
             FROM students s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.university_id = ? AND u.status = 'active' AND $sql",
            array_merge([$universityId], $params)
        );

        return array_map(fn ($r) => ['user_id' => (int) $r->user_id, 'student_id' => (int) $r->student_id], $rows);
    }

    /** الامتحان ده الطالب ده مؤهل يشوفه؟ (وقت فتح تفاصيل امتحان بعينه من "امتحاناتي"). */
    public function studentIsEligibleForExam($examId, $studentId): bool
    {
        $student = DB::selectOne('SELECT * FROM students WHERE id = ? LIMIT 1', [$studentId]);
        if (!$student) {
            return false;
        }

        [$sql, $params] = $this->eligibilityWhereGroups($this->rowsAsArrays($this->forExam($examId)));
        if ($sql === null) {
            return false;
        }

        $row = DB::selectOne(
            "SELECT 1 AS ok FROM students s WHERE s.id = ? AND $sql",
            array_merge([$studentId], $params)
        );
        return $row !== null;
    }

    /** @return int[] كل exam_id اللي الطالب ده مؤهل يشوفها (من غير فلترة status/تاريخ — الـ service بيضيفها). */
    public function examIdsForStudent($studentId, $universityId): array
    {
        $student = DB::table('students')->where('id', $studentId)->first();
        if (!$student) {
            return [];
        }

        $examIds = DB::table('exam_targets as t')
            ->join('exams as e', 'e.id', '=', 't.exam_id')
            ->where('e.university_id', $universityId)
            ->where(function ($q) use ($student) {
                $q->where('t.student_id', $student->id)
                    ->orWhere(function ($q2) use ($student) {
                        $q2->where(function ($q3) use ($student) {
                            $q3->whereNull('t.faculty_id')->orWhere('t.faculty_id', $student->faculty_id);
                        })->where(function ($q3) use ($student) {
                            $q3->whereNull('t.department_id')->orWhere('t.department_id', $student->department_id);
                        })->where(function ($q3) use ($student) {
                            $q3->whereNull('t.academic_year')->orWhere('t.academic_year', $student->academic_year);
                        })->where(function ($q3) use ($student) {
                            $q3->whereNull('t.group_id')->orWhere('t.group_id', $student->group_id);
                        })->whereNull('t.student_id');
                    });
            })
            ->pluck('e.id')
            ->unique()
            ->values()
            ->all();

        return $examIds;
    }

    /** @return array<int,array<string,mixed>> */
    private function rowsAsArrays(array $targets): array
    {
        return array_map(fn (ExamTarget $t) => [
            'faculty_id'    => $t->faculty_id,
            'department_id' => $t->department_id,
            'academic_year' => $t->academic_year,
            'group_id'      => $t->group_id,
            'student_id'    => $t->student_id,
        ], $targets);
    }

    private function normalizeRow(array $row): array
    {
        $isStudent = !empty($row['student_id']);
        return [
            'faculty_id'    => $isStudent ? null : ($row['faculty_id'] ?? null),
            'department_id' => $isStudent ? null : ($row['department_id'] ?? null),
            'academic_year' => $isStudent ? null : ($row['academic_year'] ?? null),
            'group_id'      => $isStudent ? null : ($row['group_id'] ?? null),
            'student_id'    => $isStudent ? (int) $row['student_id'] : null,
        ];
    }

    /**
     * بتبني (group1 OR group2 OR ...) SQL fragment + params من مصفوفة صفوف.
     * @return array{0:?string,1:array} $sql هيبقى null لو الصفوف فاضية (يبقى محدش مؤهل).
     */
    private function eligibilityWhereGroups(array $rows): array
    {
        if (empty($rows)) {
            return [null, []];
        }

        $groups = [];
        $params = [];
        foreach ($rows as $row) {
            if (!empty($row['student_id'])) {
                $groups[] = 's.id = ?';
                $params[] = (int) $row['student_id'];
                continue;
            }

            $conds = [];
            foreach (['faculty_id', 'department_id', 'group_id', 'academic_year'] as $col) {
                if (!empty($row[$col])) {
                    $conds[] = "s.$col = ?";
                    $params[] = (int) $row[$col];
                }
            }

            $groups[] = $conds ? ('(' . implode(' AND ', $conds) . ')') : '1=1';
        }

        return ['(' . implode(' OR ', $groups) . ')', $params];
    }
}
