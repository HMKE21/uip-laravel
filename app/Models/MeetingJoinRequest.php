<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `meeting_join_requests` (Round 2 — migration
 * 2026_08_31_010000). صف واحد بيمثل حالتين مختلفتين حسب status:
 *
 *  - قبل القرار (status=pending): طلب في الـ waiting room مستني الهوست
 *    (بند 22).
 *  - بعد القبول (status=admitted) لو user_id=null: ده *هو* سجل حضور
 *    الضيف نفسه (بند 23) — مفيش صف موازي له في meeting_participants،
 *    الجدول ده مقصور على المستخدمين المسجلين بس زي ما اتحدد في Round 1.
 *
 * لليوزر المسجّل (user_id != null) بعد القبول، الصف الحقيقي لحضوره
 * الفعلي هو meeting_participants (زي الأول)، والصف هنا بيفضل بس سجل
 * تاريخي لطلب الدخول نفسه (وقته، هل اتقبل ولا اتّرفض، مين اللي قرر).
 */
class MeetingJoinRequest extends Model
{
    protected $table = 'meeting_join_requests';

    protected $fillable = [
        'meeting_id', 'user_id', 'guest_name', 'status',
        'password_verified', 'device_preferences',
        'requested_at', 'decided_at', 'decided_by_user_id', 'left_at',
        // Round 3 (Signaling) — راجع docblock migration 2026_08_31_030000.
        'mic_enabled', 'camera_enabled', 'screen_sharing', 'connection_state', 'last_seen_at',
        // Round 4 (WebRTC Core، بند 34) — راجع docblock migration 2026_08_31_040000.
        'connection_quality',
        // Round 5 (Live Collaboration، بند 11/9) — راجع docblock migration 2026_08_31_050000.
        'hand_raised', 'hand_raised_at', 'screen_share_allowed',
        // Round 6 (Host Controls، بند 10) — راجع docblock migration 2026_08_31_070000.
        'removed_by_user_id', 'removed_at',
    ];

    protected $casts = [
        'password_verified'     => 'boolean',
        'device_preferences'    => 'array',
        'requested_at'          => 'datetime',
        'decided_at'            => 'datetime',
        'left_at'               => 'datetime',
        'mic_enabled'           => 'boolean',
        'camera_enabled'        => 'boolean',
        'screen_sharing'        => 'boolean',
        'last_seen_at'          => 'datetime',
        'hand_raised'           => 'boolean',
        'hand_raised_at'        => 'datetime',
        'screen_share_allowed'  => 'boolean',
        'removed_at'            => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** Round 6 — الهوست/co-host اللي شال الضيف ده بعد ما كان مقبول (status=rejected بمعنى "القبول اتلغى"). */
    public function removedBy()
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    /** بند 23 — الشكل اللي الهوست بيشوفه في قائمة الحضور: "Guest — Ahmed". */
    public function displayLabel(): string
    {
        if (!$this->isGuest()) {
            return $this->user->full_name ?? ('User #' . $this->user_id);
        }

        return 'Guest — ' . $this->guest_name;
    }
}
