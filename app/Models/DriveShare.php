<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** رابط مشاركة لعنصر في "جهازي". الاستعلامات بتتقيّد بـ user_id في DriveShareService. */
class DriveShare extends Model
{
    protected $table = 'drive_shares';

    protected $fillable = [
        'user_id', 'node_id', 'token', 'can_download', 'expires_at', 'revoked_at', 'views', 'downloads', 'last_used_at',
        'require_visitor_info', 'password_hash', 'gate_version', 'paused_at',
    ];

    protected $hidden = ['user_id', 'password_hash'];

    protected $casts = [
        'can_download' => 'boolean',
        'require_visitor_info' => 'boolean',
        'gate_version' => 'integer',
        'paused_at'    => 'datetime',
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

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && !$this->isPaused() && !$this->isExpired();
    }

    /** بوابة قبل الفتح: باسورد و/أو بيانات الزائر. */
    public function hasGate(): bool
    {
        return $this->password_hash !== null && $this->password_hash !== '' || (bool) $this->require_visitor_info;
    }

    /** active | paused | expired | revoked */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->isPaused()) {
            return 'paused';
        }
        return $this->isExpired() ? 'expired' : 'active';
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
            'status'       => $this->status(),
            'paused'       => $this->isPaused(),
            'require_visitor_info' => (bool) $this->require_visitor_info,
            'has_password' => $this->password_hash !== null && $this->password_hash !== '',
            'views'        => (int) $this->views,
            'downloads'    => (int) $this->downloads,
            'last_used_at' => $this->last_used_at,
            'created_at'   => $this->created_at,
        ];
    }
}
