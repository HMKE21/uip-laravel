<?php

namespace App\Repositories;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/StudentRepository.php القديمة. القديمة فيها
 * كمان distinctScopeValues()/fullHierarchyForUser() (لبند Supervisors
 * لاحقًا) — مش منقولة هنا عمدًا، هتتضاف لما بنده ييجي. bulkAssignGroup()
 * انضافت مع بند 16 (Groups).
 */
class StudentRepository
{
    public function find($id): ?Student
    {
        return Student::find($id);
    }

    public function findByUserId($userId): ?Student
    {
        return Student::where('user_id', $userId)->first();
    }

    /** نفس findByUserId()، لكن بتعمل self-heal لحساب اتسجل قبل ما التسجيل يبقى بيعمل provision لصف students تلقائي. */
    public function getOrCreate($userId): Student
    {
        $student = $this->findByUserId($userId);
        if ($student) {
            return $student;
        }
        return Student::create(['user_id' => $userId]);
    }

    /**
     * بروفايل طالب واحد كامل — أعمدة الحساب (اسم/إيميل/تليفون/أفاتار) +
     * أسماء الكلية/القسم/البرنامج المتحلة — لصفحة show()/update().
     */
    public function withProfileDetails($id): ?array
    {
        $row = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 's.university_id')
            ->leftJoin('faculties as f', 'f.id', '=', 's.faculty_id')
            ->leftJoin('departments as d', 'd.id', '=', 's.department_id')
            ->select(
                's.*',
                'u.full_name', 'u.name_ar', 'u.name_en', 'u.email', 'u.phone', 'u.avatar_path', 'u.status as account_status',
                'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar',
                'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar',
                'd.name_en as department_name_en', 'd.name_ar as department_name_ar'
            )
            ->where('s.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * التسلسل الهرمي المؤسسي الكامل لطالب واحد (بحساب لوجينه، مش صف
     * students.id) — جامعة/كلية/قسم + مجموعته، لصفحة Contacts
     * (بند 25). نفس أعمدة withProfileDetails() تقريبًا بس مفتاحة بـ
     * user_id ومعاها group_id/group_name صراحة عشان الفرونت يقرر يعرض
     * كارت "My Group Members" ولا لأ.
     */
    public function fullHierarchyForUser($userId): ?array
    {
        $row = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 's.university_id')
            ->leftJoin('faculties as f', 'f.id', '=', 's.faculty_id')
            ->leftJoin('departments as d', 'd.id', '=', 's.department_id')
            ->leftJoin('student_groups as g', 'g.id', '=', 's.group_id')
            ->select(
                's.*',
                'u.full_name', 'u.name_ar', 'u.name_en', 'u.email', 'u.phone', 'u.avatar_path', 'u.status as account_status',
                'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar',
                'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar',
                'd.name_en as department_name_en', 'd.name_ar as department_name_ar',
                'g.name as group_name'
            )
            ->where('s.user_id', $userId)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * كل طلاب جامعة، مع حساباتهم + اسم المجموعة + عدد مشاريع لايف —
     * الشكل اللي index() (سكوب جامعة) محتاجه.
     * @return array<int,array<string,mixed>>
     */
    public function forUniversityWithStats($universityId): array
    {
        return $this->withStatsQuery()->where('s.university_id', $universityId)->get()->map(fn ($r) => (array) $r)->all();
    }

    /** نفس forUniversityWithStats()، بس محكومة بكلية واحدة — لـ index() (سكوب كلية). */
    public function forFacultyWithStats($facultyId): array
    {
        return $this->withStatsQuery()->where('s.faculty_id', $facultyId)->get()->map(fn ($r) => (array) $r)->all();
    }

    private function withStatsQuery()
    {
        return DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('student_groups as g', 'g.id', '=', 's.group_id')
            ->select('s.*', 'u.full_name', 'u.name_ar', 'u.name_en', 'u.email', 'u.status as account_status', 'g.name as group_name')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')->whereColumn('p.owner_id', 's.user_id');
            }, 'projects_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')->whereColumn('p.owner_id', 's.user_id')->where('p.status', 'published');
            }, 'published_count')
            ->orderBy('u.full_name');
    }

    /**
     * Lookup محكوم بالملكية — كل تعديل (update/delete/resend/activate/
     * deactivate) لازم يعدي من هنا. $facultyId اختياري: بورتال الكلية
     * (migration 106) بيمررها عشان حساب كلية يقدر بس يتصرف في طلاب كليته.
     */
    public function findOwned($id, $universityId, $facultyId = null): ?Student
    {
        $student = Student::find($id);
        if (!$student || (int) $student->university_id !== (int) $universityId) {
            return null;
        }
        if ($facultyId !== null && (int) $student->faculty_id !== (int) $facultyId) {
            return null;
        }
        return $student;
    }

    public function findByStudentNumber($universityId, string $studentNumber): ?Student
    {
        return Student::where('university_id', $universityId)->where('student_number', $studentNumber)->first();
    }

    /**
     * بند 14 — Graduation: قائمة خفيفة (Model instances، من غير join
     * إحصائيات) لكل طلاب جامعة/كلية — GraduationService::
     * listForUniversity() بتلف عليها وتحسب أهلية كل طالب واحد واحد
     * (checkEligibility() اللي أصلًا بتعمل query تانية بتفاصيل كاملة)،
     * فمفيش داعي لنفس الـ join الثقيل بتاع forUniversityWithStats() هنا.
     * @return \Illuminate\Support\Collection<int,Student>
     */
    public function forUniversity($universityId)
    {
        return Student::where('university_id', $universityId)->get();
    }

    /** نفس forUniversity()، بس محكومة بكلية واحدة — لبورتال الكلية. */
    public function forFaculty($facultyId)
    {
        return Student::where('faculty_id', $facultyId)->get();
    }

    /**
     * Exam System (Round 2 targeting-picker follow-up): typeahead search
     * لطالب بعينه، محكومة بجامعة الكولر — نفس نمط UniversityRepository::
     * searchAll() (LIKE + LIMIT، من غير الـ join الثقيل بتاع
     * withStatsQuery()). بترجع اسم الكلية/القسم كنص (الأعمدة النصية
     * القديمة faculty/department على students نفسها — راجع Student
     * model docblock) عشان الواجهة تقدر تعرضهم من غير استعلام تاني.
     * بترشح على الحسابات active بس، زي ExamTargetRepository بالظبط.
     * @return array<int,array<string,mixed>>
     */
    public function searchForTargeting($universityId, string $q, int $limit = 20): array
    {
        $needle = '%' . $q . '%';

        return DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('student_groups as g', 'g.id', '=', 's.group_id')
            ->select(
                's.id', 's.student_number', 's.faculty', 's.faculty_id',
                's.department', 's.department_id',
                's.academic_year', 's.group_id', 'g.name as group_name',
                'u.full_name', 'u.name_ar', 'u.name_en', 'u.email'
            )
            ->where('s.university_id', $universityId)
            ->where('u.status', 'active')
            ->where(function ($w) use ($needle) {
                $w->where('u.full_name', 'like', $needle)
                    ->orWhere('u.name_ar', 'like', $needle)
                    ->orWhere('u.name_en', 'like', $needle)
                    ->orWhere('u.email', 'like', $needle)
                    ->orWhere('s.student_number', 'like', $needle);
            })
            ->orderBy('u.full_name')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * بند 16 — Groups: نقل مجموعة طلاب (اتفحصت ملكيتهم أصلًا) لمجموعة، أو
     * تصفير المجموعة لو $groupId = null. منقولة من القديمة حرف بحرف
     * (Core\Database -> DB facade).
     */
    public function bulkAssignGroup(array $studentIds, $universityId, $groupId): int
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds)));
        if (!$studentIds) {
            return 0;
        }

        return DB::table('students')
            ->whereIn('id', $studentIds)
            ->where('university_id', $universityId)
            ->update(['group_id' => $groupId]);
    }
}
