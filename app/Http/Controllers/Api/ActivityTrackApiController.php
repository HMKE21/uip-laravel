<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserActivityService;
use Illuminate\Http\Request;

/**
 * POST /api/v1/activity/track — the browser tracker (src/lib/activityTracker.js) sends batches of what the signed-in person
 * did: page views, clicks, form submits, failed requests and JavaScript errors. The user is always the token's own user —
 * the body can never name another account.
 */
class ActivityTrackApiController extends Controller
{
    public function __construct(private UserActivityService $activity)
    {
    }

    public function track(Request $request)
    {
        $uid = (int) $request->attributes->get('uip_user_id');
        if (!$uid) {
            return $this->apiError('Not authenticated.', null, 401);
        }
        if (!UserActivityService::enabled()) {
            return $this->apiSuccess(['stored' => 0], 'Activity tracking is off.');
        }

        $events = $request->input('events');
        if (!is_array($events) || !$events) {
            return $this->apiSuccess(['stored' => 0], 'Nothing to store.');
        }

        $stored = $this->activity->recordBatch($uid, $events, [
            'session_id' => (string) $request->input('session_id', ''),
            'ip'         => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return $this->apiSuccess(['stored' => $stored], 'Activity stored.');
    }
}
