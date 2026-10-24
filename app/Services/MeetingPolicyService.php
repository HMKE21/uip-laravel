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
        return (int) \App\Services\MeetingAdminSettings::get('default_duration_minutes');
    }

    public function maxDurationMinutes(): int
    {
        return (int) \App\Services\MeetingAdminSettings::get('max_duration_minutes');
    }

    public function maxMeshParticipants(): int
    {
        return (int) \App\Services\MeetingAdminSettings::get('max_mesh_participants');
    }

    public function waitingRoomDefaultEnabled(): bool
    {
        return (bool) \App\Services\MeetingAdminSettings::get('waiting_room_default');
    }

    public function allowGuestsDefault(): bool
    {
        return (bool) \App\Services\MeetingAdminSettings::get('allow_guests_default');
    }

    public function invitationExpiryHours(): int
    {
        return (int) \App\Services\MeetingAdminSettings::get('invitation_expiry_hours');
    }

    /** Round 2 — بند 23 (Guest Access). راجع docblock config/meetings.php. */
    public function guestSessionTtlMinutes(): int
    {
        return (int) \App\Services\MeetingAdminSettings::get('guest_session_ttl_minutes');
    }

    /** Round 3 — بند 12 ("Support configurable reminders"). كام دقيقة قبل الميعاد يتبعت إشعار "starting soon". */
    public function reminderMinutesBefore(): int
    {
        return (int) \App\Services\MeetingAdminSettings::get('reminder_minutes_before');
    }

    /** Master switch (Admin → Meetings Control). */
    public function systemEnabled(): bool
    {
        return (bool) \App\Services\MeetingAdminSettings::get('feature_meetings');
    }

    public function maintenanceMessage(): string
    {
        $m = trim((string) \App\Services\MeetingAdminSettings::get('maintenance_message'));

        return $m !== '' ? $m : 'Meetings are temporarily disabled by the administrator.';
    }

    /**
     * يتنادى قبل إنشاء أي اجتماع.
     * @param array $data نفس payload الإنشاء (type, scheduled_start_at, password ...)
     * @throws \RuntimeException النظام مقفول / اليوزر محظور / دوره مش مسموح / عدّى حد / النوع متقفل.
     * @throws \InvalidArgumentException كلمة السر إجبارية ومش مبعوتة.
     */
    public function assertCanCreate(int $userId, ?string $role, array $data): void
    {
        $A = \App\Services\MeetingAdminSettings::class;

        if (!$this->systemEnabled()) {
            throw new \RuntimeException($this->maintenanceMessage());
        }

        // الأدمن نفسه مش بيتحظر ومش بيتقيّد بقيود الإنشاء.
        if ($role === 'admin') {
            return;
        }

        if (in_array($userId, (array) $A::get('blocked_user_ids'), true)) {
            throw new \RuntimeException('You are not allowed to create meetings. Please contact the administrator.');
        }

        $roles = (array) $A::get('creator_roles');
        if ($roles && !in_array((string) $role, $roles, true)) {
            throw new \RuntimeException('Your account type is not allowed to create meetings.');
        }

        $type = $data['type'] ?? 'instant';
        if ($type === 'instant' && !$A::get('allow_instant')) {
            throw new \RuntimeException('Instant meetings are disabled by the administrator.');
        }
        if ($type === 'scheduled' && !$A::get('allow_scheduled')) {
            throw new \RuntimeException('Scheduled meetings are disabled by the administrator.');
        }

        $maxActive = (int) $A::get('max_active_meetings_per_user');
        if ($maxActive > 0 && $type === 'instant') {
            $open = Meeting::where('host_user_id', $userId)
                ->where(fn ($q) => $q->whereIn('status', ['lobby', 'live'])->orWhere(fn ($q2) => $q2->where('type', 'instant')->where('status', 'scheduled')))
                ->count();
            if ($open >= $maxActive) {
                throw new \RuntimeException('You have reached the maximum number of open meetings (' . $maxActive . '). End one first.');
            }
        }

        $maxScheduled = (int) $A::get('max_scheduled_meetings_per_user');
        if ($maxScheduled > 0 && $type === 'scheduled') {
            $n = Meeting::where('host_user_id', $userId)->where('type', 'scheduled')->where('status', 'scheduled')->count();
            if ($n >= $maxScheduled) {
                throw new \RuntimeException('You have reached the maximum number of scheduled meetings (' . $maxScheduled . ').');
            }
        }

        $aheadDays = (int) $A::get('max_schedule_ahead_days');
        if ($aheadDays > 0 && $type === 'scheduled' && !empty($data['scheduled_start_at'])) {
            try {
                $start = \Illuminate\Support\Carbon::parse($data['scheduled_start_at']);
                if ($start->gt(now()->addDays($aheadDays))) {
                    throw new \RuntimeException('Meetings can only be scheduled up to ' . $aheadDays . ' days ahead.');
                }
            } catch (\Carbon\Exceptions\InvalidFormatException $e) {
                // التاريخ الغلط بيتمسك في الـ validation الأصلي.
            }
        }

        if ($A::get('require_password') && empty($data['password'])) {
            throw new \InvalidArgumentException('A meeting password is required by the administrator.');
        }
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
