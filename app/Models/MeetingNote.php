<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `meeting_notes` (Round 7 — migration 2026_08_31_080000).
 * صف واحد لكل اجتماع (unique(meeting_id)) — الأجندة/الملاحظات الحرة
 * كمستند تعاوني واحد. راجع docblock migration الملف والعناصر البنيوية
 * (Decision/Action Item/Task) في MeetingActionItem بدلًا.
 */
class MeetingNote extends Model
{
    protected $table = 'meeting_notes';

    protected $fillable = [
        'meeting_id', 'body', 'last_edited_by_key', 'last_edited_by_display_name', 'last_edited_at',
    ];

    protected $casts = [
        'last_edited_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}
