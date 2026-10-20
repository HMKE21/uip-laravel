<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `refresh_tokens` القديم بالظبط. */
class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    public $timestamps = false; // فيه created_at بس، مفيهوش updated_at

    protected $fillable = [
        'user_id', 'token_hash', 'device_label', 'ip_address',
        'expires_at', 'revoked_at', 'replaced_by_id',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
