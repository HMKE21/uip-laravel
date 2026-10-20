<?php

namespace App\Repositories;

use App\Models\SupervisorAssignment;
use Illuminate\Support\Facades\DB;

/**
 * منقولة كاملة من app/Repositories/SupervisorAssignmentRepository.php
 * القديمة (Core\Database -> DB facade + Eloquent) — بند 9 (Supervisors).
 * كانت جزئية (supervisorsForStudent() بس، لبند 4/StudentsApiController)
 * — اتكمّلت هنا بباقي الميثودز اللي SupervisorsApiController/
 * SupervisorManagementService محتاجينها: forSupervisor/
 * forSupervisorWithLabels/create/exists/deleteOwned/deleteAllForSupervisor/
 * scopedStudents/scopedProjects/projectInScope/coversStudent.
 * scopedStudents()/scopedProjects() هي أساس "المشرف بيدير بس الطلاب/
 * المشاريع المُسندة له" — كل كنترولر بند 9 المفروض يفلتر عبرهم، مش
 * يستعلم على students/projects مباشرة.
 */
class SupervisorAssignmentRepository
{
    /** @return SupervisorAssignment[] */
    public function forSupervisor($supervisorId): array
    {
        return SupervisorAssignment::where('supervisor_id', $supervisorId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * صفوف مُجهّزة للوحة "Manage Access" بتاعة الجامعة — نطاقات المشروع
     * بتحمل عنوان المشروع؛ نطاقات faculty/department/group بتحمل اسم
     * الكيان الحقيقي، بعد تحويل scope_value (id مخزّن كنص) لـ FK بتاعه.
     */
    public function forSupervisorWithLabels($supervisorId): array
    {
        return DB::select(
            "SELECT sa.*, p.title_ar AS project_title_ar, p.title_en AS project_title_en,
                    f.name_ar AS faculty_name_ar, f.name_en AS faculty_name_en,
                    d.name_ar AS department_name_ar, d.name_en AS department_name_en,
                    g.name AS group_name
             FROM supervisor_assignments sa
             LEFT JOIN projects p ON p.id = sa.project_id AND sa.scope_type = 'project'
             LEFT JOIN faculties f ON sa.scope_type = 'faculty' AND f.id = CAST(sa.scope_value AS UNSIGNED)
             LEFT JOIN departments d ON sa.scope_type = 'department' AND d.id = CAST(sa.scope_value AS UNSIGNED)
             LEFT JOIN student_groups g ON sa.scope_type = 'group' AND g.id = CAST(sa.scope_value AS UNSIGNED)
             WHERE sa.supervisor_id = ?
             ORDER BY sa.created_at DESC",
            [$supervisorId]
        );
    }

    public function create(array $data): SupervisorAssignment
    {
        return SupervisorAssignment::create($data);
    }

    /** True لو النطاق ده مُسند بالفعل (بيمنع صفوف مكررة عند تكرار الإرسال). */
    public function exists($supervisorId, string $scopeType, ?string $scopeValue, $projectId = null): bool
    {
        $query = SupervisorAssignment::where('supervisor_id', $supervisorId)->where('scope_type', $scopeType);
        if ($scopeType === 'project') {
            $query->where('project_id', $projectId);
        } else {
            $query->where('scope_value', $scopeValue);
        }
        return $query->exists();
    }

    public function deleteOwned($id, $supervisorId): bool
    {
        $row = SupervisorAssignment::find($id);
        if (!$row || (int) $row->supervisor_id !== (int) $supervisorId) {
            return false;
        }
        return (bool) $row->delete();
    }

    public function deleteAllForSupervisor($supervisorId): void
    {
        SupervisorAssignment::where('supervisor_id', $supervisorId)->delete();
    }

    /**
     * كل الطلاب اللي المشرف ده شايفهم: أي حد بيطابق نطاق faculty/
     * department/academic_year شغال، UNION أصحاب أي مشروع مُعيّن صراحة.
     * صفوف طالب+حساب كاملة (نفس شكل StudentRepository::
     * forUniversityWithStats) عشان صفحة Supervisor Students تقدر تعيد
     * استخدام نفس منطق عرض الصفوف.
     */
    public function scopedStudents($supervisorId, $universityId): array
    {
        $scopes = $this->forSupervisor($supervisorId);
        if (empty($scopes)) {
            return [];
        }

        [$where, $bindings] = $this->scopeWhereClause($scopes);
        if ($where === '') {
            return [];
        }

        $sql = "SELECT s.*, u.full_name, u.status AS account_status,
                        (SELECT COUNT(*) FROM projects p WHERE p.owner_id = s.user_id) AS projects_count,
                        (SELECT COUNT(*) FROM projects p WHERE p.owner_id = s.user_id AND p.status = 'published') AS published_count
                 FROM students s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.university_id = ? AND ({$where})
                 ORDER BY u.full_name ASC";

        return DB::select($sql, array_merge([$universityId], $bindings));
    }

    /**
     * كل المشاريع اللي المشرف ده شايفها: أي مشروع لطالب داخل نطاقه
     * (faculty/department/academic_year)، UNION أي مشروع مُعيّن بالـ id
     * صراحة.
     */
    public function scopedProjects($supervisorId, $universityId, ?string $status = null): array
    {
        $scopes = $this->forSupervisor($supervisorId);
        if (empty($scopes)) {
            return [];
        }

        $projectIds = array_values(array_filter(array_map(
            fn ($s) => $s->scope_type === 'project' ? (int) $s->project_id : null,
            $scopes
        )));
        $textScopes = array_filter($scopes, fn ($s) => $s->scope_type !== 'project');

        $orParts = [];
        $bindings = [$universityId];

        if (!empty($textScopes)) {
            [$studentWhere, $studentBindings] = $this->scopeWhereClause(array_values($textScopes));
            if ($studentWhere !== '') {
                $orParts[] = "p.owner_id IN (SELECT s.user_id FROM students s WHERE s.university_id = ? AND ({$studentWhere}))";
                $bindings[] = $universityId;
                $bindings = array_merge($bindings, $studentBindings);
            }
        }

        if (!empty($projectIds)) {
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $orParts[] = "p.id IN ({$placeholders})";
            $bindings = array_merge($bindings, $projectIds);
        }

        if (empty($orParts)) {
            return [];
        }

        $sql = "SELECT p.*, u.full_name AS owner_name, s.faculty AS owner_faculty
                FROM projects p
                INNER JOIN users u ON u.id = p.owner_id
                LEFT JOIN students s ON s.user_id = p.owner_id
                WHERE p.university_id = ? AND (" . implode(' OR ', $orParts) . ')';
        if ($status !== null) {
            $sql .= ' AND p.status = ?';
            $bindings[] = $status;
        }
        $sql .= ' ORDER BY p.created_at DESC';

        return DB::select($sql, $bindings);
    }

    /** True لو المشروع ده داخل نطاق المشرف — بوابة السيرفر قبل approve/reject/comment. */
    public function projectInScope($supervisorId, $universityId, int $projectId): bool
    {
        foreach ($this->scopedProjects($supervisorId, $universityId) as $row) {
            if ((int) $row->id === $projectId) {
                return true;
            }
        }
        return false;
    }

    /**
     * عكس scopedStudents(): كل مشرف شغال نطاقه فعليًا بيغطي الطالب ده،
     * عن طريق faculty_id/department_id/group_id/academic_year الحقيقية،
     * أو مشروع مُعيّن صراحة يملكه. مُستخدمة لحل مشرف(ين) الطالب الحقيقيين
     * كـ targets للمراسلة — أبدًا مش مشرف عشوائي في المنصة.
     * @return array<int,array{id:int,user_id:int,full_name:string,email:string}>
     */
    public function supervisorsForStudent($studentUserId): array
    {
        return DB::select(
            "SELECT DISTINCT sup.id, sup.user_id, u.full_name, u.email
             FROM students s
             INNER JOIN supervisor_assignments sa ON sa.university_id = s.university_id AND (
                 (sa.scope_type = 'faculty'       AND s.faculty_id IS NOT NULL       AND CAST(sa.scope_value AS UNSIGNED) = s.faculty_id) OR
                 (sa.scope_type = 'department'    AND s.department_id IS NOT NULL    AND CAST(sa.scope_value AS UNSIGNED) = s.department_id) OR
                 (sa.scope_type = 'group'         AND s.group_id IS NOT NULL         AND CAST(sa.scope_value AS UNSIGNED) = s.group_id) OR
                 (sa.scope_type = 'academic_year' AND s.academic_year IS NOT NULL    AND sa.scope_value = CAST(s.academic_year AS CHAR) COLLATE utf8mb4_unicode_ci) OR
                 (sa.scope_type = 'project' AND sa.project_id IN (SELECT p.id FROM projects p WHERE p.owner_id = s.user_id))
             )
             INNER JOIN supervisors sup ON sup.id = sa.supervisor_id AND sup.status = 'active' AND sup.user_id IS NOT NULL
             INNER JOIN users u ON u.id = sup.user_id
             WHERE s.user_id = ?
             ORDER BY u.full_name ASC",
            [$studentUserId]
        );
    }

    /** True لو نطاق $supervisorId بيغطي الطالب اللي وراه $studentUserId — بوابة مراسلة طالب<->مشرف. */
    public function coversStudent($supervisorId, $studentUserId): bool
    {
        foreach ($this->supervisorsForStudent($studentUserId) as $row) {
            if ((int) $row->id === (int) $supervisorId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param SupervisorAssignment[] $scopes
     * @return array{0:string,1:array}
     *
     * نطاقات faculty/department بتطابق على أعمدة الـ FK الحقيقية
     * (students.faculty_id/department_id)، مش أعمدة النص القديمة —
     * scope_value مخزّنة كنص لكنها الـ id الحقيقي (شوف
     * SupervisorManagementService::assignScope())، فبتتحول لرقم للمقارنة.
     */
    private function scopeWhereClause(array $scopes): array
    {
        $orParts = [];
        $bindings = [];
        foreach ($scopes as $scope) {
            if ($scope->scope_type === 'faculty') {
                $orParts[] = 's.faculty_id = ?';
                $bindings[] = (int) $scope->scope_value;
            } elseif ($scope->scope_type === 'department') {
                $orParts[] = 's.department_id = ?';
                $bindings[] = (int) $scope->scope_value;
            } elseif ($scope->scope_type === 'group') {
                $orParts[] = 's.group_id = ?';
                $bindings[] = (int) $scope->scope_value;
            } elseif ($scope->scope_type === 'academic_year') {
                $orParts[] = 's.academic_year = ?';
                $bindings[] = $scope->scope_value;
            }
        }
        return [empty($orParts) ? '' : implode(' OR ', $orParts), $bindings];
    }
}
