<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `permissions` القديم بالظبط (migration 002 — RBAC catalogue). */
class Permission extends Model
{
    protected $table = 'permissions';

    public $timestamps = false;

    protected $fillable = ['slug', 'module', 'description'];
}
