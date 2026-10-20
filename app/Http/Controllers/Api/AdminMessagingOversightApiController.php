<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\MessagingPolicyService;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminMessagingOversightApiController.php
 * القديمة — بند 25 batch 5. بتعيد استخدام MessagingService::
 * adminSearchConversations()/adminThread()/adminDeleteMessage()/
 * adminDeleteAttachment()/adminAnalytics() و MessagingPolicyService::
 * getUploadPolicy()/updateUploadPolicy()/getRetentionPolicy()/
 * updateRetentionPolicy()/portalToggles()/setPortalEnabled() بالظبط —
 * نفس اللي Admin\AdminMessagingController القديمة (قسم "Admin Controls
 * gap fix") كانت بتعمله عبر oversight()/oversightThread()/
 * oversightDeleteMessage()/oversightDeleteAttachment()/analytics()/
 * settings()/updateUploadSettings()/updateRetentionSettings()/
 * updatePortalToggle().
 *
 * لوحة الإشراف على مستوى المنصة كلها — كل محادثة عبر كل بورتال،
 * قراءة-فقط ما عدا الحذف الإجباري لرسالة/مرفق — مختلفة عن inbox
 * الأدمن الشخصي اللي بيتقدّم بالفعل عبر /api/v1/messaging/*
 * (MessagingController) اللي كل واجهة شات بأي بورتال بتكلمه. مفيش حاجة
 * مختلَقة هنا.
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php).
 */
class AdminMessagingOversightApiController extends Controller
{
    public function __construct(
        private MessagingService $messaging,
        private MessagingPolicyService $policy,
        private AuditLogService $auditLog
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access messaging oversight.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/messaging/oversight — ?search=&type=&page= */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $filters = array_filter(
            $request->only(['search', 'type', 'page']),
            fn ($v) => $v !== null && $v !== ''
        );

        return $this->apiSuccess($this->messaging->adminSearchConversations($filters), 'Conversations retrieved successfully.');
    }

    /** GET /api/v1/admin/messaging/oversight/{id} — ثريد قراءة-فقط، بيتسجل. */
    public function thread(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $thread = $this->messaging->adminThread((int) $id);
        if (!$thread) {
            return $this->apiError('Conversation not found.', null, 404);
        }

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'admin.messaging_conversation_viewed', 'Conversation', (int) $id);

        return $this->apiSuccess($thread, 'Conversation retrieved successfully.');
    }

    /** POST /api/v1/admin/messaging/oversight/messages/{id}/delete — حذف إجباري، أي محادثة. */
    public function deleteMessage(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $messageId = (int) $id;
        $adminId = $request->attributes->get('uip_user_id');

        try {
            $conversationId = $this->messaging->adminDeleteMessage($messageId, $adminId);
            $this->auditLog->record($adminId, 'admin.messaging_message_deleted', 'Message', $messageId, null, ['conversation_id' => $conversationId]);
            return $this->apiSuccess(['conversation_id' => $conversationId], 'Message deleted.');
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** DELETE /api/v1/admin/messaging/oversight/attachments/{id} — حذف إجباري، أي محادثة. */
    public function deleteAttachment(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $attachmentId = (int) $id;
        $adminId = $request->attributes->get('uip_user_id');

        try {
            $result = $this->messaging->adminDeleteAttachment($attachmentId, $adminId);
            $this->auditLog->record($adminId, 'admin.messaging_attachment_deleted', 'Attachment', $attachmentId, null, ['conversation_id' => $result['conversation_id']]);
            return $this->apiSuccess($result, 'Attachment deleted.');
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** GET /api/v1/admin/messaging/analytics — استخدام الرسايل على مستوى المنصة كلها. */
    public function analytics(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->messaging->adminAnalytics(), 'Messaging analytics retrieved successfully.');
    }

    /** GET /api/v1/admin/messaging/settings — حدود الرفع، الاحتفاظ، تفعيل كل بورتال. */
    public function settings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess([
            'upload_policy'    => $this->policy->getUploadPolicy(),
            'retention_policy' => $this->policy->getRetentionPolicy(),
            'portal_toggles'   => $this->policy->portalToggles(),
        ], 'Messaging settings retrieved successfully.');
    }

    /** PATCH /api/v1/admin/messaging/settings/upload — {max_kb, restrict_extensions, allowed_extensions}. */
    public function updateUploadSettings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        try {
            $policy = $this->policy->updateUploadPolicy([
                'max_kb'              => (int) $request->input('max_kb', 0),
                'restrict_extensions' => (bool) $request->input('restrict_extensions', false),
                'allowed_extensions'  => (string) $request->input('allowed_extensions', ''),
            ], $request->attributes->get('uip_user_id'));
            return $this->apiSuccess($policy, 'Upload limits saved.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** PATCH /api/v1/admin/messaging/settings/retention — {retention_days, auto_cleanup_enabled}. */
    public function updateRetentionSettings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        try {
            $policy = $this->policy->updateRetentionPolicy([
                'retention_days'       => (int) $request->input('retention_days', 0),
                'auto_cleanup_enabled' => (bool) $request->input('auto_cleanup_enabled', false),
            ], $request->attributes->get('uip_user_id'));
            return $this->apiSuccess($policy, 'Retention policy saved.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** POST /api/v1/admin/messaging/settings/portal/{key} — {enabled: 0|1}. */
    public function updatePortalToggle(Request $request, string $key)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $enabled = (bool) ((int) $request->input('enabled', 1));

        try {
            $this->policy->setPortalEnabled($key, $enabled, $request->attributes->get('uip_user_id'));
            return $this->apiSuccess(['key' => $key, 'enabled' => $enabled], 'Portal status updated.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }
}
