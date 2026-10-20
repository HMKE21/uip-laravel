<?php

namespace App\Services;

use App\Models\Meeting;
use App\Repositories\MeetingRepository;
use App\Repositories\SettingRepository;

/**
 * Meetings — Round 1. نفس فلسفة MessagingPolicyService: قيم افتراضية
 * قابلة للتعديل من لوحة الأدمن وقت التشغيل (جدول `settings`، scope=global)
 * فوق config/meetings.php الثابت + قرارات الصلاحية (مين يقدر يدير/يشوف
 * اجتماع). الاتنين هنا سوا لأن معظم قرارات الصلاحية في الموديول ده
 * (زي حد المشاركين الأقصى) هي نفسها قيمة Policy قابلة للتعديل، مش hardcoded.
 */
class MeetingPolicyService
{
    public function __construct(
        private SettingRepository $settings
    ) {
    }

    // -- إعدادات قابلة للتعديل من الأدمن (fallback لـ config/meetings.php) --

    public function defaultDurationMinutes(): int
    {
        return (int) $this->settings->get('meeting_default_duration_minutes', 'global', null, (string) config('meetings.default_duration_minutes'));
    }

    public function maxDurationMinutes(): int
    {
        return (int) $this->settings->get('meeting_max_duration_minutes', 'global', null, (string) config('meetings.max_duration_minutes'));
    }

    public function maxMeshParticipants(): int
    {
        return (int) $this->settings->get('meeting_max_mesh_participants', 'global', null, (string) config('meetings.max_mesh_participants'));
    }

    public function waitingRoomDefaultEnabled(): bool
    {
        return $this->settings->get('meeting_waiting_room_default', 'global', null, config('meetings.waiting_room_default_enabled') ? '1' : '0') === '1';
    }

    public function allowGuestsDefault(): bool
    {
        return $this->settings->get('meeting_allow_guests_default', 'global', null, config('meetings.allow_guests_default') ? '1' : '0') === '1';
    }

    public function invitationExpiryHours(): int
    {
        return (int) $this->settings->get('meeting_invitation_expiry_hours', 'global', null, (string) config('meetings.invitation_expiry_hours'));
    }

    /** Round 2 — بند 23 (Guest Access). راجع docblock config/meetings.php. */
    public function guestSessionTtlMinutes(): int
    {
        return (int) $this->settings->get('meeting_guest_session_ttl_minutes', 'global', null, (string) config('meetings.guest_session_ttl_minutes'));
    }

    /** Round 3 — بند 12 ("Support configurable reminders"). كام دقيقة قبل الميعاد يتبعت إشعار "starting soon". */
    public function reminderMinutesBefore(): int
    {
        return (int) $this->settings->get('meeting_reminder_minutes_before', 'global', null, (string) config('meetings.reminder_minutes_before'));
    }

    // -- قرارات الصلاحية ------------------------------------------------

    public function isHost(Meeting $meeting, $userId): bool
    {
        return (int) $meeting->host_user_id === (int) $userId;
    }

    /** هوست أو co_host — بيقدر يدير الاجتماع (تعديل/إلغاء/دعوة/طرد). */
    public function canManage(Meeting $meeting, $userId, MeetingRepository $meetings): bool
    {
        if ($this->isHost($meeting, $userId)) {
            return true;
        }
        $participant = $meetings->findParticipant($meeting->id, $userId);
        return $participant !== null && $participant->role === 'co_host' && $participant->status !== 'removed';
    }

    /** هوست، أو أي مستخدم مدعو/مشارك فعليًا في الاجتماع. */
    public function canView(Meeting $meeting, $userId, MeetingRepository $meetings): bool
    {
        if ($this->isHost($meeting, $userId)) {
            return true;
        }
        return $meetings->findParticipant($meeting->id, $userId) !== null;
    }
}
