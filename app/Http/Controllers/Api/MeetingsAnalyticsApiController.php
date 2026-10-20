<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingAnalyticsService;
use App\Services\MeetingPolicyService;
use Illuminate\Http\Request;

/**
 * Round 10 (Admin & Docs): بند 15 ("Meeting Dashboard" -> "Meeting
 * Analytics") + بند 39 ("Admin Monitoring"). سطحين منفصلين عمدًا:
 *
 * - GET /api/v1/meetings/{uuid}/analytics: هوست/co-host الاجتماع ده بس
 *   (canManage — نفس تدرّج host-controls، مش أي مشارك عادي)، أرقام
 *   مجمّعة عن اجتماعه هو بس.
 * - GET /api/v1/admin/meetings/monitoring: أدمن بس (uip.admin middleware،
 *   نفس نمط AdminDashboardApiController). أرقام على مستوى المنصة كلها —
 *   بند 39 بينص صراحة: "Admins should NOT automatically have access to
 *   private meeting content unless the existing UIP policies explicitly
 *   allow it" — فالسطح ده بيرجّع عدادات/إحصائيات بس (زي
 *   MeetingAnalyticsService::platformMonitoring())، مفيش أي محتوى شات/
 *   ملفات/تسجيل فعلي بيتسرب من هنا.
 */
class MeetingsAnalyticsApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy,
        private MeetingAnalyticsService $analytics
    ) {
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** GET /api/v1/meetings/{uuid}/analytics — بند 15. */
    public function forMeeting(Request $request, string $uuid)
    {
        $meeting = $this->meetings->findByUuid($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        if (!$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Only the host or co-host can view meeting analytics.', null, 403);
        }

        return $this->apiSuccess($this->analytics->forMeeting($meeting), 'Meeting analytics retrieved successfully.');
    }

    /** GET /api/v1/admin/meetings/monitoring — بند 39. */
    public function platformMonitoring(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view the meetings monitoring dashboard.', null, 403);
        }

        return $this->apiSuccess($this->analytics->platformMonitoring(), 'Meetings monitoring data retrieved successfully.');
    }
}
