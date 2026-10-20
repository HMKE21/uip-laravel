<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `settings` القديم بالظبط (migration 025). */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['scope', 'user_id', 'key', 'value'];
}
