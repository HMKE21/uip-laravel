<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * /api/v1/notifications/* — كنترولر موحّد لكل بورتال (زي Common\
 * MessagingController بالظبط: كنترولر واحد، NotificationRepository/
 * NotificationService مربوطين بـ user_id مش role). بند 19 (Notifications)
 * كامل الآن — الـ Notification Center: بحث/فلترة/ترتيب/صفحات، عدادات
 * التابات، وكل حالات الإشعار الفردية (read/unread/pin/important/archive/
 * delete/restore/purge) + bulk toolbar. RBAC بيتحقق عند مستوى الـ route
 * group (middleware uip.auth) — كل ميثود تحت كده بتتأكد كمان إن الإشعار
 * فعلًا مملوك لـ uip_user_id عبر NotificationService (نفس اتفاقية
 * MessagingController)، فمستخدم مايقدرش يقرا/يعدّل إشعار مستخدم تاني.
 *
 * نفس عقد الفرونت بالظبط (src/pages/Notifications.jsx):
 *   GET    /notifications            list (query params = filters)
 *   GET    /notifications/counts     عدادات تابات
 *   PATCH  /notifications/{id}/{read|unread|pin|unpin|important|
 *                              unimportant|archive|unarchive}
 *   POST   /notifications/{id}/restore
 *   DELETE /notifications/{id}       soft delete -> Trash
 *   DELETE /notifications/{id}/purge حذف نهائي
 *   POST   /notifications/read-all
 *   DELETE /notifications/read       حذف كل المقروء (Trash)
 *   POST   /notifications/bulk       { ids, action }
 */
class NotificationsApiController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /**
     * GET /api/v1/notifications
     * فلاتر (كلها اختيارية): status, type, category, priority, search,
     * date_from, date_to, sort, page, per_page — نفس مفاتيح
     * NotificationRepository::search() بالظبط.
     */
    public function index(Request $request)
    {
        $filters = array_filter([
            'status'    => $request->input('status'),
            'type'      => $request->input('type'),
            'category'  => $request->input('category'),
            'priority'  => $request->input('priority'),
            'search'    => $request->input('search'),
            'date_from' => $request->input('date_from'),
            'date_to'   => $request->input('date_to'),
            'sort'      => $request->input('sort'),
            'page'      => $request->input('page'),
            'per_page'  => $request->input('per_page'),
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->notifications->search($this->uid($request), $filters);

        $page = (int) ($result['page'] ?? 1);
        $perPage = (int) ($result['per_page'] ?? 20);
        $total = (int) ($result['total'] ?? 0);

        return $this->apiSuccess($result['items'], 'Notifications retrieved successfully.', 200, [
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /** GET /api/v1/notifications/counts — عدادات التابات (unread/read/pinned/important/archived/deleted/total) */
    public function counts(Request $request)
    {
        return $this->apiSuccess($this->notifications->counts($this->uid($request)), 'Counts retrieved successfully.');
    }

    /** PATCH /api/v1/notifications/{id}/read */
    public function markRead(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'markRead', 'Notification marked as read.');
    }

    /** PATCH /api/v1/notifications/{id}/unread */
    public function markUnread(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'markUnread', 'Notification marked as unread.');
    }

    /** PATCH /api/v1/notifications/{id}/pin */
    public function pin(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'pin', 'Notification pinned.');
    }

    /** PATCH /api/v1/notifications/{id}/unpin */
    public function unpin(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'unpin', 'Notification unpinned.');
    }

    /** PATCH /api/v1/notifications/{id}/important */
    public function markImportant(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'markImportant', 'Notification marked as important.');
    }

    /** PATCH /api/v1/notifications/{id}/unimportant */
    public function unmarkImportant(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'unmarkImportant', 'Notification unmarked as important.');
    }

    /** PATCH /api/v1/notifications/{id}/archive */
    public function archive(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'archive', 'Notification archived.');
    }

    /** PATCH /api/v1/notifications/{id}/unarchive */
    public function unarchive(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'unarchive', 'Notification unarchived.');
    }

    /** DELETE /api/v1/notifications/{id} — soft delete (تروح Trash)، قابلة للرجوع عبر restore() */
    public function delete(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'delete', 'Notification moved to trash.');
    }

    /** POST /api/v1/notifications/{id}/restore */
    public function restore(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'restore', 'Notification restored.');
    }

    /** DELETE /api/v1/notifications/{id}/purge — حذف نهائي، عكس delete() اللي فوق */
    public function purge(Request $request, $id)
    {
        return $this->mutateOwned($request, $id, 'purge', 'Notification permanently deleted.');
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request)
    {
        $this->notifications->markAllRead($this->uid($request));
        return $this->apiSuccess(null, 'All notifications marked as read.');
    }

    /** DELETE /api/v1/notifications/read — بترحّل كل المقروء (مش غير المقروء) لـ Trash */
    public function deleteAllRead(Request $request)
    {
        $this->notifications->deleteAllRead($this->uid($request));
        return $this->apiSuccess(null, 'Read notifications deleted.');
    }

    /**
     * POST /api/v1/notifications/bulk
     * توولبار الاختيار الجماعي. Body: { "ids": [1,2,3], "action": "archive" }
     */
    public function bulk(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $action = (string) $request->input('action', '');

        if (!$ids) {
            return $this->apiError('The ids field is required.', ['ids' => ['At least one id is required.']], 422);
        }
        if ($action === '') {
            return $this->apiError('The action field is required.', ['action' => ['Action is required.']], 422);
        }

        $affected = $this->notifications->bulkAction($ids, $this->uid($request), $action);

        if ($affected === 0) {
            return $this->apiError('Unrecognized action or no matching notifications.', null, 422);
        }

        return $this->apiSuccess(['affected' => $affected], 'Bulk action applied successfully.');
    }

    /**
     * تنفيذ مشترك لكل فعل مفرد فوق: بيحل {id} من الراوت، بينادي ميثود
     * NotificationService المطابقة (التحقق من الملكية بيحصل جواها عبر
     * $userId)، وبيحوّل رجوع false (مش موجود / مش مملوك) لـ 404 بدل ما
     * يرجّع "success" كاذبة.
     */
    private function mutateOwned(Request $request, $id, string $method, string $successMessage)
    {
        $ok = $this->notifications->{$method}((int) $id, $this->uid($request));

        if (!$ok) {
            return $this->apiError('Notification not found.', null, 404);
        }

        return $this->apiSuccess(null, $successMessage);
    }
}
