<?php

namespace App\Repositories;

use App\Models\Faculty;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/FacultyRepository.php القديمة. findOwned()
 * هي أي lookup محتاج تعديل لازم يعدي منها — عشان جامعة متقدرش تقرا/تعدل
 * كلية جامعة تانية.
 */
class FacultyRepository
{
    public function find($id): ?Faculty
    {
        return Faculty::find($id);
    }

    /** الكلية اللي حساب Faculty-portal (migration 106) مربوط بيها. */
    public function findByUserId($userId): ?Faculty
    {
        return Faculty::where('user_id', $userId)->first();
    }

    /** Lookup محكوم بالملكية — كل تعديل لازم يعدي من هنا. */
    public function findOwned($id, $universityId): ?Faculty
    {
        $faculty = Faculty::find($id);
        if (!$faculty || (int) $faculty->university_id !== (int) $universityId) {
            return null;
        }
        return $faculty;
    }

    public function findBySlug($universityId, string $slug): ?Faculty
    {
        return Faculty::where('university_id', $universityId)->where('slug', $slug)->first();
    }

    /** @return Faculty[] كل كليات جامعة واحدة */
    public function forUniversity($universityId, ?string $status = null): array
    {
        $query = Faculty::where('university_id', $universityId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderBy('name_en')->get()->all();
    }

    /**
     * قائمة كليات بعدد الأقسام لايف — للوحة الجامعة. `$status` اختياري:
     * لو معدّى، بيفلتر مطابقة تامة (مثلاً 'archived' لتاب الأرشيف). لو
     * null، بترجع كل الكليات أيًّا كانت حالتها — الكنترولر هو اللي
     * بيقرر يستبعد الأرشيف ولا لأ افتراضيًا.
     * @return array<int,array<string,mixed>>
     */
    public function forUniversityWithCounts($universityId, ?string $status = null): array
    {
        $query = DB::table('faculties as f')
            ->select('f.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('departments as d')->whereNull('d.deleted_at')->whereColumn('d.faculty_id', 'f.id');
            }, 'departments_count')
            ->where('f.university_id', $universityId);

        if ($status !== null) {
            $query->where('f.status', $status);
        }

        return $query->orderBy('f.name_en')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function create(array $data): Faculty
    {
        return Faculty::create($data);
    }

    public function slugExists($universityId, string $slug, ?int $excludeId = null): bool
    {
        $query = Faculty::where('university_id', $universityId)->where('slug', $slug);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        return $query->exists();
    }

    /**
     * كلية واحدة + عدد أقسام/برامج/طلاب/كادر أكاديمي/مشاريع منشورة لايف —
     * يطابق UniversityRepository::withProfileStats() في الشكل.
     * @return array<string,mixed>|null
     */
    public function withProfileStats($id): ?array
    {
        $row = DB::table('faculties as f')
            ->select('f.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('departments as d')->whereNull('d.deleted_at')->whereColumn('d.faculty_id', 'f.id');
            }, 'departments_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.faculty_id', 'f.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('academic_staff as st')->whereColumn('st.faculty_id', 'f.id');
            }, 'academic_staff_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as pr')
                    ->join('students as s', 's.user_id', '=', 'pr.owner_id')
                    ->whereColumn('s.faculty_id', 'f.id')
                    ->where('pr.status', 'published');
            }, 'published_projects_count')
            // بريد حساب دخول الكلية الحالي (users.email عبر f.user_id) — لعرضه
            // في UI تغيير الإيميل يدويًا؛ null لو لسه معملهاش login.
            ->selectSub(function ($q) {
                $q->select('email')->from('users')->whereColumn('users.id', 'f.user_id');
            }, 'login_email')
            ->where('f.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }
}
