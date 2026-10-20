<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingAttendanceService;
use App\Services\MeetingPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * سطح /api/v1/meetings/{uuid}/attendance/* — Round 7 (Collaboration
 * Extras): بند 17 (Attendance Tracking، "Generate an attendance
 * report" + "Allow authorized users to export attendance data").
 *
 * مسجّلة تحت uip.auth العادي (زي host-controls، مش uip.auth.optional)
 * — تتبّع الحضور نفسه (recordJoin/recordLeave) بيحصل تلقائي من جوه
 * MeetingSignalingService، مفيش endpoint هنا لتسجيله يدويًا. الاتنين
 * endpoint هنا (report/export) للقراءة بس، و"authorized users" في نص
 * المواصفة اتفسّرت هوست/co-host (canManage) — نفس تدرّج الصلاحيات
 * المتبع في باقي أدوات إدارة الاجتماع، بيانات الحضور فيها معلومات
 * حساسة عن كل المشاركين مش مناسب لأي actor عادي يشوفها.
 */
class MeetingsAttendanceApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingAttendanceService $attendance,
        private MeetingPolicyService $policy
    ) {
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function findMeeting(string $uuid)
    {
        return $this->meetings->findByUuid($uuid);
    }

    private function authorizeManagerOr403(Request $request, $meeting)
    {
        if (!$this->policy->canManage($meeting, $this->userId($request), $this->meetings)) {
            return $this->apiError('Only the host or co-host can view attendance data.', null, 403);
        }

        return null;
    }

    public function report(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $denied = $this->authorizeManagerOr403($request, $meeting);
        if ($denied) {
            return $denied;
        }

        return $this->apiSuccess(['attendance' => $this->attendance->report($meeting)], 'Attendance report retrieved successfully.');
    }

    public function export(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $denied = $this->authorizeManagerOr403($request, $meeting);
        if ($denied) {
            return $denied;
        }

        $csv = $this->attendance->exportCsv($meeting);

        return Response::make($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="attendance-' . $meeting->uuid . '.csv"',
        ]);
    }
}
