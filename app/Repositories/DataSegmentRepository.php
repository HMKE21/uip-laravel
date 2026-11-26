<?php

namespace App\Repositories;

use App\Models\DataSegment;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/DataSegmentRepository.php القديمة — بند 24
 * batch 2. Data access لـ `data_segments` (migration 042). `criteria`
 * عمود JSON، بيتعامل معاه هنا يدويًا encode/decode.
 */
class DataSegmentRepository
{
    /** الأدوار القابلة للاختيار في فلتر "role" بتاع Segments — نفس الأدوار اللي مولّد الديمو بيزرعها بالجملة. */
    public const USER_ROLE_SLUGS = ['student', 'university'];

    /** قيم users.status (migration 001) — لازم criteria الـ segment تطابقها بالظبط، مش label مترجم. */
    public const USER_STATUS_VALUES = ['active', 'pending', 'suspended', 'banned'];

    /** الكيانات المسموحة في Segments (لازم تطابق enum/عمود data_segments.entity). */
    public const ENTITIES = ['projects', 'users', 'students', 'exams', 'courses'];

    /** students.invitation_status (migration 005). */
    public const STUDENT_INVITATION_VALUES = ['pending', 'accepted', 'expired'];

    /** السنة الدراسية 1..8 (students.academic_year / courses.academic_year). */
    public const ACADEMIC_YEAR_VALUES = [1, 2, 3, 4, 5, 6, 7, 8];

    /** exams.status (migration 2026_08_28_150000). */
    public const EXAM_STATUS_VALUES = ['draft', 'scheduled', 'published', 'active', 'closed', 'grading', 'graded', 'archived'];

    /** exams.result_visibility. */
    public const EXAM_RESULT_VISIBILITY_VALUES = ['immediate', 'after_close', 'manual'];

    /** courses.status / courses.semester. */
    public const COURSE_STATUS_VALUES = ['active', 'archived'];
    public const COURSE_SEMESTER_VALUES = [1, 2];

    /**
     * خيارات الفلاتر المسموحة لكل كيان — بتتبعت للفرونت عشان القوائم
     * تفضل متزامنة مع تحقق الباك إند.
     * @return array<string,array<string,array<int,string|int>>>
     */
    public static function criteriaOptions(): array
    {
        return [
            'students' => [
                'status'            => self::USER_STATUS_VALUES,
                'academic_year'     => self::ACADEMIC_YEAR_VALUES,
                'invitation_status' => self::STUDENT_INVITATION_VALUES,
            ],
            'exams' => [
                'status'            => self::EXAM_STATUS_VALUES,
                'result_visibility' => self::EXAM_RESULT_VISIBILITY_VALUES,
            ],
            'courses' => [
                'status'        => self::COURSE_STATUS_VALUES,
                'semester'      => self::COURSE_SEMESTER_VALUES,
                'academic_year' => self::ACADEMIC_YEAR_VALUES,
            ],
        ];
    }

    public function create($userId, string $name, string $entity, array $criteria): DataSegment
    {
        return DataSegment::create([
            'user_id'  => $userId,
            'name'     => $name,
            'entity'   => $entity,
            'criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<int,array<string,mixed>> مع `criteria` مفكوكة ومعاها matched_count حي */
    public function forUser($userId): array
    {
        $rows = DB::table('data_segments')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        return $rows->map(function ($row) {
            $row = (array) $row;
            $row['criteria'] = json_decode($row['criteria'] ?? '{}', true) ?: [];
            $row['matched_count'] = $this->countMatches($row['entity'], $row['criteria']);
            return $row;
        })->all();
    }

    public function findOwned($id, $userId): ?DataSegment
    {
        $s = DataSegment::find($id);
        if ($s && (string) $s->user_id === (string) $userId) {
            return $s;
        }
        return null;
    }

    public function delete($id, $userId): bool
    {
        $s = $this->findOwned($id, $userId);
        if (!$s) {
            return false;
        }
        return (bool) $s->delete();
    }

    /**
     * بتقيّم criteria الـ segment المحفوظة على الجدول الحقيقي دلوقتي، بدل
     * ما تكاش عدد قديم — الـ segments المفروض تكون فلاتر حية. بس مجموعة
     * محدودة allow-listed من معايير equality/range مدعومة لكل entity، عشان
     * نتجنب بناء query builder عام (وقابل للحقن) هنا.
     */
    private function countMatches(string $entity, array $criteria): int
    {
        if ($entity === 'projects') {
            $query = DB::table('projects');
            if (!empty($criteria['category'])) {
                $query->where('category', $criteria['category']);
            }
            if (!empty($criteria['status'])) {
                $query->where('status', $criteria['status']);
            }
            return $query->count();
        }

        if ($entity === 'users') {
            $query = DB::table('users as u');
            if (!empty($criteria['status'])) {
                $query->where('u.status', $criteria['status']);
            }
            if (!empty($criteria['role'])) {
                $query->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
                    ->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->where('r.slug', $criteria['role']);
            }
            return $query->count();
        }

        if ($entity === 'students') {
            // students ⨝ users: حالة الحساب (users.status) + بيانات الطالب نفسها.
            $query = DB::table('students as s')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->whereNull('u.deleted_at');
            if (!empty($criteria['status'])) {
                $query->where('u.status', $criteria['status']);
            }
            if (!empty($criteria['academic_year'])) {
                $query->where('s.academic_year', (int) $criteria['academic_year']);
            }
            if (!empty($criteria['invitation_status'])) {
                $query->where('s.invitation_status', $criteria['invitation_status']);
            }
            return $query->count();
        }

        if ($entity === 'exams') {
            $query = DB::table('exams')->whereNull('deleted_at');
            if (!empty($criteria['status'])) {
                $query->where('status', $criteria['status']);
            }
            if (!empty($criteria['result_visibility'])) {
                $query->where('result_visibility', $criteria['result_visibility']);
            }
            return $query->count();
        }

        if ($entity === 'courses') {
            $query = DB::table('courses');
            if (!empty($criteria['status'])) {
                $query->where('status', $criteria['status']);
            }
            if (!empty($criteria['semester'])) {
                $query->where('semester', (int) $criteria['semester']);
            }
            if (!empty($criteria['academic_year'])) {
                $query->where('academic_year', (int) $criteria['academic_year']);
            }
            return $query->count();
        }

        return 0;
    }
}