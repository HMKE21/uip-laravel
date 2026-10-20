<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationEmailQueue extends Model
{
    use HasFactory;

    protected $table = 'notification_email_queue';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'notification_id',
        'kind',
        'to_email',
        'to_name',
        'subject',
        'body',
        'link_url',
        'locale',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }
}
