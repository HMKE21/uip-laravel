<?php

namespace App\Repositories;

use App\Models\Department;
use Illuminate\Support\Facades\DB;

/** منقولة من app/Repositories/DepartmentRepository.php القديمة. */
class DepartmentRepository
{
    public function find($id): ?Department
    {
        return Department::find($id);
    }

    /** Lookup محكوم بالملكية، مقيّد بكلية واحدة. */
    public function findOwnedByFaculty($id, $facultyId): ?Department
    {
        $department = Department::find($id);
        if (!$department || (int) $department->faculty_id !== (int) $facultyId) {
            return null;
        }
        return $department;
    }

    /** Lookup محكوم بالملكية، مقيّد بجامعة كاملة (join واحد بدل رحلتين). */
    public function findOwnedByUniversity($id, $universityId, bool $withTrashed = false): ?Department
    {
        $row = DB::table('departments as d')
            ->join('faculties as f', 'f.id', '=', 'd.faculty_id')
            ->when(!$withTrashed, fn ($q) => $q->whereNull('d.deleted_at'))
            ->where('d.id', $id)
            ->where('f.university_id', $universityId)
            ->select('d.*')
            ->first();

        return $row ? Department::hydrate([(array) $row])->first() : null;
    }

    public function findBySlug($facultyId, string $slug): ?Department
    {
        return Department::where('faculty_id', $facultyId)->where('slug', $slug)->first();
    }

    /** @return Department[] كل أقسام كلية واحدة */
    public function forFaculty($facultyId, ?string $status = null): array
    {
        $query = Department::where('faculty_id', $facultyId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderBy('name_en')->get()->all();
    }

    /** @return Department[] الأقسام المحذوفة (قابلة للاسترجاع) لكلية واحدة */
    public function trashedForFaculty($facultyId): array
    {
        return Department::onlyTrashed()->where('faculty_id', $facultyId)->orderByDesc('deleted_at')->get()->all();
    }

    /** كل أقسام جامعة كاملة (عبر كل كلياتها)، مع اسم الكلية — لفلتر "Department" في Innovation Statistics. منقولة من DepartmentRepository::forUniversity() القديمة. بند 23. @return array<int,array<string,mixed>> */
    public function forUniversity($universityId): array
    {
        return DB::table('departments as d')
            ->join('faculties as f', 'f.id', '=', 'd.faculty_id')
            ->where('f.university_id', $universityId)
            ->whereNull('d.deleted_at')
            ->select('d.*', 'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar')
            ->orderBy('f.name_en')
            ->orderBy('d.name_en')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function create(array $data): Department
    {
        return Department::create($data);
    }

    public function slugExists($facultyId, string $slug, ?int $excludeId = null): bool
    {
        // withTrashed: a soft-deleted department still owns its slug (unique per faculty) until purged.
        $query = Department::withTrashed()->where('faculty_id', $facultyId)->where('slug', $slug);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        return $query->exists();
    }

    /**
     * Same shape as FacultyRepository::withProfileStats() — powers the new
     * Department Portfolio page (GET /api/v1/departments/{id}). Adds the
     * owning faculty's name for the page's breadcrumb since a department
     * portfolio is always viewed in the context of its faculty.
     * @return array<string,mixed>|null
     */
    public function withProfileStats($id): ?array
    {
        $row = DB::table('departments as d')
            ->join('faculties as f', 'f.id', '=', 'd.faculty_id')
            ->select('d.*', 'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.department_id', 'd.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('academic_staff as st')->whereColumn('st.department_id', 'd.id');
            }, 'academic_staff_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as pr')
                    ->join('students as s', 's.user_id', '=', 'pr.owner_id')
                    ->whereColumn('s.department_id', 'd.id')
                    ->where('pr.status', 'published');
            }, 'published_projects_count')
            ->where('d.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }
}
