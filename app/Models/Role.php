<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `roles` القديم بالظبط. */
class Role extends Model
{
    protected $table = 'roles';

    public $timestamps = false;

    protected $fillable = ['slug', 'name_ar', 'name_en', 'description', 'portal_prefix', 'is_system'];
}
