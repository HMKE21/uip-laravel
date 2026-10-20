<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminNotificationSettingsApiController.php
 * القديمة — بند 25 batch 4. سطح /api/v1/admin/notification-settings JSON
 * واحد، بيعيد استخدام NotificationService::retentionPolicy()/
 * setRetentionPolicy() بالظبط زي ما
 * Admin\AdminNotificationController::settings()/updateSettings() القديمة
 * كانت بتعمل. سياسة الأرشفة/الحذف التلقائي على مستوى المنصة كلها —
 * مختلفة عن قايمة "My Notifications" بتاعة اليوزر نفسه (NotificationsApiController
 * بيغطيها بالفعل). مفيش حاجة مختلَقة هنا.
 */
class AdminNotificationSettingsApiController extends Controller
{
    public function __construct(private NotificationService $notifications, private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can manage the notification retention policy.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/notification-settings */
    public function show(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->notifications->retentionPolicy(), 'Notification retention policy retrieved successfully.');
    }

    /** PATCH /api/v1/admin/notification-settings — {archive_days, delete_days} (0-3650, 0 = disabled). */
    public function update(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $archiveDays = (int) $request->input('archive_days', 0);
        $deleteDays = (int) $request->input('delete_days', 0);

        if ($archiveDays < 0 || $archiveDays > 3650 || $deleteDays < 0 || $deleteDays > 3650) {
            return $this->apiError('The period must be between 0 and 3650 days.', null, 422);
        }

        $before = $this->notifications->retentionPolicy();
        $this->notifications->setRetentionPolicy($archiveDays, $deleteDays);
        $this->auditLog->record(
            $request->attributes->get('uip_user_id'),
            'admin.notification_retention_policy_update',
            'Setting',
            null,
            $before,
            ['archive_days' => $archiveDays, 'delete_days' => $deleteDays]
        );

        return $this->apiSuccess(['archive_days' => $archiveDays, 'delete_days' => $deleteDays], 'Notification retention policy saved.');
    }
}
