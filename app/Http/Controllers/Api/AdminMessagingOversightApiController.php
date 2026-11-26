<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\MessagingOversightAccessService;
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
        private AuditLogService $auditLog,
        private MessagingOversightAccessService $access
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

        $result = $this->messaging->adminSearchConversations($filters);

        // عنوان/موضوع المحادثة ممكن يكشف محتوى: بيتخفى إلا لو فيه تصريح ساري بيغطي المحادثة دي.
        $grants = $this->access->activeGrants((int) $request->attributes->get('uip_user_id'), 'people');
        foreach ($result['items'] as &$row) {
            if (!empty($row['subject']) && !$this->access->coversConversation($grants, (int) $row['id'])) {
                $row['subject'] = null;
                $row['subject_locked'] = true;
            }
        }
        unset($row);

        return $this->apiSuccess($result, 'Conversations retrieved successfully.');
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

        $adminId = (int) $request->attributes->get('uip_user_id');
        $grants = $this->access->activeGrants($adminId, 'people', (int) $id);

        // الإخفاء على السيرفر: أي رسالة برا الفترة المصرّح بيها (أو من غير تصريح) بترجع مشفّرة وبدون أي محتوى/ملفات.
        $open = 0;
        $locked = 0;
        foreach ($thread['messages'] as $i => $m) {
            if ($this->access->covers($grants, $m['created_at'] ?? null)) {
                $thread['messages'][$i]['locked'] = false;
                $open++;
            } else {
                $thread['messages'][$i] = $this->access->maskPeopleMessage($m);
                $locked++;
            }
        }
        if (!empty($thread['conversation']['subject']) && !$this->access->coversConversation($grants, (int) $id)) {
            $thread['conversation']['subject'] = null;
            $thread['conversation']['subject_locked'] = true;
        }
        $thread['access'] = [
            'scope'    => 'people',
            'open'     => $open,
            'locked'   => $locked,
            'grants'   => array_map(fn ($g) => [
                'id' => (int) $g['id'], 'date_from' => substr((string) $g['date_from'], 0, 10), 'date_to' => substr((string) $g['date_to'], 0, 10),
                'expires_at' => (string) $g['expires_at'], 'conversation_id' => $g['conversation_id'] !== null ? (int) $g['conversation_id'] : null,
            ], $grants),
            'configured' => $this->access->keyConfigured('people'),
        ];

        $this->auditLog->record($adminId, 'admin.messaging_conversation_viewed', 'Conversation', (int) $id, null, ['messages_opened' => $open, 'messages_locked' => $locked]);

        return $this->apiSuccess($thread, 'Conversation retrieved successfully.');
    }

    /** GET /api/v1/admin/messaging/oversight-access — حالة المفاتيح + التصاريح السارية للأدمن الحالي. */
    public function accessStatus(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->access->status((int) $request->attributes->get('uip_user_id')), 'Access status retrieved.');
    }

    /** POST /api/v1/admin/messaging/oversight-access — {scope: people|ai, key, reason, date_from, date_to, conversation_id?, minutes?} */
    public function grantAccess(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        try {
            $grant = $this->access->grant(
                (int) $request->attributes->get('uip_user_id'),
                (string) $request->input('scope', 'people'),
                $request->only(['key', 'reason', 'from_at', 'to_at', 'date_from', 'date_to', 'conversation_id', 'minutes']),
                $request->ip()
            );

            return $this->apiSuccess($grant, 'Access granted.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            $code = in_array($e->getCode(), [403, 429, 503], true) ? $e->getCode() : 403;

            return $this->apiError($e->getMessage(), null, $code);
        }
    }

    /** DELETE /api/v1/admin/messaging/oversight-access/{id} — قفل التصريح فورًا. */
    public function revokeAccess(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $ok = $this->access->revoke((int) $request->attributes->get('uip_user_id'), (int) $id, $request->ip());

        return $ok ? $this->apiSuccess(['id' => (int) $id], 'Access revoked.') : $this->apiError('Grant not found.', null, 404);
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
