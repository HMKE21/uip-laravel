<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Group Hub — إعلان داخل مجموعة طلاب واحدة. راجع migration 2026_08_28_000100. */
class GroupAnnouncement extends Model
{
    protected $table = 'group_announcements';

    protected $fillable = [
        'group_id', 'user_id', 'title', 'body',
    ];
}
