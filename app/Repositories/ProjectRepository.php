<?php

namespace App\Repositories;

use App\Models\Project;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * منقولة جزئيًا من app/Repositories/ProjectRepository.php القديمة —
 * forOwner() (بند 4) + findByUuid()/findForUniversityByUuid()/
 * forUniversityWithOwner()/updateStatusForUniversity() (بند 5، لطابور
 * اعتماد الجامعة) + forFacultyWithOwner()/findForFacultyByUuid()/
 * updateStatusForFaculty() + الـ breakdown/mostViewed/recent الخاصين
 * بالكلية (بند 10، لطابور اعتماد الكلية + FacultyDashboardController).
 * موديول Projects الكامل (بند 11 — 10 endpoint: CRUD + files/media/team/
 * discussion/AI + ميثودز oversight الأدمن) هيوسّعها لاحقًا بدل ما نكرر شغل.
 */
class ProjectRepository
{
    /**
     * كل مشروع على المنصة، من غير فلتر status — لتقرير "export everything"
     * بتاع بورتال Data Analysis (بند 24 batch 2). على عكس forAdminOversight()
     * (submitted/approved/published/rejected بس)، ده بيشمل الـ drafts
     * الخام كمان، لأن المحلل اللي بيعد "كل حاجة" محتاج الإجمالي الحقيقي.
     * منقولة من ProjectRepository::allWithOwners() القديمة.
     * @return array<int,array<string,mixed>>
     */
    public function allWithOwners(): array
    {
        return DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->orderByDesc('p.created_at')
            ->select('p.*', 'u.full_name as owner_name', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** @return Project[] */
    public function forOwner($ownerId): array
    {
        return Project::where('owner_id', $ownerId)->orderByDesc('created_at')->get()->all();
    }

    /**
     * مشاريع منشورة لجامعة واحدة، جاهزة كـ card array (Project::toCardArray()) —
     * لصفحة /u/{uuid} العامة (PublicApiController::universityProfile()، زي
     * PortfolioService::forPublicShare() بالظبط بس مفلترة بـ university_id
     * مش owner واحد). @return array<int,array<string,mixed>>
     */
    public function publishedByUniversity(int $universityId, int $limit = 24): array
    {
        return Project::where('university_id', $universityId)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Project $p) => $p->toCardArray())
            ->all();
    }

    /**
     * منقولة من app/Repositories/ProjectRepository.php القديمة — بند 21
     * (AIDuplicateDetectionService::candidatePool()). كل مشروع منصة-واسع
     * وصل لمرحلة submitted فما فوق (submitted/under_review/approved/
     * published)، ماعدا $excludeUuid نفسه — مقارنة بـ published() اللي
     * بتاعة الـ Discovery العام، هنا المرشحين بيشملوا حتى لسه تحت
     * المراجعة، لأن التكرار ممكن يتلاحظ من ساعة التقديم.
     */
    public function candidatesForDuplicateCheck(string $excludeUuid, int $limit = 60): array
    {
        return DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->where('p.uuid', '!=', $excludeUuid)
            ->whereIn('p.status', ['submitted', 'under_review', 'approved', 'published'])
            ->orderByDesc('p.created_at')
            ->limit($limit)
            ->select('p.*', 'u.full_name as owner_name')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** بيجيب مشروع بس لو بتاع $ownerId — حارس ملكية لكل أفعال الطالب (CRUD/files/links/team). */
    public function findOwnedByUuid(string $uuid, $ownerId): ?Project
    {
        $project = $this->findByUuid($uuid);
        if ($project && (string) $project->owner_id === (string) $ownerId) {
            return $project;
        }
        return null;
    }

    /** بيعمل مشروع جديد بملكية $ownerId — uuid/status/visibility افتراضيين لو مش متبعتين. */
    public function createForOwner($ownerId, array $data): Project
    {
        $data['uuid'] = $data['uuid'] ?? $this->uuid4();
        $data['owner_id'] = $ownerId;
        $data['status'] = $data['status'] ?? 'draft';
        $data['visibility'] = $data['visibility'] ?? 'private';

        return Project::create($data);
    }

    public function updateOwned(string $uuid, $ownerId, array $data): bool
    {
        $project = $this->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return false;
        }
        $project->fill($data);
        return $project->save();
    }

    public function deleteOwned(string $uuid, $ownerId): bool
    {
        $project = $this->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return false;
        }
        return (bool) $project->delete();
    }

    /** أسماء المشرفين المستخدمة فعليًا في مشاريع جامعة معينة — autocomplete لفورم إنشاء/تعديل مشروع الطالب. @return string[] */
    public function distinctSupervisorNames($universityId, int $limit = 30): array
    {
        if (!$universityId) {
            return [];
        }
        return DB::table('projects')
            ->where('university_id', $universityId)
            ->whereNotNull('supervisor_name')
            ->where('supervisor_name', '!=', '')
            ->distinct()
            ->orderBy('supervisor_name')
            ->limit($limit)
            ->pluck('supervisor_name')
            ->all();
    }

    /**
     * التاجات المستخدمة فعليًا على مستوى المنصة كلها، الأكتر استخدامًا
     * الأول — autocomplete لفورم إنشاء مشروع. عمود `tags` مخزّن JSON، فمفيش
     * بديل عن فك الترميز والعدّ في PHP.
     * @return string[]
     */
    public function distinctTags(int $limit = 40): array
    {
        $rows = DB::table('projects')
            ->whereNotNull('tags')
            ->where('tags', '!=', '')
            ->where('tags', '!=', 'null')
            ->orderByDesc('created_at')
            ->limit(500)
            ->pluck('tags');

        $counts = [];
        foreach ($rows as $raw) {
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, $limit);
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function findByUuid(string $uuid): ?Project
    {
        return Project::where('uuid', $uuid)->first();
    }

    /** مشروع بالـ uuid، بس لو تابع لـ $universityId — وإلا null. */
    public function findForUniversityByUuid(string $uuid, $universityId): ?Project
    {
        $project = $this->findByUuid($uuid);
        if ($project && (string) $project->university_id === (string) $universityId) {
            return $project;
        }
        return null;
    }

    /**
     * صفوف طابور الاعتماد لجامعة، مدموجة مع اسم/كلية الطالب المالك عشان
     * جدول University portal مايحتاجش N+1 كويري. صفوف خام (مش Project
     * models) لأنها بتخلط أعمدة projects + users + students.
     * @return array<int,array<string,mixed>>
     */
    public function forUniversityWithOwner($universityId): array
    {
        return DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('p.university_id', $universityId)
            ->select('p.*', 'u.full_name as owner_name', 's.faculty as owner_faculty')
            ->orderByDesc('p.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * تحديث حالة مشروع مقيّد بجامعته — جامعة تانية أبدًا متقدرش تلمس
     * مشروع مش بتاعها. اعتماد (published) بيعمل slug فريد لو المشروع
     * لسه معندوش واحد (أول مرة ينشر).
     */
    public function updateStatusForUniversity(string $uuid, $universityId, string $status, array $extra = []): bool
    {
        $project = $this->findForUniversityByUuid($uuid, $universityId);
        if (!$project) {
            return false;
        }
        $project->fill(array_merge(['status' => $status], $extra));
        if ($status === 'published' && !$project->slug) {
            $project->slug = $this->generateUniqueSlug($project);
        }
        return $project->save();
    }

    /**
     * عدد المشاريع مقسّم على الحالة لجامعة واحدة — نفس شكل countByStatus()
     * فوق بس بفلترة university_id بدل status عالمي. عليها معدل الاعتماد
     * في UniversityDashboard/Analytics (بند 10). منقولة من
     * ProjectRepository::countByStatusForUniversity() القديمة.
     * @return array<string,int>
     */
    public function countByStatusForUniversity($universityId): array
    {
        $rows = DB::table('projects')
            ->where('university_id', $universityId)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->total;
        }
        return $counts;
    }

    /**
     * عدد المشاريع المقدَّمة لكل شهر تقويمي، لآخر $months شهر (شاملة
     * الشهر الحالي)، الأقدم أولًا — نفس شكل UserRepository::monthlySignups()
     * بس مقيّدة بمشاريع جامعة واحدة. منقولة من
     * ProjectRepository::monthlyActivityForUniversity() القديمة.
     * @return array<int,array{month:string,total:int}>
     */
    public function monthlyActivityForUniversity($universityId, int $months = 6): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();

        $rows = DB::table('projects')
            ->where('university_id', $universityId)
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $result[] = [
                'month' => $month,
                'total' => (int) ($rows[$month]->total ?? 0),
            ];
        }
        return $result;
    }

    /**
     * عدد المشاريع مقسّم على كلية مالك المشروع (owner_id -> students.faculty)
     * جوّه جامعة واحدة — بتنضم مع students لأن الكلية مش عمود على
     * projects. صفوف من غير كلية مسجّلة مستبعدة بدل ما تتعرض كـ "غير
     * معروف". منقولة من ProjectRepository::facultyBreakdownForUniversity()
     * القديمة.
     * @return array<int,array{faculty:string,total:int}>
     */
    public function facultyBreakdownForUniversity($universityId, int $limit = 8): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('p.university_id', $universityId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('s.faculty')
            ->where('s.faculty', '!=', '')
            ->select('s.faculty')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('s.faculty')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * توزيع كلية × تصنيف (faculty x category) جوّه جامعة واحدة — لكارت
     * "Innovation Statistics" في الداشبورد. مفيش عمود ترم/فصل دراسي في
     * الاسكيمة، فده faculty x category بس، مش faculty x semester. منقولة
     * من ProjectRepository::facultyCategoryBreakdownForUniversity() القديمة.
     * @return array<int,array{faculty:string,category:string,total:int}>
     */
    public function facultyCategoryBreakdownForUniversity($universityId, int $limit = 6): array
    {
        $rows = $this->withCategoryAr(DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('p.university_id', $universityId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('s.faculty')
            ->where('s.faculty', '!=', '')
            ->whereNotNull('p.category')
            ->where('p.category', '!=', '')
            ->select('s.faculty', 'p.category')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('s.faculty', 'p.category')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all());

        // students.faculty نص حر (name_en غالبًا) — نضيف الاسم العربي من جدول faculties لو متطابق.
        $facultyAr = DB::table('faculties')->where('university_id', $universityId)->pluck('name_ar', 'name_en')->all();
        return array_map(function ($r) use ($facultyAr) {
            $r['faculty_ar'] = $facultyAr[$r['faculty'] ?? ''] ?? ($r['faculty'] ?? null);
            return $r;
        }, $rows);
    }

    /**
     * عدد المشاريع مقسّم على قسم مالك المشروع، جوّه جامعة واحدة — نفس
     * تقاليد facultyBreakdownForUniversity() فوق (students.department
     * نص حر، مشاريع غير draft بس، صفوف من غير قسم مستبعدة). منقولة من
     * ProjectRepository::departmentBreakdownForUniversity() القديمة.
     * @return array<int,array{department:string,total:int}>
     */
    public function departmentBreakdownForUniversity($universityId, int $limit = 8): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('p.university_id', $universityId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('s.department')
            ->where('s.department', '!=', '')
            ->select('s.department')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('s.department')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * عدد المشاريع مقسّم على السنة الدراسية لمالك المشروع، جوّه جامعة
     * واحدة — مرتّبة تصاعديًا بالسنة (مش بالعدد) عشان trend السنين يتقرا
     * بترتيبه الطبيعي. منقولة من
     * ProjectRepository::academicYearBreakdownForUniversity() القديمة.
     * @return array<int,array{academic_year:mixed,total:int}>
     */
    public function academicYearBreakdownForUniversity($universityId): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('p.university_id', $universityId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('s.academic_year')
            ->select('s.academic_year')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('s.academic_year')
            ->orderBy('s.academic_year')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * أكتر مشاريع جامعة واحدة مشاهدة (status=published بس، views_count
     * بتتزوّد من صفحة المشروع العامة بس — incrementViews()). منقولة من
     * ProjectRepository::mostViewedForUniversity() القديمة.
     * @return array<int,array<string,mixed>>
     */
    public function mostViewedForUniversity($universityId, int $limit = 5): array
    {
        return DB::table('projects')
            ->where('university_id', $universityId)
            ->where('status', 'published')
            ->select('id', 'uuid', 'slug', 'title_ar', 'title_en', 'views_count')
            ->orderByDesc('views_count')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * أحدث مشاريع جامعة واحدة (أي حالة) — لقايمة "Recent Projects" في
     * الداشبورد. منقولة من ProjectRepository::recentForUniversity() القديمة.
     * @return array<int,array<string,mixed>>
     */
    public function recentForUniversity($universityId, int $limit = 5): array
    {
        return DB::table('projects')
            ->where('university_id', $universityId)
            ->select('id', 'uuid', 'slug', 'title_ar', 'title_en', 'status', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    // -- Faculty (بند 10): نفس شكل الميثودز الجامعة فوق بالظبط، لكن
    // مقيّدة بكلية واحدة عبر students.faculty_id (migration 100/102) —
    // projects نفسها معندهاش عمود faculty_id، فالربط بيعدي من مالك
    // المشروع (owner_id -> students.user_id). ميثودز منفصلة (مش parameter
    // زيادة على اللي فوق) عشان bug في مسار الكلية عمره ما يوسّع نطاق
    // مراجع جامعة بالغلط. ------------------------------------------------

    /** زي forUniversityWithOwner()، مقيّدة بكلية واحدة. @return array<int,array<string,mixed>> */
    public function forFacultyWithOwner($facultyId, ?string $status = null): array
    {
        $query = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('s.faculty_id', $facultyId)
            ->select('p.*', 'u.full_name as owner_name', 's.faculty as owner_faculty');

        if ($status !== null) {
            $query->where('p.status', $status);
        }

        return $query->orderByDesc('p.created_at')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** زي findForUniversityByUuid()، محكومة عبر faculty_id الطالب المالك. */
    public function findForFacultyByUuid(string $uuid, $facultyId): ?Project
    {
        $project = $this->findByUuid($uuid);
        if (!$project) {
            return null;
        }
        $student = Student::where('user_id', $project->owner_id)->first();
        if (!$student || (string) $student->faculty_id !== (string) $facultyId) {
            return null;
        }
        return $project;
    }

    /** زي updateStatusForUniversity()، مقيّدة بكلية واحدة. */
    public function updateStatusForFaculty(string $uuid, $facultyId, string $status, array $extra = []): bool
    {
        $project = $this->findForFacultyByUuid($uuid, $facultyId);
        if (!$project) {
            return false;
        }
        $project->fill(array_merge(['status' => $status], $extra));
        if ($status === 'published' && !$project->slug) {
            $project->slug = $this->generateUniqueSlug($project);
        }
        return $project->save();
    }

    /** توزيع المشاريع على الأقسام جوه كلية واحدة (مستبعد draft). @return array<int,array{department:string,total:int}> */
    public function departmentBreakdownForFaculty($facultyId, int $limit = 8): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('s.faculty_id', $facultyId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('s.department')
            ->where('s.department', '!=', '')
            ->select('s.department')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('s.department')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** توزيع المشاريع على التصنيفات (AI, IoT, FinTech...) جوه كلية واحدة. @return array<int,array{category:string,total:int}> */
    public function categoryBreakdownForFaculty($facultyId, int $limit = 8): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('s.faculty_id', $facultyId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('p.category')
            ->where('p.category', '!=', '')
            ->select('p.category')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('p.category')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** توزيع المشاريع على السنة الدراسية جوه كلية واحدة. @return array<int,array{academic_year:mixed,total:int}> */
    public function academicYearBreakdownForFaculty($facultyId): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('s.faculty_id', $facultyId)
            ->where('p.status', '!=', 'draft')
            ->whereNotNull('s.academic_year')
            ->select('s.academic_year')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('s.academic_year')
            ->orderBy('s.academic_year')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** أكتر مشاريع كلية واحدة مشاهدة (status=published بس). @return array<int,array<string,mixed>> */
    public function mostViewedForFaculty($facultyId, int $limit = 5): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('s.faculty_id', $facultyId)
            ->where('p.status', 'published')
            ->select('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en', 'p.views_count')
            ->orderByDesc('p.views_count')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** أحدث مشاريع كلية واحدة (أي حالة). @return array<int,array<string,mixed>> */
    public function recentForFaculty($facultyId, int $limit = 5): array
    {
        return DB::table('projects as p')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('s.faculty_id', $facultyId)
            ->select('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en', 'p.status', 'p.updated_at')
            ->orderByDesc('p.updated_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * تجمّع مشاريع المنصة كلها المنشورة (status=published)، مدموجة مع اسم
     * مالكها + جامعته — نفس الـ pool اللي صفحة Discovery بتاعة الشركة
     * بتتصفحه. صفوف خام (مش Project models) لأنها بتخلط تلات جداول.
     * @param array $filters اختياري: category (مطابقة تامة), search (LIKE على العنوان/الملخص), sort ('newest'|'views')
     * @return array<int,array<string,mixed>>
     */
    public function published(array $filters = []): array
    {
        $query = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->where('p.status', 'published');

        $this->applyPublishedFilters($query, $filters);

        $query->select('p.*', 'u.full_name as owner_name', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar');

        if (($filters['sort'] ?? '') === 'views') {
            $query->orderByDesc('p.views_count');
        } else {
            $query->orderByDesc('p.published_at')->orderByDesc('p.created_at');
        }

        return $query->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * نسخة مقسّمة بصفحات من published() — نفس الفلاتر/الشكل، لكن محدودة
     * بـ LIMIT/OFFSET + كويري COUNT مطابقة، عشان شبكة Discovery الكبيرة
     * ميحتاجش يحمّل كل المنشور مرة واحدة. بند 11 مرحلة 3: إضافة
     * category_name_en/ar/icon + university_slug + live_demo_url/repo_url
     * (subqueries، مش JOIN، عشان مشروع بأكتر من رابط من نفس النوع يفضل
     * صف واحد بس) لصفحة PublicApiController::projects()/الاكتشاف العام —
     * نفس الأعمدة الإضافية دي كانت موجودة في القديمة بالظبط. استدعاءات
     * الشركة/المستثمر/الباحث الحالية بتتجاهل الأعمدة الزيادة دي عادي (زي
     * أي SELECT * إضافي)، فمفيش كسر لحاجة شغالة.
     * @param array $filters اختياري: category (مطابقة تامة), category_slug (slug واحد أو مصفوفة), search (LIKE على العنوان/الملخص), sort ('newest'|'views'|'oldest'|'az')
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function publishedPaginated(array $filters, int $page, int $perPage): array
    {
        $base = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->where('p.status', 'published');
        $this->applyPublishedFilters($base, $filters);

        $total = (clone $base)->count();

        $perPage = max(1, $perPage);
        $offset = max(0, $page - 1) * $perPage;

        $query = (clone $base)
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->select(
                'p.*',
                'u.full_name as owner_name',
                'uni.official_name_en as university_name_en',
                'uni.official_name_ar as university_name_ar',
                'uni.slug as university_slug',
                'cat.slug as category_slug',
                'cat.name_en as category_name_en',
                'cat.name_ar as category_name_ar',
                'cat.icon as category_icon'
            )
            ->selectSub(function ($q) {
                $q->select('pl.url')->from('project_links as pl')
                    ->whereColumn('pl.project_id', 'p.id')
                    ->whereIn('pl.type', ['live_demo', 'website'])
                    ->orderByDesc('pl.is_primary')->orderBy('pl.id')->limit(1);
            }, 'live_demo_url')
            ->selectSub(function ($q) {
                $q->select('pl.url')->from('project_links as pl')
                    ->whereColumn('pl.project_id', 'p.id')
                    ->whereIn('pl.type', ['github', 'gitlab'])
                    ->orderByDesc('pl.is_primary')->orderBy('pl.id')->limit(1);
            }, 'repo_url');

        match ($filters['sort'] ?? '') {
            'views'  => $query->orderByDesc('p.views_count'),
            'oldest' => $query->orderBy('p.published_at')->orderBy('p.created_at'),
            'az'     => $query->orderBy('p.title_en'),
            default  => $query->orderByDesc('p.published_at')->orderByDesc('p.created_at'),
        };

        $rows = $query->limit($perPage)->offset($offset)->get()->map(fn ($r) => (array) $r)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * مرتبة SQL-level بدرجة إمكانية النجاح (startup_potential.potential_score
     * DESC) بدل "حمّل كل المشاريع المنشورة وatsort() في PHP" القديمة —
     * صفحة Startup Potential الخاصة بالمستثمر (بند 8)، نفس فلسفة
     * publishedPaginated() في التعامل مع مجمّعات كبيرة.
     * @param array $filters اختياري: category (مطابقة تامة), search (LIKE على title/summary)
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function publishedRankedByPotentialPaginated(array $filters, int $page, int $perPage): array
    {
        $base = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->join('startup_potential as sp', 'sp.project_id', '=', 'p.id')
            ->where('p.status', 'published');
        $this->applyPublishedFilters($base, $filters);

        $total = (clone $base)->count();

        $perPage = max(1, $perPage);
        $offset = max(0, $page - 1) * $perPage;

        $rows = (clone $base)
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->select('p.*', 'u.full_name as owner_name', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->orderByDesc('sp.potential_score')
            ->limit($perPage)
            ->offset($offset)
            ->get()
            ->map(fn ($r) => (array) $r)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * عكس publishedRankedByPotentialPaginated() — المشاريع المنشورة اللي
     * لسه ما اتحللتش (LEFT JOIN ... IS NULL)، لقسم "لسه ما اتحللش" في نفس
     * الصفحة. مرتبة بالأحدث نشرًا زي published() العادية.
     * @param array $filters اختياري: category (مطابقة تامة), search (LIKE على title/summary)
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function publishedUnanalyzedByPotentialPaginated(array $filters, int $page, int $perPage): array
    {
        $base = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('startup_potential as sp', 'sp.project_id', '=', 'p.id')
            ->where('p.status', 'published')
            ->whereNull('sp.id');
        $this->applyPublishedFilters($base, $filters);

        $total = (clone $base)->count();

        $perPage = max(1, $perPage);
        $offset = max(0, $page - 1) * $perPage;

        $rows = (clone $base)
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->select('p.*', 'u.full_name as owner_name', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->orderByDesc('p.published_at')->orderByDesc('p.created_at')
            ->limit($perPage)
            ->offset($offset)
            ->get()
            ->map(fn ($r) => (array) $r)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** نسخة COUNT بس من publishedPaginated() — بدون تحميل صفوف، لعداد التابات. */
    public function countPublishedFiltered(array $filters): int
    {
        $query = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->where('p.status', 'published');
        $this->applyPublishedFilters($query, $filters);
        return $query->count();
    }

    private function applyPublishedFilters($query, array $filters): void
    {
        if (!empty($filters['category'])) {
            $query->where('p.category', $filters['category']);
        }

        // category_slug: فلترة عبر التصنيف الحقيقي (categories.slug ->
        // projects.category_id) بدل عمود p.category النصي القديم — بند 11
        // مرحلة 3، PublicApiController::projects(). واحد أو أكتر (شريط
        // تصفية الاكتشاف العام بيسمح بأكتر من تصنيف مرة واحدة، checkboxes).
        // منفصل عن 'category' فوق عشان مستدعي زي ProjectDiscoveryService
        // (لسه بيستخدم النصي) يفضل شغال زي ما هو.
        if (!empty($filters['category_slug'])) {
            $slugs = is_array($filters['category_slug'])
                ? array_values(array_filter($filters['category_slug']))
                : [$filters['category_slug']];
            if ($slugs) {
                $query->whereIn('p.category_id', function ($sub) use ($slugs) {
                    $sub->select('id')->from('categories')->whereIn('slug', $slugs);
                });
            }
        }

        if (!empty($filters['search'])) {
            $q = '%' . $filters['search'] . '%';
            $query->where(function ($w) use ($q) {
                $w->where('p.title_en', 'like', $q)
                    ->orWhere('p.title_ar', 'like', $q)
                    ->orWhere('p.summary', 'like', $q);
            });
        }
    }

    /** مشروع منشور واحد بالـ uuid، مدموج بنفس شكل published()، أو null. @return array<string,mixed>|null */
    public function findPublishedByUuid(string $uuid): ?array
    {
        $row = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->where('p.uuid', $uuid)
            ->where('p.status', 'published')
            ->select('p.*', 'u.full_name as owner_name', 'u.email as owner_email', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * مشروع منشور واحد بالـ slug العام — الصف اللي زائر /projects/{slug}
     * (بند 11 مرحلة 3، PublicApiController::projectShow()) بيوصله.
     * نفس بوابة status='published' وشكل الدمج بتاع findPublishedByUuid()،
     * زائد أسماء الكلية/القسم (عبر الطالب المالك) عشان الصفحة العامة
     * تعرض University -> Faculty -> Department في كويري واحدة بدل تلاتة.
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $row = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->leftJoin('students as st', 'st.user_id', '=', 'p.owner_id')
            ->leftJoin('faculties as fac', 'fac.id', '=', 'st.faculty_id')
            ->leftJoin('departments as dep', 'dep.id', '=', 'st.department_id')
            ->where('p.slug', $slug)
            ->where('p.status', 'published')
            ->select(
                'p.*',
                'u.full_name as owner_name',
                'uni.official_name_en as university_name_en',
                'uni.official_name_ar as university_name_ar',
                'uni.slug as university_slug',
                'fac.name_en as faculty_name_en',
                'fac.name_ar as faculty_name_ar',
                'fac.slug as faculty_slug',
                'dep.name_en as department_name_en',
                'dep.name_ar as department_name_ar',
                'dep.slug as department_slug'
            )
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * زي findPublishedBySlug()، لكن متسامحة مع رابط عام لسه شايل uuid
     * المشروع بدل الـ slug — أي مشروع بقى published قبل ما slug يتولد له
     * (أو عدّى مسار مايعديش من updateStatusForUniversity()/
     * updateStatusForFaculty()، زي seeder خام). PublicApiController::
     * projectShow() بينادي دي بدل findPublishedBySlug() مباشرة عشان
     * /public/projects/{slug-or-uuid} يفضل شغال دايمًا، وبيصلّح الصف ذاتيًا:
     * أول ما حد يفتح رابط زي ده، slug حقيقي بيتولد ويتحفظ، فأي رابط بعد
     * كده (شبكة الاكتشاف، الرابط النهائي لنفس الطلب ده) بيستخدم الـ slug
     * من ساعتها.
     */
    public function findPublishedBySlugOrUuid(string $identifier): ?array
    {
        $row = $this->findPublishedBySlug($identifier);
        if ($row) {
            return $row;
        }

        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $identifier)) {
            return null;
        }

        $row = $this->findPublishedByUuid($identifier);
        if (!$row) {
            return null;
        }

        if (empty($row['slug'])) {
            $project = $this->findByUuid($identifier);
            if ($project) {
                $project->slug = $this->generateUniqueSlug($project);
                $project->save();
                $row['slug'] = $project->slug;
            }
        }

        return $row;
    }

    /**
     * مشاريع مميّزة (Admin-curated عبر setFeatured()، مش "الأكتر
     * مشاهدة") لصفحة الهبوط العامة — بند 11 مرحلة 3، PublicApiController::
     * landing(). لسه فاضي لحد ما بند الأدمن (26) يبني شاشة إدارتها؛
     * الصفحة نفسها بتخفي القسم بالكامل لو رجعت فاضية.
     * @return array<int,array<string,mixed>> نفس شكل صفوف publishedPaginated()['rows']
     */
    public function featured(int $limit = 6): array
    {
        $limit = max(1, $limit);

        return DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->where('p.status', 'published')
            ->where('p.is_featured', 1)
            ->select(
                'p.*',
                'u.full_name as owner_name',
                'uni.official_name_en as university_name_en',
                'uni.official_name_ar as university_name_ar',
                'uni.slug as university_slug',
                'cat.slug as category_slug',
                'cat.name_en as category_name_en',
                'cat.name_ar as category_name_ar',
                'cat.icon as category_icon'
            )
            ->selectSub(function ($q) {
                $q->select('pl.url')->from('project_links as pl')
                    ->whereColumn('pl.project_id', 'p.id')
                    ->whereIn('pl.type', ['live_demo', 'website'])
                    ->orderByDesc('pl.is_primary')->orderBy('pl.id')->limit(1);
            }, 'live_demo_url')
            ->selectSub(function ($q) {
                $q->select('pl.url')->from('project_links as pl')
                    ->whereColumn('pl.project_id', 'p.id')
                    ->whereIn('pl.type', ['github', 'gitlab'])
                    ->orderByDesc('pl.is_primary')->orderBy('pl.id')->limit(1);
            }, 'repo_url')
            ->orderBy('p.featured_order')
            ->orderByDesc('p.featured_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** كل التصنيفات المستخدمة فعليًا في المشاريع المنشورة + عدد كل واحدة. @return array<int,array{category:string,total:int}> */
    public function distinctPublishedCategories(): array
    {
        return DB::table('projects')
            ->where('status', 'published')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** عدد المشاريع بحالة معينة على مستوى المنصة كلها. */
    public function countByStatus(string $status): int
    {
        return Project::where('status', $status)->count();
    }

    /** منقولة من ProjectRepository::decisionCounts() القديمة — بند الـ Reports/Analytics (AnalyticsService::platformOverview()) بس دلوقتي. @return array{published:int,rejected:int} */
    public function decisionCounts(): array
    {
        return [
            // معتمد = approved + published (الاعتماد منفصل عن النشر): بيدخلوا في معدل الاعتماد الاتنين.
            'published' => $this->countByStatus('published') + $this->countByStatus('approved'),
            'rejected'  => $this->countByStatus('rejected'),
        ];
    }

    /**
     * توزيع عدد المشاريع (غير المسودة) على كل تصنيف — يطابق
     * ProjectRepository::categoryDistribution() القديمة بالظبط. بند 7
     * (AnalyticsService::categoryDistribution() لصفحة Trends بتاعة الباحث).
     * @return array<int,array{category:string,total:int}>
     */
    /**
     * خريطة اسم التصنيف الإنجليزي → العربي من جدول categories. عمود
     * projects.category بيخزّن name_en (ProjectPublishingService::resolveCategory)،
     * فبنستخدمها نضيف category_ar للتحليلات من غير تغيير الـ GROUP BY.
     * @return array<string,string>
     */
    private function categoryArMap(): array
    {
        static $map = null;
        return $map ??= DB::table('categories')->pluck('name_ar', 'name_en')->all();
    }

    /** يضيف category_ar لكل صف فيه عمود category (fallback: نفس النص). */
    private function withCategoryAr(array $rows): array
    {
        $map = $this->categoryArMap();
        return array_map(function ($r) use ($map) {
            $r['category_ar'] = $map[$r['category'] ?? ''] ?? ($r['category'] ?? null);
            return $r;
        }, $rows);
    }

    public function categoryDistribution(int $limit = 6, array $filters = []): array
    {
        return $this->withCategoryAr(DB::table('projects')
            ->where('status', '!=', 'draft')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->when(!empty($filters['university_id']), fn ($q) => $q->where('university_id', (int) $filters['university_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('created_at', '>=', $filters['date_from'] . ' 00:00:00'))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('created_at', '<=', $filters['date_to'] . ' 23:59:59'))
            ->select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all());
    }

    /**
     * نمو حقيقي مقارنة بفترة سابقة لكل تصنيف (تقديمات آخر $days يوم مقابل
     * الـ $days اللي قبلها) — يطابق ProjectRepository::categoryGrowth()
     * القديمة بالظبط. بند 7 (AnalyticsService::categoryGrowthTrends()).
     * @return array<int,array{category:string,current:int,previous:int,growth_pct:?float}>
     */
    public function categoryGrowth(int $days = 30, int $limit = 6): array
    {
        $periodStart = now()->subDays($days);
        $prevStart = now()->subDays($days * 2);

        $rows = DB::table('projects')
            ->where('status', '!=', 'draft')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->where('created_at', '>=', $prevStart)
            ->select(
                'category',
                DB::raw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS current_count'),
                DB::raw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS previous_count')
            )
            ->addBinding([$periodStart, $prevStart, $periodStart], 'select')
            ->groupBy('category')
            ->havingRaw('current_count > 0 OR previous_count > 0')
            ->orderByDesc('current_count')
            ->limit($limit)
            ->get();

        return $this->withCategoryAr($rows->map(function ($r) {
            $current = (int) $r->current_count;
            $previous = (int) $r->previous_count;
            $growth = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : ($current > 0 ? 100.0 : null);
            return ['category' => $r->category, 'current' => $current, 'previous' => $previous, 'growth_pct' => $growth];
        })->all());
    }

    /**
     * متوسط عدد الأيام من تاريخ إنشاء المشروع لحد قرار مراجعة الجامعة
     * (project_approvals.stage='university_review')، لجامعة واحدة بس —
     * null (مش 0) لو مفيش أي قرار اتاخد لسه، عشان الداشبورد يقدر يعرض
     * "مفيش بيانات كفاية" بدل رقم كاذب. منقولة من
     * ProjectRepository::avgDecisionDaysForUniversity() القديمة. بند 23.
     */
    public function avgDecisionDaysForUniversity($universityId): ?float
    {
        $value = DB::table('project_approvals as pa')
            ->join('projects as p', 'p.id', '=', 'pa.project_id')
            ->where('p.university_id', $universityId)
            ->where('pa.stage', 'university_review')
            ->where('pa.decision', '!=', 'pending')
            ->whereNotNull('pa.decided_at')
            ->selectRaw('AVG(DATEDIFF(pa.decided_at, p.created_at)) as avg_days')
            ->value('avg_days');

        return $value !== null ? round((float) $value, 1) : null;
    }

    /**
     * ترتيب الجامعات حسب حجم المشاريع الحقيقي — لكارت "Innovation
     * Statistics" (University Project Leaderboard). فلترة بفترة تاريخية
     * اختيارية فقط؛ university_id مش منطقي هنا لأن الناتج أصلًا صف لكل
     * جامعة. منقولة من ProjectRepository::universityProjectLeaderboard()
     * القديمة. بند 23.
     * @param array{date_from?:string,date_to?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function universityProjectLeaderboard(int $limit = 5, array $filters = []): array
    {
        return DB::table('universities as uni')
            ->join('projects as p', function ($join) use ($filters) {
                $join->on('p.university_id', '=', 'uni.id')->where('p.status', '!=', 'draft');
                if (!empty($filters['date_from'])) {
                    $join->where('p.created_at', '>=', $filters['date_from'] . ' 00:00:00');
                }
                if (!empty($filters['date_to'])) {
                    $join->where('p.created_at', '<=', $filters['date_to'] . ' 23:59:59');
                }
            })
            ->select('uni.id', 'uni.official_name_en', 'uni.official_name_ar', DB::raw('COUNT(p.id) as projects_count'))
            ->groupBy('uni.id', 'uni.official_name_en', 'uni.official_name_ar')
            ->orderByDesc('projects_count')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * نسبة/عدد المشاريع (غير المسودة) اللي وصلت لقرار "approved"/"published"
     * — Success/Completion Rate. فلترة اختيارية بجامعة/فترة تاريخية، نفس
     * اتفاقية categoryDistribution() فوق. منقولة من
     * ProjectRepository::successRate() القديمة. بند 23.
     * @return array{rate:?float,successful:int,total:int}
     */
    public function successRate(array $filters = []): array
    {
        $row = DB::table('projects')
            ->where('status', '!=', 'draft')
            ->when(!empty($filters['university_id']), fn ($q) => $q->where('university_id', (int) $filters['university_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('created_at', '>=', $filters['date_from'] . ' 00:00:00'))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('created_at', '<=', $filters['date_to'] . ' 23:59:59'))
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('approved','published') THEN 1 ELSE 0 END) as successful")
            ->first();

        $total = (int) ($row->total ?? 0);
        $successful = (int) ($row->successful ?? 0);

        return [
            'rate'       => $total > 0 ? round($successful / $total * 100, 1) : null,
            'successful' => $successful,
            'total'      => $total,
        ];
    }

    /**
     * نظير categoryGrowth() بس مقيّد بجامعة واحدة — لكارت "Trend Analysis"
     * في University Portal > Analytics. منقولة من
     * ProjectRepository::categoryGrowthForUniversity() القديمة. بند 23.
     * @return array<int,array{category:string,current:int,previous:int,growth_pct:?float}>
     */
    public function categoryGrowthForUniversity($universityId, int $days = 30, int $limit = 6): array
    {
        $periodStart = now()->subDays($days);
        $prevStart = now()->subDays($days * 2);

        $rows = DB::table('projects')
            ->where('status', '!=', 'draft')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->where('university_id', $universityId)
            ->where('created_at', '>=', $prevStart)
            ->select(
                'category',
                DB::raw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS current_count'),
                DB::raw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS previous_count')
            )
            ->addBinding([$periodStart, $prevStart, $periodStart], 'select')
            ->groupBy('category')
            ->havingRaw('current_count > 0 OR previous_count > 0')
            ->orderByDesc('current_count')
            ->limit($limit)
            ->get();

        return $this->withCategoryAr($rows->map(function ($r) {
            $current = (int) $r->current_count;
            $previous = (int) $r->previous_count;
            $growth = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : ($current > 0 ? 100.0 : null);
            return ['category' => $r->category, 'current' => $current, 'previous' => $previous, 'growth_pct' => $growth];
        })->all());
    }

    /**
     * نمو حقيقي لكل جامعة (تقديمات آخر $months شهر مقابل اللي قبلهم) —
     * عمود "Growth (N mo.)" في Admin > Innovation Statistics. منقولة من
     * ProjectRepository::universityGrowth() القديمة. بند 23.
     * @param array{university_id?:int|string} $filters
     * @return array<int,array{current:int,previous:int,growth_pct:?float}> university_id => ...
     */
    public function universityGrowth(int $months = 6, array $filters = []): array
    {
        $days = $months * 30;
        $periodStart = now()->subDays($days);
        $prevStart = now()->subDays($days * 2);

        $rows = DB::table('projects')
            ->where('status', '!=', 'draft')
            ->whereNotNull('university_id')
            ->where('created_at', '>=', $prevStart)
            ->when(!empty($filters['university_id']), fn ($q) => $q->where('university_id', (int) $filters['university_id']))
            ->select(
                'university_id',
                DB::raw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS current_count'),
                DB::raw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS previous_count')
            )
            ->addBinding([$periodStart, $prevStart, $periodStart], 'select')
            ->groupBy('university_id')
            ->get();

        $byUni = [];
        foreach ($rows as $r) {
            $current = (int) $r->current_count;
            $previous = (int) $r->previous_count;
            $growth = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : ($current > 0 ? 100.0 : null);
            $byUni[(int) $r->university_id] = ['current' => $current, 'previous' => $previous, 'growth_pct' => $growth];
        }
        return $byUni;
    }

    /** عدد كل المشاريع على مستوى المنصة أيًّا كانت حالتها — كارت "Total Projects" في Admin Dashboard (spec §24). بند 23. */
    public function totalCount(): int
    {
        return Project::count();
    }

    /** عدد المشاريع المنتظرة قرار (submitted + under_review) على مستوى المنصة — كارت "Pending Projects" (spec §24). بند 23. */
    public function pendingCount(): int
    {
        return DB::table('projects')->whereIn('status', ['submitted', 'under_review'])->count();
    }

    /** عدد التصنيفات الحقيقية (المستخدمة فعليًا) المختلفة عبر كل مشروع غير مسودة — كارت "Project Categories" (spec §24). بند 23. */
    public function distinctCategoryCount(): int
    {
        return (int) DB::table('projects')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->count('category');
    }

    /**
     * أكتر مشاريع منشورة مشاهدة (views_count) على مستوى المنصة كلها، مع
     * اسم جامعتها — بانل "Most Viewed" في Admin Analytics. منقولة من
     * ProjectRepository::mostViewedPlatformWide() القديمة. بند 23.
     */
    public function mostViewedPlatformWide(int $limit = 5): array
    {
        return DB::table('projects as p')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->where('p.status', 'published')
            ->select('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en', 'p.views_count', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->orderByDesc('p.views_count')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * الكليات مرتبة حسب حجم المشاريع الحقيقي (join على students.faculty_id
     * لأن projects مفيهاش faculty_id مباشر) — بانل "Most Active Faculties"
     * في Admin Analytics. منقولة من ProjectRepository::facultyProjectLeaderboard()
     * القديمة. بند 23.
     * @return array<int,array{id:int,name_en:string,name_ar:string,university_name_en:?string,university_name_ar:?string,projects_count:int}>
     */
    public function facultyProjectLeaderboard(int $limit = 5): array
    {
        return DB::table('faculties as f')
            ->join('students as s', 's.faculty_id', '=', 'f.id')
            ->join('projects as p', function ($join) {
                $join->on('p.owner_id', '=', 's.user_id')->where('p.status', '!=', 'draft');
            })
            ->leftJoin('universities as u', 'u.id', '=', 'f.university_id')
            ->select('f.id', 'f.name_en', 'f.name_ar', 'u.official_name_en as university_name_en', 'u.official_name_ar as university_name_ar', DB::raw('COUNT(p.id) as projects_count'))
            ->groupBy('f.id', 'f.name_en', 'f.name_ar', 'u.official_name_en', 'u.official_name_ar')
            ->orderByDesc('projects_count')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** نفس شكل facultyProjectLeaderboard() بس على مستوى القسم (students.department_id) — بانل "Most Active Departments". منقولة من ProjectRepository::departmentProjectLeaderboard() القديمة. بند 23. */
    public function departmentProjectLeaderboard(int $limit = 5): array
    {
        return DB::table('departments as d')
            ->whereNull('d.deleted_at')
            ->join('students as s', 's.department_id', '=', 'd.id')
            ->join('projects as p', function ($join) {
                $join->on('p.owner_id', '=', 's.user_id')->where('p.status', '!=', 'draft');
            })
            ->leftJoin('faculties as f', 'f.id', '=', 'd.faculty_id')
            ->leftJoin('universities as u', 'u.id', '=', 'f.university_id')
            ->select('d.id', 'd.name_en', 'd.name_ar', 'f.name_en as faculty_name_en', 'f.name_ar as faculty_name_ar', 'u.official_name_en as university_name_en', 'u.official_name_ar as university_name_ar', DB::raw('COUNT(p.id) as projects_count'))
            ->groupBy('d.id', 'd.name_en', 'd.name_ar', 'f.name_en', 'f.name_ar', 'u.official_name_en', 'u.official_name_ar')
            ->orderByDesc('projects_count')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** عداد مشاهدات، بيتزود كل ما حد يفتح صفحة تفاصيل مشروع منشور. */
    public function incrementViews(string $uuid): void
    {
        DB::table('projects')->where('uuid', $uuid)->increment('views_count');
    }

    /**
     * منقولة من app/Repositories/ProjectRepository.php القديمة — بند 11
     * مرحلة 4 (Admin Featured Projects). كل مشروع منشور، المميّز
     * (is_featured) الأول ومرتب بـ featured_order، والباقي بعده بالأحدث
     * نشرًا — نفس تجمّع Admin\AdminFeaturedProjectsController القديمة
     * بالظبط. صفوف خام (مش Project models) لأن AdminFeaturedProjectsApiController
     * بيرجعها زي ما هي للفرونت.
     * @return array<int,array<string,mixed>>
     */
    public function publishedForFeaturedAdmin(): array
    {
        return DB::table('projects as p')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->where('p.status', 'published')
            ->select('p.*', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->orderByDesc('p.is_featured')
            ->orderBy('p.featured_order')
            ->orderByDesc('p.published_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** تعليم/إلغاء تعليم مشروع كمميّز على صفحة الهبوط، بترتيب معيّن. */
    public function setFeatured(int $projectId, bool $featured, int $order): bool
    {
        return (bool) DB::table('projects')->where('id', $projectId)->update([
            'is_featured'    => $featured ? 1 : 0,
            'featured_order' => $order,
            'featured_at'    => $featured ? now() : null,
        ]);
    }

    /**
     * منقولة من app/Repositories/ProjectRepository.php القديمة — بند 11
     * مرحلة 2 (Submission & Approval Workflow، مسار Admin). فيد إشراف
     * الأدمن على مستوى المنصة كلها: أي مشروع وصل لمرحلة مراجعة الجامعة
     * على الأقل (مش draft لسه ما اتقدمش)، مدموج باسم صاحبه + اسم جامعته.
     * @return array<int,array<string,mixed>>
     */
    public function forAdminOversight(): array
    {
        return DB::select(
            "SELECT p.*, u.full_name AS owner_name,
                    uni.official_name_en AS university_name_en, uni.official_name_ar AS university_name_ar
             FROM projects p
             INNER JOIN users u ON u.id = p.owner_id
             LEFT JOIN universities uni ON uni.id = p.university_id
             WHERE p.status IN ('submitted', 'approved', 'published', 'rejected')
             ORDER BY p.updated_at DESC"
        );
    }

    /**
     * زي forAdminOversight() فوق، بس بفلتر بحث SQL + LIMIT/OFFSET، عشان
     * صفحة Admin > Approval Workflows متحملش الطابور كله مرة واحدة.
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function paginateAdminOversight(string $search, int $page, int $perPage): array
    {
        $where = "WHERE p.status IN ('submitted', 'approved', 'published', 'rejected')";
        $params = [];
        if ($search !== '') {
            $where .= ' AND (p.title_en LIKE ? OR p.title_ar LIKE ? OR uni.official_name_en LIKE ? OR uni.official_name_ar LIKE ?)';
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like];
        }

        $total = (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM projects p
             INNER JOIN users u ON u.id = p.owner_id
             LEFT JOIN universities uni ON uni.id = p.university_id
             {$where}",
            $params
        )->c;

        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1)) * $perPage;
        $rows = DB::select(
            "SELECT p.*, u.full_name AS owner_name,
                    uni.official_name_en AS university_name_en, uni.official_name_ar AS university_name_ar
             FROM projects p
             INNER JOIN users u ON u.id = p.owner_id
             LEFT JOIN universities uni ON uni.id = p.university_id
             {$where}
             ORDER BY p.updated_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** عدادات رخيصة لكروت إحصاء صفحة Approval Workflows — من غير ما تحمّل صفوف. */
    public function countAdminOversight(): array
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'approved' OR status = 'published' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    COUNT(DISTINCT university_id) AS universities_reporting
             FROM projects
             WHERE status IN ('submitted', 'approved', 'published', 'rejected')"
        );
        $approved = (int) ($row->approved ?? 0);
        $decided = $approved + (int) ($row->rejected ?? 0);
        return [
            'total'                  => (int) ($row->total ?? 0),
            'pending'                => (int) ($row->pending ?? 0),
            'approval_rate'          => $decided > 0 ? round($approved / $decided * 100) : 0,
            'universities_reporting' => (int) ($row->universities_reporting ?? 0),
        ];
    }

    /** الأدمن يقدر يتصرف في أي مشروع على مستوى المنصة — من غير تقييد ملكية/جامعة. */
    /**
     * منقولة من app/Repositories/ProjectRepository.php القديمة —
     * searchAll() (بند 24 batch 7، Search — DataAnalysisSearchApiController).
     * بحث platform-wide من غير أي فلتر status/verification عمدًا، نفس
     * القديمة بالظبط — مطابقة لـ app/Views/data-analysis/search-results.php.
     * @return array<int,array<string,mixed>>
     */
    public function searchAll(string $q, int $limit = 20): array
    {
        $needle = '%' . $q . '%';

        return DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->where(function ($w) use ($needle) {
                $w->where('p.title_en', 'like', $needle)
                    ->orWhere('p.title_ar', 'like', $needle)
                    ->orWhere('p.category', 'like', $needle);
            })
            ->orderByDesc('p.created_at')
            ->limit(max(1, $limit))
            ->select('p.*', 'u.full_name as owner_name', 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function updateStatusByUuid(string $uuid, string $status, array $extra = []): bool
    {
        $project = $this->findByUuid($uuid);
        if (!$project) {
            return false;
        }
        $project->fill(array_merge(['status' => $status], $extra));
        return $project->save();
    }

    /** بيضمن إن المشروع عنده slug فريد (بيتولد مرة واحدة ويتحفظ) — النشر محتاجه. */
    public function ensureSlug(Project $project): string
    {
        if (!$project->slug) {
            $project->slug = $this->generateUniqueSlug($project);
            if ($project->exists) {
                $project->save();
            }
        }
        return (string) $project->slug;
    }

    private function generateUniqueSlug(Project $project): string
    {
        $base = Project::slugify((string) ($project->title_en ?: $project->title_ar));
        if ($base === '') {
            $base = 'project';
        }
        $base = mb_substr($base, 0, 180);

        do {
            $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        } while (Project::where('slug', $slug)->exists());

        return $slug;
    }
}
