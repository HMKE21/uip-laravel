<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentAppealService;
use Illuminate\Http\Request;

/**
 * اعتراض المستخدم على حجب المحتوى (محادثة مع الإدارة).
 *
 *  المستخدم (أي دور):
 *    GET  /content-appeals                 اعتراضاتي + حساب الإدارة (contact)
 *    GET  /content-appeals/{id}            محادثة اعتراض (بتاعي بس)
 *    POST /content-appeals {subject, message, category?}
 *    POST /content-appeals/{id}/reply {message}
 *  الأدمن (uip.admin):
 *    GET  /admin/content-appeals?status=open|answered|closed
 *    GET  /admin/content-appeals/{id}
 *    POST /admin/content-appeals/{id}/reply {message?, close_as?: resolved|declined}
 */
class ContentAppealApiController extends Controller
{
    public function __construct(private ContentAppealService $appeals)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function guard()
    {
        return $this->appeals->available() ? null : $this->apiError('Appeals are not set up yet. Run the database migrations.', null, 503);
    }

    public function mine(Request $request)
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        return $this->apiSuccess(['items' => $this->appeals->listForUser($this->uid($request)), 'contact' => $this->appeals->contactAdmin()], 'Appeals retrieved successfully.');
    }

    public function showMine(Request $request, $id)
    {
        if ($deny = $this->guard()) {
            return $deny;
        }
        $a = $this->appeals->show((int) $id, $this->uid($request));

        return $a ? $this->apiSuccess($a, 'Appeal retrieved successfully.') : $this->apiError('Appeal not found.', null, 404);
    }

    public function store(Request $request)
    {
        if ($deny = $this->guard()) {
            return $deny;
        }
        $res = $this->appeals->create($this->uid($request), (string) $request->input('subject', ''), (string) $request->input('message', ''), $request->input('category') ? (string) $request->input('category') : null);

        return $res['ok'] ? $this->apiSuccess(['id' => $res['id']], 'Your message was sent to the administration.', 201) : $this->apiError($res['error'], null, $res['status']);
    }

    public function replyMine(Request $request, $id)
    {
        if ($deny = $this->guard()) {
            return $deny;
        }
        $res = $this->appeals->userReply($this->uid($request), (int) $id, (string) $request->input('message', ''));

        return $res['ok'] ? $this->apiSuccess(null, 'Reply sent.') : $this->apiError($res['error'], null, $res['status']);
    }

    // ------------------------------------------------------------------ admin

    private function requireAdmin(Request $request)
    {
        return $request->attributes->get('uip_role') === 'admin' ? null : $this->apiError('Only admin accounts can manage appeals.', null, 403);
    }

    public function adminIndex(Request $request)
    {
        if ($deny = $this->requireAdmin($request) ?? $this->guard()) {
            return $deny;
        }

        return $this->apiSuccess($this->appeals->adminList((string) $request->query('status', '')), 'Appeals retrieved successfully.');
    }

    public function adminShow(Request $request, $id)
    {
        if ($deny = $this->requireAdmin($request) ?? $this->guard()) {
            return $deny;
        }
        $a = $this->appeals->show((int) $id);

        return $a ? $this->apiSuccess($a, 'Appeal retrieved successfully.') : $this->apiError('Appeal not found.', null, 404);
    }

    public function adminReply(Request $request, $id)
    {
        if ($deny = $this->requireAdmin($request) ?? $this->guard()) {
            return $deny;
        }
        $close = $request->input('close_as');
        $res = $this->appeals->adminReply($this->uid($request), (int) $id, (string) $request->input('message', ''), $close ? (string) $close : null);

        return $res['ok'] ? $this->apiSuccess(null, 'Reply sent.') : $this->apiError($res['error'], null, $res['status']);
    }
}
