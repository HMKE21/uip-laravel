<?php

namespace Tests\Feature;

use App\Repositories\ExamTargetRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests لمنطق "مين مؤهل يشوف الامتحان ده" في
 * ExamTargetRepository::studentIsEligibleForExam() و countMatching()/
 * listMatching(). القواعد اللي بنتأكد منها هنا (راجع تعليق الكلاس نفسه
 * في ExamTargetRepository لتفاصيل الـ semantics):
 *
 *  - صف target من غير أي عمود (كله null) = كل طلاب الجامعة مؤهلين.
 *  - صف عليه student_id = الطالب ده بالتحديد بس (باقي أعمدة الصف تتجاهل).
 *  - صف عليه أكتر من عمود (faculty/department/academic_year/
 *    group_id) = لازم الطالب يطابق كل الأعمدة المحددة سوا (AND).
 *  - أكتر من صف target لنفس الامتحان = OR بينهم (يكفي الطالب يطابق صف واحد).
 *  - امتحان من غير أي target rows خالص = محدش مؤهل (مش "الكل مؤهل").
 *
 * ملحوظة بيئة: الباتش ده مبنيش فيه migrations الجداول الأساسية
 * (students/faculties/...) لأنها مفروض موجودة في التطبيق الأصلي — تم
 * إضافة database/migrations/2020_01_01_000000_create_test_support_tables.php
 * كنسخة مصغّرة منها عشان الاختبار يقدر يشتغل معزول. الاختبار بيستخدم
 * DatabaseTransactions بدل RefreshDatabase عشان مايحاولش يعمل migrate
 * لكل الجداول التانية اللي مش جزء من الباتش ده.
 */
class ExamTargetEligibilityTest extends TestCase
{
    use DatabaseTransactions;

    private ExamTargetRepository $repo;
    private int $universityId = 1;
    private int $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ExamTargetRepository();
        DB::table('universities')->insert(['id' => $this->universityId, 'name' => 'Test University']);

        $this->examId = DB::table('exams')->insertGetId([
            'university_id'                => $this->universityId,
            'created_by_academic_staff_id' => $this->makeAcademicStaff(),
            'title'                        => 'Sample Exam',
            'duration_minutes'             => 60,
            'status'                       => 'published',
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
    }

    private function makeAcademicStaff(): int
    {
        $userId = DB::table('users')->insertGetId([
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'full_name'     => 'Dr. Staff',
            'email'         => 'staff_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'),
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return DB::table('academic_staff')->insertGetId([
            'user_id'       => $userId,
            'university_id' => $this->universityId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /** بيتأكد إن lookup row (faculty/department/group) موجود فعلاً قبل ما نحط id بتاعه في student أو target، عشان الـ FK constraints. */
    private function ensureLookupRow(string $table, ?int $id): void
    {
        if ($id === null) {
            return;
        }
        if (!DB::table($table)->where('id', $id)->exists()) {
            DB::table($table)->insert(['id' => $id, 'name' => ucfirst($table) . " $id", 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** بيعمل طالب بمواصفات معينة ويرجّع الـ student.id بتاعه. */
    private function makeStudent(array $attrs = []): int
    {
        $this->ensureLookupRow('faculties', $attrs['faculty_id'] ?? null);
        $this->ensureLookupRow('departments', $attrs['department_id'] ?? null);
        $this->ensureLookupRow('student_groups', $attrs['group_id'] ?? null);
        $userId = DB::table('users')->insertGetId([
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'full_name'     => $attrs['full_name'] ?? 'Student ' . uniqid(),
            'email'         => 'student_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'),
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return DB::table('students')->insertGetId(array_merge([
            'user_id'        => $userId,
            'university_id'  => $this->universityId,
            'student_number' => 'S' . uniqid(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $attrs));
    }

    private function addTarget(array $row): void
    {
        $this->ensureLookupRow('faculties', $row['faculty_id'] ?? null);
        $this->ensureLookupRow('departments', $row['department_id'] ?? null);
        $this->ensureLookupRow('student_groups', $row['group_id'] ?? null);

        DB::table('exam_targets')->insert(array_merge([
            'exam_id'       => $this->examId,
            'faculty_id'    => null,
            'department_id' => null,
            'academic_year' => null,
            'group_id'      => null,
            'student_id'    => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $row));
    }

    #[Test]
    public function no_target_rows_means_nobody_is_eligible(): void
    {
        $student = $this->makeStudent();

        $this->assertFalse($this->repo->studentIsEligibleForExam($this->examId, $student));
        $this->assertSame(0, $this->repo->countMatching($this->universityId, []));
    }

    #[Test]
    public function a_wildcard_row_with_no_columns_makes_the_whole_university_eligible(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();
        $this->addTarget([]); // كل حاجة null = الجامعة كلها

        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $studentA));
        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $studentB));
    }

    #[Test]
    public function a_student_id_row_targets_only_that_student_regardless_of_other_attributes(): void
    {
        $targetedStudent = $this->makeStudent(['faculty_id' => 5, 'department_id' => 9]);
        $otherStudent    = $this->makeStudent(['faculty_id' => 5, 'department_id' => 9]);

        $this->addTarget(['student_id' => $targetedStudent]);

        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $targetedStudent));
        $this->assertFalse($this->repo->studentIsEligibleForExam($this->examId, $otherStudent));
    }

    #[Test]
    public function multiple_columns_in_one_row_are_combined_with_and(): void
    {
        // صف واحد بيه faculty_id=1 AND department_id=2: الطالب لازم يطابق
        // الاتنين مع بعض، مش يكفي واحد بس.
        $matches       = $this->makeStudent(['faculty_id' => 1, 'department_id' => 2]);
        $wrongDept     = $this->makeStudent(['faculty_id' => 1, 'department_id' => 3]);
        $wrongFaculty  = $this->makeStudent(['faculty_id' => 2, 'department_id' => 2]);

        $this->addTarget(['faculty_id' => 1, 'department_id' => 2]);

        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $matches));
        $this->assertFalse($this->repo->studentIsEligibleForExam($this->examId, $wrongDept));
        $this->assertFalse($this->repo->studentIsEligibleForExam($this->examId, $wrongFaculty));
    }

    #[Test]
    public function multiple_rows_are_combined_with_or(): void
    {
        // صفين منفصلين: faculty_id=1 أو academic_year=3. طالب يطابق أي
        // واحد فيهم لازم يبقى مؤهل، حتى لو مش مطابق التاني.
        $matchesFacultyOnly = $this->makeStudent(['faculty_id' => 1, 'academic_year' => 1]);
        $matchesYearOnly    = $this->makeStudent(['faculty_id' => 9, 'academic_year' => 3]);
        $matchesNeither     = $this->makeStudent(['faculty_id' => 9, 'academic_year' => 1]);

        $this->addTarget(['faculty_id' => 1]);
        $this->addTarget(['academic_year' => 3]);

        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $matchesFacultyOnly));
        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $matchesYearOnly));
        $this->assertFalse($this->repo->studentIsEligibleForExam($this->examId, $matchesNeither));
    }

    #[Test]
    public function group_id_targeting_works_alongside_other_columns(): void
    {
        $inGroup    = $this->makeStudent(['group_id' => 7, 'department_id' => 4]);
        $wrongGroup = $this->makeStudent(['group_id' => 8, 'department_id' => 4]);

        $this->addTarget(['group_id' => 7, 'department_id' => 4]);

        $this->assertTrue($this->repo->studentIsEligibleForExam($this->examId, $inGroup));
        $this->assertFalse($this->repo->studentIsEligibleForExam($this->examId, $wrongGroup));
    }

    #[Test]
    public function count_matching_reflects_the_same_eligibility_rules_used_for_preview(): void
    {
        // countMatching()/listMatching() هي نفس الـ SQL fragment المستخدم في
        // studentIsEligibleForExam() — لازم ترجع نفس العدد اللي هنلاقيه لو
        // فلترنا الطلاب واحد واحد بالـ eligibility check.
        $this->makeStudent(['faculty_id' => 1]);
        $this->makeStudent(['faculty_id' => 1]);
        $this->makeStudent(['faculty_id' => 2]);

        $count = $this->repo->countMatching($this->universityId, [
            ['faculty_id' => 1, 'department_id' => null, 'academic_year' => null, 'group_id' => null, 'student_id' => null],
        ]);

        $this->assertSame(2, $count);
    }

    #[Test]
    public function inactive_students_are_excluded_from_count_matching(): void
    {
        // countMatching()/listMatching() بتفلتر على u.status = 'active' —
        // طالب حسابه معطل مايتحسبش حتى لو مطابق لقواعد الاستهداف.
        $this->makeStudent(['faculty_id' => 1]);

        $suspendedUserId = DB::table('users')->insertGetId([
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'full_name'     => 'Suspended Student',
            'email'         => 'suspended_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'),
            'status'        => 'suspended',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('students')->insert([
            'user_id'        => $suspendedUserId,
            'university_id'  => $this->universityId,
            'student_number' => 'S' . uniqid(),
            'faculty_id'     => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $count = $this->repo->countMatching($this->universityId, [
            ['faculty_id' => 1, 'department_id' => null, 'academic_year' => null, 'group_id' => null, 'student_id' => null],
        ]);

        $this->assertSame(1, $count);
    }
}
