<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\UserActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Users → User details → Activity. Read side of the per-user timeline (see UserActivityService):
 *   GET    /api/v1/admin/users/{uuid}/activity            filtered, paginated timeline
 *   GET    /api/v1/admin/users/{uuid}/activity/summary    counters, top pages/clicks/errors, devices, IPs, per-day chart
 *   GET    /api/v1/admin/users/{uuid}/activity/sessions   browser visits
 *   GET    /api/v1/admin/users/{uuid}/activity/export     CSV of everything matching the filters
 *   DELETE /api/v1/admin/users/{uuid}/activity            wipe this user's timeline (audited)
 */
class AdminUserActivityApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MAX = 200;

    public function __construct(private UserActivityService $activity)
    {
    }

    private function guard(Request $request, string $uuid): array
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return [null, $this->apiError('Only admin accounts can view user activity.', null, 403)];
        }
        $id = DB::table('users')->where('uuid', $uuid)->value('id');
        if (!$id) {
            return [null, $this->apiError('User not found.', null, 404)];
        }

        return [(int) $id, null];
    }

    public function index(Request $request, $uuid)
    {
        [$uid, $err] = $this->guard($request, (string) $uuid);
        if ($err) {
            return $err;
        }
        $f = $this->activity->filters($request->all());
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));
        $res = $this->activity->search($uid, $f, $page, $perPage);

        return $this->apiSuccess($res['rows'], 'Activity retrieved successfully.', 200, [
            'page' => $page, 'perPage' => $perPage, 'total' => $res['total'], 'enabled' => UserActivityService::enabled(),
        ]);
    }

    public function summary(Request $request, $uuid)
    {
        [$uid, $err] = $this->guard($request, (string) $uuid);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->activity->summary($uid, $this->activity->filters($request->all())), 'Activity summary retrieved successfully.');
    }

    public function sessions(Request $request, $uuid)
    {
        [$uid, $err] = $this->guard($request, (string) $uuid);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->activity->sessions($uid, $this->activity->filters($request->all())), 'Sessions retrieved successfully.');
    }

    public function export(Request $request, $uuid)
    {
        [$uid, $err] = $this->guard($request, (string) $uuid);
        if ($err) {
            return $err;
        }
        $rows = $this->activity->searchAll($uid, $this->activity->filters($request->all()));

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens Arabic text correctly
        fputcsv($stream, ['Time', 'Type', 'Severity', 'Source', 'Event', 'Page', 'Target', 'Method', 'Endpoint', 'Status', 'Duration (ms)', 'Message shown / error', 'IP', 'Device', 'Browser', 'Session', 'Details']);
        foreach ($rows as $r) {
            fputcsv($stream, [
                str_replace('T', ' ', (string) $r['occurred_at']), $r['type'], $r['severity'], $r['source'], $this->csvSafe($r['title']), $this->csvSafe($r['path']), $this->csvSafe($r['target']),
                $r['method'], $this->csvSafe($r['endpoint']), $r['status'], $r['duration_ms'], $this->csvSafe($r['message']), $r['ip'], $r['device'], $r['browser'], $r['session_id'],
                $r['details'] ? json_encode($r['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="user-activity-' . date('Y-m-d_His') . '.csv"',
        ]);
    }

    /** Neutralises spreadsheet formula injection (a page title / error text starting with = + - @). */
    private function csvSafe($v)
    {
        $s = (string) ($v ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $s : $s;
    }

    public function destroy(Request $request, $uuid)
    {
        [$uid, $err] = $this->guard($request, (string) $uuid);
        if ($err) {
            return $err;
        }
        $n = $this->activity->purgeUser($uid);
        app(AuditLogService::class)->record((int) $request->attributes->get('uip_user_id'), 'user.activity_cleared', 'user', $uid, null, ['events_deleted' => $n], $request->ip());

        return $this->apiSuccess(['deleted' => $n], 'Activity cleared.');
    }
}
