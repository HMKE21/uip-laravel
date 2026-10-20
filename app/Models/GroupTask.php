<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Group Hub — مهمة داخل مجموعة طلاب واحدة. راجع migration 2026_08_28_000100. */
class GroupTask extends Model
{
    protected $table = 'group_tasks';

    protected $fillable = [
        'group_id', 'created_by_user_id', 'assignee_user_id',
        'title', 'status', 'due_date', 'completed_at',
    ];

    protected $casts = [
        'due_date'     => 'date:Y-m-d',
        'completed_at' => 'datetime',
    ];
}
