<?php

namespace App\Repositories;

use App\Models\ProjectAnalyticsEvent;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ProjectAnalyticsRepository.php القديمة —
 * بند 11 مرحلة 4 (Analytics). Data access لـ `project_analytics_events`
 * (migration 141). صف append-only واحد لكل تفاعل؛ الريبو ده بس بيعمل
 * INSERT (record*) أو تجميع (summary/trend/top) — عمره ما بيعمل update/
 * delete لصف، لأن الجدول سجل خام (event log) باقي الشاشات بتتجمّع منه.
 */
class ProjectAnalyticsRepository
{
    /** إضافة صف حدث واحد. $visitorHash/$meta اختياريين حسب event_type — شوف migration 141. */
    public function record($projectId, string $eventType, ?string $visitorHash = null, ?string $meta = null): ProjectAnalyticsEvent
    {
        return ProjectAnalyticsEvent::create([
            'project_id'   => $projectId,
            'event_type'   => $eventType,
            'visitor_hash' => $visitorHash,
            'meta'         => $meta !== null ? mb_substr($meta, 0, 255) : null,
        ]);
    }

    /** True لو الزائر ده عنده حدث 'view' لنفس المشروع النهاردة (لفصل unique views). */
    public function hasViewedToday($projectId, string $visitorHash): bool
    {
        return DB::table('project_analytics_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'view')
            ->where('visitor_hash', $visitorHash)
            ->whereRaw('DATE(created_at) = CURDATE()')
            ->exists();
    }

    /** عدادات كل الوقت لكل event_type لمشروع واحد — تاب Analytics بتاع الطالب/شركة. */
    public function countsByType($projectId): array
    {
        $rows = DB::table('project_analytics_events')
            ->where('project_id', $projectId)
            ->select('event_type', DB::raw('COUNT(*) as c'))
            ->groupBy('event_type')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->event_type] = (int) $row->c;
        }
        return $counts;
    }

    /** عدد visitor_hash المختلفة لأحداث 'view' على المشروع ده (مشاهدات فريدة، كل الوقت). */
    public function uniqueViewCount($projectId): int
    {
        return (int) DB::table('project_analytics_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'view')
            ->whereNotNull('visitor_hash')
            ->distinct('visitor_hash')
            ->count('visitor_hash');
    }

    /**
     * مشاهدات 'view' يوم بيوم لآخر $days يوم (شامل الأيام اللي مفيهاش
     * مشاهدات، عشان الرسم البياني ميحصلش فيه فجوة)، الأقدم الأول.
     * @return array<int,array{date:string,views:int}>
     */
    public function dailyViewCounts($projectId, int $days): array
    {
        $rows = DB::table('project_analytics_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'view')
            ->whereRaw('created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [max(0, $days - 1)])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row->d] = (int) $row->c;
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} day"));
            $out[] = ['date' => $date, 'views' => $byDate[$date] ?? 0];
        }
        return $out;
    }

    /**
     * أكتر عناصر اتنقر/اتنزّلت (بحسب `meta`، مثلًا تسمية رابط أو اسم
     * ملف) عبر كل event_type غير 'view' — بيغذّي بانل "الأكتر تفاعلًا".
     * @return array<int,array{meta:string,c:int}>
     */
    public function topInteractions($projectId, int $limit = 5): array
    {
        return DB::table('project_analytics_events')
            ->where('project_id', $projectId)
            ->where('event_type', '!=', 'view')
            ->whereNotNull('meta')
            ->where('meta', '!=', '')
            ->select('meta', DB::raw('COUNT(*) as c'))
            ->groupBy('meta')
            ->orderByDesc('c')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * أكتر مشاريع جامعة تفاعلًا — University Dashboard spec §21. "تفاعل" =
     * أي حدث غير view (نقرات github/demo/link، تنزيلات، طلبات تواصل)؛
     * مشاهدة عادية مش محسوبة هنا، دي متريك ProjectRepository::
     * mostViewedForFaculty() المنفصلة.
     * @return array<int,array<string,mixed>>
     */
    public function mostInteractedForUniversity($universityId, int $limit = 5): array
    {
        return DB::table('project_analytics_events as e')
            ->join('projects as p', 'p.id', '=', 'e.project_id')
            ->where('p.university_id', $universityId)
            ->where('e.event_type', '!=', 'view')
            ->select('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en', DB::raw('COUNT(e.id) as interactions'))
            ->groupBy('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en')
            ->orderByDesc('interactions')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * أكتر مشاريع تفاعلًا على مستوى المنصة كلها، مع اسم جامعتها — بانل
     * "Most Interacted" في Admin Analytics. نفس منطق mostInteractedForUniversity()
     * بس من غير تقييد بجامعة. منقولة من
     * ProjectAnalyticsRepository::mostInteractedPlatformWide() القديمة. بند 23.
     * @return array<int,array<string,mixed>>
     */
    public function mostInteractedPlatformWide(int $limit = 5): array
    {
        return DB::table('project_analytics_events as e')
            ->join('projects as p', 'p.id', '=', 'e.project_id')
            ->leftJoin('universities as uni', 'uni.id', '=', 'p.university_id')
            ->where('e.event_type', '!=', 'view')
            ->select('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en', DB::raw('COUNT(e.id) as interactions'), 'uni.official_name_en as university_name_en', 'uni.official_name_ar as university_name_ar')
            ->groupBy('p.id', 'p.uuid', 'p.slug', 'p.title_ar', 'p.title_en', 'uni.official_name_en', 'uni.official_name_ar')
            ->orderByDesc('interactions')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }
}
