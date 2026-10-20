<?php

namespace App\Services;

use App\Repositories\ProjectAnalyticsRepository;
use Illuminate\Http\Request;

/**
 * منقولة من app/Services/ProjectAnalyticsService.php القديمة — بند 11
 * مرحلة 4 (Analytics). بتنظّم project_analytics_events (migration 141)
 * فوق ProjectAnalyticsRepository: بتحوّل Request خام لـ visitor_hash
 * محايد للخصوصية، وبتسجّل كل تفاعل قابل للتتبع، وبتشكّل التجميعات اللي
 * تاب "Analytics" بتاع الطالب/الباحث وعرض الشركة بيقروها.
 *
 * visitor_hash = sha256(ip + user-agent + تاريخ النهاردة + APP_KEY).
 * عمرها ما بتخزن IP/UA الخام — مكوّن التاريخ يعني نفس الزائر بيبقى له
 * hash مختلف بكرة، فمفيش هوية زائر عابرة للأيام متخزنة؛ الهدف بس فصل
 * "مشاهدات فريدة" لكل مشروع لكل يوم.
 */
class ProjectAnalyticsService
{
    public function __construct(private ProjectAnalyticsRepository $events)
    {
    }

    private function visitorHash(Request $request): string
    {
        $salt = (string) config('app.key', '');
        return hash('sha256', $request->ip() . '|' . (string) $request->userAgent() . '|' . date('Y-m-d') . '|' . $salt);
    }

    /** تحميل صفحة مشروع عامة — بتتفلتر لـ "مشاهدات فريدة" باليوم عبر visitor_hash. */
    public function recordView($projectId, Request $request): void
    {
        $this->events->record($projectId, 'view', $this->visitorHash($request));
    }

    public function recordGithubClick($projectId, Request $request): void
    {
        $this->events->record($projectId, 'github_click', $this->visitorHash($request));
    }

    public function recordDemoClick($projectId, Request $request): void
    {
        $this->events->record($projectId, 'demo_click', $this->visitorHash($request));
    }

    /** $label: تسمية/نوع الرابط (مثلًا "Docs"، "Paper") — بيغذّي بانل "الأكتر تفاعلًا". */
    public function recordLinkClick($projectId, Request $request, string $label): void
    {
        $this->events->record($projectId, 'link_click', $this->visitorHash($request), $label);
    }

    /** $fileName: original_name للملف المتنزّل — بيغذّي بانل "الأكتر تفاعلًا". */
    public function recordFileDownload($projectId, Request $request, string $fileName): void
    {
        $this->events->record($projectId, 'file_download', $this->visitorHash($request), $fileName);
    }

    /** مفيش visitor_hash: طلب التواصل محتاج تسجيل دخول دايمًا، فمفيش داعي لفصل المكرر. */
    public function recordContactRequest($projectId, Request $request): void
    {
        $this->events->record($projectId, 'contact_request');
    }

    /**
     * الأرقام الرئيسية لتاب "Analytics" بتاع الطالب/الباحث.
     * @return array{total_views:int,unique_views:int,github_clicks:int,demo_clicks:int,link_clicks:int,file_downloads:int,contact_requests:int}
     */
    public function summary($projectId): array
    {
        $counts = $this->events->countsByType($projectId);

        return [
            'total_views'      => $counts['view'] ?? 0,
            'unique_views'     => $this->events->uniqueViewCount($projectId),
            'github_clicks'    => $counts['github_click'] ?? 0,
            'demo_clicks'      => $counts['demo_click'] ?? 0,
            'link_clicks'      => $counts['link_click'] ?? 0,
            'file_downloads'   => $counts['file_download'] ?? 0,
            'contact_requests' => $counts['contact_request'] ?? 0,
        ];
    }

    /** @return array<int,array{date:string,views:int}> مشاهدات يوم بيوم، الأقدم الأول، فجوات معبّاة بصفر */
    public function dailyViewTrend($projectId, int $days = 14): array
    {
        return $this->events->dailyViewCounts($projectId, $days);
    }

    /** @return array<int,array{meta:string,c:int}> أكتر العناصر اتنقر/اتنزّلت عبر كل الأنواع غير view */
    public function topInteractions($projectId, int $limit = 5): array
    {
        return $this->events->topInteractions($projectId, $limit);
    }
}
