<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Group Hub — ملف متشارك داخل مجموعة طلاب واحدة. راجع migration 2026_08_28_000100. */
class GroupFile extends Model
{
    protected $table = 'group_files';

    protected $fillable = [
        'group_id', 'user_id', 'original_name', 'stored_path',
        'mime_type', 'size_bytes', 'description',
    ];
}
