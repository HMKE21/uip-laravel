<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MessagingService;
use App\Services\NotificationService;
use App\Services\PermissionService;
use App\Services\UserSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/app/summary — ONE request that replaces what the shell used to ask for in 4+ separate calls
 * (and again on every poll):
 *   - GET /auth/permissions        -> `permissions` block (identical shape)
 *   - GET /session/heartbeat       -> the session heartbeat is recorded here
 *   - GET /notifications/counts    -> `notification_counts`
 *   - GET /messaging/inbox (full list, only to sum the unread badge) -> `unread_messages` (a number)
 *
 * Each section is best-effort: a failing one comes back as null and the client falls back to the old endpoint
 * for that section only. Nothing here widens access — every section is computed for the signed-in user only.
 */
class AppSummaryApiController extends Controller
{
    public function show(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $role   = (string) $request->attributes->get('uip_role');

        // 1) heartbeat (keeps "last active" fresh, same as GET /session/heartbeat)
        try {
            $sid = (int) $request->attributes->get('uip_session_id');
            if ($sid > 0) {
                app(UserSessionService::class)->heartbeat($sid);
            }
        } catch (\Throwable $e) {
            // best effort
        }

        // 2) permissions: reuse the exact controller so the payload can never drift from /auth/permissions.
        //    كاش 15 ثانية لكل (مستخدم، دور): الصلاحيات نادرًا بتتغير وكانت بتتحسب مع كل poll لكل مستخدم.
        $permissions = null;
        try {
            $permissions = Cache::remember('summary:perm:' . $userId . ':' . $role, 15, function () use ($request) {
                $res = app(MyPermissionsApiController::class)->show($request);
                return $res->getData(true)['data'] ?? null;
            });
        } catch (\Throwable $e) {
            $permissions = null;
        }

        // 3) notification counters
        $counts = null;
        try {
            $counts = app(NotificationService::class)->counts($userId);
        } catch (\Throwable $e) {
            $counts = null;
        }

        // 4) unread-messages badge (same maths the client used on the full inbox: muted chats do not count).
        //    الحساب تقيل (بيحمّل الـ inbox كله) فبيتخزّن 20 ثانية، وبيتمسح فورًا لما توصل رسالة/تغيير للمستخدم (Realtime::toUsers).
        $unreadMessages = null;
        try {
            $unreadMessages = Cache::remember('summary:unread:' . $userId, 20, function () use ($userId, $role) {
                $canChat = app(PermissionService::class)->currentUserCan($userId, $role, 'messaging.use');
                if (!$canChat) {
                    return -1; // -1 = مش مسموح له (عشان نقدر نخزّن null في الكاش)
                }
                $rows = app(MessagingService::class)->inboxForUser($userId);
                $n = 0;
                foreach ($rows as $row) {
                    if (!empty($row['is_muted'])) {
                        continue;
                    }
                    $n += (int) ($row['unread_count'] ?? (!empty($row['is_unread']) ? 1 : 0));
                }
                return $n;
            });
            if ($unreadMessages === -1) {
                $unreadMessages = null;
            }
        } catch (\Throwable $e) {
            $unreadMessages = null;
        }

        return $this->apiSuccess([
            'permissions'         => $permissions,
            'notification_counts' => $counts,
            'unread_messages'     => $unreadMessages,
            'server_time'         => time(),
        ], 'ok')->header('Cache-Control', 'no-store');
    }
}
