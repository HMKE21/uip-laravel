<?php

namespace App\Services;

use App\Models\Notification;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingRepository;

/**
 * بند 19 (Notifications) — الآن كامل على مستوى الـ Notification Center:
 * search/counts/state-transitions (read/unread/pin/important/archive/
 * delete/restore/purge)/bulk، فوق notify()/forUser()/unreadCount() القدامى
 * من بند 4 (لسه بنفس التوقيع بالظبط — خدمات كتير في المشروع بتنادي notify()
 * وميحصلش أي كسر ليهم).
 *
 * فجوة لسه موثقة عمدًا (مش جزء من عقد الفرونت الحالي — Notifications.jsx
 * مش بيعتمد عليها): notify() القديمة كانت كمان بتتحقق من isMuted()/
 * isCategoryMuted() (تفضيلات كتم) وبتعمل dedup لنفس النوع خلال نافذة زمنية
 * قصيرة (findRecentDuplicate/bumpOccurrence — الميثودز نفسها موجودة الآن في
 * NotificationRepository لكن notify() لسه مش بتناديهم)، وبتبعت إيميل عبر
 * NotificationEmailQueue. التلاتة دول (mute preferences تفعيل فعلي داخل
 * notify()، email queue، digest) لسه مؤجلين — يحتاجوا NotificationPreferencesService
 * تتوصل هنا وربط NotificationEmailQueueRepository/MailService، وده تغيير في
 * توقيع الـ constructor مش مجرد إضافة ميثودز، فمتعمد منفصل عن التسليمة دي.
 */
class NotificationService
{
    private const CATEGORY_MAP = [
        'message'      => 'messages',
        'mention'      => 'messages',
        'reply'        => 'messages',
        'comment'      => 'messages',
        'post'         => 'university',
        'announcement' => 'university',
        'project'      => 'projects',
        'approval'     => 'projects',
        'approved'     => 'projects',
        'rejected'     => 'projects',
        'ai_'          => 'ai',
        'report'       => 'reports',
        'team'         => 'invitations',
        'invitation'   => 'invitations',
        'file'         => 'files',
        'upload'       => 'files',
        'shar'         => 'files',
        'security'     => 'security',
        'login'        => 'security',
        'password'     => 'security',
        'system'       => 'system',
        'student_affiliation' => 'university',
        'student_academic'    => 'university',
        'course_request'      => 'university',
    ];

    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(private NotificationRepository $notifications, private SettingRepository $settings)
    {
    }

    /**
     * منقولة من NotificationService::retentionPolicy() القديمة — بند 25
     * batch 4 (AdminNotificationSettingsApiController). سياسة الأرشفة/
     * الحذف التلقائي على مستوى المنصة كلها للإشعارات المقروءة (بيطبّقها
     * cron/apply_notification_retention.php مرة يوميًا) — مختلفة عن
     * قايمة "My Notifications" بتاعة اليوزر نفسه.
     * @return array{archive_days:int,delete_days:int}
     */
    public function retentionPolicy(): array
    {
        return [
            'archive_days' => (int) $this->settings->get('notification_auto_archive_days', 'global', null, '0'),
            'delete_days'  => (int) $this->settings->get('notification_auto_delete_days', 'global', null, '0'),
        ];
    }

    /** منقولة من setRetentionPolicy() القديمة بالظبط. */
    public function setRetentionPolicy(int $archiveDays, int $deleteDays): void
    {
        $this->settings->set('notification_auto_archive_days', max(0, $archiveDays), 'global', null);
        $this->settings->set('notification_auto_delete_days', max(0, $deleteDays), 'global', null);
    }

    public function notify($userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null, string $priority = 'normal'): void
    {
        if (!$userId) {
            return;
        }
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            $priority = 'normal';
        }

        $notification = Notification::create([
            'user_id'  => $userId,
            'type'     => $type,
            'priority' => $priority,
            'category' => $this->categoryFor($type),
            'title'    => $title,
            'body'     => $body,
            'link_url' => $linkUrl,
            'portal'   => \App\Repositories\NotificationRepository::portalFor($type, $linkUrl),
        ]);

        // لحظي (Reverb): الإشعار يظهر في الجرس/مركز الإشعارات في نفس الثانية بدون انتظار الـ polling.
        \App\Support\Realtime::toUsers([(int) $userId], 'notification', [
            'id'       => (int) $notification->id,
            'type'     => $type,
            'title'    => mb_substr($title, 0, 120),
            'body'     => $body !== null ? mb_substr($body, 0, 140) : null,
            'link_url' => $linkUrl,
            'priority' => $priority,
        ]);

        // Push للموبايل — بعد ما الرد يتبعت (مفيش تأخير)، وأي فشل مبيكسرش الإشعار الداخلي.
        try {
            \App\Jobs\SendPushNotificationJob::dispatchAfterResponse((int) $notification->id);
        } catch (\Throwable $e) {
            // تجاهل عمدًا
        }

        // إيميل لأحداث مشروع التخرج (بيدخل الطابور بس؛ الإرسال الفعلي في notifications:send-emails).
        // محاط بـ try/catch جوه الخدمة نفسها — أي مشكلة في الإيميل مبتكسرش الإشعار الداخلي.
        try {
            app(NotificationEmailService::class)->queueFor($notification);
        } catch (\Throwable $e) {
            // تجاهل عمدًا
        }
    }

    /** @return array<int,array<string,mixed>> شكله للـ Notifications view */
    public function forUser($userId): array
    {
        return array_map(fn ($n) => $this->toRow($n), $this->notifications->forUser($userId));
    }

    public function unreadCount($userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    private function toRow($n): array
    {
        return [
            'id'               => $n->id,
            'type'             => $n->type,
            'category'         => $n->category,
            'priority'         => $n->priority ?: 'normal',
            'occurrence_count' => (int) ($n->occurrence_count ?: 1),
            'icon'             => $this->iconFor($n->type),
            'title'            => $n->title,
            'body'             => $n->body,
            'link_url'         => $n->link_url,
            'is_read'          => (bool) $n->is_read,
            'read_at'          => $n->read_at,
            'is_pinned'        => (bool) $n->is_pinned,
            'is_important'     => (bool) $n->is_important,
            'is_archived'      => (bool) $n->is_archived,
            'archived_at'      => $n->archived_at,
            'is_deleted'       => (bool) $n->is_deleted,
            'deleted_at'       => $n->deleted_at,
            'created_at'       => $n->created_at,
        ];
    }

    // ======================================================================
    // بند 19 — Notification Center الكامل (فوق notify()/forUser()/
    // unreadCount() القدامى، بند 4، اللي فضلوا زي ما هما من غير أي كسر —
    // خدمات كتير في المشروع بتنادي notify() بالتوقيع ده بالظبط).
    // ======================================================================

    /**
     * @param array<string,mixed> $filters نفس مفاتيح NotificationRepository::search()
     * @return array{items:array,total:int,page:int,per_page:int}
     */
    public function search($userId, array $filters = []): array
    {
        $result = $this->notifications->search($userId, $filters);
        return [
            'items'    => array_map(fn ($n) => $this->toRow($n), $result['items']),
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ];
    }

    /** @return array{unread:int,read:int,pinned:int,important:int,archived:int,deleted:int,total:int} */
    public function counts($userId): array
    {
        return $this->notifications->counts($userId);
    }

    public function markRead($id, $userId): bool
    {
        return $this->notifications->markRead($id, $userId);
    }

    public function markUnread($id, $userId): bool
    {
        return $this->notifications->markUnread($id, $userId);
    }

    /** بتعلّم إشعارات new_message بتاعة محادثة معينة كمقروءة (لما اليوزر يفتح المحادثة). */
    public function markConversationNotificationsRead($userId, int $conversationId): void
    {
        $this->notifications->markConversationNotificationsRead($userId, $conversationId);
    }

    public function markAllRead($userId): void
    {
        $this->notifications->markAllRead($userId);
    }

    public function pin($id, $userId): bool
    {
        return $this->notifications->pin($id, $userId);
    }

    public function unpin($id, $userId): bool
    {
        return $this->notifications->unpin($id, $userId);
    }

    public function markImportant($id, $userId): bool
    {
        return $this->notifications->markImportant($id, $userId);
    }

    public function unmarkImportant($id, $userId): bool
    {
        return $this->notifications->unmarkImportant($id, $userId);
    }

    public function archive($id, $userId): bool
    {
        return $this->notifications->archive($id, $userId);
    }

    public function unarchive($id, $userId): bool
    {
        return $this->notifications->unarchive($id, $userId);
    }

    /** Soft delete (يروح Trash) — قابل للرجوع عبر restore(). */
    public function delete($id, $userId): bool
    {
        return $this->notifications->delete($id, $userId);
    }

    public function restore($id, $userId): bool
    {
        return $this->notifications->restore($id, $userId);
    }

    /** حذف نهائي — لا رجعة فيه، عكس delete() فوق. */
    public function purge($id, $userId): bool
    {
        return $this->notifications->purge($id, $userId);
    }

    public function deleteAllRead($userId): void
    {
        $this->notifications->deleteAllRead($userId);
    }

    /**
     * @param array<int,int|string> $ids
     * @param string $action markRead|markUnread|pin|unpin|important|unimportant|archive|unarchive|delete|restore
     */
    public function bulkAction(array $ids, $userId, string $action): int
    {
        return $this->notifications->bulkAction($ids, $userId, $action);
    }

    private function categoryFor(string $type): string
    {
        foreach (self::CATEGORY_MAP as $needle => $category) {
            if (str_contains($type, $needle)) {
                return $category;
            }
        }
        return 'general';
    }

    private function iconFor(string $type): string
    {
        return match (true) {
            str_contains($type, 'approved') => 'check-circle',
            str_contains($type, 'rejected') => 'x-circle',
            str_contains($type, 'message')  => 'message',
            str_contains($type, 'ai_')      => 'sparkles',
            default                         => 'bell',
        };
    }
}
