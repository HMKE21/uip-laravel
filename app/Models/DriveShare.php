<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** رابط مشاركة لعنصر في "جهازي". الاستعلامات بتتقيّد بـ user_id في DriveShareService. */
class DriveShare extends Model
{
    protected $table = 'drive_shares';

    protected $fillable = [
        'user_id', 'node_id', 'token', 'can_download', 'expires_at', 'revoked_at', 'views', 'downloads', 'last_used_at',
    ];

    protected $hidden = ['user_id'];

    protected $casts = [
        'can_download' => 'boolean',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'views'        => 'integer',
        'downloads'    => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && !$this->isExpired();
    }

    public function toRowArray(): array
    {
        return [
            'id'           => (int) $this->id,
            'node_id'      => (int) $this->node_id,
            'token'        => $this->token,
            'can_download' => (bool) $this->can_download,
            'expires_at'   => $this->expires_at,
            'expired'      => $this->isExpired(),
            'views'        => (int) $this->views,
            'downloads'    => (int) $this->downloads,
            'last_used_at' => $this->last_used_at,
            'created_at'   => $this->created_at,
        ];
    }
}
