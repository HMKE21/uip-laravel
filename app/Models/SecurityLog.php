<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityLog extends Model
{
    use HasFactory;

    protected $table = 'security_logs';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
        'severity',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
