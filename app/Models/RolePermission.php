<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use HasFactory;

    protected $table = 'role_permission';
    public $incrementing = false;
    // Composite primary key (role_id, permission_id) - Eloquent has no native support;
    // query via where() clauses, e.g. static::where('col1', $a)->where('col2', $b).
    public $timestamps = false;

    protected $fillable = [];

    // Relationships

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
