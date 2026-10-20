<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `trusted_devices` (migration 070) بالظبط. */
class TrustedDevice extends Model
{
    protected $table = 'trusted_devices';

    public $timestamps = false; // created_at بس عنده default، مفيهوش updated_at

    protected $fillable = [
        'user_id', 'selector', 'token_hash', 'device_label',
        'user_agent', 'ip_address', 'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'created_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];
}
