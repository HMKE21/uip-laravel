<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `blocked_ips` القديم بالظبط (migration 039). */
class BlockedIp extends Model
{
    const UPDATED_AT = null;

    protected $table = 'blocked_ips';

    protected $fillable = ['ip_address', 'reason', 'blocked_by'];
}
