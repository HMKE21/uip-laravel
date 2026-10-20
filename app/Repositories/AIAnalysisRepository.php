<?php

namespace App\Repositories;

use App\Models\AIAnalysis;
use App\Models\AIAnalysisConsent;
use App\Models\AIClassification;
use App\Models\AIReadinessScore;
use App\Models\ImprovementSuggestion;
use App\Models\StartupPotential;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AIAnalysisRepository.php القديمة —
 * readinessForProjects()/readinessFor()/classificationFor()/
 * startupPotentialFor()/startupPotentialForProjects() كانوا موجودين
 * بالفعل من بنود 4/5/8. بند 21 (AI الكامل) ضاف باقي السطح: الموافقة
 * (consent)، سجل التشغيل (ai_analysis)، upsert* لكل نتيجة، الاقتراحات
 * (batch replace)، والملخص. المتعمّد اتأجيله هنا: رول-أب التحليلات
 * الإدارية الثقيلة بـ raw SQL (readinessDistributionForUniversity،
 * avgReadinessByUniversity/Faculty/Semester، platformReadinessSummary،
 * platformOverview) — دول Admin/University "Innovation Statistics"
 * dashboards (بنود 23/26)، ومفيش صفحة Student
 * محتاجاهم عشان يشتغلوا؛ هيتوصلوا لما بند الـ Analytics/Admin يتفتح.
 */
class AIAnalysisRepository
{
    // -- الموافقة (ai_analysis_consents) -----------------------------------
    // شوف AIAnalysisService::hasValidConsent()/recordConsent() للمقارنة
    // بإصدار السياسة اللي بتحدد هل صف موافقة مخزّن هنا لسه صالح لـ run جديد.

    public function consentFor(int $projectId): ?AIAnalysisConsent
    {
        return AIAnalysisConsent::where('project_id', $projectId)->first();
    }

    /** Insert-or-update: إعادة الموافقة (مثلًا بعد رفع policy_version) بتكتب فوق المنحة القديمة في مكانها. */
    public function upsertConsent(int $projectId, int $userId, string $policyVersion, ?string $ip): AIAnalysisConsent
    {
        $existing = $this->consentFor($projectId);
        $data = [
            'project_id'     => $projectId,
            'user_id'        => $userId,
            'policy_version' => $policyVersion,
            'ip_address'     => $ip,
            'consented_at'   => now(),
            'revoked_at'     => null,
        ];
        if ($existing) {
            $existing->fill($data);
            $existing->save();
            return $existing;
        }
        return AIAnalysisConsent::create($data);
    }

    // -- سجل التشغيل (ai_analysis) -----------------------------------------

    public function startLog(int $projectId, string $type, ?int $requestedBy): AIAnalysis
    {
        return AIAnalysis::create([
            'project_id'    => $projectId,
            'analysis_type' => $type,
            'status'        => 'processing',
            'requested_by'  => $requestedBy,
        ]);
    }

    public function completeLog(AIAnalysis $log, array $rawResult, string $model): void
    {
        $log->status = 'completed';
        $log->raw_result = json_encode($rawResult, JSON_UNESCAPED_UNICODE);
        $log->model_used = $model;
        $log->completed_at = now();
        $log->save();
    }

    public function failLog(AIAnalysis $log, string $message): void
    {
        $log->status = 'failed';
        $log->raw_result = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        $log->completed_at = now();
        $log->save();
    }

    /** آخر صف log لكل analysis_type لمشروع، مفتاحها النوع. @return array<string,AIAnalysis> */
    public function latestLogsForProject(int $projectId): array
    {
        $rows = AIAnalysis::where('project_id', $projectId)->orderByDesc('created_at')->get();
        $latest = [];
        foreach ($rows as $row) {
            $type = $row->analysis_type;
            if (!isset($latest[$type])) {
                $latest[$type] = $row;
            }
        }
        return $latest;
    }

    // -- Readiness score (upsert) -------------------------------------------

    public function upsertReadiness(int $projectId, array $data): AIReadinessScore
    {
        $existing = $this->readinessFor($projectId);
        $data['project_id'] = $projectId;
        $data['computed_at'] = now();
        $data['is_demo_data'] = 0;
        if ($existing) {
            $existing->fill($data);
            $existing->save();
            return $existing;
        }
        return AIReadinessScore::create($data);
    }

    // -- Classification (آخر صف بيكسب، insert-only) -------------------------

    public function upsertClassification(int $projectId, array $data): AIClassification
    {
        $data['project_id'] = $projectId;
        $data['is_demo_data'] = 0;
        if (isset($data['alternative_categories']) && is_array($data['alternative_categories'])) {
            $data['alternative_categories'] = json_encode($data['alternative_categories'], JSON_UNESCAPED_UNICODE);
        }
        return AIClassification::create($data);
    }

    // -- Startup potential (upsert) ------------------------------------------

    public function upsertStartupPotential(int $projectId, array $data): StartupPotential
    {
        $existing = $this->startupPotentialFor($projectId);
        $data['project_id'] = $projectId;
        $data['is_demo_data'] = 0;
        if (isset($data['risk_factors']) && is_array($data['risk_factors'])) {
            $data['risk_factors'] = json_encode($data['risk_factors'], JSON_UNESCAPED_UNICODE);
        }
        if ($existing) {
            $existing->fill($data);
            $existing->save();
            return $existing;
        }
        return StartupPotential::create($data);
    }

    // -- اقتراحات التحسين (استبدال الدفعة بالكامل) ----------------------------

    /** @return \Illuminate\Support\Collection<int,ImprovementSuggestion> */
    public function suggestionsFor(int $projectId)
    {
        return ImprovementSuggestion::where('project_id', $projectId)->orderByDesc('created_at')->get();
    }

    /**
     * @param array<int,array{suggestion:string,category:string,priority:string}> $suggestions
     * @return \Illuminate\Support\Collection<int,ImprovementSuggestion>
     */
    public function replaceSuggestions(int $projectId, array $suggestions)
    {
        ImprovementSuggestion::where('project_id', $projectId)->delete();
        $created = collect();
        foreach ($suggestions as $s) {
            $created->push(ImprovementSuggestion::create([
                'project_id'   => $projectId,
                'suggestion'   => $s['suggestion'],
                'category'     => $s['category'] ?? 'other',
                'priority'     => $s['priority'] ?? 'medium',
                'is_demo_data' => 0,
            ]));
        }
        return $created;
    }

    // -- ملخص المشروع (مفيش جدول مخصص؛ متخزّن عبر log الـ ai_analysis) --------

    /** نص آخر run "summary" مكتمل، لو موجود. */
    public function summaryFor(int $projectId): ?string
    {
        $row = AIAnalysis::where('project_id', $projectId)
            ->where('analysis_type', 'summary')
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->first();

        return $row?->result()['summary'] ?? null;
    }

    /** @return array<int,AIReadinessScore> مفتاحها project_id */
    public function readinessForProjects(array $projectIds): array
    {
        $rows = AIReadinessScore::whereIn('project_id', array_unique($projectIds))->get();
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->project_id] = $row;
        }
        return $keyed;
    }

    /** آخر درجة جاهزية لمشروع واحد، أو null لو لسه ماتحللش. */
    public function readinessFor(int $projectId): ?AIReadinessScore
    {
        return AIReadinessScore::where('project_id', $projectId)->first();
    }

    /**
     * آخر تصنيف تلقائي لمشروع واحد. مش unique في الـ schema، فدايمًا بناخد
     * أحدث صف بس (created_at DESC) — يطابق القديمة بالظبط.
     */
    public function classificationFor(int $projectId): ?AIClassification
    {
        return AIClassification::where('project_id', $projectId)->orderByDesc('created_at')->first();
    }

    /** درجة إمكانية النجاح (Startup Potential) لمشروع واحد، أو null لو لسه ماتحللش — بند 8. */
    public function startupPotentialFor(int $projectId): ?StartupPotential
    {
        return StartupPotential::where('project_id', $projectId)->first();
    }

    /** نسخة دفعة من startupPotentialFor() — @return array<int,StartupPotential> مفتاحها project_id */
    public function startupPotentialForProjects(array $projectIds): array
    {
        $rows = StartupPotential::whereIn('project_id', array_unique($projectIds))->get();
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->project_id] = $row;
        }
        return $keyed;
    }

    // -- Roll-up تحليلات الأدمن/الجامعة (بند 23 — كانت مؤجّلة من بند 21) -----
    // منقولة من app/Repositories/AIAnalysisRepository.php القديمة —
    // readinessDistributionForUniversity/avgReadinessByUniversity/
    // platformReadinessSummary/avgReadinessByFaculty/avgReadinessBySemester،
    // كلهم بيقرو ai_readiness_scores بشرط is_demo_data=0 بس (نفس اتفاقية
    // "منعرضش رقم مصطنع" اللي القديمة كانت ماشية عليها).

    /** توزيع درجات الجاهزية على buckets (0-20/21-40/.../81-100) لجامعة واحدة — Innovation Statistics القديمة. */
    public function readinessDistributionForUniversity($universityId): array
    {
        $scores = DB::table('ai_readiness_scores as ars')
            ->join('projects as p', 'p.id', '=', 'ars.project_id')
            ->where('p.university_id', $universityId)
            ->where('ars.is_demo_data', 0)
            ->pluck('ars.overall_score');

        $buckets = ['0-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0];
        foreach ($scores as $score) {
            $buckets[$this->readinessBucketLabel((float) $score)]++;
        }

        return [
            'buckets'       => array_map(fn ($label, $count) => ['label' => $label, 'count' => $count], array_keys($buckets), array_values($buckets)),
            'analyzedCount' => $scores->count(),
        ];
    }

    /** متوسط درجة الجاهزية لكل جامعة (على المشاريع المحللة فعلًا) — عمود "Avg. Readiness Score" في Admin > Innovation Statistics. @param array{date_from?:string,date_to?:string,department_id?:int|string} $filters @return array<int,float> university_id => avg */
    public function avgReadinessByUniversity(array $filters = []): array
    {
        $query = DB::table('ai_readiness_scores as ars')
            ->join('projects as p', 'p.id', '=', 'ars.project_id')
            ->where('ars.is_demo_data', 0)
            ->whereNotNull('p.university_id');
        $this->applyReadinessFilters($query, $filters);

        $rows = $query->select('p.university_id', DB::raw('AVG(ars.overall_score) as avg_score'))
            ->groupBy('p.university_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->university_id] = round((float) $row->avg_score, 1);
        }
        return $map;
    }

    /**
     * محرك درجة الجاهزية على مستوى المنصة كلها (Admin > Innovation
     * Statistics > Readiness Score Distribution). كل رقم هنا aggregate
     * حقيقي (is_demo_data=0 بس)؛ منصة من غير مشاريع محللة بترجع
     * قيم null/0/فاضية بدل رقم مختلق.
     * @param array{university_id?:int|string,date_from?:string,date_to?:string,department_id?:int|string} $filters
     * @return array{analyzedCount:int,average:?float,highest:?array,lowest:?array,distribution:array<int,array{label:string,count:int}>}
     */
    public function platformReadinessSummary(array $filters = []): array
    {
        $query = DB::table('ai_readiness_scores as ars')
            ->join('projects as p', 'p.id', '=', 'ars.project_id')
            ->leftJoin('universities as u', 'u.id', '=', 'p.university_id')
            ->where('ars.is_demo_data', 0);
        $this->applyReadinessFilters($query, $filters);

        $rows = $query->select(
            'ars.overall_score', 'ars.project_id', 'p.title_en', 'p.title_ar',
            'p.university_id', 'u.official_name_en', 'u.official_name_ar'
        )->get();

        $buckets = ['0-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0];
        $sum = 0.0;
        $highest = null;
        $lowest = null;

        foreach ($rows as $row) {
            $score = (float) $row->overall_score;
            $sum += $score;
            $buckets[$this->readinessBucketLabel($score)]++;

            $entry = [
                'project_id'    => (int) $row->project_id,
                'title_en'      => $row->title_en,
                'title_ar'      => $row->title_ar,
                'university_en' => $row->official_name_en,
                'university_ar' => $row->official_name_ar,
                'score'         => $score,
            ];
            if ($highest === null || $score > $highest['score']) {
                $highest = $entry;
            }
            if ($lowest === null || $score < $lowest['score']) {
                $lowest = $entry;
            }
        }

        $count = $rows->count();

        return [
            'analyzedCount' => $count,
            'average'       => $count ? round($sum / $count, 1) : null,
            'highest'       => $highest,
            'lowest'        => $lowest,
            'distribution'  => array_map(
                fn ($label, $c) => ['label' => $label, 'count' => $c],
                array_keys($buckets),
                array_values($buckets)
            ),
        ];
    }

    /** مقارنة الكليات (Faculty Comparison): متوسط الجاهزية مجمّع بـ students.faculty (نص) — مشاريع الطلاب بس، لأن باقي الأدوار مفيهاش كلية. @param array{university_id?:int|string,date_from?:string,date_to?:string} $filters @return array<int,array{faculty:string,avg_score:float,count:int}> */
    public function avgReadinessByFaculty(array $filters = []): array
    {
        $query = DB::table('ai_readiness_scores as ars')
            ->join('projects as p', 'p.id', '=', 'ars.project_id')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('ars.is_demo_data', 0)
            ->whereNotNull('s.faculty')
            ->where('s.faculty', '!=', '');
        // department_id متعمّد مش متبعت هنا (التجميع بالاسم النصي مش القسم).
        $this->applyReadinessFilters($query, ['university_id' => $filters['university_id'] ?? null, 'date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null]);

        $rows = $query->select('s.faculty', DB::raw('AVG(ars.overall_score) as avg_score'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('s.faculty')
            ->orderByDesc('avg_score')
            ->get();

        return $rows->map(fn ($r) => [
            'faculty'   => $r->faculty,
            'avg_score' => round((float) $r->avg_score, 1),
            'count'     => (int) $r->cnt,
        ])->all();
    }

    /** مقارنة الفصل الدراسي (Semester Comparison): متوسط الجاهزية مجمّع بـ students.current_semester — جامعات لسه ما بدأتش تسجّله ببساطة مش هتظهر هنا (نفس اتفاقية "فاضي بأمانة" في الكلاس ده كله). @param array{university_id?:int|string,date_from?:string,date_to?:string,department_id?:int|string} $filters @return array<int,array{semester:int,avg_score:float,count:int}> */
    public function avgReadinessBySemester(array $filters = []): array
    {
        $query = DB::table('ai_readiness_scores as ars')
            ->join('projects as p', 'p.id', '=', 'ars.project_id')
            ->join('students as s', 's.user_id', '=', 'p.owner_id')
            ->where('ars.is_demo_data', 0)
            ->whereNotNull('s.current_semester');
        $this->applyReadinessFilters($query, $filters);

        $rows = $query->select('s.current_semester', DB::raw('AVG(ars.overall_score) as avg_score'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('s.current_semester')
            ->orderBy('s.current_semester')
            ->get();

        return $rows->map(fn ($r) => [
            'semester'  => (int) $r->current_semester,
            'avg_score' => round((float) $r->avg_score, 1),
            'count'     => (int) $r->cnt,
        ])->all();
    }

    /** توزيع درجة على bucket نصي — شير مشترك بين readinessDistributionForUniversity()/platformReadinessSummary(). */
    private function readinessBucketLabel(float $score): string
    {
        if ($score <= 20) {
            return '0-20';
        }
        if ($score <= 40) {
            return '21-40';
        }
        if ($score <= 60) {
            return '41-60';
        }
        if ($score <= 80) {
            return '61-80';
        }
        return '81-100';
    }

    /**
     * فلاتر مشتركة (university_id/date_from/date_to/department_id) لكل
     * ميثودز الـ roll-up فوق — يطابق readinessFilterClause() القديمة.
     * department_id بيضيف join على students (project owner) بس لو
     * مطلوب فعلًا، عشان ميكررش الجوين من غير داعي.
     * @param array{university_id?:int|string,date_from?:string,date_to?:string,department_id?:int|string} $filters
     */
    private function applyReadinessFilters($query, array $filters): void
    {
        $query->when(!empty($filters['university_id']), fn ($q) => $q->where('p.university_id', (int) $filters['university_id']));
        $query->when(!empty($filters['date_from']), fn ($q) => $q->where('ars.computed_at', '>=', $filters['date_from'] . ' 00:00:00'));
        $query->when(!empty($filters['date_to']), fn ($q) => $q->where('ars.computed_at', '<=', $filters['date_to'] . ' 23:59:59'));
        if (!empty($filters['department_id'])) {
            $query->join('students as f_students', 'f_students.user_id', '=', 'p.owner_id');
            $query->where('f_students.department_id', (int) $filters['department_id']);
        }
    }

    /**
     * منقولة من AIAnalysisRepository::platformOverview() القديمة — بند 25
     * (AdminDashboardApiController::index() -> 'ai_overview'). Roll-up
     * بسيط على ai_analysis: عدد الـ runs الناجحة/الفاشلة، عدد المشاريع
     * المتفرّدة اللي اتحللت، وتوزيعهم حسب analysis_type — نفس أربع
     * المفاتيح بالظبط زي القديمة.
     * @return array{completedRuns:int,failedRuns:int,analyzedProjects:int,byType:array<string,int>}
     */
    public function platformOverview(): array
    {
        $completedRuns = AIAnalysis::where('status', 'completed')->count();
        $failedRuns = AIAnalysis::where('status', 'failed')->count();
        $analyzedProjects = AIAnalysis::where('status', 'completed')->distinct('project_id')->count('project_id');

        $byType = AIAnalysis::where('status', 'completed')
            ->selectRaw('analysis_type, COUNT(*) as cnt')
            ->groupBy('analysis_type')
            ->pluck('cnt', 'analysis_type')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'completedRuns'    => $completedRuns,
            'failedRuns'       => $failedRuns,
            'analyzedProjects' => $analyzedProjects,
            'byType'           => $byType,
        ];
    }
}
